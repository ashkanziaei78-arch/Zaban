<?php
/**
 * کلاس‌ها: ساخت، ویرایش، انتشار، ثبت‌نام زبان‌آموز.
 *
 * «انتشار» یعنی جلسه‌های ترم ساخته می‌شوند. تا قبل از آن کلاس پیش‌نویس
 * است و در برنامهٔ کسی نمی‌آید. دلیلش این است که مدیر معمولاً چند بار
 * برنامه را عوض می‌کند و نباید هر بار به مدرس و زبان‌آموز اعلان برود.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_ctx.php';
require_once __DIR__ . '/_perm.php';

require_post();
$in     = body_json();
$action = s_in($in, 'action', 40);

const DAY_PATTERNS = ['فرد', 'زوج', 'پنجشنبه', 'جمعه', 'فشرده'];
const MODES        = ['in_person', 'online', 'hybrid'];
const PROVIDERS    = ['bbb', 'meet', 'skyroom', 'custom', 'jitsi'];

/**
 * لینک جلسه برای ارائه‌دهندهٔ انتخاب‌شده — جیتسی خودش می‌سازد (مدیر
 * تایپ نمی‌کند)، بقیه همان لینکی که کلاینت فرستاده.
 */
function class_join_url(string $classId, string $provider, ?string $clientUrl): ?string
{
    if ($provider === 'jitsi') {
        if (!jitsi_allowed()) fail(403, 'meeting_not_allowed', jitsi_denied_message());
        return jitsi_room_url($classId);
    }
    return $clientUrl;
}

switch ($action) {

case 'create':
    require_perm('class.create');
    $name = s_in($in, 'name', 160);
    if ($name === '') fail(400, 'invalid', 'نام کلاس را وارد کنید.');

    $teacherId = member_or_null($in, 'teacherId', 'teacher');
    $roomId    = s_in($in, 'roomId', 32);
    if ($roomId !== '') own('room', $roomId, 'کلاس');

    $term = t_one('SELECT id FROM term WHERE __I__ AND status = ? ORDER BY starts_on DESC LIMIT 1', ['active']);

    $id       = new_id();
    $provider = enum_in($in, 'provider', PROVIDERS, 'meet');
    $joinUrl  = class_join_url($id, $provider, join_url_in($in, 'joinUrl'));

    db()->prepare(
        'INSERT INTO klass (id, institute_id, term_id, name, level, teacher_user_id, room_id,
                            day_pattern, start_time, duration_min, capacity, total_sessions,
                            starts_on, ends_on, midterm_on, final_on,
                            mode, provider, join_url, price, status, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $id, inst_id(), $term['id'] ?? null, $name, s_in($in, 'level', 40),
        $teacherId, $roomId ?: null,
        enum_in($in, 'dayPattern', DAY_PATTERNS, 'فرد'),
        time_in($in, 'time', '18:00'),
        i_in($in, 'duration', 90, 15, 480),
        i_in($in, 'cap', 12, 1, 200),
        i_in($in, 'totalSessions', 20, 1, 60),
        ...class_dates_in($in, null),
        enum_in($in, 'mode', MODES, 'in_person'),
        $provider, $joinUrl,
        i_in($in, 'price', 0, 0, 9999999999),
        'draft', now_utc(),
    ]);
    audit('class.created', my_id(), ['class' => $id, 'name' => $name]);
    ok(['id' => $id]);

case 'update':
    require_perm('class.edit');
    $cl = own('klass', s_in($in, 'id', 32), 'کلاس');

    $teacherId = array_key_exists('teacherId', $in) ? member_or_null($in, 'teacherId', 'teacher') : $cl['teacher_user_id'];
    $roomId    = array_key_exists('roomId', $in) ? s_in($in, 'roomId', 32) : (string)$cl['room_id'];
    if ($roomId !== '') own('room', $roomId, 'کلاس');

    $newCap = i_in($in, 'cap', (int)$cl['capacity'], 1, 200);
    $have   = (int)(t_one('SELECT COUNT(*) AS n FROM enrolment WHERE __I__ AND class_id = ? AND status = ?', [$cl['id'], 'active'])['n'] ?? 0);
    if ($newCap < $have) {
        fail(409, 'capacity_below_enrolled', "ظرفیت را نمی‌شود کمتر از {$have} نفرِ ثبت‌نام‌شده گذاشت.");
    }

    $provider = enum_in($in, 'provider', PROVIDERS, (string)$cl['provider']);
    $joinUrl  = class_join_url(
        (string)$cl['id'], $provider,
        array_key_exists('joinUrl', $in) ? join_url_in($in, 'joinUrl') : $cl['join_url']
    );

    db()->prepare(
        'UPDATE klass SET name=?, level=?, teacher_user_id=?, room_id=?, day_pattern=?, start_time=?,
                          duration_min=?, capacity=?, total_sessions=?,
                          starts_on=?, ends_on=?, midterm_on=?, final_on=?,
                          mode=?, provider=?, join_url=?, price=?
          WHERE id=? AND institute_id=?'
    )->execute([
        s_in($in, 'name', 160) ?: (string)$cl['name'],
        array_key_exists('level', $in) ? s_in($in, 'level', 40) : (string)$cl['level'],
        $teacherId, $roomId ?: null,
        enum_in($in, 'dayPattern', DAY_PATTERNS, (string)$cl['day_pattern']),
        time_in($in, 'time', (string)$cl['start_time']),
        i_in($in, 'duration', (int)$cl['duration_min'], 15, 480),
        $newCap,
        i_in($in, 'totalSessions', (int)$cl['total_sessions'], 1, 60),
        ...class_dates_in($in, $cl),
        enum_in($in, 'mode', MODES, (string)$cl['mode']),
        $provider, $joinUrl,
        i_in($in, 'price', (int)$cl['price'], 0, 9999999999),
        $cl['id'], inst_id(),
    ]);
    audit('class.updated', my_id(), ['class' => $cl['id']]);
    ok();

/* ─────────── انتشار: جلسه‌ها ساخته می‌شوند ─────────── */
case 'publish':
    require_perm('class.edit');
    $cl = own('klass', s_in($in, 'id', 32), 'کلاس');

    if (empty($cl['teacher_user_id'])) {
        fail(409, 'no_teacher', 'کلاس بدون مدرس منتشر نمی‌شود. اول مدرس را تعیین کنید.');
    }
    if ($cl['mode'] !== 'in_person' && empty($cl['join_url']) && $cl['provider'] !== 'bbb') {
        fail(409, 'no_link', 'کلاس آنلاین بدون لینک جلسه منتشر نمی‌شود. لینک را وارد کنید.');
    }

    $term = t_one('SELECT * FROM term WHERE __I__ AND status = ? ORDER BY starts_on DESC LIMIT 1', ['active']);
    if (!$term) fail(409, 'no_term', 'اول یک ترم فعال بسازید.');

    $from = date_in($in, 'from', (string)$term['starts_on']);
    $made = generate_sessions((string)$cl['id'], (string)$cl['day_pattern'], (string)$cl['start_time'],
                              (int)$cl['total_sessions'], (string)$from, $cl['join_url']);

    db()->prepare('UPDATE klass SET status = ?, term_id = ? WHERE id = ? AND institute_id = ?')
        ->execute(['published', $term['id'], $cl['id'], inst_id()]);

    audit('class.published', my_id(), ['class' => $cl['id'], 'sessions' => $made]);
    ok(['sessions' => $made]);

case 'delete':
    require_perm('class.delete');
    $cl = own('klass', s_in($in, 'id', 32), 'کلاس');
    $n = (int)(t_one('SELECT COUNT(*) AS n FROM enrolment WHERE __I__ AND class_id = ? AND status = ?', [$cl['id'], 'active'])['n'] ?? 0);
    if ($n > 0) {
        fail(409, 'has_students', "این کلاس {$n} زبان‌آموز دارد. اول آن‌ها را جابه‌جا کنید یا کلاس را ببندید.");
    }
    db()->prepare('DELETE FROM klass WHERE id = ? AND institute_id = ?')->execute([$cl['id'], inst_id()]);
    audit('class.deleted', my_id(), ['class' => $cl['id']]);
    ok();

case 'close':
    require_perm('class.edit');
    $cl = own('klass', s_in($in, 'id', 32), 'کلاس');
    db()->prepare('UPDATE klass SET status = ? WHERE id = ? AND institute_id = ?')->execute(['closed', $cl['id'], inst_id()]);
    ok();

/* ─────────── ثبت‌نام و حذف زبان‌آموز ─────────── */
case 'enrol':
    require_perm('enrolment.create');
    $cl  = own('klass', s_in($in, 'classId', 32), 'کلاس');
    $uid = s_in($in, 'studentId', 32);

    $mem = t_one('SELECT user_id FROM membership WHERE __I__ AND user_id = ? AND role = ? AND status = ?', [$uid, 'student', 'active']);
    if (!$mem) fail(404, 'not_found', 'زبان‌آموز پیدا نشد.');

    $n = (int)(t_one('SELECT COUNT(*) AS n FROM enrolment WHERE __I__ AND class_id = ? AND status = ?', [$cl['id'], 'active'])['n'] ?? 0);
    if ($n >= (int)$cl['capacity']) fail(409, 'class_full', 'ظرفیت این کلاس پر است.');

    try {
        db()->prepare('INSERT INTO enrolment (id, institute_id, class_id, student_user_id, status, created_at) VALUES (?,?,?,?,?,?)')
            ->execute([new_id(), inst_id(), $cl['id'], $uid, 'active', now_utc()]);
    } catch (PDOException $e) {
        db()->prepare('UPDATE enrolment SET status = ? WHERE class_id = ? AND student_user_id = ?')
            ->execute(['active', $cl['id'], $uid]);
    }
    audit('enrolment.created', my_id(), ['class' => $cl['id'], 'student' => $uid]);
    ok();

case 'withdraw':
    require_perm('enrolment.delete');
    $cl = own('klass', s_in($in, 'classId', 32), 'کلاس');
    // ردیف می‌ماند و فقط وضعیتش عوض می‌شود؛ حضور و نمرهٔ گذشته نباید گم شود
    db()->prepare('UPDATE enrolment SET status = ? WHERE class_id = ? AND student_user_id = ? AND institute_id = ?')
        ->execute(['withdrawn', $cl['id'], s_in($in, 'studentId', 32), inst_id()]);
    audit('enrolment.withdrawn', my_id(), ['class' => $cl['id']]);
    ok();

/* ─────────── فهرست زبان‌آموزان یک کلاس ─────────── */
case 'roster':
    $cl = own_class(s_in($in, 'classId', 32));
    $rows = t_all(
        'SELECT u.id, u.full_name, u.phone FROM enrolment e JOIN app_user u ON u.id = e.student_user_id
          WHERE e.__I__ AND e.class_id = ? AND e.status = ? ORDER BY u.full_name',
        [$cl['id'], 'active']
    );
    ok(['roster' => array_map(fn($r) => [
        'id' => (string)$r['id'], 'name' => (string)$r['full_name'],
        'phone' => preg_replace('/^(\d{4})\d{4}(\d{3})$/', '$1****$2', (string)$r['phone']),
    ], $rows)]);

default:
    fail(400, 'unknown_action', 'درخواست نامشخص.');
}

/** شناسهٔ عضوی با نقش خواسته‌شده، یا null — تا کلاس به کاربر آموزشگاه دیگر وصل نشود */
function member_or_null(array $in, string $key, string $role): ?string
{
    $id = s_in($in, $key, 32);
    if ($id === '') return null;
    $m = t_one('SELECT user_id FROM membership WHERE __I__ AND user_id = ? AND role = ? AND status = ?', [$id, $role, 'active']);
    if (!$m) fail(404, 'not_found', 'مدرس پیدا نشد.');
    return (string)$m['user_id'];
}

/**
 * چهار تاریخ کلاس، اعتبارسنجی‌شده و به ترتیبِ ستون‌ها.
 *
 * @param array|null $cur ردیف فعلی کلاس، یا null در ساخت
 * @return array{0:?string,1:?string,2:?string,3:?string}
 */
function class_dates_in(array $in, ?array $cur): array
{
    $get = function (string $key, string $col) use ($in, $cur): ?string {
        // در ویرایش، کلیدی که فرستاده نشده یعنی «دست نزن»؛ کلیدِ خالی
        // یعنی «پاکش کن». این دو باید از هم جدا باشند، وگرنه هر ذخیرهٔ
        // جزئی تاریخ‌های قبلی را می‌شوید.
        if (!array_key_exists($key, $in)) return $cur ? ($cur[$col] ?? null) : null;
        return date_in($in, $key);
    };

    $start = $get('startsOn',  'starts_on');
    $end   = $get('endsOn',    'ends_on');
    $mid   = $get('midtermOn', 'midterm_on');
    $fin   = $get('finalOn',   'final_on');

    /*
     * ترتیب تاریخ‌ها بررسی می‌شود، نه فقط شکلشان.
     *
     * «پایان پیش از شروع» یا «آزمون پایان‌ترم پیش از میان‌ترم» غلط
     * تایپی است، نه تصمیم. اگر همین‌جا نگیریمش، بعداً به شکل «۰ جلسه
     * مانده» یا کارنامه‌ای با ترتیب وارونه بیرون می‌زند و کسی نمی‌فهمد
     * از کجا آمده.
     */
    if ($start && $end && $end < $start) {
        fail(400, 'bad_dates', 'تاریخ پایان کلاس نمی‌تواند پیش از شروع باشد.');
    }
    foreach ([['میان‌ترم', $mid], ['پایان‌ترم', $fin]] as [$label, $d]) {
        if (!$d) continue;
        if ($start && $d < $start) fail(400, 'bad_dates', "تاریخ آزمون {$label} پیش از شروع کلاس است.");
        if ($end   && $d > $end)   fail(400, 'bad_dates', "تاریخ آزمون {$label} بعد از پایان کلاس است.");
    }
    if ($mid && $fin && $fin < $mid) {
        fail(400, 'bad_dates', 'آزمون پایان‌ترم نمی‌تواند پیش از میان‌ترم باشد.');
    }

    return [$start, $end, $mid, $fin];
}
