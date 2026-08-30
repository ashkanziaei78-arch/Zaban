<?php
/**
 * آزمون هم‌ارزی فاز ۳.
 *
 * برای هر یک از ۲۷ نقطهٔ تبدیل‌شده و هر سه نقش، تصمیم قدیم
 * (require_role) و تصمیم جدید (require_perm) را کنار هم می‌گذارد.
 *
 * هدف این نیست که همه یکسان باشند — بعضی تفاوت‌ها عمدی‌اند و در سند
 * معماری خواسته شده‌اند (مثل «مدرس بتواند کلاس بسازد»). هدف این است
 * که هیچ تفاوتی ناخواسته نماند: هر تفاوت باید در فهرست EXPECTED باشد،
 * وگرنه آزمون شکست می‌خورد.
 */
declare(strict_types=1);

define('DSN', getenv('TALKORA_TEST_DSN_DB') ?: 'mysql:host=127.0.0.1;port=3399;dbname=talkora_test;charset=utf8mb4');

/** نقطه‌های تبدیل‌شده: [فایل, عمل, نقش‌های مجاز قدیم, مجوز جدید] */
const SITES = [
    ['assignments', 'create',           ['manager','teacher'], 'assignment.create'],
    ['assignments', 'close',            ['manager','teacher'], 'assignment.edit'],
    ['assignments', 'delete',           ['manager','teacher'], 'assignment.delete'],
    ['assignments', 'submit',           ['student'],           'assignment.submit'],
    ['assignments', 'grade',            ['manager','teacher'], 'assignment.grade'],
    ['assignments', 'queue',            ['manager','teacher'], 'assignment.grade'],
    ['attendance',  'save',             ['manager','teacher'], 'attendance.write'],
    ['attendance',  'student(other)',   ['manager','teacher'], 'attendance.view', true],
    ['classes',     'create',           ['manager'],           'class.create'],
    ['classes',     'update',           ['manager'],           'class.edit'],
    ['classes',     'publish',          ['manager'],           'class.edit'],
    ['classes',     'delete',           ['manager'],           'class.delete'],
    ['classes',     'close',            ['manager'],           'class.edit'],
    ['classes',     'enrol',            ['manager'],           'enrolment.create'],
    ['classes',     'withdraw',         ['manager'],           'enrolment.delete'],
    ['institute',   'setup',            ['manager'],           'institute.edit'],
    ['institute',   'update',           ['manager'],           'institute.edit'],
    ['institute',   'members',          ['manager'],           'member.view'],
    ['institute',   'invite',           ['manager'],           'member.invite'],
    ['institute',   'removeMember',     ['manager'],           'member.remove'],
    ['institute',   'setRate',          ['manager'],           'member.edit'],
    ['institute',   'setMeetingAccess', ['manager'],           'member.grant'],
    ['institute',   'addRoom',          ['manager'],           'room.manage'],
    ['institute',   'deleteRoom',       ['manager'],           'room.manage'],
    ['sessions',    'start',            ['manager','teacher'], 'session.start_meeting'],
    ['sessions',    'end',              ['manager','teacher'], 'session.edit'],
    ['sessions',    'cancel',           ['manager','teacher'], 'session.edit'],
];

/**
 * تفاوت‌های عمدی — هر کدام از سند معماری می‌آید.
 * کلید: "عمل|نقش" ← دلیل
 */
const EXPECTED_DIFFS = [
    'classes.create|teacher'  => 'بند «مورد دوم» سند: مدرس بتواند کلاس بسازد، در محدودهٔ خودش',
    'classes.update|teacher'  => 'بند ۱۰: مدیریت کلاس‌های خودش',
    'classes.publish|teacher' => 'بند ۱۰: مدیریت کلاس‌های خودش',
    'classes.close|teacher'   => 'بند ۱۰: مدیریت کلاس‌های خودش',
    'classes.enrol|teacher'   => 'بند ۱۰: مدیریت کلاس‌های خودش',
    'institute.members|teacher' => 'بند ۱۰: پشتیبانی و چت با زبان‌آموزان مرتبط',
];

$roleId = ['manager' => 'r_manager', 'teacher' => 'r_teacher', 'student' => 'r_student'];

$pdo = new PDO(DSN, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

/** آیا این نقش این مجوز را دارد؟ */
function role_has(PDO $pdo, string $rid, string $perm): bool {
    $st = $pdo->prepare('SELECT 1 FROM role_permission WHERE role_id = ? AND perm_key = ?');
    $st->execute([$rid, $perm]);
    return (bool)$st->fetchColumn();
}
function role_scope(PDO $pdo, string $rid, string $perm): string {
    $st = $pdo->prepare('SELECT scope FROM role_permission WHERE role_id = ? AND perm_key = ?');
    $st->execute([$rid, $perm]);
    return (string)($st->fetchColumn() ?: '—');
}

$same = 0; $intended = 0; $unexpected = [];

printf("%-26s %-22s %-9s %-9s %s\n", 'نقطه', 'مجوز', 'نقش', 'قدیم→جدید', 'محدوده');
echo str_repeat('─', 92) . "\n";

foreach (SITES as $site) {
    [$file, $action, $oldRoles, $perm] = $site;
    $scopeGuarded = $site[4] ?? false;
    foreach (['manager','teacher','student'] as $role) {
        $old = in_array($role, $oldRoles, true);
        $new = role_has($pdo, $roleId[$role], $perm);
        // نقطه‌ای که با require_perm_on_user محافظت می‌شود: محدودهٔ own کافی نیست
        if ($new && $scopeGuarded && role_scope($pdo, $roleId[$role], $perm) === 'own') {
            $new = false;
        }
        $key = "$file.$action|$role";

        if ($old === $new) { $same++; continue; }

        $arrow = ($old ? 'مجاز' : 'رد') . ' → ' . ($new ? 'مجاز' : 'رد');
        if (isset(EXPECTED_DIFFS[$key])) {
            $intended++;
            printf("%-26s %-22s %-9s %-9s %s\n",
                   "$file.$action", $perm, $role, $arrow, role_scope($pdo, $roleId[$role], $perm));
        } else {
            $unexpected[] = [$key, $perm, $arrow];
        }
    }
}

echo str_repeat('─', 92) . "\n";
printf("یکسان: %d    تفاوت عمدی: %d    تفاوت ناخواسته: %d\n",
       $same, $intended, count($unexpected));

if ($unexpected) {
    echo "\n\xE2\x9C\x97 تفاوت‌های ناخواسته — اینها باید بررسی شوند:\n";
    foreach ($unexpected as [$k, $p, $a]) echo "   $k  ($p)  $a\n";
    exit(1);
}

echo "\n\xE2\x9C\x93 هیچ تفاوت ناخواسته‌ای نیست.\n";
echo "  هر " . $intended . " تفاوت، عمدی و مستند است:\n";
foreach (EXPECTED_DIFFS as $k => $why) echo "   • $k — $why\n";
exit(0);
