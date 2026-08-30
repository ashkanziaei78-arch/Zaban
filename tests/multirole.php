<?php
/**
 * آزمون فاز ۴ — چند-نقشی و زمینهٔ فعال.
 *
 * پیش‌نیاز: tests/schema.php اجرا شده و migrations/007-multi-role.sql
 * روی همان دیتابیس آزمون اعمال شده باشد.
 */
declare(strict_types=1);

define('DSN', getenv('TALKORA_TEST_DSN_DB') ?: 'mysql:host=127.0.0.1;port=3399;dbname=talkora_test;charset=utf8mb4');

$pass = 0; $fail = 0;
function ok(string $w): void  { global $pass; $pass++; echo "  \xE2\x9C\x93 $w\n"; }
function bad(string $w, string $why = ''): void {
    global $fail; $fail++; echo "  \xE2\x9C\x97 $w" . ($why !== '' ? "\n      $why" : '') . "\n";
}
function check(bool $c, string $w, string $why = ''): void { $c ? ok($w) : bad($w, $why); }

$pdo = new PDO(DSN, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$now = gmdate('Y-m-d H:i:s');

/*
 * ردیف‌های اجرای قبلی پاک می‌شوند.
 *
 * این آزمون شناسه‌های ثابت دارد (i1، i2، u1، u2، …) و باید داشته باشد:
 * قیدی که می‌آزماید روی همان سه‌تاییِ مشخص است، پس شناسهٔ تصادفی
 * چیزی را ساده‌تر نمی‌کند. ولی بدون تمیزکاری، اجرای دوم به ردیف‌های
 * اجرای اول می‌خورد و شکست می‌دهد — آزمونی که فقط یک‌بار سبز می‌شود
 * عملاً آزمون نیست، چون کسی دومین بار اجرایش نمی‌کند.
 *
 * ترتیب مهم است: عضویت پیش از آموزشگاه و کاربر، چون کلید خارجی دارد.
 */
$pdo->exec("DELETE FROM membership WHERE id IN ('m-dual','m-ghost','m-i2','m-exp')");
$pdo->exec("DELETE FROM membership WHERE institute_id = 'i2'");
$pdo->exec("DELETE FROM institute  WHERE id = 'i2'");

echo "\n\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90 ۱. قید تک‌نقشی برداشته شده \xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\n";
$idx = $pdo->query("SHOW INDEX FROM membership WHERE Key_name = 'uq_member'")->fetchAll();
check(count($idx) === 0, 'قید uq_member دیگر نیست');
$idx2 = $pdo->query("SHOW INDEX FROM membership WHERE Key_name = 'uq_member_role'")->fetchAll();
check(count($idx2) === 3, 'قید uq_member_role روی سه ستون است', 'ستون‌ها: ' . count($idx2));

echo "\n\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90 ۲. یک کاربر، چند نقش \xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\n";
// u2 از قبل مدرس آموزشگاه i1 است — حالا زبان‌آموز همان آموزشگاه هم بشود
try {
    $pdo->prepare('INSERT INTO membership (id,institute_id,user_id,role,role_id,status,created_at)
                   VALUES (?,?,?,?,?,?,?)')
        ->execute(['m-dual','i1','u2','student','r_student','active',$now]);
    ok('مدرس می‌تواند در همان آموزشگاه زبان‌آموز هم باشد');
} catch (PDOException $e) {
    bad('نقش دوم در همان آموزشگاه رد شد', $e->getMessage());
}

$n = (int)$pdo->query("SELECT COUNT(*) FROM membership WHERE user_id='u2' AND institute_id='i1'")->fetchColumn();
check($n === 2, 'کاربر u2 حالا دو عضویت در i1 دارد', "شمار: $n");

echo "\n\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90 ۳. عضویت تکراری همچنان رد می‌شود \xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\n";
try {
    $pdo->prepare('INSERT INTO membership (id,institute_id,user_id,role,role_id,status,created_at)
                   VALUES (?,?,?,?,?,?,?)')
        ->execute(['m-dup','i1','u2','teacher','r_teacher','active',$now]);
    bad('عضویت کاملاً تکراری پذیرفته شد — قید تازه کار نمی‌کند!');
} catch (PDOException $e) {
    ok('همان نقش برای همان کاربر در همان آموزشگاه، دوباره پذیرفته نشد');
}

echo "\n\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90 ۴. نقش در آموزشگاه دیگر \xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\n";
$pdo->prepare('INSERT INTO institute (id,name,owner_user_id,term_weeks,created_at) VALUES (?,?,?,?,?)')
    ->execute(['i2','آموزشگاه دوم','u1',12,$now]);
try {
    $pdo->prepare('INSERT INTO membership (id,institute_id,user_id,role,role_id,status,created_at)
                   VALUES (?,?,?,?,?,?,?)')
        ->execute(['m-i2','i2','u2','manager','r_manager','active',$now]);
    ok('همان کاربر در آموزشگاه دیگر مدیر شد');
} catch (PDOException $e) {
    bad('عضویت در آموزشگاه دوم رد شد', $e->getMessage());
}

echo "\n\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90 ۵. کلید خارجی نقش \xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\n";
try {
    $pdo->prepare('INSERT INTO membership (id,institute_id,user_id,role,role_id,status,created_at)
                   VALUES (?,?,?,?,?,?,?)')
        ->execute(['m-ghost','i1','u3','ghost','r_nonexistent','active',$now]);
    bad('نقش ناموجود پذیرفته شد — کلید خارجی کار نمی‌کند');
} catch (PDOException $e) {
    ok('ارجاع به نقش ناموجود رد شد');
}

echo "\n\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90 ۶. عضویت زمان‌دار \xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\n";
$past = gmdate('Y-m-d H:i:s', time() - 86400);
$pdo->prepare('INSERT INTO membership (id,institute_id,user_id,role,role_id,status,expires_at,created_at)
               VALUES (?,?,?,?,?,?,?,?)')
    ->execute(['m-exp','i2','u3','teacher','r_teacher','active',$past,$now]);

// همان شرطی که my_memberships() می‌گذارد
$live = $pdo->prepare(
    "SELECT COUNT(*) FROM membership m JOIN institute i ON i.id = m.institute_id
      WHERE m.user_id = ? AND m.status = 'active'
        AND (m.expires_at IS NULL OR m.expires_at > ?) AND i.status <> 'suspended'");
$live->execute(['u3', gmdate('Y-m-d H:i:s')]);
$c3 = (int)$live->fetchColumn();
check($c3 === 1, 'عضویت منقضی در فهرست زمینه‌ها نمی‌آید', "شمار زندهٔ u3: $c3 (باید ۱ باشد)");

echo "\n\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90 ۷. ستون‌های زمینهٔ فعال \xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\n";
$cols = $pdo->query("SHOW COLUMNS FROM session_token")->fetchAll(PDO::FETCH_COLUMN);
foreach (['active_institute_id','active_role_id','context_set_at'] as $col) {
    check(in_array($col, $cols, true), "ستون $col روی session_token هست");
}

echo "\n" . str_repeat('─', 56) . "\n";
printf("موفق: %d    ناموفق: %d\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
