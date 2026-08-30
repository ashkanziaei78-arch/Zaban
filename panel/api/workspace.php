<?php
/**
 * ساخت آموزشگاه برای کاربری که وارد شده ولی عضو هیچ آموزشگاهی نیست.
 *
 * این نقطهٔ پایانی از ctx() استفاده نمی‌کند، چون ctx() خودش وجود عضویت
 * را لازم دارد و اینجا دقیقاً همان چیزی است که هنوز نداریم.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_ctx.php';

require_post();
$in = body_json();
if (s_in($in, 'action', 40) !== 'create') fail(400, 'unknown_action', 'درخواست نامشخص.');

$u    = require_user();
$name = s_in($in, 'name', 160);
if ($name === '') fail(400, 'invalid', 'نام آموزشگاه را وارد کنید.');

$db = db();

// فقط کسی که هیچ عضویتی ندارد. وگرنه هر زبان‌آموزی می‌توانست
// برای خودش آموزشگاه بسازد و از نقش خودش بیرون بزند.
$st = $db->prepare('SELECT 1 FROM membership WHERE user_id = ? AND status = ? LIMIT 1');
$st->execute([$u['id'], 'active']);
if ($st->fetchColumn()) {
    fail(409, 'already_member', 'شما از قبل عضو یک آموزشگاه هستید.');
}

$iid = bin2hex(random_bytes(16));
$db->prepare('INSERT INTO institute (id, name, owner_user_id, created_at) VALUES (?,?,?,?)')
   ->execute([$iid, $name, $u['id'], now_utc()]);
$db->prepare('INSERT INTO membership (id, institute_id, user_id, role, role_id, status, can_host_meeting, created_at) VALUES (?,?,?,?,?,?,?,?)')
   ->execute([bin2hex(random_bytes(16)), $iid, $u['id'], 'manager', system_role_id('manager'), 'active',
              default_can_host_meeting('manager'), now_utc()]);

audit('institute.created', (string)$u['id'], ['institute' => $iid, 'name' => $name]);
ok(['instituteId' => $iid]);
