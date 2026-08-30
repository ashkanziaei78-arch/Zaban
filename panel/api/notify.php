<?php
/**
 * اعلان: فرستادن و خواندن.
 *
 * مخاطب همان لحظهٔ ارسال باز می‌شود و در notification_target ثابت
 * می‌ماند — نه یک پرس‌وجو که هر بار از نو حساب شود. دلیلش در
 * migrations/010 نوشته شده.
 *
 * محدودهٔ مجوز تعیین می‌کند چه کسی به چه کسانی می‌رسد: مدرس با
 * own_classes فقط به زبان‌آموزان کلاس‌های خودش، مدیر با institute به
 * همهٔ آموزشگاه. هیچ‌کجای این فایل نام نقش را نمی‌خواند.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_ctx.php';
require_once __DIR__ . '/_perm.php';

require_post();
$in     = body_json();
$action = s_in($in, 'action', 40);

switch ($action) {

/* ─────────── صندوق ورودی ─────────── */
case 'inbox':
    require_perm('notify.view');
    $onlyUnread = (bool)($in['unread'] ?? false);
    $limit      = i_in($in, 'limit', 40, 1, 100);

    $rows = db()->prepare(
        'SELECT n.id, n.title, n.body, n.link, n.kind, n.sender_name, n.created_at,
                t.id AS target_id, t.read_at
           FROM notification_target t JOIN notification n ON n.id = t.notification_id
          WHERE t.user_id = ?' . ($onlyUnread ? ' AND t.read_at IS NULL' : '') . '
          ORDER BY n.seq DESC LIMIT ' . $limit);
    $rows->execute([my_id()]);

    $unread = db()->prepare(
        'SELECT COUNT(*) FROM notification_target WHERE user_id = ? AND read_at IS NULL');
    $unread->execute([my_id()]);

    ok([
        'unread' => (int)$unread->fetchColumn(),
        'items'  => array_map(fn($r) => [
            'id'        => (string)$r['target_id'],
            'title'     => (string)$r['title'],
            'body'      => (string)$r['body'],
            'link'      => $r['link'] ? (string)$r['link'] : null,
            'kind'      => (string)$r['kind'],
            'from'      => (string)$r['sender_name'],
            'createdAt' => (string)$r['created_at'],
            'read'      => $r['read_at'] !== null,
        ], $rows->fetchAll()),
    ]);

case 'read':
    require_perm('notify.view');
    $ids = $in['ids'] ?? null;

    /*
     * «همه را خوانده‌شده کن» با یک UPDATE، نه با حلقه روی شناسه‌ها.
     *
     * کاربری که سیصد اعلان نخوانده دارد، سیصد شناسه نمی‌فرستد؛ و اگر
     * می‌فرستاد، بدنهٔ درخواست از سقف رد می‌شد. شرط user_id هم اینجاست
     * تا کسی نتواند اعلان دیگری را خوانده اعلام کند.
     */
    if (!is_array($ids)) {
        db()->prepare('UPDATE notification_target SET read_at = ? WHERE user_id = ? AND read_at IS NULL')
            ->execute([now_utc(), my_id()]);
        ok(['all' => true]);
    }

    $ids = array_values(array_filter(array_map('strval', $ids),
             fn($x) => (bool)preg_match('/^[a-f0-9]{32}$/', $x)));
    if (!$ids) ok(['changed' => 0]);
    $ids = array_slice($ids, 0, 200);

    $q  = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("UPDATE notification_target SET read_at = ?
                          WHERE user_id = ? AND read_at IS NULL AND id IN ($q)");
    $st->execute(array_merge([now_utc(), my_id()], $ids));
    ok(['changed' => $st->rowCount()]);


/* ─────────── مخاطب‌های ممکن ───────────
 *
 * پیش از نوشتن متن، فرستنده باید بداند به چه کسانی می‌تواند بفرستد و
 * هرکدام چند نفرند. شمار مهم است: «۳ نفر» یعنی کلاس را اشتباه
 * انتخاب کرده‌اید، و بهتر است پیش از فرستادن معلوم شود.
 */
case 'audiences':
    require_perm('notify.send');
    $scope = perm_scope('notify.send');
    $out   = [];

    if (scope_rank($scope) >= scope_rank('institute')) {
        $out[] = ['key' => 'institute', 'label' => 'همهٔ اعضای آموزشگاه',
                  'count' => audience_count('institute', '')];
        foreach (['student' => 'همهٔ زبان‌آموزان', 'teacher' => 'همهٔ مدرسان'] as $r => $lbl) {
            $out[] = ['key' => 'role:' . $r, 'label' => $lbl,
                      'count' => audience_count('role', $r)];
        }
    }

    [$where, $args] = class_scope_sql($scope);
    $classes = t_all('SELECT k.id, k.name FROM klass k
                       WHERE k.__I__' . $where . " AND k.status <> 'archived'
                       ORDER BY k.name", $args);
    foreach ($classes as $c) {
        $out[] = ['key' => 'class:' . $c['id'], 'label' => class_label((string)$c['name']),
                  'count' => audience_count('class', (string)$c['id'])];
    }

    /*
     * canSms عمداً false است، حتی برای مدیری که مجوزش را دارد: تا وقتی
     * قالب مصوب نیست، نشان‌دادن تیک «با پیامک هم بفرست» یعنی وعده‌ای که
     * لحظهٔ ارسال شکسته می‌شود. بهتر است اصلاً دیده نشود.
     */
    ok(['audiences' => $out, 'canSms' => false]);


/* ─────────── فرستادن ─────────── */
case 'send':
    require_perm('notify.send');

    $title = s_in($in, 'title', 140);
    $body  = s_in($in, 'body', 2000);
    if ($title === '') fail(400, 'invalid', 'عنوان اعلان را بنویسید.');
    if ($body === '')  fail(400, 'invalid', 'متن اعلان را بنویسید.');

    $kind = enum_in($in, 'kind', ['info', 'success', 'warn', 'urgent'], 'info');
    $aud  = s_in($in, 'audience', 60);
    $sms  = (bool)($in['sms'] ?? false);

    [$type, $arg] = audience_parse($aud);
    $scope = perm_scope('notify.send');

    /*
     * محدوده پیش از هر کاری بررسی می‌شود، نه بعد از باز کردن فهرست.
     *
     * مدرسی که شناسهٔ کلاسِ همکارش را دستی بفرستد، نباید حتی بفهمد آن
     * کلاس چند نفر دارد.
     */
    if ($type !== 'class' && scope_rank($scope) < scope_rank('institute')) {
        fail(403, 'forbidden', 'شما فقط به کلاس‌های خودتان می‌توانید اعلان بفرستید.');
    }
    if ($type === 'class') {
        // نام مستعار k لازم است: class_scope_sql پیش‌فرض با همان می‌نویسد
        [$w, $a] = class_scope_sql($scope);
        $mine = t_one('SELECT k.id FROM klass k WHERE k.__I__ AND k.id = ?' . $w,
                      array_merge([$arg], $a));
        if (!$mine) fail(404, 'not_found', 'کلاس پیدا نشد.');
    }

    /*
     * پیامک هنوز راه نیفتاده و اینجا صادقانه رد می‌شود.
     *
     * ستون sms_sent و مجوز notify.sms از قبل هستند چون طراحی‌شان روشن
     * است: پیامک جدا مجوز می‌خواهد — نه چون کار دیگری می‌کند بلکه چون
     * هزینه دارد و برگشت‌ناپذیر است؛ اعلان اشتباه پاک می‌شود، پیامکِ
     * رفته نه — و فقط برای «فوری» است، وگرنه کاربر بعد از هفتهٔ اول
     * همه را خاموش می‌کند و آن‌وقت پیام واقعاً فوری هم نمی‌رسد.
     *
     * ولی چیزی که هست، سرویس «وریفای» sms.ir با یک قالب مصوب برای کد
     * ورود است (بند P.4). فرستادن متن آزاد از آن قالب ممکن نیست؛ قالب
     * دومی لازم است که آموزشگاه باید از sms.ir بگیرد و تأییدش چند روز
     * طول می‌کشد.
     *
     * تا آن روز، اینجا خطا برمی‌گردد و sms_sent صفر می‌ماند. نوشتن
     * sms_sent = 1 بدون فرستادن، دروغی است که بعداً کسی به‌عنوان
     * «پیامک رفته» به آن استناد می‌کند.
     */
    if ($sms) {
        require_perm('notify.sms');
        if ($kind !== 'urgent') {
            fail(400, 'sms_not_urgent', 'پیامک فقط برای اعلان فوری است.');
        }
        fail(503, 'sms_unavailable',
            'ارسال پیامکی اعلان هنوز فعال نیست: قالب مصوب sms.ir برای متن آزاد لازم است. '
          . 'اعلان را بدون پیامک بفرستید.');
    }

    $targets = audience_users($type, $arg);
    if (!$targets) {
        // چون فرستنده از فهرست کنار می‌رود، «خالی» می‌تواند یعنی
        // «فقط خودت» — و آن‌وقت پیام «عضوی ندارد» گیج‌کننده است
        fail(400, 'no_targets', 'این مخاطب کسی جز خودتان ندارد.');
    }

    $me  = ctx()['user'];
    $nid = new_id();
    $now = now_utc();
    $db  = db();

    $db->beginTransaction();
    try {
        $db->prepare(
            'INSERT INTO notification
               (id, institute_id, sender_id, sender_name, title, body, link, kind,
                audience, recipients, sms_sent, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([$nid, inst_id(), my_id(), (string)($me['full_name'] ?? 'آموزشگاه'),
                    $title, $body, s_in($in, 'link', 120) ?: null, $kind,
                    audience_label($type, $arg), count($targets), $sms ? 1 : 0, $now]);

        /*
         * درج دسته‌ای، نه یک execute به‌ازای هر نفر.
         *
         * آموزشگاهی با چهارصد زبان‌آموز یعنی چهارصد رفت‌وبرگشت به
         * دیتابیس؛ روی هاست اشتراکی همین می‌شود چند ثانیه انتظار و
         * گاهی timeout. دسته‌های صدتایی، هم سریع‌اند هم از سقف
         * max_allowed_packet دور.
         */
        foreach (array_chunk($targets, 100) as $chunk) {
            $vals = implode(',', array_fill(0, count($chunk), '(?,?,?)'));
            $args = [];
            foreach ($chunk as $uid) { $args[] = new_id(); $args[] = $nid; $args[] = $uid; }
            $db->prepare("INSERT INTO notification_target (id, notification_id, user_id) VALUES $vals")
               ->execute($args);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('notify send failed: ' . $e->getMessage());
        fail(500, 'send_failed', 'اعلان فرستاده نشد. دوباره تلاش کنید.');
    }

    audit('notify.sent', my_id(), ['kind' => $kind, 'audience' => $aud,
                                   'recipients' => count($targets), 'sms' => $sms]);
    ok(['id' => $nid, 'recipients' => count($targets),
        'audience' => audience_label($type, $arg)]);


/* ─────────── سابقهٔ ارسال ─────────── */
case 'sent':
    require_perm('notify.send');
    $scope = perm_scope('notify.send');

    // مدرس فقط فرستاده‌های خودش را می‌بیند؛ مدیر همهٔ آموزشگاه را
    $mineOnly = scope_rank($scope) < scope_rank('institute');

    /*
     * اعلانی که سوپرادمین به این آموزشگاه فرستاده، اینجا نمی‌آید.
     *
     * این فهرست «چیزهایی که *ما* فرستادیم» است. اعلان پلتفرمی که به
     * یک آموزشگاه خاص می‌رود، institute_id همان آموزشگاه را دارد و
     * بدون این شرط در سیاههٔ مدیر می‌نشست — با نام فرستنده‌ای که مال
     * او نیست. مدیر هنوز خودِ اعلان را در صندوقش می‌بیند چون عضو
     * است؛ فقط در فهرست فرستاده‌هایش نیست.
     */
    $rows = t_all(
        'SELECT id, title, body, kind, audience, recipients, sms_sent, sender_name, created_at
           FROM notification
          WHERE __I__ AND sender_admin IS NULL' . ($mineOnly ? ' AND sender_id = ?' : '') . '
          ORDER BY seq DESC LIMIT 60',
        $mineOnly ? [my_id()] : []);

    ok(['sent' => array_map(fn($r) => [
        'id'         => (string)$r['id'],
        'title'      => (string)$r['title'],
        'body'       => (string)$r['body'],
        'kind'       => (string)$r['kind'],
        'audience'   => (string)$r['audience'],
        'recipients' => (int)$r['recipients'],
        'sms'        => (bool)$r['sms_sent'],
        'from'       => (string)$r['sender_name'],
        'createdAt'  => (string)$r['created_at'],
        'readCount'  => read_count((string)$r['id']),
    ], $rows)]);

default:
    fail(400, 'unknown_action', 'درخواست نامشخص.');
}


/* ═════════════════════ کمکی ═════════════════════ */

/** «class:abc…» → ['class','abc…'] */
function audience_parse(string $a): array
{
    if ($a === '' || $a === 'institute') return ['institute', ''];
    $p = explode(':', $a, 2);
    $t = $p[0];
    $v = $p[1] ?? '';
    if ($t === 'role'  && in_array($v, ['student', 'teacher', 'manager'], true)) return ['role', $v];
    if ($t === 'class' && preg_match('/^[a-f0-9]{32}$/', $v)) return ['class', $v];
    fail(400, 'bad_audience', 'مخاطب نامشخص است.');
}

/**
 * نام خوانای یک کلاس برای فهرست مخاطبان.
 *
 * «کلاس» را جلوی نام می‌گذارد، مگر خودِ نام از همان کلمه شروع شده
 * باشد. آموزشگاه‌ها کلاس‌هایشان را «کلاس الف» صدا می‌زنند، نه «الف»،
 * و نتیجه‌اش «کلاس کلاس الف» می‌شد — چیزی که فرستنده در فهرست
 * مخاطبان می‌دید و در سابقهٔ ارسال هم ثبت می‌شد.
 */
function class_label(string $name): string
{
    $name = trim($name);
    if ($name === '') return 'کلاس —';
    // «کلاسِ» و «کلاس‌های» هم همین‌جا می‌افتند
    return mb_substr($name, 0, 4) === 'کلاس' ? $name : 'کلاس ' . $name;
}

function audience_label(string $type, string $arg): string
{
    if ($type === 'role') {
        return ['student' => 'همهٔ زبان‌آموزان', 'teacher' => 'همهٔ مدرسان',
                'manager' => 'مدیران'][$arg] ?? 'اعضا';
    }
    if ($type === 'class') {
        $k = t_one('SELECT name FROM klass WHERE __I__ AND id = ?', [$arg]);
        return class_label((string)($k['name'] ?? '—'));
    }
    return 'همهٔ اعضای آموزشگاه';
}

/**
 * شناسهٔ کاربرانِ مخاطب، بدون تکرار.
 *
 * DISTINCT لازم است چون از نسخهٔ ۷ یک نفر می‌تواند در یک آموزشگاه چند
 * نقش داشته باشد. بدون آن، مدیری که خودش هم تدریس می‌کند دو نسخه از
 * هر اعلان می‌گرفت — و قید یکتای notification_target کل ارسال را با
 * خطا برمی‌گرداند.
 *
 * @return list<string>
 */
function audience_users(string $type, string $arg): array
{
    if ($type === 'class') {
        $rows = t_all(
            'SELECT DISTINCT e.student_user_id AS uid
               FROM enrolment e JOIN klass k ON k.id = e.class_id
              WHERE k.__I__ AND e.class_id = ? AND e.status = ?', [$arg, 'active']);
        // مدرس کلاس هم باید اعلانِ کلاس خودش را ببیند، جز وقتی خودش فرستاده
        $k = t_one('SELECT teacher_user_id FROM klass WHERE __I__ AND id = ?', [$arg]);
        $ids = array_map(fn($r) => (string)$r['uid'], $rows);
        if ($k && $k['teacher_user_id'] && (string)$k['teacher_user_id'] !== my_id()) {
            $ids[] = (string)$k['teacher_user_id'];
        }
        return array_values(array_unique(array_filter($ids)));
    }

    $where = $type === 'role' ? ' AND m.role = ?' : '';
    $args  = $type === 'role' ? [$arg] : [];
    $rows  = t_all(
        "SELECT DISTINCT m.user_id AS uid FROM membership m
          WHERE m.__I__ AND m.status = 'active'
            AND (m.expires_at IS NULL OR m.expires_at > UTC_TIMESTAMP())" . $where, $args);

    /*
     * فرستنده اعلان خودش را نمی‌گیرد.
     *
     * مسیر کلاس این را از اول رعایت می‌کرد (مدرسِ کلاس را وقتی خودش
     * فرستاده بود کنار می‌گذاشت) ولی «همهٔ اعضا» و «همهٔ مدرسان» نه —
     * پس مدیری که به کل آموزشگاه خبر می‌داد، زنگ خودش هم قرمز می‌شد و
     * باید اعلان خودش را «خوانده» می‌کرد. شمار گیرنده هم یکی بیشتر
     * گزارش می‌شد از آنچه واقعاً به کسی رسیده.
     */
    $me = my_id();
    return array_values(array_filter(
        array_map(fn($r) => (string)$r['uid'], $rows),
        fn($uid) => $uid !== $me));
}

function audience_count(string $type, string $arg): int
{
    return count(audience_users($type, $arg));
}

function read_count(string $notifId): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM notification_target
                          WHERE notification_id = ? AND read_at IS NOT NULL');
    $st->execute([$notifId]);
    return (int)$st->fetchColumn();
}
