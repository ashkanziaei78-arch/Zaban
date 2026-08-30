<?php
/**
 * زمینهٔ درخواست: کاربر، آموزشگاه، نقش — و ابزارهای دسترسی.
 *
 * ═══ چرا این فایل وجود دارد ═══
 *
 * روی پستگرس، جداسازی مستأجرها با Row Level Security انجام می‌شد و
 * دیتابیس خودش نگهبان بود (server/db/001_init.sql). روی MySQL هاست
 * اشتراکی چنین چیزی نداریم. پس نگهبان باید کد باشد، و اگر در هر نقطهٔ
 * پایانی جداگانه بنویسیمش، بالاخره یک جا فراموش می‌شود و دادهٔ یک
 * آموزشگاه به آموزشگاه دیگر نشت می‌کند.
 *
 * برای همین همه‌چیز از همین‌جا می‌گذرد:
 *   - ctx()          کاربر و آموزشگاه فعال را می‌دهد
 *   - t_all/t_one    کوئری‌هایی که institute_id را خودشان اضافه می‌کنند
 *   - own_*          بررسی می‌کند شناسه‌ای که کلاینت فرستاده واقعاً
 *                    مال همین آموزشگاه است، پیش از هر تغییری
 *
 * قاعدهٔ ساده: هیچ کوئری‌ای نباید با شناسهٔ خامِ آمده از کلاینت اجرا
 * شود مگر آنکه اول از own_* گذشته باشد.
 */

declare(strict_types=1);

// ctx() روی active_context() می‌نشیند، پس موتور باید پیش از آن باشد
require_once __DIR__ . '/_perm.php';

if (realpath(__FILE__) === realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    http_response_code(404);
    exit;
}

/* ─────────── شناسه ─────────── */

function new_id(): string { return bin2hex(random_bytes(16)); }

/* ─────────── زمینه ─────────── */

/**
 * @return array{user:array, institute_id:string, role:string, institute:array}
 */
function ctx(): array
{
    static $c = null;
    if ($c !== null) return $c;

    $u = require_user();

    /*
     * آموزشگاه و نقش از زمینهٔ فعالِ نشست می‌آیند، نه از «اولین عضویت».
     * پیش از چند-نقشی این دو یکی بودند؛ حالا نه. اگر اینجا هنوز اولی
     * را برمی‌داشتیم، کاربری که به نقش دیگری سوییچ کرده بود همچنان
     * دادهٔ نقش اول را می‌دید.
     */
    $ac = active_context();

    $st = db()->prepare(
        'SELECT m.institute_id, m.role, m.can_host_meeting,
                i.name, i.term_weeks, i.city, i.phone, i.status, i.suspended_reason, i.jitsi_enabled
           FROM membership m
           JOIN institute i ON i.id = m.institute_id
          WHERE m.user_id = ? AND m.institute_id = ? AND m.role_id = ? AND m.status = ?
          LIMIT 1'
    );
    $st->execute([$u['id'], $ac['institute_id'], $ac['role_id'], 'active']);
    $m = $st->fetch();

    if (!$m) {
        fail(403, 'no_institute', 'شما هنوز عضو هیچ آموزشگاهی نیستید. از مدیر آموزشگاه بخواهید با همین شماره دعوت‌تان کند.');
    }

    /*
     * تعلیق آموزشگاه توسط سوپرادمین (superadmin/) دقیقاً همین‌جا اثر
     * می‌کند — چون هر نقطهٔ پایانی کسب‌وکاری (bootstrap.php و هرچه از
     * t_all/t_one/own_* استفاده کند) از ctx() می‌گذرد. اگر این بررسی
     * اینجا نبود، تعلیق فقط یک پرچم تزئینی در پنل سوپرادمین بود.
     */
    if ((string)$m['status'] === 'suspended') {
        $reason = trim((string)($m['suspended_reason'] ?? ''));
        fail(403, 'institute_suspended',
            'دسترسی این آموزشگاه موقتاً معلق شده' . ($reason !== '' ? ': ' . $reason : '.')
          . ' برای رفع مشکل با پشتیبانی تماس بگیرید.');
    }

    $c = [
        'user'             => $u,
        'institute_id'     => (string)$m['institute_id'],
        'role'             => (string)$m['role'],
        'can_host_meeting' => (bool)$m['can_host_meeting'],
        'institute'        => [
            'id'           => (string)$m['institute_id'],
            'name'         => (string)$m['name'],
            'termWeeks'    => (int)$m['term_weeks'],
            'city'         => $m['city'],
            'phone'        => $m['phone'],
            'jitsiEnabled' => (bool)$m['jitsi_enabled'],
        ],
    ];
    return $c;
}

function inst_id(): string { return ctx()['institute_id']; }
function my_id(): string   { return (string)ctx()['user']['id']; }
function my_role(): string { return ctx()['role']; }

/**
 * مجوز «ساخت/شروع جلسهٔ میت» — آبشاری: هم آموزشگاه باید فعالش کرده
 * باشد (سوپرادمین)، هم خود عضویت باید مجوز داشته باشد (مدیر از ابتدا
 * دارد، مدرس باید از مدیر بگیرد؛ سوپرادمین می‌تواند مجوز هرکسی را هم
 * قطع کند — دقیقاً همین یک شرط دومی که آن را ممکن می‌کند).
 *
 * برای تصمیم‌گیری در کد، jitsi_allowed() در _perm.php را صدا بزنید،
 * نه این را: آن محدودهٔ مجوز را هم حساب می‌کند. این تابع فقط پرچمِ
 * خام است و جایی به کار می‌آید که فقط همان پرچم را می‌خواهید.
 */
function can_host_meeting(): bool
{
    $c = ctx();
    return $c['institute']['jitsiEnabled'] && $c['can_host_meeting'];
}

/* ─────────── جلسهٔ میت (Jitsi) ─────────── */

/**
 * دامنهٔ سرور جیتسی — پیش‌فرض سرور عمومی و رایگان meet.jit.si. از
 * site_setting خوانده می‌شود تا اگر روزی سرور اختصاصی گرفتید، فقط از
 * پنل سوپرادمین عوض شود، نه با ویرایش کد (همان الگوی sms_conf() در
 * _sms.php برای خواندن مستقیم یک تنظیم بدون بارکردن کل _settings.php).
 */
function jitsi_domain(): string
{
    static $d = null;
    if ($d !== null) return $d;
    $d = 'meet.jit.si';
    try {
        $st = db()->query("SELECT svalue FROM site_setting WHERE skey = 'jitsi_domain'");
        $v = trim((string)($st->fetchColumn() ?: ''));
        if ($v !== '') $d = $v;
    } catch (Throwable $e) {
        error_log('jitsi_domain read failed: ' . $e->getMessage());
    }
    return $d;
}

/**
 * آدرس اتاق یک کلاس — همیشه یکی، برای کل عمر کلاس. classId خودش یک
 * شناسهٔ تصادفی ۳۲ کاراکتری است (new_id())، پس حدس‌زدنی نیست و نیازی
 * به رمز یا HMAC تازه نیست.
 */
function jitsi_room_url(string $classId): string
{
    return 'https://' . jitsi_domain() . '/talkora-' . $classId;
}

function require_role(string ...$roles): void
{
    if (!in_array(my_role(), $roles, true)) {
        fail(403, 'forbidden', 'این کار در دسترس نقش شما نیست.');
    }
}

/* ─────────── کوئری با مستأجر ─────────── */

/**
 * SQL باید شامل «__I__» باشد، جایی که شرط آموزشگاه می‌نشیند.
 * این‌طور فراموش‌کردنش ممکن نیست: اگر ننویسید، خطا می‌گیرید.
 */
function t_sql(string $sql, array $args): array
{
    if (strpos($sql, '__I__') === false) {
        // خطای برنامه‌نویس است، نه خطای کاربر — باید سر و صدا کند
        error_log('t_sql بدون __I__ صدا زده شد: ' . $sql);
        fail(500, 'server_error', 'خطای داخلی.');
    }

    /*
     * شناسهٔ آموزشگاه دقیقاً در همان جایگاهی می‌نشیند که __I__ نوشته شده،
     * نه اول فهرست.
     *
     * نسخهٔ اول این تابع همیشه شناسه را اول می‌گذاشت. تا وقتی __I__ اولین
     * «؟» کوئری بود کار می‌کرد، ولی در کوئری‌ای که «؟» زودتری داشت — مثلاً
     * LEFT JOIN ... ON a.session_id = ? — دو آرگومان جابه‌جا می‌شدند و
     * نتیجه بی‌سروصدا خالی برمی‌گشت. نه خطایی، نه هشداری؛ فقط لیست حضور
     * و غیابِ خالی. این‌طور شمردن، آن دسته اشکال را از ریشه می‌بندد.
     */
    $segments = explode('__I__', $sql);
    $out  = '';
    $vals = [];
    $idx  = 0;
    $last = count($segments) - 1;

    foreach ($segments as $i => $seg) {
        $out .= $seg;
        $n = substr_count($seg, '?');
        $vals = array_merge($vals, array_slice($args, $idx, $n));
        $idx += $n;
        if ($i < $last) {
            $out .= 'institute_id = ?';
            $vals[] = inst_id();
        }
    }
    // هر آرگومانی که بعد از آخرین __I__ مانده
    return [$out, array_merge($vals, array_slice($args, $idx))];
}

function t_all(string $sql, array $args = []): array
{
    [$q, $a] = t_sql($sql, $args);
    $st = db()->prepare($q);
    $st->execute($a);
    return $st->fetchAll();
}

function t_one(string $sql, array $args = []): ?array
{
    [$q, $a] = t_sql($sql, $args);
    $st = db()->prepare($q);
    $st->execute($a);
    $r = $st->fetch();
    return $r ?: null;
}

function t_exec(string $sql, array $args = []): int
{
    [$q, $a] = t_sql($sql, $args);
    $st = db()->prepare($q);
    $st->execute($a);
    return $st->rowCount();
}

/* ─────────── مالکیت ─────────── */

/**
 * شناسه‌ای که کلاینت فرستاده واقعاً مال همین آموزشگاه است؟
 * پاسخ «پیدا نشد» عمداً همان چیزی است که برای شناسهٔ آموزشگاه دیگر
 * برمی‌گردد — تا کسی نتواند با آزمون‌وخطا بفهمد چه شناسه‌هایی وجود دارند.
 */
function own(string $table, string $id, string $what = 'مورد'): array
{
    if ($id === '' || !preg_match('/^[a-f0-9]{32}$/', $id)) {
        fail(404, 'not_found', "$what پیدا نشد.");
    }
    $allowed = ['klass', 'class_session', 'assignment', 'submission', 'enrolment', 'room', 'term',
                'membership', 'join_request'];
    if (!in_array($table, $allowed, true)) {
        error_log("own() روی جدول غیرمجاز: $table");
        fail(500, 'server_error', 'خطای داخلی.');
    }
    $st = db()->prepare("SELECT * FROM {$table} WHERE id = ? AND institute_id = ?");
    $st->execute([$id, inst_id()]);
    $r = $st->fetch();
    if (!$r) fail(404, 'not_found', "$what پیدا نشد.");
    return $r;
}

/** کلاسی که کاربر فعلی حق دیدنش را دارد */
/**
 * دروازهٔ مالکیت کلاس.
 *
 * پیش از نسخهٔ ۶ بر پایهٔ نام نقش تصمیم می‌گرفت. حالا محدودهٔ مجوز را
 * می‌خواند، که برای سه نقش سیستمی دقیقاً همان نتیجه را می‌دهد
 * (مدیر=institute، مدرس=own_classes، زبان‌آموز=own) ولی برای نقش
 * سفارشی هم درست کار می‌کند — پیش از این هر نقش تازه به شاخهٔ
 * زبان‌آموز می‌افتاد و دنبال ثبت‌نامی می‌گشت که هرگز نداشت.
 *
 * $perm مجوزی است که این دسترسی زیر سایهٔ آن انجام می‌شود؛ پیش‌فرض
 * class.view یعنی «اجازهٔ دست‌زدن به این کلاس را دارم؟».
 */
function own_class(string $id, string $perm = 'class.view'): array
{
    $c = own('klass', $id, 'کلاس');

    $scope = perm_scope($perm) ?? 'own';

    // آموزشگاه و بالاتر: شرط مستأجر را own() بسته، همین کافی است
    if (scope_rank($scope) >= scope_rank('institute')) return $c;

    if ($scope === 'own_classes' || $scope === 'assigned_students') {
        if ((string)$c['teacher_user_id'] !== my_id()) {
            fail(403, 'forbidden', 'این کلاس شما نیست.');
        }
        return $c;
    }

    // own — باید ثبت‌نام فعال داشته باشد
    $st = db()->prepare('SELECT 1 FROM enrolment WHERE class_id = ? AND student_user_id = ? AND status = ?');
    $st->execute([$id, my_id(), 'active']);
    if (!$st->fetchColumn()) fail(403, 'forbidden', 'شما در این کلاس ثبت‌نام نیستید.');
    return $c;
}

/* ─────────── ورودی ─────────── */

function s_in(array $in, string $key, int $max = 200, string $default = ''): string
{
    $v = trim((string)($in[$key] ?? $default));
    return mb_substr($v, 0, $max);
}

function i_in(array $in, string $key, int $default = 0, int $min = 0, int $max = 100000000): int
{
    $v = $in[$key] ?? $default;
    if (is_string($v)) $v = en_digits($v);
    $v = (int)$v;
    return max($min, min($max, $v));
}

/** ارقام فارسی و عربی → لاتین */
function en_digits(string $s): string
{
    $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $en = ['0','1','2','3','4','5','6','7','8','9'];
    return str_replace($ar, $en, str_replace($fa, $en, $s));
}

function enum_in(array $in, string $key, array $allowed, string $default): string
{
    $v = (string)($in[$key] ?? $default);
    return in_array($v, $allowed, true) ? $v : $default;
}

/** HH:MM بیست‌وچهارساعته، با ارقام فارسی هم کار می‌کند */
function time_in(array $in, string $key, string $default = '18:00'): string
{
    $v = en_digits(trim((string)($in[$key] ?? '')));
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $v, $m)) {
        $h = (int)$m[1]; $mi = (int)$m[2];
        if ($h >= 0 && $h <= 23 && $mi >= 0 && $mi <= 59) {
            return sprintf('%02d:%02d', $h, $mi);
        }
    }
    return $default;
}

/** تاریخ میلادی ISO؛ ورودی از کلاینت همیشه میلادی است، تبدیل شمسی در مرورگر */
function date_in(array $in, string $key, ?string $default = null): ?string
{
    $v = en_digits(trim((string)($in[$key] ?? '')));
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)
        && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return $v;
    }
    return $default;
}

/* ─────────── لینک کلاس آنلاین ─────────── */

/**
 * لینک جلسه فقط اگر http(s) و بدون اسکیم خطرناک باشد پذیرفته می‌شود.
 * javascript: و data: از همین‌جا رد می‌شوند، وگرنه لینکی که مدرس وارد
 * می‌کند در مرورگر زبان‌آموز اجرا می‌شد.
 */
function join_url_in(array $in, string $key): ?string
{
    $v = trim((string)($in[$key] ?? ''));
    if ($v === '') return null;
    if (mb_strlen($v) > 500) return null;
    $p = parse_url($v);
    if (!$p || empty($p['scheme']) || empty($p['host'])) return null;
    if (!in_array(strtolower($p['scheme']), ['http', 'https'], true)) return null;
    return $v;
}

/* ─────────── تقویم کلاس ─────────── */

/**
 * الگوی روزهای هفته به شمارهٔ روز PHP (۰=یکشنبه … ۶=شنبه).
 *
 * قرارداد «زوج و فرد» ایرانی است و ربطی به زوج‌بودن عدد روز ندارد
 * (بند P.3): فرد یعنی شنبه، دوشنبه، چهارشنبه — و زوج یعنی یکشنبه و
 * سه‌شنبه. اشتباه‌گرفتن این دو، کل برنامهٔ ترم را جابه‌جا می‌کند.
 */
function day_numbers(string $pattern): array
{
    switch ($pattern) {
        case 'زوج':     return [0, 2];
        case 'پنجشنبه': return [4];
        case 'جمعه':    return [5];
        case 'فشرده':   return [6, 0, 1, 2, 3];
        default:        return [6, 1, 3];   // فرد
    }
}

/**
 * جلسه‌های یک کلاس را از تاریخ شروع ترم می‌سازد.
 * جلسه‌های موجود پاک نمی‌شوند؛ اگر کلاس قبلاً جلسه داشته، دست نمی‌خورد.
 */
function generate_sessions(string $classId, string $pattern, string $startTime,
                           int $count, string $fromDate, ?string $joinUrl): int
{
    $exists = db()->prepare('SELECT COUNT(*) FROM class_session WHERE class_id = ?');
    $exists->execute([$classId]);
    if ((int)$exists->fetchColumn() > 0) return 0;

    $days = day_numbers($pattern);
    $ts   = strtotime($fromDate . ' 00:00:00 UTC');
    if ($ts === false) return 0;

    $ins = db()->prepare(
        'INSERT INTO class_session (id, institute_id, class_id, seq, session_date, start_time, status, join_url)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $made = 0; $seq = 1; $guard = 0;
    // سقف نگهبان: با بدترین الگو (هفته‌ای یک جلسه) ۶۰ جلسه حدود ۴۲۰ روز است
    while ($made < $count && $guard < 500) {
        if (in_array((int)gmdate('w', $ts), $days, true)) {
            $ins->execute([new_id(), inst_id(), $classId, $seq, gmdate('Y-m-d', $ts), $startTime, 'scheduled', $joinUrl]);
            $seq++; $made++;
        }
        $ts += 86400; $guard++;
    }
    return $made;
}
