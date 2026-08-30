<?php
/**
 * مصرف تیکت «ورود به‌جای کاربر» که پنل سوپرادمین (admin.talkora.ir) ساخته.
 *
 * برخلاف بقیهٔ این پوشه، این فایل با GET و بدون هیچ نشستی صدا زده
 * می‌شود — چون خودِ لینکش، از یک درخواست POST احرازشده در پنل
 * سوپرادمین ساخته شده و مرورگر با کلیک روی آن به اینجا می‌آید.
 * امنیتش از نشست نمی‌آید، از خود تیکت می‌آید:
 *
 *   - شناسهٔ تیکت ۳۲ کاراکتر تصادفی است (قابل حدس نیست)
 *   - حداکثر ۶۰ ثانیه زنده می‌ماند
 *   - دقیقاً یک‌بار قابل مصرف است (consumed_at بلافاصله ست می‌شود)
 *   - نه رمز و نه کد ورود کاربر هدف جایی رد و بدل نمی‌شود؛ فقط یک
 *     نشست عادی برایش صادر می‌شود — همان issue_session() موجود
 *   - مصرف تیکت با هویت سوپرادمینی که ساخته‌بودش، دلیل، و کاربر هدف
 *     در audit_log ثبت می‌شود؛ این ثبت مستقل از پنل سوپرادمین است،
 *     پس حتی اگر آن سمت چیزی گم شود، اینجا رد پا می‌ماند
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$ticket = trim((string)($_GET['ticket'] ?? ''));
if (!preg_match('/^[a-f0-9]{32}$/', $ticket)) {
    http_response_code(400);
    echo 'لینک نامعتبر است.';
    exit;
}

$db = db();
$st = $db->prepare(
    'SELECT * FROM impersonation_ticket WHERE id = ? AND consumed_at IS NULL AND expires_at > ?'
);
$st->execute([$ticket, now_utc()]);
$row = $st->fetch();

if (!$row) {
    http_response_code(410);
    echo 'این لینک منقضی شده یا قبلاً استفاده شده. از پنل سوپرادمین دوباره بسازید.';
    exit;
}

// دقیقاً همین‌جا مصرف می‌شود — قبل از صدور نشست، تا یک درخواست هم‌زمان دوم رد شود
$db->prepare('UPDATE impersonation_ticket SET consumed_at = ? WHERE id = ?')
   ->execute([now_utc(), $ticket]);

$st = $db->prepare("SELECT status FROM app_user WHERE id = ?");
$st->execute([(string)$row['target_user_id']]);
$targetStatus = $st->fetchColumn();
if ($targetStatus !== 'active') {
    audit('admin.impersonation_blocked', (string)$row['target_user_id'], [
        'by' => $row['super_admin_id'], 'institute' => $row['institute_id'], 'reason' => 'user_not_active',
    ]);
    http_response_code(403);
    echo 'حساب این کاربر غیرفعال است.';
    exit;
}

issue_session((string)$row['target_user_id']);

audit('admin.impersonation_used', (string)$row['target_user_id'], [
    'by'        => (string)$row['super_admin_id'],
    'institute' => (string)$row['institute_id'],
    'reason'    => (string)$row['reason'],
]);

header('Location: /');
exit;
