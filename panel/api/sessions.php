<?php
/**
 * جلسه‌ها: شروع، پایان، لغو، و لینک ورود.
 *
 * ═══ دربارهٔ کلاس آنلاین، صادقانه ═══
 *
 * روی هاست اشتراکی، BigBlueButton نصب نمی‌شود — سرور اختصاصی می‌خواهد
 * (بند Q و S). پس تا وقتی سرور BBB ندارید، «ارائه‌دهنده» یعنی لینکی که
 * مدرس یا مدیر وارد می‌کند: Google Meet، اسکای‌روم، یا هر لینک دیگر.
 *
 * این کم‌ارزش نیست؛ کاری که واقعاً لازم است را انجام می‌دهد:
 *   - زبان‌آموز لینک را جای ثابت و همیشگی می‌بیند، نه در پیام‌رسان
 *   - لینک فقط از ۱۵ دقیقه قبل تا پایان جلسه فعال است
 *   - «کی کلاس را شروع کرد و کی تمام شد» ثبت می‌شود
 *
 * چیزی که بدون سرور BBB واقعاً نداریم: حضور و غیاب خودکار و ضبط کلاس.
 * به‌جای وانمودکردن، کد این را صریح اعلام می‌کند (attendanceAuto) تا
 * رابط کاربری بتواند به مدرس بگوید حضور را دستی بزند.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_ctx.php';
require_once __DIR__ . '/_perm.php';

require_post();
$in     = body_json();
$action = s_in($in, 'action', 40);

/** فقط ارائه‌دهنده‌ای که سرورش را داریم می‌تواند حضور خودکار بدهد */
function attendance_auto(string $provider): bool
{
    return $provider === 'bbb';
}

/** پنجرهٔ باز بودن لینک: ۱۵ دقیقه قبل تا ۴ ساعت بعد از شروع */
function session_window(array $s): array
{
    $start = strtotime((string)$s['session_date'] . ' ' . (string)$s['start_time'] . ':00 UTC');
    return [$start - 15 * 60, $start + 4 * 3600];
}

switch ($action) {

/* ─────────── فهرست جلسه‌های یک کلاس ─────────── */
case 'list':
    $cl = own_class(s_in($in, 'classId', 32));
    $rows = t_all('SELECT * FROM class_session WHERE __I__ AND class_id = ? ORDER BY seq', [$cl['id']]);

    // چند نفر برای هر جلسه حضور خورده — تا رابط کاربری بداند کدام جلسه ثبت نشده
    $marked = [];
    foreach (t_all('SELECT a.session_id AS k, COUNT(*) AS n FROM attendance a
                      JOIN class_session s ON s.id = a.session_id
                     WHERE a.__I__ AND s.class_id = ? GROUP BY a.session_id', [$cl['id']]) as $r) {
        $marked[(string)$r['k']] = (int)$r['n'];
    }

    ok(['sessions' => array_map(fn($r) => [
        'id'      => (string)$r['id'],
        'seq'     => (int)$r['seq'],
        'date'    => (string)$r['session_date'],
        'time'    => (string)$r['start_time'],
        'status'  => (string)$r['status'],
        'joinUrl' => $r['join_url'],
        'note'    => $r['note'],
        'marked'  => $marked[(string)$r['id']] ?? 0,
    ], $rows)]);

/* ─────────── شروع جلسه ─────────── */
case 'start':
    require_perm('session.start_meeting');
    $s  = own('class_session', s_in($in, 'id', 32), 'جلسه');
    $cl = own_class((string)$s['class_id']);

    if ($s['status'] === 'cancelled') fail(409, 'cancelled', 'این جلسه لغو شده.');

    /*
     * جلسهٔ میت مجوز می‌خواهد. قاعده‌اش در jitsi_allowed() است، همان
     * که classes.php هنگام ساخت کلاس صدا می‌زند — پیش از این هرکدام
     * جداگانه تصمیم می‌گرفتند و نتیجه‌شان یکی نبود: اینجا مدیر را از
     * کلید خاموشیِ آموزشگاه هم معاف می‌کرد.
     */
    if ((string)$cl['provider'] === 'jitsi' && !jitsi_allowed()) {
        fail(403, 'meeting_not_allowed', jitsi_denied_message());
    }

    // لینک: اگر مدرس لینک تازه داد همان، وگرنه لینک ثابت کلاس
    $url = join_url_in($in, 'joinUrl') ?? $s['join_url'] ?? $cl['join_url'];
    if ($cl['mode'] !== 'in_person' && !$url && $cl['provider'] !== 'bbb') {
        fail(409, 'no_link', 'لینک جلسه را وارد کنید تا زبان‌آموزها بتوانند وارد شوند.');
    }

    db()->prepare('UPDATE class_session SET status = ?, join_url = ?, started_at = ? WHERE id = ? AND institute_id = ?')
        ->execute(['live', $url, now_utc(), $s['id'], inst_id()]);

    // پیش‌نویس حضور: همه حاضر. مدرس فقط غایب‌ها را می‌زند — بند R
    $roster = t_all('SELECT student_user_id FROM enrolment WHERE __I__ AND class_id = ? AND status = ?', [$cl['id'], 'active']);
    $ins = db()->prepare('INSERT INTO attendance (id, institute_id, session_id, student_user_id, status, marked_by, marked_at) VALUES (?,?,?,?,?,?,?)');
    $made = 0;
    foreach ($roster as $r) {
        try {
            $ins->execute([new_id(), inst_id(), $s['id'], $r['student_user_id'], 'present', my_id(), now_utc()]);
            $made++;
        } catch (PDOException $e) {
            // از قبل ردیف داشته — جلسه دوباره شروع شده، حضور قبلی نباید پاک شود
        }
    }

    audit('session.started', my_id(), ['session' => $s['id'], 'class' => $cl['id']]);
    ok([
        'joinUrl'        => $url,
        'provider'       => (string)$cl['provider'],
        'attendanceAuto' => attendance_auto((string)$cl['provider']),
        'roster'         => count($roster),
        'drafted'        => $made,
    ]);

/* ─────────── پایان جلسه ─────────── */
case 'end':
    require_perm('session.edit');
    $s  = own('class_session', s_in($in, 'id', 32), 'جلسه');
    own_class((string)$s['class_id']);
    db()->prepare('UPDATE class_session SET status = ?, ended_at = ? WHERE id = ? AND institute_id = ?')
        ->execute(['done', now_utc(), $s['id'], inst_id()]);
    audit('session.ended', my_id(), ['session' => $s['id']]);
    ok();

case 'cancel':
    require_perm('session.edit');
    $s = own('class_session', s_in($in, 'id', 32), 'جلسه');
    own_class((string)$s['class_id']);
    db()->prepare('UPDATE class_session SET status = ?, note = ? WHERE id = ? AND institute_id = ?')
        ->execute(['cancelled', s_in($in, 'note', 255) ?: null, $s['id'], inst_id()]);
    audit('session.cancelled', my_id(), ['session' => $s['id']]);
    ok();

/* ─────────── ورود زبان‌آموز به کلاس ─────────── */
case 'join':
    $s  = own('class_session', s_in($in, 'id', 32), 'جلسه');
    $cl = own_class((string)$s['class_id']);   // خودش بررسی می‌کند ثبت‌نام هست یا نه

    if ($s['status'] === 'cancelled') fail(409, 'cancelled', 'این جلسه لغو شده.');

    [$open, $close] = session_window($s);
    $now = time();
    if ($now < $open) {
        fail(409, 'too_early', 'لینک از ۱۵ دقیقه قبل از شروع کلاس فعال می‌شود.', ['opensAt' => gmdate('c', $open)]);
    }
    if ($now > $close) {
        fail(409, 'too_late', 'این جلسه تمام شده.');
    }

    $url = $s['join_url'] ?? $cl['join_url'];
    if (!$url) fail(409, 'no_link', 'هنوز لینکی برای این جلسه ثبت نشده. با آموزشگاه تماس بگیرید.');

    audit('session.joined', my_id(), ['session' => $s['id']]);
    ok(['joinUrl' => $url, 'provider' => (string)$cl['provider']]);

/* ─────────── جلسه‌های امروز، برای پیشخوان ─────────── */
case 'today':
    $today = gmdate('Y-m-d');
    /*
     * محدوده از مجوز می‌آید، نه از نام نقش. شکل قبلی برای هر نقشی جز
     * مدرس و زبان‌آموز هیچ فیلتری نمی‌گذاشت — یعنی اولین نقش سفارشی،
     * جلسه‌های کل آموزشگاه را می‌دید.
     */
    [$where, $scopeArgs] = class_scope_sql(perm_scope('session.view') ?? 'own', 'k');
    $args = array_merge([$today], $scopeArgs);
    $rows = t_all(
        "SELECT s.*, k.name AS class_name, k.provider, k.mode, k.join_url AS class_url
           FROM class_session s JOIN klass k ON k.id = s.class_id
          WHERE s.__I__ AND s.session_date = ?{$where}
          ORDER BY s.start_time",
        $args
    );
    ok(['sessions' => array_map(fn($r) => [
        'id'        => (string)$r['id'],
        'classId'   => (string)$r['class_id'],
        'className' => (string)$r['class_name'],
        'seq'       => (int)$r['seq'],
        'time'      => (string)$r['start_time'],
        'status'    => (string)$r['status'],
        'mode'      => (string)$r['mode'],
        'provider'  => (string)$r['provider'],
        'hasLink'   => !empty($r['join_url'] ?? $r['class_url']),
    ], $rows)]);

default:
    fail(400, 'unknown_action', 'درخواست نامشخص.');
}
