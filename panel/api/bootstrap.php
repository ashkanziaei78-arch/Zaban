<?php
/**
 * یک درخواست، همهٔ چیزی که پنل برای بالا آمدن لازم دارد.
 *
 * چرا یک نقطهٔ پایانی بزرگ به‌جای ده تا کوچک: روی هاست اشتراکی ایران
 * تأخیر هر رفت‌وبرگشت زیاد است. ده درخواست موازی یعنی ده اتصال PHP و
 * ده بار باز کردن دیتابیس. یک درخواست حدود ۱۰ کوئری می‌زند و تمام.
 *
 * خروجی عمداً همان شکلی است که رابط کاربری از قبل با دادهٔ نمونه
 * می‌شناخت، تا نمایش‌ها دست‌نخورده بمانند. تاریخ‌ها میلادی ISO است؛
 * تبدیل به شمسی در مرورگر انجام می‌شود (بند P.2).
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_ctx.php';

$c    = ctx();
$role = $c['role'];
$me   = $c['user'];
$iid  = $c['institute_id'];

/* ─────────── ترم فعال ─────────── */

$term = t_one('SELECT * FROM term WHERE __I__ AND status = ? ORDER BY starts_on DESC LIMIT 1', ['active']);

$week = 0;
if ($term) {
    $days = (int)floor((time() - strtotime((string)$term['starts_on'] . ' 00:00:00 UTC')) / 86400);
    $week = max(1, min((int)$term['weeks'], intdiv($days, 7) + 1));
}

/* ─────────── اعضا ─────────── */

$members = t_all(
    'SELECT m.user_id, m.role, m.hourly_rate, u.full_name, u.phone
       FROM membership m JOIN app_user u ON u.id = m.user_id
      WHERE m.__I__ AND m.status = ?
      ORDER BY u.full_name',
    ['active']
);

$nameOf = [];
$teachers = [];
foreach ($members as $m) {
    $nameOf[(string)$m['user_id']] = (string)$m['full_name'];
    if ($m['role'] === 'teacher') {
        $teachers[] = [
            'id'    => (string)$m['user_id'],
            'name'  => (string)$m['full_name'],
            'phone' => mask_phone((string)$m['phone']),
            'rate'  => (int)$m['hourly_rate'],
            'busy'  => [],
        ];
    }
}

/* ─────────── کلاس‌ها ─────────── */

// مدرس فقط کلاس‌های خودش، زبان‌آموز فقط کلاس‌هایی که ثبت‌نام کرده
$where = '';
$args  = [];
if ($role === 'teacher') {
    $where = ' AND teacher_user_id = ?';
    $args[] = $me['id'];
} elseif ($role === 'student') {
    $where = ' AND id IN (SELECT class_id FROM enrolment WHERE student_user_id = ? AND status = ?)';
    $args[] = $me['id'];
    $args[] = 'active';
}

$rows = t_all("SELECT * FROM klass WHERE __I__{$where} ORDER BY created_at", $args);

// شمارش ثبت‌نام، حضور و میانگین نمره — همه با گروه‌بندی، نه در حلقه
$enrolCount = map_count('SELECT class_id AS k, COUNT(*) AS n FROM enrolment WHERE __I__ AND status = ? GROUP BY class_id', ['active']);
$doneCount  = map_count('SELECT class_id AS k, COUNT(*) AS n FROM class_session WHERE __I__ AND status = ? GROUP BY class_id', ['done']);

$attRows = t_all(
    'SELECT s.class_id AS k, a.status AS st, COUNT(*) AS n
       FROM attendance a JOIN class_session s ON s.id = a.session_id
      WHERE a.__I__ GROUP BY s.class_id, a.status'
);
$attPct = [];
$attTot = [];
foreach ($attRows as $r) {
    $k = (string)$r['k'];
    $attTot[$k] = ($attTot[$k] ?? 0) + (int)$r['n'];
    if ($r['st'] === 'present' || $r['st'] === 'late') {
        $attPct[$k] = ($attPct[$k] ?? 0) + (int)$r['n'];
    }
}

$avgRows = t_all(
    'SELECT a.class_id AS k, AVG(sub.score) AS avg_score
       FROM submission sub JOIN assignment a ON a.id = sub.assignment_id
      WHERE sub.__I__ AND sub.score IS NOT NULL GROUP BY a.class_id'
);
$avgOf = [];
foreach ($avgRows as $r) $avgOf[(string)$r['k']] = round((float)$r['avg_score'], 1);

$rooms = t_all('SELECT id, name, capacity FROM room WHERE __I__ ORDER BY name');
$roomName = [];
foreach ($rooms as $r) $roomName[(string)$r['id']] = (string)$r['name'];

$classes = [];
foreach ($rows as $r) {
    $id  = (string)$r['id'];
    $tot = $attTot[$id] ?? 0;
    $classes[] = [
        'id'         => $id,
        'name'       => (string)$r['name'],
        'level'      => (string)$r['level'],
        'dayPattern' => (string)$r['day_pattern'],
        'time'       => (string)$r['start_time'],
        'roomId'     => $r['room_id'],
        'room'       => $r['room_id'] ? ($roomName[(string)$r['room_id']] ?? '—') : '—',
        'mode'       => (string)$r['mode'],
        'teacherId'  => $r['teacher_user_id'],
        'teacher'    => $r['teacher_user_id'] ? ($nameOf[(string)$r['teacher_user_id']] ?? '—') : '',
        'session'    => $doneCount[$id] ?? 0,
        'total'      => (int)$r['total_sessions'],
        'cap'        => (int)$r['capacity'],
        'enrolled'   => $enrolCount[$id] ?? 0,
        'provider'   => (string)$r['provider'],
        'joinUrl'    => $r['join_url'],
        'price'      => (int)$r['price'],
        'status'     => (string)$r['status'],
        'avg'        => $avgOf[$id] ?? null,
        'attendance' => $tot > 0 ? (int)round((($attPct[$id] ?? 0) / $tot) * 100) : null,

        'startsOn'   => $r['starts_on'],
        'endsOn'     => $r['ends_on'],
        'midtermOn'  => $r['midterm_on'],
        'finalOn'    => $r['final_on'],
        /*
         * «چند جلسه مانده» اینجا حساب می‌شود نه در مرورگر.
         *
         * جلسه‌های برگزارشده را فقط سرور می‌شمارد؛ اگر عدد را خام
         * می‌فرستادیم، هر سه پنل باید همان تفریق را تکرار می‌کردند و
         * روزی یکی‌شان با بقیه فرق می‌کرد.
         */
        'remaining'  => max(0, (int)$r['total_sessions'] - ($doneCount[$id] ?? 0)),
        'expired'    => $r['ends_on'] !== null && (string)$r['ends_on'] < gmdate('Y-m-d'),
    ];
}
$classIds = array_column($classes, 'id');

/* ─────────── زبان‌آموزان ─────────── */

$students = [];
if ($role !== 'student') {
    $sRows = t_all(
        'SELECT e.student_user_id AS uid, e.class_id, u.full_name, u.phone
           FROM enrolment e JOIN app_user u ON u.id = e.student_user_id
          WHERE e.__I__ AND e.status = ? ORDER BY u.full_name',
        ['active']
    );
    $sAtt = t_all(
        'SELECT a.student_user_id AS uid, a.status AS st, COUNT(*) AS n
           FROM attendance a WHERE a.__I__ GROUP BY a.student_user_id, a.status'
    );
    $sp = []; $stt = [];
    foreach ($sAtt as $r) {
        $u = (string)$r['uid'];
        $stt[$u] = ($stt[$u] ?? 0) + (int)$r['n'];
        if ($r['st'] === 'present' || $r['st'] === 'late') $sp[$u] = ($sp[$u] ?? 0) + (int)$r['n'];
    }
    $sAvg = t_all('SELECT student_user_id AS uid, AVG(score) AS g FROM submission WHERE __I__ AND score IS NOT NULL GROUP BY student_user_id');
    $gOf = [];
    foreach ($sAvg as $r) $gOf[(string)$r['uid']] = round((float)$r['g'], 1);

    foreach ($sRows as $r) {
        $u = (string)$r['uid'];
        if ($role === 'teacher' && !in_array((string)$r['class_id'], $classIds, true)) continue;
        $students[] = [
            'id'    => $u,
            'name'  => (string)$r['full_name'],
            'phone' => mask_phone((string)$r['phone']),
            'cls'   => (string)$r['class_id'],
            'att'   => isset($stt[$u]) && $stt[$u] > 0 ? (int)round((($sp[$u] ?? 0) / $stt[$u]) * 100) : null,
            'grade' => $gOf[$u] ?? null,
        ];
    }
}

/* ─────────── جلسه‌ها ─────────── */

$sessions = [];
if ($classIds) {
    $ph = implode(',', array_fill(0, count($classIds), '?'));
    $sRows = t_all(
        "SELECT * FROM class_session
          WHERE __I__ AND class_id IN ($ph)
            AND (session_date >= ? OR status = ?)
          ORDER BY session_date, start_time LIMIT 200",
        array_merge($classIds, [gmdate('Y-m-d', time() - 7 * 86400), 'live'])
    );
    foreach ($sRows as $r) {
        $sessions[] = [
            'id'       => (string)$r['id'],
            'cls'      => (string)$r['class_id'],
            'seq'      => (int)$r['seq'],
            'date'     => (string)$r['session_date'],
            'time'     => (string)$r['start_time'],
            'status'   => (string)$r['status'],
            'joinUrl'  => $r['join_url'],
            'note'     => $r['note'],
        ];
    }
}

/* ─────────── تکالیف ─────────── */

$assignments = [];
$submissions = [];
if ($classIds) {
    $ph = implode(',', array_fill(0, count($classIds), '?'));
    $aRows = t_all("SELECT * FROM assignment WHERE __I__ AND class_id IN ($ph) ORDER BY due_at DESC LIMIT 100", $classIds);
    $aIds  = array_map(fn($r) => (string)$r['id'], $aRows);

    $subCount = [];
    $gradeCount = [];
    if ($aIds) {
        $ph2 = implode(',', array_fill(0, count($aIds), '?'));
        foreach (t_all("SELECT assignment_id AS k, COUNT(*) AS n FROM submission WHERE __I__ AND assignment_id IN ($ph2) GROUP BY assignment_id", $aIds) as $r) {
            $subCount[(string)$r['k']] = (int)$r['n'];
        }
        foreach (t_all("SELECT assignment_id AS k, COUNT(*) AS n FROM submission WHERE __I__ AND assignment_id IN ($ph2) AND score IS NOT NULL GROUP BY assignment_id", $aIds) as $r) {
            $gradeCount[(string)$r['k']] = (int)$r['n'];
        }

        // مدرس و مدیر همهٔ پاسخ‌ها را می‌بینند؛ زبان‌آموز فقط مال خودش
        $sq = "SELECT s.*, u.full_name FROM submission s JOIN app_user u ON u.id = s.student_user_id
                WHERE s.__I__ AND s.assignment_id IN ($ph2)";
        $sa = $aIds;
        if ($role === 'student') { $sq .= ' AND s.student_user_id = ?'; $sa[] = $me['id']; }
        foreach (t_all($sq . ' ORDER BY s.submitted_at DESC LIMIT 300', $sa) as $r) {
            $submissions[] = [
                'id'       => (string)$r['id'],
                'sid'      => (string)$r['student_user_id'],
                'name'     => (string)$r['full_name'],
                'aid'      => (string)$r['assignment_id'],
                'at'       => (string)$r['submitted_at'],
                'late'     => (bool)$r['is_late'],
                'text'     => $r['body_text'],
                'fileName' => $r['file_name'],
                'hasFile'  => !empty($r['file_path']),
                'score'    => $r['score'] !== null ? (float)$r['score'] : null,
                'feedback' => $r['feedback'],
                'state'    => $r['score'] !== null ? 'graded' : 'submitted',
            ];
        }
    }

    foreach ($aRows as $r) {
        $cid = (string)$r['class_id'];
        $assignments[] = [
            'id'        => (string)$r['id'],
            'cls'       => $cid,
            'title'     => (string)$r['title'],
            'type'      => (string)$r['type'],
            'desc'      => $r['description'],
            'due'       => $r['due_at'],
            // مهلت *مؤثر*؛ کارت تکلیف باید همان را بشمارد که ملاک
            // دیرکرد است، نه مهلت اصلی
            'extendedTo' => $r['extended_to'],
            'extendNote' => $r['extend_note'],
            'effective'  => $r['extended_to'] ?: $r['due_at'],
            'max'       => (int)$r['max_score'],
            'submitted' => $subCount[(string)$r['id']] ?? 0,
            'graded'    => $gradeCount[(string)$r['id']] ?? 0,
            'total'     => $enrolCount[$cid] ?? 0,
            'status'    => (string)$r['status'],
        ];
    }
}

/* ─────────── هشدارها (فقط مدیر) ─────────── */

$alerts = [];
if ($role === 'manager') {
    $unmarked = (int)(t_one(
        'SELECT COUNT(*) AS n FROM class_session s
          WHERE s.__I__ AND s.status = ? AND s.session_date < ?
            AND NOT EXISTS (SELECT 1 FROM attendance a WHERE a.session_id = s.id)',
        ['done', gmdate('Y-m-d')]
    )['n'] ?? 0);
    if ($unmarked > 0) {
        $alerts[] = ['sev' => 'hi', 't' => "{$unmarked} جلسه حضور و غیاب نخورده", 'a' => 'یادآوری به مدرس', 'go' => 'm/classes'];
    }

    $noTeacher = (int)(t_one('SELECT COUNT(*) AS n FROM klass WHERE __I__ AND (teacher_user_id IS NULL OR teacher_user_id = ?)', [''])['n'] ?? 0);
    if ($noTeacher > 0) {
        $alerts[] = ['sev' => 'hi', 't' => "{$noTeacher} کلاس هنوز مدرس ندارد", 'a' => 'تعیین مدرس', 'go' => 'm/classes'];
    }

    $ungraded = (int)(t_one('SELECT COUNT(*) AS n FROM submission WHERE __I__ AND score IS NULL')['n'] ?? 0);
    if ($ungraded > 0) {
        $alerts[] = ['sev' => 'md', 't' => "{$ungraded} تکلیف تصحیح‌نشده مانده", 'a' => 'صف تصحیح', 'go' => 'm/classes'];
    }

    $manual = 0;
    foreach ($classes as $cl) {
        if ($cl['mode'] !== 'in_person' && $cl['provider'] === 'meet') $manual++;
    }
    if ($manual > 0) {
        $alerts[] = ['sev' => 'md', 't' => "{$manual} کلاس روی Google Meet است و حضورش دستی می‌ماند", 'a' => 'تغییر ارائه‌دهنده', 'go' => 'm/classes'];
    }
}

/* ─────────── خروجی ─────────── */

$roleFa = ['manager' => 'مدیر آموزشگاه', 'teacher' => 'مدرس', 'student' => 'زبان‌آموز'];

/*
 * مجوزهای مؤثر و فهرست نقش‌ها به رابط داده می‌شوند تا منو و صفحه‌ها از
 * روی مجوز ساخته شوند، نه از روی نام نقش. تا وقتی فرانت بنویسد «اگر
 * مدیر است این را نشان بده»، ساخت نقش سفارشی یعنی رابطی که برایش خالی
 * می‌ماند.
 *
 * contexts فقط وقتی بیش از یکی است که کاربر واقعاً چند نقش دارد؛ رابط
 * انتخابگر را در همان حالت نشان می‌دهد.
 */
$ac       = active_context();
$contexts = array_map(function ($o) use ($ac) {
    $o['active'] = ($o['instituteId'] === $ac['institute_id'] && $o['roleId'] === $ac['role_id']);
    return $o;
}, context_options());

ok([
    'role' => $role,
    'permissions' => array_keys(effective_perms()),
    'scopes'      => effective_perms(),
    'contexts'    => $contexts,
    'multiRole'   => count($contexts) > 1,
    'readonly'    => is_readonly_institute(),
    'me'   => [
        'id'    => (string)$me['id'],
        'name'  => (string)$me['full_name'],
        'role'  => $roleFa[$role] ?? $role,
        'phone' => mask_phone((string)$me['phone']),
    ],
    'institute' => [
        'name'  => $c['institute']['name'],
        'city'  => $c['institute']['city'],
        'phone' => $c['institute']['phone'],
        'term'  => $term ? (string)$term['name'] : null,
        'termId'=> $term ? (string)$term['id'] : null,
        'termStart' => $term ? (string)$term['starts_on'] : null,
        'week'  => $week,
        'weeks' => $term ? (int)$term['weeks'] : (int)$c['institute']['termWeeks'],
        'jitsiEnabled' => $c['institute']['jitsiEnabled'],
    ],
    /*
     * jitsi_allowed() نه can_host_meeting()، تا پنل همان چیزی را نشان
     * دهد که API واقعاً اجازه می‌دهد.
     *
     * پنل با همین مقدار تصمیم می‌گیرد «جلسهٔ میت» را در فهرست
     * ارائه‌دهنده‌ها بگذارد یا نه. اگر پرچم خام را بفرستیم، مدیری که
     * مجوز شخصی‌اش نوشته نشده (مهاجرت ۰۱۳ را ببینید) گزینه را اصلاً
     * نمی‌بیند — با اینکه classes.php درخواستش را می‌پذیرفت. یعنی
     * دقیقاً همان باگ، این‌بار یک لایه بالاتر.
     */
    'canHostMeeting' => jitsi_allowed(),
    'jitsiDomain'    => jitsi_domain(),
    'classes'     => $classes,
    'students'    => $students,
    'teachers'    => $teachers,
    'rooms'       => array_map(fn($r) => ['id' => (string)$r['id'], 'name' => (string)$r['name'], 'cap' => (int)$r['capacity']], $rooms),
    'sessions'    => $sessions,
    'assignments' => $assignments,
    'submissions' => $submissions,
    'alerts'      => $alerts,
    'serverDate'  => gmdate('Y-m-d'),
]);

/* ─────────── کمکی ─────────── */

function map_count(string $sql, array $args = []): array
{
    $out = [];
    foreach (t_all($sql, $args) as $r) $out[(string)$r['k']] = (int)$r['n'];
    return $out;
}

function mask_phone(string $p): string
{
    return (string)preg_replace('/^(\d{4})\d{4}(\d{3})$/', '$1****$2', $p);
}
