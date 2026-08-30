<?php
/**
 * تأیید کد و ساخت نشست.
 *
 * اگر کاربر تازه باشد و نام آموزشگاه داده شده باشد، همان‌جا ساخته می‌شود.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_ctx.php';
require __DIR__ . '/_creds.php';

require_post();

$in       = body_json();
$phone    = normalize_phone((string)($in['phone'] ?? ''));
$codeRaw  = (string)($in['code'] ?? '');
$fullName = trim((string)($in['fullName'] ?? ''));
$instName = trim((string)($in['instituteName'] ?? ''));
/*
 * نام کاربری و رمز اختیاری‌اند و فقط هنگام ساخت حساب پذیرفته می‌شوند.
 * اگر کاربر ندهدشان، حساب بدون رمز ساخته می‌شود و همیشه با پیامک وارد
 * می‌شود — که برای زبان‌آموزها حالت عادی است.
 */
$wantUser = username_normalize((string)($in['username'] ?? ''));
$wantPass = (string)($in['password'] ?? '');

if ($phone === null) fail(400, 'invalid_phone', 'شمارهٔ موبایل معتبر نیست.');

// ارقام فارسی در کد هم پذیرفته می‌شود
$fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
$en = ['0','1','2','3','4','5','6','7','8','9'];
$code = preg_replace('/\D/', '', str_replace($fa, $en, $codeRaw)) ?? '';
if (strlen($code) !== 5) fail(400, 'invalid_code', 'کد باید ۵ رقم باشد.');

$c  = cfg();
$db = db();
$ip = client_ip();

if (!rate_ok('otp_verify_ip', $ip, 30, 3600)) {
    fail(429, 'rate_limited', 'تلاش‌های زیاد. بعداً امتحان کنید.');
}

$st = $db->prepare(
    'SELECT id, code_hash, attempts FROM otp_code
      WHERE phone = ? AND consumed_at IS NULL AND expires_at > ?
      ORDER BY created_at DESC LIMIT 1'
);
$st->execute([$phone, now_utc()]);
$row = $st->fetch();

if (!$row) fail(400, 'expired', 'کد منقضی شده. دوباره درخواست کنید.');

$maxAttempts = (int)($c['otp_max_attempts'] ?? 5);
if ((int)$row['attempts'] >= $maxAttempts) {
    $db->prepare('UPDATE otp_code SET consumed_at = ?, pending_code = NULL WHERE id = ?')->execute([now_utc(), $row['id']]);
    audit('otp.too_many_attempts', null, ['phone' => $phone]);
    fail(429, 'too_many_attempts', 'تلاش زیاد. کد باطل شد؛ دوباره درخواست کنید.');
}

$candidate = hash_hmac('sha256', $phone . ':' . $code, (string)$c['otp_pepper']);

if (!hash_equals((string)$row['code_hash'], $candidate)) {
    $db->prepare('UPDATE otp_code SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
    $left = $maxAttempts - ((int)$row['attempts'] + 1);
    fail(400, 'wrong_code', 'کد اشتباه است.', ['attemptsLeft' => max(0, $left)]);
}

$st = $db->prepare('SELECT id, full_name, role, institute_name, status FROM app_user WHERE phone = ?');
$st->execute([$phone]);
$user = $st->fetch();

if ($user && $user['status'] !== 'active') {
    $db->prepare('UPDATE otp_code SET consumed_at = ?, pending_code = NULL WHERE id = ?')->execute([now_utc(), $row['id']]);
    fail(403, 'account_disabled', 'این حساب غیرفعال است. با پشتیبانی تماس بگیرید.');
}

/*
 * کاربر تازه است و هنوز نامش را نداریم.
 *
 * کد را اینجا عمداً مصرف نمی‌کنیم: اگر می‌سوزاندیمش، کاربر پیام
 * «نامت را بده» می‌گرفت ولی کدش دیگر معتبر نبود و هرگز نمی‌توانست
 * ثبت‌نام را تمام کند. کلاینت همین کد را با نام دوباره می‌فرستد.
 */
if (!$user && $fullName === '') {
    ok(['needsProfile' => true, 'authenticated' => false]);
}

/*
 * نام کاربری و رمز *قبل* از سوزاندن کد بررسی می‌شوند.
 *
 * اگر بعدش بررسی می‌کردیم، کاربری که نام کاربری گرفته‌شده‌ای انتخاب
 * کرده پیام خطا می‌گرفت و کدش هم سوخته بود — یعنی باید شصت ثانیه صبر
 * می‌کرد و کد تازه می‌گرفت، فقط چون یک نام تکراری تایپ کرده بود.
 * اینجا کد دست‌نخورده می‌ماند و کاربر نام دیگری می‌زند و ادامه می‌دهد.
 */
if (!$user && ($wantUser !== '' || $wantPass !== '')) {
    username_check($wantUser);
    password_check($wantPass);
    if (!username_free($wantUser)) {
        fail(409, 'username_taken', 'این نام کاربری قبلاً گرفته شده. یکی دیگر انتخاب کنید.');
    }
}

// از اینجا به بعد ورود قطعی است، پس کد یک‌بارمصرف سوزانده می‌شود
$db->prepare('UPDATE otp_code SET consumed_at = ?, pending_code = NULL WHERE id = ?')->execute([now_utc(), $row['id']]);

$isNew = false;
if (!$user) {
    $id = bin2hex(random_bytes(16));
    $db->prepare(
        'INSERT INTO app_user (id, phone, full_name, institute_name, role, username, pass_hash, phone_verified_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$id, $phone, $fullName, ($instName !== '' ? $instName : null),
                $instName !== '' ? 'manager' : 'student',
                $wantUser !== '' ? $wantUser : null,
                $wantPass !== '' ? password_hash($wantPass, PASSWORD_DEFAULT) : null,
                now_utc(), now_utc()]);
    $user  = ['id' => $id, 'full_name' => $fullName, 'role' => $instName !== '' ? 'manager' : 'student',
              'institute_name' => $instName ?: null];
    $isNew = true;
    audit('user.created', $id, ['phone' => $phone]);
} else {
    $db->prepare('UPDATE app_user SET last_login_at = ?, phone_verified_at = COALESCE(phone_verified_at, ?) WHERE id = ?')
       ->execute([now_utc(), now_utc(), $user['id']]);
}

$uid = (string)$user['id'];

/*
 * ساخت آموزشگاه برای مدیر تازه.
 *
 * فقط وقتی نام آموزشگاه داده شده *و* کاربر هنوز هیچ عضویتی ندارد.
 * شرط دوم مهم است: وگرنه کسی که از قبل زبان‌آموز یک آموزشگاه است
 * می‌توانست با فرستادن instituteName در درخواست ورود، برای خودش
 * آموزشگاه بسازد و مدیر شود.
 */
if ($instName !== '' && !has_any_membership($db, $uid)) {
    $iid = bin2hex(random_bytes(16));
    $db->prepare('INSERT INTO institute (id, name, owner_user_id, created_at) VALUES (?,?,?,?)')
       ->execute([$iid, $instName, $uid, now_utc()]);
    $db->prepare('INSERT INTO membership (id, institute_id, user_id, role, role_id, status, can_host_meeting, created_at) VALUES (?,?,?,?,?,?,?,?)')
       ->execute([bin2hex(random_bytes(16)), $iid, $uid, 'manager', system_role_id('manager'), 'active',
                  default_can_host_meeting('manager'), now_utc()]);
    audit('institute.created', $uid, ['institute' => $iid, 'name' => $instName]);
}

/*
 * پذیرش دعوت‌ها.
 *
 * مدیر با شماره دعوت می‌کند و حسابی ساخته نمی‌شود. عضویت دقیقاً همین
 * لحظه ساخته می‌شود — بعد از اینکه صاحب شماره با کد پیامکی ثابت کرد
 * خودش است. یعنی هیچ‌کس با شمارهٔ دیگری عضو آموزشگاه نمی‌شود.
 */
$invites = $db->prepare('SELECT * FROM invite WHERE phone = ? AND accepted_at IS NULL');
$invites->execute([$phone]);
$accepted = 0;
foreach ($invites->fetchAll() as $inv) {
    try {
        $db->prepare('INSERT INTO membership (id, institute_id, user_id, role, role_id, status, can_host_meeting, created_at) VALUES (?,?,?,?,?,?,?,?)')
           ->execute([bin2hex(random_bytes(16)), $inv['institute_id'], $uid, $inv['role'],
                        system_role_id((string)$inv['role']), 'active',
                        default_can_host_meeting((string)$inv['role']), now_utc()]);
    } catch (PDOException $e) {
        // از قبل عضو بوده — دعوت باز هم باید بسته شود
    }
    if (!empty($inv['class_id'])) {
        try {
            $db->prepare('INSERT INTO enrolment (id, institute_id, class_id, student_user_id, status, created_at) VALUES (?,?,?,?,?,?)')
               ->execute([bin2hex(random_bytes(16)), $inv['institute_id'], $inv['class_id'], $uid, 'active', now_utc()]);
        } catch (PDOException $e) {
            // از قبل ثبت‌نام بوده
        }
    }
    $db->prepare('UPDATE invite SET accepted_at = ? WHERE id = ?')->execute([now_utc(), $inv['id']]);
    $accepted++;
}
if ($accepted > 0) audit('invite.accepted', $uid, ['count' => $accepted]);

// نقشِ واقعی از عضویت می‌آید، نه از ستون app_user.role که فقط یادگاری ثبت‌نام است
$st = $db->prepare(
    'SELECT m.role, i.name FROM membership m JOIN institute i ON i.id = m.institute_id
      WHERE m.user_id = ? AND m.status = ? ORDER BY m.created_at ASC LIMIT 1'
);
$st->execute([$uid, 'active']);
$mem = $st->fetch();

issue_session($uid);
audit('auth.login', $uid, ['new' => $isNew]);

ok([
    'isNew' => $isNew,
    'user'  => [
        'name'      => $user['full_name'],
        'role'      => $mem ? (string)$mem['role'] : (string)$user['role'],
        'institute' => $mem ? (string)$mem['name'] : $user['institute_name'],
        // پنل با این می‌فهمد کاربر هنوز به هیچ آموزشگاهی وصل نیست
        'hasWorkspace' => (bool)$mem,
    ],
]);

function has_any_membership(PDO $db, string $uid): bool
{
    $st = $db->prepare('SELECT 1 FROM membership WHERE user_id = ? LIMIT 1');
    $st->execute([$uid]);
    return (bool)$st->fetchColumn();
}
