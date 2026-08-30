<?php
/**
 * تنظیمات سایت (قیمت، تماس، متن‌ها، پیامک) و ارسال ایمیل.
 *
 * این فایل عمداً از منطق نشست/ورود سوپرادمین جداست — چون سایت معرفی
 * (`public.php`، بدون هیچ ورودی) هم برای نمایش قیمت و فرستادن ایمیل
 * درخواست دمو به همین توابع نیاز دارد. اگر این‌ها داخل فایل نشست
 * سوپرادمین می‌ماندند، سایت معرفی مجبور می‌شد کل منطق ورود ادمین را
 * هم بارگذاری کند — بی‌ربط و یک سطح دسترسی اضافه‌ای که لازم نیست.
 *
 * خوانده و نوشته می‌شود توسط: public.php (سایت، فقط خواندن + ایمیل)،
 * و superadmin/api/super.php (نوشتن از پنل سوپرادمین).
 */

declare(strict_types=1);

if (realpath(__FILE__) === realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    http_response_code(404);
    exit;
}

/**
 * مقدارهای پیش‌فرض. هر کلیدی که اینجا نباشد از بیرون قابل نوشتن نیست —
 * یعنی کسی نمی‌تواند با فرستادن کلید دلخواه، ردیف بی‌ربط در جدول بسازد.
 *
 * قیمت‌ها ریال‌اند و صحیح. نمایش به تومان با تقسیم بر ۱۰ انجام می‌شود
 * (بند P.5) — هیچ‌جا عدد اعشاری برای پول نگه نمی‌داریم.
 */
function setting_defaults(): array
{
    return [
        // ── تماس ──
        'contact_phone'    => '',
        'contact_email'    => 'info@talkora.ir',
        'demo_email'       => 'info@talkora.ir',   // درخواست دموی رایگان به این آدرس می‌رود
        'contact_address'  => '',
        'support_hours'    => 'شنبه تا چهارشنبه، ۹ تا ۱۷',

        // ── قیمت‌ها، به ریال در ماه ──
        'price_basic'      => '4900000',
        'price_growth'     => '17000000',
        'price_pro'        => '44000000',
        // درصد تخفیف پرداخت سالانه
        'annual_discount'  => '20',
        'trial_days'       => '14',

        // ── متن‌های سایت ──
        'hero_title'       => 'آموزشگاه‌تان را از یک صفحه اداره کنید',
        'hero_sub'         => 'ثبت‌نام، ترم‌بندی، حضور و غیاب، کلاس آنلاین، تکلیف و نمره، شهریهٔ قسطی — همه در یک پنل فارسی.',
        'cta_text'         => 'درخواست دموی رایگان',
        'banner_text'      => '',      // نوار بالای سایت؛ خالی یعنی نمایش داده نمی‌شود
        'banner_on'        => '0',

        // ── پیامک ──
        /*
         * 'bridge' یعنی پیامکی فرستاده نمی‌شود و کد ورود در پنل سوپرادمین
         * دیده می‌شود؛ 'smsir' یعنی ارسال واقعی. کلید و شناسهٔ قالب
         * اینجا هم می‌آیند تا بدون FTP قابل تغییر باشند. هیچ‌کدام از
         * این چهار کلید در فهرست عمومی public.php نیست.
         */
        'sms_mode'          => 'bridge',
        'smsir_api_key'     => '',
        'smsir_template_id' => '',
        'smsir_param_name'  => 'CODE',
        /*
         * عمر کد، به ثانیه.
         *
         * دو دقیقه وقتی درست است که پیامک بلافاصله برسد. در عمل روی
         * اپراتورهای ایران گاهی یک تا دو دقیقه تأخیر هست، و آن‌وقت کد
         * درست لحظه‌ای به دست کاربر می‌رسد که دیگر منقضی شده — و او
         * فکر می‌کند سامانه خراب است. پنج دقیقه این را جبران می‌کند
         * بدون اینکه پنجرهٔ حمله معنادار شود: کد پنج‌رقمی با سقف پنج
         * تلاش و محدودیت نرخ، در پنج دقیقه هم قابل حدس‌زدن نیست.
         */
        'otp_ttl'           => '300',

        // ── نمادها ──
        'enamad_html'      => '',
        'samandehi_html'   => '',

        // ── وضعیت ──
        'maintenance'      => '0',
        'signup_open'      => '1',
    ];
}

function settings_all(): array
{
    $out = setting_defaults();
    foreach (db()->query('SELECT skey, svalue FROM site_setting')->fetchAll() as $r) {
        $k = (string)$r['skey'];
        if (array_key_exists($k, $out)) $out[$k] = (string)$r['svalue'];
    }
    return $out;
}

function setting(string $key, string $default = ''): string
{
    static $c = null;
    if ($c === null) $c = settings_all();
    return $c[$key] ?? $default;
}

function settings_save(array $values, ?string $adminId): int
{
    $allowed = setting_defaults();
    $db = db();
    $ins = $db->prepare('INSERT INTO site_setting (skey, svalue, updated_at, updated_by) VALUES (?,?,?,?)');
    $upd = $db->prepare('UPDATE site_setting SET svalue = ?, updated_at = ?, updated_by = ? WHERE skey = ?');

    $n = 0;
    foreach ($values as $k => $v) {
        if (!array_key_exists($k, $allowed)) continue;   // کلید ناشناخته بی‌صدا رد می‌شود
        $v = mb_substr(trim((string)$v), 0, 4000);
        try {
            $ins->execute([$k, $v, now_utc(), $adminId]);
        } catch (PDOException $e) {
            $upd->execute([$v, now_utc(), $adminId, $k]);
        }
        $n++;
    }
    return $n;
}

/* ─────────── ایمیل ─────────── */

/**
 * ارسال ایمیل با mail() خود PHP.
 *
 * روی پلسک، اگر سرویس Mail برای دامنه روشن باشد و صندوق info@talkora.ir
 * ساخته شده باشد، این کار می‌کند و ایمیل از خود دامنه می‌رود — که برای
 * نرسیدن به پوشهٔ اسپم مهم است.
 *
 * محدودیت صادقانه: mail() صف و تلاش دوباره ندارد. اگر ایمیل مهم شد
 * (مثلاً رسید پرداخت)، باید به SMTP با صف تبدیل شود. برای اعلان
 * «یک نفر دمو خواست» کافی است، و شکستش هم ثبت می‌شود.
 */
function send_mail(string $to, string $subject, string $body, ?string $replyTo = null): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    if (!function_exists('mail')) { error_log('mail() در دسترس نیست'); return false; }

    $from = setting('contact_email', 'info@talkora.ir');
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) $from = 'info@talkora.ir';

    // هدرها از ورودی کاربر ساخته نمی‌شوند؛ replyTo قبل از استفاده اعتبارسنجی می‌شود
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'From: =?UTF-8?B?' . base64_encode('تاکورا') . '?= <' . $from . '>',
        'X-Mailer: Talkora',
    ];
    if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }

    $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $okSent = @mail($to, $subj, $body, implode("\r\n", $headers), '-f' . $from);
    if (!$okSent) error_log("ارسال ایمیل به {$to} ناموفق بود");
    return (bool)$okSent;
}
