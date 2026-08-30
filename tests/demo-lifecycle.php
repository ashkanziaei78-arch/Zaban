<?php
/**
 * آزمون فاز ۵ — چرخهٔ عمر دمو و مدیریت نقش.
 *
 * منطق تراکنشی demo.approve و demo.sweep را عیناً روی دیتابیس آزمون
 * اجرا می‌کند. این بخش چند جدول را هم‌زمان دست می‌زند، پس اگر جایی
 * نیمه‌کاره بماند، آموزشگاهی بدون مدیر یا کاربری بدون آموزشگاه
 * می‌سازد — چیزی که فقط با اجرای واقعی معلوم می‌شود.
 */
declare(strict_types=1);

define('DSN', getenv('TALKORA_TEST_DSN_DB') ?: 'mysql:host=127.0.0.1;port=3399;dbname=talkora_test;charset=utf8mb4');

$pass = 0; $fail = 0;
function ok(string $w): void  { global $pass; $pass++; echo "  \xE2\x9C\x93 $w\n"; }
function bad(string $w, string $why = ''): void {
    global $fail; $fail++; echo "  \xE2\x9C\x97 $w" . ($why !== '' ? "\n      $why" : '') . "\n";
}
function check(bool $c, string $w, string $why = ''): void { $c ? ok($w) : bad($w, $why); }
function utc(int $offset = 0): string { return gmdate('Y-m-d H:i:s', time() + $offset); }
function nid(): string { return bin2hex(random_bytes(16)); }

$pdo = new PDO(DSN, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

/*
 * کلید نقشِ آزمون در هر اجرا یکتا می‌شود تا آزمون تکرارپذیر بماند.
 * بار اول این را نداشت و اجرای دوم به قید یکتا می‌خورد — که خودش
 * نشان داد قید کار می‌کند، ولی آزمونی که فقط یک‌بار سبز می‌شود
 * به‌درد نمی‌خورد.
 */
$suffix = substr(bin2hex(random_bytes(4)), 0, 6);

echo "\n\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90 ۱. تأیید درخواست دمو \xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\n";

$leadId = nid();
$pdo->prepare('INSERT INTO demo_lead (id,name,phone,institute,status,created_at) VALUES (?,?,?,?,?,?)')
    ->execute([$leadId, 'رضا محمدی', '09121112233', 'زبان‌سرای نور', 'new', utc()]);
ok('درخواست دمو ثبت شد');

$days  = 14;
$until = utc($days * 86400);
$phone = '09121112233';

// همان تراکنشی که demo.approve اجرا می‌کند
$pdo->beginTransaction();
try {
    $uq = $pdo->prepare('SELECT id FROM app_user WHERE phone = ?');
    $uq->execute([$phone]);
    $uid = $uq->fetchColumn();
    if (!$uid) {
        $uid = nid();
        $pdo->prepare('INSERT INTO app_user (id,phone,full_name,role,status,created_at) VALUES (?,?,?,?,?,?)')
            ->execute([$uid, $phone, 'رضا محمدی', 'manager', 'active', utc()]);
    }
    $iid = nid();
    $pdo->prepare('INSERT INTO institute (id,name,owner_user_id,phone,term_weeks,plan,trial_ends_at,created_at)
                   VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$iid, 'زبان‌سرای نور', $uid, $phone, 12, 'trial', $until, utc()]);
    $pdo->prepare('INSERT INTO membership (id,institute_id,user_id,role,role_id,status,expires_at,granted_reason,created_at)
                   VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([nid(), $iid, $uid, 'manager', 'r_manager', 'active', $until, 'دورهٔ آزمایشی ۱۴ روزه', utc()]);
    $pdo->prepare('UPDATE demo_lead SET status=?,institute_id=?,user_id=?,trial_days=?,approved_at=? WHERE id=?')
        ->execute(['won', $iid, $uid, $days, utc(), $leadId]);
    $pdo->commit();
    ok('تراکنش تأیید بدون خطا انجام شد');
} catch (Throwable $e) {
    $pdo->rollBack();
    bad('تراکنش تأیید شکست', $e->getMessage());
    exit(1);
}

$inst = $pdo->prepare('SELECT plan, trial_ends_at FROM institute WHERE id = ?');
$inst->execute([$iid]);
$row = $inst->fetch();
check((string)$row['plan'] === 'trial', 'آموزشگاه با وضعیت trial ساخته شد', 'plan: ' . $row['plan']);
check($row['trial_ends_at'] !== null, 'تاریخ پایان دوره ثبت شد');

$mem = $pdo->prepare('SELECT role_id, expires_at FROM membership WHERE institute_id = ?');
$mem->execute([$iid]);
$m = $mem->fetch();
check((string)$m['role_id'] === 'r_manager', 'کاربر نقش مدیر گرفت');
check($m['expires_at'] !== null, 'عضویت زمان‌دار است');

$ld = $pdo->prepare('SELECT status, institute_id, trial_days FROM demo_lead WHERE id = ?');
$ld->execute([$leadId]);
$l = $ld->fetch();
check((string)$l['status'] === 'won' && (string)$l['institute_id'] === $iid,
      'درخواست به آموزشگاه ساخته‌شده وصل شد');
check((int)$l['trial_days'] === 14, 'مدت پیش‌فرض ۱۴ روز ثبت شد', 'روز: ' . $l['trial_days']);

echo "\n\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90 ۲. تأیید دوباره نباید ممکن باشد \xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\n";
$again = $pdo->prepare('SELECT institute_id FROM demo_lead WHERE id = ?');
$again->execute([$leadId]);
check($again->fetchColumn() !== null,
      'درخواست تأییدشده institute_id دارد، پس اکشن دوباره‌اش را رد می‌کند');

echo "\n\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90 ۳. انقضا → فقط-خواندنی \xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\n";
// تاریخ را به دیروز می‌بریم، بعد همان sweep را اجرا می‌کنیم
$pdo->prepare('UPDATE institute SET trial_ends_at = ? WHERE id = ?')->execute([utc(-86400), $iid]);
$sw = $pdo->prepare('UPDATE institute SET plan = ? WHERE plan = ? AND trial_ends_at IS NOT NULL AND trial_ends_at <= ?');
$sw->execute(['readonly', 'trial', utc()]);
check($sw->rowCount() === 1, 'یک آموزشگاه منقضی شد', 'شمار: ' . $sw->rowCount());

$inst->execute([$iid]);
check((string)$inst->fetch()['plan'] === 'readonly', 'وضعیت به readonly رفت');

$nData = (int)$pdo->query("SELECT COUNT(*) FROM membership WHERE institute_id = '$iid'")->fetchColumn();
check($nData === 1, 'هیچ داده‌ای حذف نشد — عضویت سر جایش است');

echo "\n\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90 ۴. تبدیل به مشتری دائم \xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\n";
$pdo->prepare('UPDATE institute SET plan = ?, trial_ends_at = NULL WHERE id = ?')->execute(['active', $iid]);
$pdo->prepare('UPDATE membership SET expires_at = NULL WHERE institute_id = ?')->execute([$iid]);
$inst->execute([$iid]);
$row = $inst->fetch();
check((string)$row['plan'] === 'active' && $row['trial_ends_at'] === null, 'آموزشگاه دائمی شد');
$mem->execute([$iid]);
check($mem->fetch()['expires_at'] === null, 'عضویت دیگر زمان‌دار نیست');

echo "\n\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90 ۵. نقش سفارشی \xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\n";
$rid = nid();
$pdo->prepare('INSERT INTO role (id,institute_id,role_key,name_fa,default_scope,is_system,created_at)
               VALUES (?,?,?,?,?,0,?)')
    ->execute([$rid, '', 'fin_' . $suffix, 'مسئول مالی', 'institute', utc()]);
ok('نقش سفارشی ساخته شد');

$pdo->prepare('INSERT INTO role_permission (role_id,perm_key,is_platform,scope) VALUES (?,?,0,?)')
    ->execute([$rid, 'finance.tuition.view', 'institute']);
$pdo->prepare('INSERT INTO role_permission (role_id,perm_key,is_platform,scope) VALUES (?,?,0,?)')
    ->execute([$rid, 'finance.payout.view', 'institute']);
ok('دو مجوز مالی به آن داده شد');

try {
    $pdo->prepare('INSERT INTO role_permission (role_id,perm_key,is_platform,scope) VALUES (?,?,0,?)')
        ->execute([$rid, 'platform.settings', 'platform']);
    bad('نقش سفارشی توانست مجوز پلتفرمی بگیرد — دیوار شکسته!');
} catch (PDOException $e) {
    ok('نقش سفارشی نتوانست به سطح پلتفرم برسد');
}

// حذف نقشی که کاربر دارد باید رد شود — این قاعده در کد است، اینجا فقط
// می‌سنجیم که شمارش درست کار می‌کند
$pdo->prepare('INSERT INTO membership (id,institute_id,user_id,role,role_id,status,created_at)
               VALUES (?,?,?,?,?,?,?)')
    ->execute([nid(), $iid, $uid, 'manager', $rid, 'active', utc()]);
$cnt = $pdo->prepare('SELECT COUNT(*) FROM membership WHERE role_id = ? AND status = ?');
$cnt->execute([$rid, 'active']);
check((int)$cnt->fetchColumn() === 1, 'شمارش کاربرانِ یک نقش درست است — مبنای رد حذف');

echo "\n\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90 ۶. اعطای موردی زمان‌دار \xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\n";
$pdo->prepare('INSERT INTO user_permission (id,institute_id,user_id,perm_key,effect,scope,expires_at,granted_by,created_at)
               VALUES (?,?,?,?,?,?,?,?,?)')
    ->execute([nid(), $iid, $uid, 'report.view', 'allow', 'institute', utc(-3600), 'a1', utc()]);

// همان شرطی که effective_perms() می‌گذارد
$live = $pdo->prepare('SELECT COUNT(*) FROM user_permission
                        WHERE institute_id = ? AND user_id = ? AND (expires_at IS NULL OR expires_at > ?)');
$live->execute([$iid, $uid, utc()]);
check((int)$live->fetchColumn() === 0, 'اعطای منقضی‌شده دیگر شمرده نمی‌شود');

echo "\n" . str_repeat('─', 56) . "\n";
printf("موفق: %d    ناموفق: %d\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
