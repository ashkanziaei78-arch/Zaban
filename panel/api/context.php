<?php
/**
 * زمینهٔ فعال: فهرست نقش‌ها و تعویض بین آن‌ها.
 *
 * ═══ چرا این یک نقطهٔ پایانی جداست ═══
 *
 * این تنها فایلی در پوشه است که *بدون* زمینهٔ فعال هم باید کار کند —
 * چون کارش دقیقاً انتخاب همان زمینه است. پس هرگز ctx() یا
 * active_context() را صدا نمی‌زند؛ فقط require_user() و
 * my_memberships() که هر دو مستقل از زمینه‌اند.
 *
 * ═══ چرا تعویض باید سمت سرور باشد ═══
 *
 * اگر سوییچ فقط یک متغیر در مرورگر بود، هیچ حفاظتی نداشت: کسی که در
 * نمای زبان‌آموز نشسته می‌توانست با یک درخواست دستی دادهٔ مدرس را
 * بخواند. زمینه روی ردیف نشست نوشته می‌شود و هر بررسی دسترسی از همان
 * می‌خواند.
 *
 * توکن نشست عوض نمی‌شود، پس کاربر هنگام سوییچ بیرون نمی‌افتد.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_perm.php';

require_post();

$in     = body_json();
$action = s_in($in, 'action', 40);
$u      = require_user();

switch ($action) {

/* ─────────── گزینه‌های در دسترس ─────────── */
case 'list':
    ok([
        'options' => context_options(),
        'active'  => current_context_summary(),
    ]);

/* ─────────── تعویض ─────────── */
case 'switch':
    $mid = s_in($in, 'membershipId', 32);
    if ($mid === '') fail(400, 'invalid', 'نقش مقصد را مشخص کنید.');

    /*
     * مالکیت عضویت تأیید می‌شود، نه فقط وجودش. بدون این بررسی،
     * فرستادن شناسهٔ عضویت شخص دیگری کافی بود تا کسی جای او بنشیند —
     * my_memberships() فقط عضویت‌های همین کاربر را برمی‌گرداند، پس
     * جست‌وجو در همان فهرست، خودش بررسی مالکیت است.
     */
    $target = null;
    foreach (my_memberships() as $m) {
        if ($m['membership_id'] === $mid) { $target = $m; break; }
    }
    if ($target === null) {
        fail(404, 'not_found', 'این نقش برای شما در دسترس نیست.');
    }

    $before = current_context_summary();
    context_set($target['institute_id'], $target['role_id']);

    /*
     * تعویض نقش رویداد حساسی است چون دامنهٔ دیدِ کاربر را عوض می‌کند؛
     * بدون ثبتش، بازبینی بعدی نمی‌تواند بگوید در لحظهٔ فلان کار، کاربر
     * با چه نقشی نشسته بود.
     */
    audit('context.switched', (string)$u['id'], [
        'from'      => $before,
        'institute' => $target['institute_id'],
        'role'      => $target['role_id'],
    ]);

    ok(['active' => [
        'membershipId' => $target['membership_id'],
        'instituteId'  => $target['institute_id'],
        'institute'    => $target['institute_name'],
        'roleId'       => $target['role_id'],
        'role'         => $target['role_name'],
        'roleKey'      => $target['role_key'],
        'readonly'     => $target['plan'] === 'readonly',
    ]]);

default:
    fail(400, 'unknown_action', 'درخواست نامشخص.');
}

/**
 * خلاصهٔ زمینهٔ جاری، یا null اگر هنوز انتخاب نشده.
 *
 * عمداً active_context() را صدا نمی‌زند: آن تابع وقتی زمینه نیست خطای
 * ۴۰۹ می‌دهد، و اینجا «زمینه نیست» یک پاسخ معتبر است نه خطا.
 */
function current_context_summary(): ?array
{
    $tok = $_COOKIE[SESSION_COOKIE] ?? '';
    if ($tok === '' || !preg_match('/^[a-f0-9]{64}$/', $tok)) return null;

    $st = db()->prepare('SELECT active_institute_id, active_role_id FROM session_token WHERE token_hash = ?');
    $st->execute([hash('sha256', $tok)]);
    $row = $st->fetch();
    if (!$row || $row['active_institute_id'] === null) return null;

    foreach (my_memberships() as $m) {
        if ($m['institute_id'] === (string)$row['active_institute_id']
            && $m['role_id'] === (string)$row['active_role_id']) {
            return [
                'membershipId' => $m['membership_id'],
                'instituteId'  => $m['institute_id'],
                'institute'    => $m['institute_name'],
                'roleId'       => $m['role_id'],
                'role'         => $m['role_name'],
                'roleKey'      => $m['role_key'],
                'readonly'     => $m['plan'] === 'readonly',
            ];
        }
    }
    return null;   // زمینهٔ ذخیره‌شده دیگر معتبر نیست
}
