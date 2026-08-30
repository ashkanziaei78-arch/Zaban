<?php
/**
 * ورود با نام کاربری و رمز.
 *
 * کنار ورود با پیامک می‌نشیند، نه به‌جایش. هر کاربری که رمز ساخته
 * باشد می‌تواند از اینجا وارد شود؛ بقیه همان مسیر کد یک‌بارمصرف را
 * دارند.
 *
 * محافظت‌ها همان‌هایی است که برای ادمین گذاشتیم و دلیل‌شان اینجا هم
 * برقرار است:
 *   - سقف تلاش برای هر نام کاربری و هر IP
 *   - پیام یکسان برای «کاربر نیست»، «رمز ندارد» و «رمز غلط»
 *   - تأخیر ثابت روی شکست، تا زمان پاسخ چیزی لو ندهد
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_creds.php';

require_post();

$in   = body_json();
$user = username_normalize((string)($in['username'] ?? ''));
$pass = (string)($in['password'] ?? '');
$ip   = client_ip();

if ($user === '' || $pass === '') {
    fail(400, 'invalid', 'نام کاربری و رمز را وارد کنید.');
}

/*
 * اگر کاربر به‌جای نام کاربری شمارهٔ موبایلش را زده، همان را می‌پذیریم.
 * این تنها حالتی است که تشخیصش قطعی است و اشتباه رایجی است — نگفتنش
 * فقط کاربر را سرگردان می‌کند.
 */
$byPhone = normalize_phone($user);

if (!rate_ok('login_ip', $ip, 20, 3600) || !rate_ok('login_user', $user, 10, 3600)) {
    audit('login.rate_limited', null, ['username' => $user, 'ip' => $ip]);
    fail(429, 'rate_limited', 'تلاش‌های زیاد. یک ساعت دیگر امتحان کنید، یا با پیامک وارد شوید.');
}

if ($byPhone !== null) {
    $st = db()->prepare('SELECT id, pass_hash, status, full_name, role FROM app_user WHERE phone = ?');
    $st->execute([$byPhone]);
} else {
    $st = db()->prepare('SELECT id, pass_hash, status, full_name, role FROM app_user WHERE username = ?');
    $st->execute([$user]);
}
$u = $st->fetch();

if (!$u || $u['status'] !== 'active' || $u['pass_hash'] === null
    || !password_verify($pass, (string)$u['pass_hash'])) {
    usleep(400000);
    audit('login.failed', null, ['username' => $user, 'ip' => $ip]);
    fail(401, 'bad_credentials', 'نام کاربری یا رمز درست نیست.');
}

if (password_needs_rehash((string)$u['pass_hash'], PASSWORD_DEFAULT)) {
    db()->prepare('UPDATE app_user SET pass_hash = ? WHERE id = ?')
        ->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
}

db()->prepare('UPDATE app_user SET last_login_at = ? WHERE id = ?')->execute([now_utc(), $u['id']]);
issue_session((string)$u['id']);
audit('login.password', (string)$u['id'], []);

// نقشِ واقعی از عضویت می‌آید، نه از ستون app_user.role که فقط یادگاری ثبت‌نام است
// (همان کاری که otp-verify.php می‌کند — وگرنه کاربری که نقشش در پنل سوپرادمین
// عوض شده، با ورود از راه رمز به نمای اشتباه می‌رود)
$st = db()->prepare(
    'SELECT m.role, i.name FROM membership m JOIN institute i ON i.id = m.institute_id
      WHERE m.user_id = ? AND m.status = ? ORDER BY m.created_at ASC LIMIT 1'
);
$st->execute([$u['id'], 'active']);
$mem = $st->fetch();

ok(['user' => [
    'name'         => $u['full_name'],
    'role'         => $mem ? (string)$mem['role'] : (string)$u['role'],
    'institute'    => $mem ? (string)$mem['name'] : null,
    'hasWorkspace' => (bool)$mem,
]]);
