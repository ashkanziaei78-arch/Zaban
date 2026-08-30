<?php
/**
 * نقطهٔ پایانی عمومی سایت معرفی — بدون ورود.
 *
 * دو کار می‌کند:
 *   ۱) تنظیماتی که سایت برای نمایش لازم دارد (قیمت، تلفن، متن‌ها)
 *   ۲) گرفتن درخواست دموی رایگان از فرم سایت
 *
 * ═══ چرا سایت هم PHP می‌خواهد ═══
 *
 * تا قبل از این، سایت معرفی کاملاً ایستا بود و فرم دمو به هیچ‌جا وصل
 * نبود. برای اینکه ادمین بتواند قیمت و ایمیل را بدون دست‌زدن به کد
 * عوض کند، سایت باید بتواند تنظیمات را از جایی بخواند. همین یک فایل
 * کافی است؛ بقیهٔ سایت هنوز HTML ایستا است و اگر PHP هم بمیرد، صفحه
 * با مقدارهای نوشته‌شده در خودش بالا می‌آید.
 *
 * فقط کلیدهای عمومی برگردانده می‌شوند. کلید sms.ir، رمز دیتابیس و
 * آدرس ایمیل مقصد هرگز از اینجا بیرون نمی‌رود.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_settings.php';

/** فقط این‌ها عمومی‌اند. هرچه در این فهرست نباشد بیرون نمی‌رود. */
const PUBLIC_KEYS = [
    'contact_phone', 'contact_email', 'contact_address', 'support_hours',
    'price_basic', 'price_growth', 'price_pro', 'annual_discount', 'trial_days',
    'hero_title', 'hero_sub', 'cta_text', 'banner_text', 'banner_on',
    'enamad_html', 'samandehi_html', 'maintenance', 'signup_open',
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ─────────── خواندن تنظیمات ─────────── */
if ($method === 'GET') {
    $all = settings_all();
    $out = [];
    foreach (PUBLIC_KEYS as $k) $out[$k] = $all[$k] ?? '';

    header('Cache-Control: public, max-age=60');   // یک دقیقه؛ تغییر ادمین زود دیده شود
    json_out(200, ['ok' => true, 'settings' => $out]);
}

require_post();
$in     = body_json();
$action = trim((string)($in['action'] ?? ''));

if ($action !== 'demo') fail(400, 'unknown_action', 'درخواست نامشخص.');

/* ─────────── درخواست دموی رایگان ─────────── */

$ip = client_ip();

// دام ربات: فیلدی که در فرم مخفی است و آدم واقعی پرش نمی‌کند
if (trim((string)($in['website'] ?? '')) !== '') {
    audit('demo.honeypot', null, ['ip' => $ip]);
    ok(['received' => true]);   // به ربات نمی‌گوییم که گیر افتاده
}

if (!rate_ok('demo_ip', $ip, 5, 3600)) {
    fail(429, 'rate_limited', 'تعداد درخواست‌ها زیاد است. کمی بعد دوباره تلاش کنید.');
}

$name  = mb_substr(trim((string)($in['name'] ?? '')), 0, 120);
$phone = normalize_phone((string)($in['phone'] ?? ''));
$email = mb_substr(trim((string)($in['email'] ?? '')), 0, 160);
$inst  = mb_substr(trim((string)($in['institute'] ?? '')), 0, 160);
$size  = mb_substr(trim((string)($in['students'] ?? '')), 0, 40);
$note  = mb_substr(trim((string)($in['note'] ?? '')), 0, 2000);

if ($name === '')       fail(400, 'invalid', 'نام‌تان را وارد کنید.');
if ($phone === null)    fail(400, 'invalid_phone', 'شمارهٔ موبایل باید ۱۱ رقم و با ۰۹ شروع شود.');
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail(400, 'invalid_email', 'آدرس ایمیل معتبر نیست.');
}

$id = bin2hex(random_bytes(16));
db()->prepare(
    'INSERT INTO demo_lead (id, name, phone, email, institute, students, note, status, ip, created_at)
     VALUES (?,?,?,?,?,?,?,?,?,?)'
)->execute([$id, $name, $phone, $email ?: null, $inst ?: null, $size ?: null, $note ?: null, 'new', $ip, now_utc()]);

/*
 * ایمیل بعد از ذخیره فرستاده می‌شود، نه قبلش. اگر ارسال ایمیل شکست
 * بخورد سرنخ از دست نمی‌رود — در پنل سوپرادمین دیده می‌شود و فقط پرچم
 * mailed خاموش می‌ماند. برعکسش یعنی مشتری از دست رفته.
 */
$to = setting('demo_email', 'info@talkora.ir');
$body = "درخواست دموی رایگان تاکورا\n"
      . str_repeat('─', 34) . "\n\n"
      . "نام:        {$name}\n"
      . "موبایل:     {$phone}\n"
      . ($email ? "ایمیل:      {$email}\n" : '')
      . ($inst  ? "آموزشگاه:   {$inst}\n" : '')
      . ($size  ? "زبان‌آموز:   {$size}\n" : '')
      . ($note  ? "\nتوضیح:\n{$note}\n" : '')
      . "\n" . str_repeat('─', 34) . "\n"
      . 'زمان ثبت (UTC): ' . now_utc() . "\n";

$sent = send_mail($to, "درخواست دمو — {$name}", $body, $email ?: null);
if ($sent) {
    db()->prepare('UPDATE demo_lead SET mailed = 1 WHERE id = ?')->execute([$id]);
}

audit('demo.received', null, ['lead' => $id, 'mailed' => $sent]);

// چه ایمیل رفته باشد چه نه، برای کاربر موفق است — سرنخ ثبت شده
ok(['received' => true]);
