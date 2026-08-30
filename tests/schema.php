<?php
/**
 * آزمون اسکیمای نسخهٔ ۶ روی یک MariaDB واقعی.
 *
 * از همان منطق تقسیمی استفاده می‌کند که install.php دارد، تا اگر
 * دستوری زیر آن تقسیم بشکند، همین‌جا معلوم شود نه روی سرور مشتری.
 */
declare(strict_types=1);

// پورت و مسیر از محیط خوانده می‌شوند تا آزمون به این ماشین گره نخورد
define('DSN',  getenv('TALKORA_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3399;charset=utf8mb4');
define('ROOT', getenv('TALKORA_ROOT') ?: dirname(__DIR__));

$pass = 0; $fail = 0;
function ok(string $what): void   { global $pass; $pass++; echo "  \xE2\x9C\x93 $what\n"; }
function bad(string $what, string $why = ''): void {
    global $fail; $fail++;
    echo "  \xE2\x9C\x97 $what" . ($why !== '' ? "\n      $why" : '') . "\n";
}
function check(bool $cond, string $what, string $why = ''): void { $cond ? ok($what) : bad($what, $why); }

/** کپی دقیق ins_split از install.php */
function ins_split(string $sql): array {
    $lines = preg_split('/\r\n|\r|\n/', $sql) ?: [];
    $clean = [];
    foreach ($lines as $l) {
        $t = ltrim($l);
        if ($t === '' || str_starts_with($t, '--')) continue;
        $clean[] = $l;
    }
    $out = [];
    foreach (explode(';', implode("\n", $clean)) as $s) {
        $s = trim($s);
        if ($s !== '') $out[] = $s;
    }
    return $out;
}

$pdo = new PDO(DSN, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('DROP DATABASE IF EXISTS talkora_test');
$pdo->exec('CREATE DATABASE talkora_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE talkora_test');

echo "\n\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90 ۱. اجرای اسکیما \xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\n";
$stmts = ins_split((string)file_get_contents(ROOT . '/panel/api/schema.mysql.sql'));
$applied = 0;
foreach ($stmts as $i => $s) {
    try { $pdo->exec($s); $applied++; }
    catch (PDOException $e) {
        bad("دستور $i شکست", substr($s, 0, 110) . "\n      " . $e->getMessage());
    }
}
check($applied === count($stmts), "هر " . count($stmts) . " دستور اجرا شد", "فقط $applied تا موفق");

echo "\n\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90 ۲. جدول‌ها و داده‌های پایه \xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\n";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach (['permission','role','role_permission','user_permission',
          'role_grantable','role_grantable_perm','branch'] as $t) {
    check(in_array($t, $tables, true), "جدول $t ساخته شد");
}
/*
 * شمار مجوزها از خودِ فایل اسکیما شمرده می‌شود، نه از عددی که اینجا
 * نوشته شده.
 *
 * سه نسخهٔ پیاپی این عددها را جابه‌جا کردند و هر بار آزمون قرمز شد
 * بی‌آنکه چیزی خراب باشد — و آزمونی که مرتب بی‌دلیل قرمز می‌شود،
 * کم‌کم بی‌دلیل سبز هم می‌شود، چون کسی دیگر نگاهش نمی‌کند.
 *
 * چیزی که واقعاً باید تضمین شود این است: هرچه در فایل نوشته شده،
 * کامل به دیتابیس رسیده. اگر تکه‌کردنِ install.php یک دستور را نصفه
 * کند، همین‌جا معلوم می‌شود.
 */
$schemaSql = file_get_contents(__DIR__ . '/../panel/api/schema.mysql.sql');

function count_tuples(string $sql, string $table): int
{
    $n = 0;
    if (preg_match_all(
        '/INSERT INTO ' . preg_quote($table, '/') . '\s*\(.*?VALUES\s*(.*?);/is',
        $sql, $blocks)) {
        foreach ($blocks[1] as $b) $n += preg_match_all("/\('/", $b);
    }
    return $n;
}

$wantPerm = count_tuples($schemaSql, 'permission');
$np = (int)$pdo->query('SELECT COUNT(*) FROM permission')->fetchColumn();
check($np === $wantPerm, "همهٔ $wantPerm مجوزِ اسکیما درج شد", "شمار: $np");

/*
 * شمار مجوزهای پلتفرمی هم از فایل خوانده می‌شود.
 *
 * عدد ثابت اینجا هم همان مشکل را داشت: نسخهٔ ۱۲ سه مجوز وبلاگ افزود
 * و آزمون قرمز شد بی‌آنکه چیزی خراب باشد.
 */
$wantPlat = 0;
if (preg_match_all('/INSERT INTO permission\s*\(.*?VALUES\s*(.*?);/is', $schemaSql, $blocks)) {
    foreach ($blocks[1] as $b) {
        $wantPlat += preg_match_all("/\('[a-z0-9_.]+',\s*'[a-z]+',\s*'[^']*',\s*1,/", $b);
    }
}
$nplat = (int)$pdo->query('SELECT COUNT(*) FROM permission WHERE is_platform=1')->fetchColumn();
$nr = (int)$pdo->query('SELECT COUNT(*) FROM role')->fetchColumn();
check($nplat === $wantPlat, "همهٔ $wantPlat مجوز سطح پلتفرم درج شد", "شمار: $nplat");
check($nr === 3, "سه نقش سیستمی", "شمار: $nr");

/*
 * بسته‌ها هم همین‌طور. ولی یک بررسی جدا لازم است که عدد نمی‌گیرد:
 * ترتیبِ بسته‌ها. مدیر باید از مدرس بیشتر بگیرد و مدرس از زبان‌آموز.
 * اگر روزی جای دو ردیف عوض شد، شمارِ کل درست می‌ماند و فقط همین
 * بررسی می‌گیردش.
 */
$nrp = (int)$pdo->query('SELECT COUNT(*) FROM role_permission')->fetchColumn();
$wantRp = count_tuples($schemaSql, 'role_permission');
check($nrp >= $wantRp, "همهٔ $wantRp سطرِ بستهٔ نقش درج شد", "شمار: $nrp");

$mgr = (int)$pdo->query("SELECT COUNT(*) FROM role_permission WHERE role_id='r_manager'")->fetchColumn();
$tch = (int)$pdo->query("SELECT COUNT(*) FROM role_permission WHERE role_id='r_teacher'")->fetchColumn();
$std = (int)$pdo->query("SELECT COUNT(*) FROM role_permission WHERE role_id='r_student'")->fetchColumn();
check($mgr > $tch && $tch > $std && $std > 0,
      "بستهٔ مدیر > مدرس > زبان‌آموز", "مدیر $mgr، مدرس $tch، زبان‌آموز $std");

echo "\n\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90 ۳. قفل یکتایی مالک پلتفرم \xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\n";
$now = gmdate('Y-m-d H:i:s');
/*
 * رمز واقعی، نه رشتهٔ ساختگی.
 *
 * این ردیف فقط برای آزمونِ قفلِ یکتایی ساخته می‌شد و رمزش «h» بود —
 * که هیچ درهم‌سازیِ معتبری نیست، پس با آن نمی‌شد وارد شد. ولی
 * notify-platform.py به یک سوپرادمینِ قابل‌ورود نیاز دارد، و اگر
 * دستی ساخته شود، اولین اجرای schema.php پاکش می‌کند.
 *
 * پس همین‌جا رمزی می‌گذاریم که آزمون‌های بعدی بشناسند. دیتابیس آزمون
 * است و روی هیچ سروری بالا نمی‌آید.
 */
define('TEST_OWNER_PASS', 'admin12345');
$pdo->prepare('INSERT INTO admin_user (id,username,pass_hash,full_name,status,is_platform_owner,created_at)
               VALUES (?,?,?,?,?,1,?)')
    ->execute(['a1','owner',password_hash(TEST_OWNER_PASS, PASSWORD_DEFAULT),
               'مالک تاکورا','active',$now]);
ok('مالک اول ساخته شد');

try {
    $pdo->prepare('INSERT INTO admin_user (id,username,pass_hash,full_name,status,is_platform_owner,created_at)
                   VALUES (?,?,?,?,?,1,?)')
        ->execute(['a2','owner2','h','مالک دوم','active',$now]);
    bad('مالک دوم ساخته شد — قفل یکتایی کار نمی‌کند!');
} catch (PDOException $e) {
    check(str_contains($e->getMessage(), 'uq_platform_owner') || $e->getCode() === '23000',
          'مالک دوم رد شد — ایندکس یکتا کار می‌کند', $e->getMessage());
}

$pdo->prepare('INSERT INTO admin_user (id,username,pass_hash,full_name,status,is_platform_owner,created_at)
               VALUES (?,?,?,?,?,0,?)')
    ->execute(['a3','helper','h','ادمین عادی','active',$now]);
ok('ادمین غیرمالک بدون مشکل ساخته می‌شود');

try {
    $pdo->exec("UPDATE admin_user SET is_platform_owner=1 WHERE id='a3'");
    bad('ارتقای ادمین عادی به مالک ممکن شد — حفره!');
} catch (PDOException $e) {
    ok('ارتقای ادمین دوم به مالک رد شد');
}

echo "\n\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90 ۴. دیوار مجوزهای سطح پلتفرم \xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\n";
try {
    $pdo->exec("INSERT INTO role_permission (role_id,perm_key,is_platform,scope)
                VALUES ('r_manager','platform.impersonate',0,'institute')");
    bad('مجوز پلتفرمی به نقش چسبید — دیوار شکسته!');
} catch (PDOException $e) {
    ok('چسباندن مجوز پلتفرمی به نقش رد شد');
}

try {
    $pdo->exec("INSERT INTO role_permission (role_id,perm_key,is_platform,scope)
                VALUES ('r_manager','platform.settings',1,'platform')");
    bad('دور زدن با is_platform=1 ممکن شد — CHECK کار نمی‌کند!');
} catch (PDOException $e) {
    ok('دور زدن با is_platform=1 هم رد شد');
}

echo "\n\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90 ۵. نگاشت عضویت‌های موجود \xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\n";
$pdo->prepare('INSERT INTO institute (id,name,owner_user_id,term_weeks,created_at) VALUES (?,?,?,?,?)')
    ->execute(['i1','آموزشگاه آزمون','u1',12,$now]);
foreach ([['u1','manager'],['u2','teacher'],['u3','student']] as [$uid,$role]) {
    $pdo->prepare('INSERT INTO app_user (id,phone,full_name,role,status,created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$uid,'0912000000'.substr($uid,1),'کاربر '.$uid,$role,'active',$now]);
    $pdo->prepare('INSERT INTO membership (id,institute_id,user_id,role,role_id,status,created_at)
                   VALUES (?,?,?,?,?,?,?)')
        ->execute(['m'.substr($uid,1),'i1',$uid,$role,'r_'.$role,'active',$now]);
}
$map = $pdo->query('SELECT user_id, role, role_id FROM membership ORDER BY user_id')->fetchAll();
$expect = ['u1'=>'r_manager','u2'=>'r_teacher','u3'=>'r_student'];
$allOk = count($map) === 3;
foreach ($map as $m) {
    if (($expect[$m['user_id']] ?? null) !== $m['role_id']) { $allOk = false; }
}
check($allOk, 'هر سه عضویت به نقش درست نگاشت شد');

/*
 * عضویت بدون role_id باید رد شود.
 *
 * این جای آزمونِ قدیمیِ «UPDATE پایان اسکیما NULLها را پر می‌کند» را
 * گرفته. آن آزمون وقتی معنا داشت که ستون NULL‌پذیر بود و مهاجرت آن را
 * بعداً پر می‌کرد؛ حالا در نصب تازه از همان اول NOT NULL است.
 *
 * ضمانتِ تازه قوی‌تر است: عضویتِ بی‌نقش اصلاً ساخته نمی‌شود. یک بار
 * شش دستور در کد این ستون را جا انداخته بودند و چون هیچ‌جا آزموده
 * نمی‌شد، تنها نشانه‌اش خطای ۵۰۰ روی سرور زنده بود.
 */
try {
    $pdo->prepare('INSERT INTO membership (id,institute_id,user_id,role,status,created_at)
                   VALUES (?,?,?,?,?,?)')
        ->execute(['m-noRole','i1','u3','student','active',$now]);
    bad('عضویت بدون role_id پذیرفته شد');
} catch (PDOException $e) {
    ok('عضویت بدون role_id رد شد');
}

echo "\n\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90 ۶. کلید خارجی مجوز موردی \xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\n";
$pdo->prepare('INSERT INTO user_permission (id,institute_id,user_id,perm_key,effect,granted_by,created_at)
               VALUES (?,?,?,?,?,?,?)')
    ->execute(['up1','i1','u2','finance.report.view','allow','a1',$now]);
ok('اعطای موردی به مدرس ثبت شد');

try {
    $pdo->prepare('INSERT INTO user_permission (id,institute_id,user_id,perm_key,effect,granted_by,created_at)
                   VALUES (?,?,?,?,?,?,?)')
        ->execute(['up2','i1','u2','platform.settings','allow','a1',$now]);
    bad('اعطای مجوز پلتفرمی به کاربر ممکن شد — دیوار شکسته!');
} catch (PDOException $e) {
    ok('اعطای مجوز پلتفرمی به کاربر رد شد');
}

try {
    $pdo->prepare('INSERT INTO user_permission (id,institute_id,user_id,perm_key,effect,granted_by,created_at)
                   VALUES (?,?,?,?,?,?,?)')
        ->execute(['up3','i1','u2','class.create','maybe','a1',$now]);
    bad('effect نامعتبر پذیرفته شد — CHECK کار نمی‌کند');
} catch (PDOException $e) {
    ok('effect نامعتبر رد شد');
}

echo "\n" . str_repeat('─', 56) . "\n";
printf("موفق: %d    ناموفق: %d\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
