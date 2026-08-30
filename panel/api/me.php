<?php
/**
 * کاربر فعلی. نقش از عضویت می‌آید نه از ستون app_user.role — چون یک
 * کاربر می‌تواند در یک آموزشگاه مدرس و در دیگری زبان‌آموز باشد.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$u = current_user();
if (!$u) json_out(200, ['ok' => true, 'authenticated' => false]);

$st = db()->prepare(
    'SELECT m.role, i.name, i.status, i.suspended_reason FROM membership m JOIN institute i ON i.id = m.institute_id
      WHERE m.user_id = ? AND m.status = ? ORDER BY m.created_at ASC LIMIT 1'
);
$st->execute([$u['id'], 'active']);
$mem = $st->fetch();

// پنل با این می‌فهمد باید صفحهٔ «آموزشگاه معلق شده» را نشان دهد، نه
// اینکه بگذارد کاربر وارد پیشخوان شود و بعد هر درخواستی ۴۰۳ بگیرد —
// همان بررسی در ctx() نقطهٔ امنیتی واقعی است؛ این فقط برای نمایش تمیز.
$suspended = $mem && (string)$mem['status'] === 'suspended';

ok([
    'authenticated' => true,
    'user' => [
        'name'               => $u['full_name'],
        'role'               => $mem ? (string)$mem['role'] : (string)$u['role'],
        'institute'          => $mem ? (string)$mem['name'] : $u['institute_name'],
        'hasWorkspace'       => (bool)$mem,
        'instituteSuspended' => $suspended,
        'suspendedReason'    => $suspended ? $mem['suspended_reason'] : null,
        'phone'              => preg_replace('/^(\d{4})\d{4}(\d{3})$/', '$1****$2', (string)$u['phone']),
    ],
]);
