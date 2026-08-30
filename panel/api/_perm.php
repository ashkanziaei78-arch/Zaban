<?php
/**
 * موتور دسترسی — نسخهٔ ۶.
 *
 * تا اینجا کد می‌پرسید «آیا این کاربر مدیر است؟». از اینجا می‌پرسد
 * «آیا اجازهٔ ساخت کلاس دارد؟». تفاوت فنی به‌نظر می‌رسد ولی محصولی
 * است: تا وقتی نام نقش در کد حک شده باشد، ساخت نقش تازه یا دادن یک
 * دسترسی خاص به یک نفر نیازمند تغییر کد و انتشار دوباره است.
 *
 * ═══ چرا این فایل هنوز چیزی را عوض نمی‌کند ═══
 *
 * این فاز عمداً افزودنی محض است. تابع require_role() در _ctx.php
 * دست‌نخورده می‌ماند و همچنان تصمیم می‌گیرد؛ موتور تازه کنارش می‌نشیند
 * و قابل آزمون است ولی هنوز بار نمی‌برد.
 *
 * وسوسه این بود که همان حالا require_role() را به پوسته‌ای روی این
 * موتور تبدیل کنیم. این کار خطرناک است: اگر نگاشت نقش به مجوز کمی
 * گشادتر از اختیار امروز باشد، هیچ خطایی دیده نمی‌شود — فقط یک مدرس
 * ناگهان چیزی را می‌بیند که نباید. تبدیل نقطه‌به‌نقطه در فاز ۳ انجام
 * می‌شود و هر کدام با آزمون هم‌ارزی perm_equivalence_report() سنجیده
 * می‌شود: تصمیم قدیم و جدید باید برای هر نقش یکسان باشد.
 *
 * ═══ ترتیب تصمیم ═══
 *
 * زمینهٔ فعال ← اعتبار عضویت ← وضعیت آموزشگاه ← مجوز مؤثر ← محدوده.
 * ترتیب خودش بخشی از امنیت است؛ جابه‌جا کردنش حفره می‌سازد.
 *
 * مالک پلتفرم اینجا استثنا ندارد و لازم هم نیست: مالک از پنل خودش
 * (superadmin/) با کوکی جدا کار می‌کند، و برای دیدن داخل یک آموزشگاه
 * از «ورود به‌جای کاربر» استفاده می‌کند که نشست عادی همان کاربر را
 * می‌سازد. یعنی وقتی در این پنل است، عمداً به همان اندازهٔ آن کاربر
 * می‌بیند — که نکتهٔ اصلی impersonate است.
 */

declare(strict_types=1);

if (realpath(__FILE__) === realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    http_response_code(404);
    exit;
}

/* ═════════════════════ محدودهٔ داده ═════════════════════ */

/**
 * از باریک به گشاد. ترتیب معنادار است: widest_scope() فقط بزرگ‌ترین
 * اندیس را برمی‌دارد، پس افزودن سطح تازه یعنی گذاشتنش در جای درست
 * همین آرایه و نه جای دیگر.
 *
 * branch عمداً بین own_classes و institute است و فعلاً جدولش خالی
 * می‌ماند؛ تا وقتی شعبه‌ای تعریف نشده مثل institute رفتار می‌کند.
 */
const PERM_SCOPES = ['own', 'assigned_students', 'own_classes', 'branch', 'institute', 'platform'];

function scope_rank(string $scope): int
{
    $i = array_search($scope, PERM_SCOPES, true);
    return $i === false ? 0 : (int)$i;
}

function widest_scope(?string $a, ?string $b): string
{
    if ($a === null) return (string)($b ?? 'own');
    if ($b === null) return $a;
    return scope_rank($a) >= scope_rank($b) ? $a : $b;
}

/* ═════════════════════ زمینهٔ فعال ═════════════════════ */

/**
 * همهٔ عضویت‌های فعال و معتبر کاربر — ورودی انتخابگر زمینه.
 *
 * @return list<array{membership_id:string,institute_id:string,institute_name:string,
 *                    role_id:string,role_key:string,role_name:string,expires_at:?string}>
 */
function my_memberships(): array
{
    static $ms = null;
    if ($ms !== null) return $ms;

    $u = require_user();
    $st = db()->prepare(
        'SELECT m.id AS membership_id, m.institute_id, m.role_id, m.role, m.expires_at,
                i.name AS institute_name, i.status AS institute_status, i.plan,
                r.name_fa AS role_name, r.role_key
           FROM membership m
           JOIN institute i ON i.id = m.institute_id
           LEFT JOIN role r ON r.id = m.role_id
          WHERE m.user_id = ? AND m.status = ?
            AND (m.expires_at IS NULL OR m.expires_at > ?)
            AND i.status <> ?
          ORDER BY i.name, r.name_fa'
    );
    $st->execute([$u['id'], 'active', now_utc(), 'suspended']);

    $ms = array_map(fn($r) => [
        'membership_id'   => (string)$r['membership_id'],
        'institute_id'    => (string)$r['institute_id'],
        'institute_name'  => (string)$r['institute_name'],
        'role_id'         => (string)($r['role_id'] ?? ('r_' . $r['role'])),
        'role_key'        => (string)($r['role_key'] ?? $r['role']),
        'role_name'       => (string)($r['role_name'] ?? $r['role']),
        'plan'            => (string)($r['plan'] ?? 'active'),
        'expires_at'      => $r['expires_at'] !== null ? (string)$r['expires_at'] : null,
    ], $st->fetchAll());

    return $ms;
}

/**
 * زمینهٔ فعال: کاربر، آموزشگاه، و نقشی که همین حالا با آن کار می‌کند.
 *
 * ═══ چرا سمت سرور ═══
 *
 * منبع این اطلاعات ستون‌های active_* روی session_token است، نه چیزی که
 * مرورگر می‌فرستد. اگر بررسی دسترسی می‌پرسید «آیا این کاربر *جایی* نقش
 * مدرس دارد؟» به‌جای «آیا نقش *فعالش* مدرس است؟»، کسی که در نمای
 * زبان‌آموز نشسته می‌توانست با یک درخواست دستی دادهٔ مدرس را بخواند.
 *
 * ═══ وقتی زمینه نیست ═══
 *
 * یک عضویت → خودکار همان. چند عضویت → ۴۰۹ تا رابط انتخابگر را نشان
 * دهد. هیچ عضویت → ۴۰۳. نشست‌های بازِ پیش از این تغییر ستون خالی
 * دارند و از همین مسیر بی‌دردسر پر می‌شوند؛ کسی بیرون انداخته نمی‌شود.
 *
 * @return array{user_id:string, institute_id:string, role_id:string, role_key:string, plan:string}
 */
function active_context(): array
{
    static $ac = null;
    if ($ac !== null) return $ac;

    $u    = require_user();
    $list = my_memberships();

    if (!$list) {
        fail(403, 'no_membership',
            'شما عضو هیچ آموزشگاه فعالی نیستید. از مدیر آموزشگاه بخواهید با همین شماره دعوت‌تان کند.');
    }

    // زمینهٔ ذخیره‌شده روی نشست
    $tok = $_COOKIE[SESSION_COOKIE] ?? '';
    $sel = null;
    if ($tok !== '' && preg_match('/^[a-f0-9]{64}$/', $tok)) {
        $st = db()->prepare(
            'SELECT active_institute_id, active_role_id FROM session_token WHERE token_hash = ?'
        );
        $st->execute([hash('sha256', $tok)]);
        $row = $st->fetch();
        if ($row && $row['active_institute_id'] !== null) {
            foreach ($list as $m) {
                if ($m['institute_id'] === (string)$row['active_institute_id']
                    && $m['role_id'] === (string)$row['active_role_id']) {
                    $sel = $m;
                    break;
                }
            }
            /*
             * زمینهٔ ذخیره‌شده دیگر معتبر نیست — عضویت برداشته شده،
             * منقضی شده، یا آموزشگاه معلق. پاکش می‌کنیم و مثل حالت
             * بی‌زمینه رفتار می‌کنیم، نه اینکه کاربر را با خطا برانیم.
             */
            if ($sel === null) context_clear();
        }
    }

    if ($sel === null) {
        if (count($list) === 1) {
            $sel = $list[0];
            context_set($sel['institute_id'], $sel['role_id']);
        } else {
            fail(409, 'need_context', 'نقش خود را انتخاب کنید.', ['options' => context_options()]);
        }
    }

    $ac = [
        'user_id'      => (string)$u['id'],
        'institute_id' => $sel['institute_id'],
        'role_id'      => $sel['role_id'],
        'role_key'     => $sel['role_key'],
        'plan'         => $sel['plan'],
    ];
    return $ac;
}

/** زمینه را روی نشست جاری می‌نویسد */
function context_set(string $instituteId, string $roleId): void
{
    $tok = $_COOKIE[SESSION_COOKIE] ?? '';
    if ($tok === '' || !preg_match('/^[a-f0-9]{64}$/', $tok)) return;

    db()->prepare(
        'UPDATE session_token SET active_institute_id = ?, active_role_id = ?, context_set_at = ?
          WHERE token_hash = ?'
    )->execute([$instituteId, $roleId, now_utc(), hash('sha256', $tok)]);
}

function context_clear(): void
{
    $tok = $_COOKIE[SESSION_COOKIE] ?? '';
    if ($tok === '' || !preg_match('/^[a-f0-9]{64}$/', $tok)) return;

    db()->prepare(
        'UPDATE session_token SET active_institute_id = NULL, active_role_id = NULL, context_set_at = NULL
          WHERE token_hash = ?'
    )->execute([hash('sha256', $tok)]);
}

/** گزینه‌های انتخابگر، به شکلی که رابط می‌خواهد */
function context_options(): array
{
    return array_map(fn($m) => [
        'membershipId' => $m['membership_id'],
        'instituteId'  => $m['institute_id'],
        'institute'    => $m['institute_name'],
        'roleId'       => $m['role_id'],
        'role'         => $m['role_name'],
        'roleKey'      => $m['role_key'],
        'expiresAt'    => $m['expires_at'],
        'readonly'     => $m['plan'] === 'readonly',
    ], my_memberships());
}

function is_readonly_institute(): bool
{
    return active_context()['plan'] === 'readonly';
}

/* ═════════════════════ مجوزهای مؤثر ═════════════════════ */

/**
 * مجوز مؤثر = (بستهٔ نقش ∪ اعطای موردی) − سلب موردی
 *
 * سلب همیشه برنده است، بی‌استثنا — حتی بر اعطای صریح. هر استثنایی روی
 * این قاعده، «این یکی نبیند» را غیرقابل‌اتکا می‌کند.
 *
 * @return array<string,string>  کلید مجوز ← محدوده
 */
function effective_perms(): array
{
    static $eff = null;
    if ($eff !== null) return $eff;

    $ac = active_context();
    $out = [];

    // ── لایهٔ یک: بستهٔ نقش ──
    $st = db()->prepare('SELECT perm_key, scope FROM role_permission WHERE role_id = ?');
    $st->execute([$ac['role_id']]);
    foreach ($st->fetchAll() as $r) {
        $out[(string)$r['perm_key']] = (string)$r['scope'];
    }

    // ── لایه‌های دو و سه: اعطا و سلب موردی ──
    // هر دو در یک کوئری می‌آیند تا رفت‌وبرگشت اضافه نشود، ولی سلب‌ها
    // در پایان اعمال می‌شوند تا ترتیب ردیف‌ها روی نتیجه اثر نگذارد.
    $st = db()->prepare(
        'SELECT perm_key, effect, scope FROM user_permission
          WHERE institute_id = ? AND user_id = ?
            AND (expires_at IS NULL OR expires_at > ?)'
    );
    $st->execute([$ac['institute_id'], $ac['user_id'], now_utc()]);

    $denied = [];
    foreach ($st->fetchAll() as $r) {
        $k = (string)$r['perm_key'];
        if ((string)$r['effect'] === 'deny') {
            $denied[$k] = true;
            continue;
        }
        // اعطای موردی می‌تواند محدوده را گشادتر کند، ولی هرگز باریک‌تر
        $out[$k] = widest_scope($out[$k] ?? null, $r['scope'] !== null ? (string)$r['scope'] : null);
    }

    foreach (array_keys($denied) as $k) {
        unset($out[$k]);
    }

    $eff = $out;
    return $eff;
}

/* ═════════════════════ رابط عمومی ═════════════════════ */

/** بررسی نرم — برای ساختن منو، بدون پرتاب خطا */
function can(string $perm): bool
{
    return isset(effective_perms()[$perm]);
}

/** محدودهٔ این مجوز در زمینهٔ فعال، یا null اگر مجوز ندارد */
function perm_scope(string $perm): ?string
{
    return effective_perms()[$perm] ?? null;
}

/**
 * تصمیم سخت. چند مجوز یعنی «هرکدام کافی است»، نه «همه لازم است» —
 * چون الگوی رایج در این کد require_role('manager','teacher') است که
 * همین معنا را دارد.
 */
function require_perm(string ...$perms): void
{
    $eff = effective_perms();

    $hit = null;
    foreach ($perms as $p) {
        if (isset($eff[$p])) { $hit = $p; break; }
    }

    if ($hit === null) {
        fail(403, 'forbidden', 'این کار در دسترس شما نیست.');
    }

    /*
     * آموزشگاه فقط-خواندنی (پایان دمو): مجوزهای نوشتنی رد می‌شوند،
     * خواندنی‌ها عبور می‌کنند. پیام عمدی و مشخص است — کاربر باید بداند
     * چه اتفاقی افتاده و راه برگشت چیست، نه اینکه فکر کند خراب شده.
     */
    if (is_readonly_institute() && perm_is_write($hit)) {
        fail(403, 'readonly',
            'دورهٔ آزمایشی این آموزشگاه تمام شده. اطلاعات‌تان سر جایش است و می‌بینیدش، '
          . 'ولی برای ثبت تغییر تازه باید اشتراک را فعال کنید.');
    }
}

/** همهٔ مجوزها لازم است، نه یکی */
function require_all_perms(string ...$perms): void
{
    foreach ($perms as $p) {
        require_perm($p);
    }
}

/**
 * آیا این مجوز نوشتنی است؟ از کاتالوگ خوانده می‌شود، نه از روی نام —
 * چون حدس‌زدن از روی پسوند (create/edit/…) دیر یا زود اشتباه می‌شود.
 */
function perm_is_write(string $perm): bool
{
    static $cache = [];
    if (array_key_exists($perm, $cache)) return $cache[$perm];

    $st = db()->prepare('SELECT is_write FROM permission WHERE perm_key = ?');
    $st->execute([$perm]);
    $v = $st->fetchColumn();

    // مجوز ناشناخته را نوشتنی فرض می‌کنیم؛ سخت‌گیری امن‌تر از سهل‌گیری است
    $cache[$perm] = $v === false ? true : ((int)$v === 1);
    return $cache[$perm];
}

/* ═════════════════════ فیلتر محدوده ═════════════════════ */

/**
 * آیا این کاربر همین حالا اجازهٔ ساخت یا شروع جلسهٔ میت را دارد؟
 *
 * یک تابع، چون پیش از این دو جا دو جور تصمیم می‌گرفتند و هر دو غلط
 * بودند:
 *
 *   classes.php فقط can_host_meeting() را می‌دید. آن پرچم برای مدیرها
 *   هرگز نوشته نمی‌شد (مهاجرت ۰۱۳ را ببینید)، پس مدیر نمی‌توانست کلاس
 *   جیتسی بسازد و پیام خطا هم به او می‌گفت «از مدیر آموزشگاه بخواهید»
 *   — یعنی از خودش.
 *
 *   sessions.php برعکس، هرکسی را که محدوده‌اش institute بود کاملاً
 *   معاف می‌کرد و دیگر به jitsi_enabled نگاه نمی‌کرد. نتیجه این بود
 *   که کلید خاموشیِ سوپرادمین جلوی مدیر را نمی‌گرفت، با اینکه در
 *   super.php صریح نوشته شده «حتی برای مدیر».
 *
 * ترتیب اینجا عمدی است: کلید آموزشگاه مطلق است و هیچ محدوده‌ای از آن
 * معاف نمی‌کند. بعد از آن، یا پرچم شخصی، یا محدوده‌ای که کل آموزشگاه
 * را می‌گیرد — تا نقش سفارشی هم درست بیفتد، نه فقط نقشِ نامِ «مدیر».
 */
function jitsi_allowed(): bool
{
    if (!ctx()['institute']['jitsiEnabled']) return false;
    if (!empty(ctx()['can_host_meeting']))   return true;
    return scope_rank(perm_scope('session.start_meeting') ?? 'own')
           >= scope_rank('institute');
}

/**
 * مقدار can_host_meeting برای عضویتی که تازه ساخته می‌شود.
 *
 * مدیر از ابتدا دارد؛ مدرس باید از مدیر بگیرد. این یک خط جای نُه
 * تصمیم پراکنده را گرفته: پیش از این هیچ‌کدام از INSERTهای membership
 * این ستون را نمی‌نوشتند و همه پیش‌فرضِ صفر می‌گرفتند — مهاجرت ۰۱۳ را
 * ببینید. tests/membership-meeting-access.py نمی‌گذارد دوباره جا بیفتد.
 */
function default_can_host_meeting(string $role): int
{
    return $role === 'manager' ? 1 : 0;
}

/**
 * چرا اجازه ندارد — به زبانی که کاری از دست خواننده برآید.
 *
 * پیام قبلی به همه یک چیز می‌گفت: «از مدیر آموزشگاه بخواهید فعالش
 * کند». برای مدرس درست بود، برای مدیر بی‌معنا (خودش مدیر است) و برای
 * وقتی که سوپرادمین کل قابلیت را بسته بود، گمراه‌کننده — مدیر می‌رفت
 * دنبال کلیدی که در پنل خودش وجود ندارد.
 */
function jitsi_denied_message(): string
{
    if (!ctx()['institute']['jitsiEnabled']) {
        return 'جلسهٔ میت برای آموزشگاه شما فعال نیست. '
             . 'برای روشن‌کردنش با پشتیبانی تاکورا تماس بگیرید.';
    }
    return 'اجازهٔ جلسهٔ میت را ندارید. از مدیر آموزشگاه بخواهید در '
         . 'صفحهٔ اعضا برایتان فعالش کند.';
}

/**
 * قطعهٔ SQL که محدودهٔ داده را روی جدول کلاس اعمال می‌کند.
 *
 * شرط آموزشگاه جداگانه و از راه t_sql() اضافه می‌شود؛ این تابع فقط
 * لایهٔ باریک‌ترکننده را می‌سازد. برمی‌گرداند [قطعهٔ SQL، آرگومان‌ها].
 *
 * institute و بالاتر قطعه‌ای اضافه نمی‌کنند چون خود t_sql() آموزشگاه
 * را بسته است. branch تا وقتی جدول شعبه خالی است، همان رفتار را دارد.
 *
 * @param string $alias نام مستعار جدول klass در کوئری، مثلاً 'k'
 * @return array{0:string,1:array}
 */
function class_scope_sql(string $scope, string $alias = 'k'): array
{
    $a  = $alias === '' ? '' : $alias . '.';
    $me = active_context()['user_id'];

    switch ($scope) {
        case 'platform':
        case 'institute':
        case 'branch':
            return ['', []];

        case 'own_classes':
        case 'assigned_students':
            return [" AND {$a}teacher_user_id = ?", [$me]];

        case 'own':
        default:
            // زبان‌آموز: فقط کلاسی که در آن ثبت‌نام فعال دارد
            return [
                " AND {$a}id IN (SELECT class_id FROM enrolment
                                  WHERE student_user_id = ? AND status = 'active')",
                [$me],
            ];
    }
}

/**
 * همان برای فهرست کاربران آموزشگاه.
 *
 * assigned_students یعنی «زبان‌آموزان کلاس‌های خودم» — همان چیزی که
 * مدرس برای صفحهٔ پشتیبانی و گفت‌وگو لازم دارد و بیش از آن نه.
 *
 * @param string $col ستونی که شناسهٔ کاربر در آن است
 * @return array{0:string,1:array}
 */
function user_scope_sql(string $scope, string $col = 'm.user_id'): array
{
    $me = active_context()['user_id'];

    switch ($scope) {
        case 'platform':
        case 'institute':
        case 'branch':
            return ['', []];

        case 'own_classes':
        case 'assigned_students':
            return [
                " AND {$col} IN (SELECT e.student_user_id FROM enrolment e
                                   JOIN klass k ON k.id = e.class_id
                                  WHERE k.teacher_user_id = ? AND e.status = 'active')",
                [$me],
            ];

        case 'own':
        default:
            return [" AND {$col} = ?", [$me]];
    }
}

/**
 * مجوزی که روی دادهٔ یک کاربر مشخص اعمال می‌شود.
 *
 * ═══ چرا این تابع لازم شد ═══
 *
 * require_perm() تنها می‌پرسد «آیا این مجوز را دارد؟» و این برای
 * مجوزهایی که روی شخص دیگری اعمال می‌شوند کافی نیست. آزمون هم‌ارزی
 * فاز ۳ دقیقاً همین را گرفت: زبان‌آموز مجوز attendance.view را دارد
 * (با محدودهٔ own)، پس require_perm تنهایی پاس می‌شد و می‌توانست
 * تاریخچهٔ حضور زبان‌آموز دیگری را ببیند — چیزی که پیش از تبدیل ممکن
 * نبود. مجوز بدون محدوده، مجوز نیست.
 *
 * ضمناً یک شکاف قدیمی را هم می‌بندد: پیش از این، مدرس با
 * require_role('manager','teacher') می‌توانست حضور و غیاب *هر*
 * زبان‌آموز آموزشگاه را ببیند، نه فقط زبان‌آموزان کلاس خودش.
 */
function require_perm_on_user(string $perm, string $subjectUserId): void
{
    require_perm($perm);

    $ac = active_context();
    if ($subjectUserId === $ac['user_id']) return;      // دادهٔ خودش، همیشه

    $scope = perm_scope($perm) ?? 'own';

    if ($scope === 'own') {
        fail(403, 'out_of_scope', 'شما فقط به اطلاعات خودتان دسترسی دارید.');
    }

    if ($scope === 'own_classes' || $scope === 'assigned_students') {
        $st = db()->prepare(
            'SELECT 1 FROM enrolment e
               JOIN klass k ON k.id = e.class_id
              WHERE e.student_user_id = ? AND k.teacher_user_id = ?
                AND e.status = ? AND e.institute_id = ? LIMIT 1'
        );
        $st->execute([$subjectUserId, $ac['user_id'], 'active', $ac['institute_id']]);
        if (!$st->fetchColumn()) {
            fail(403, 'out_of_scope', 'این زبان‌آموز در کلاس‌های شما نیست.');
        }
    }

    // institute و بالاتر: شرط آموزشگاه را خود t_sql() می‌بندد
}

/* ═════════════════════ تفکیک وظایف ═════════════════════ */

/**
 * جلوی کاری را می‌گیرد که کاربر روی دادهٔ خودش نباید بکند.
 *
 * تا وقتی چند-نقشی نبود این حالت ممکن نبود. با فاز ۴ ممکن می‌شود:
 * مدرسی که در کلاس همکارش زبان‌آموز است، نباید بتواند تکلیف خودش را
 * تصحیح کند یا نمرهٔ خودش را ثبت کند — حتی با داشتن هر دو مجوز.
 *
 * از حالا نوشته می‌شود تا فاز ۳ که نقاط تصحیح و نمره را تبدیل می‌کند،
 * جایش آماده باشد.
 */
function deny_self_action(string $subjectUserId, string $what = 'این کار'): void
{
    if ($subjectUserId === active_context()['user_id']) {
        fail(409, 'self_action', $what . ' روی دادهٔ خودتان ممکن نیست.');
    }
}

/* ═════════════════════ ابزار مهاجرت ═════════════════════ */

/**
 * گزارش هم‌ارزی — ابزار فاز ۳، نه نقطهٔ پایانی عمومی.
 *
 * برای یک نقش مشخص، مجموعهٔ مجوزهایش را برمی‌گرداند تا بشود با
 * رفتار require_role() امروز مقایسه کرد. پیش از حذف هر بررسی قدیمی،
 * تصمیم قدیم و جدید باید یکی باشد؛ اولین تفاوت یعنی توقف، نه انتشار.
 *
 * @return array<string,string>
 */
function role_perm_map(string $roleId): array
{
    $st = db()->prepare('SELECT perm_key, scope FROM role_permission WHERE role_id = ? ORDER BY perm_key');
    $st->execute([$roleId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(string)$r['perm_key']] = (string)$r['scope'];
    }
    return $out;
}

/**
 * شناسهٔ نقش سامانه‌ای از روی نام نقش قدیمی.
 *
 * از مهاجرت ۰۰۶ به این‌سو، membership.role_id ناتهی است: هر عضویت
 * باید بگوید کدام بستهٔ مجوز را دارد، وگرنه موتور مجوز چیزی برای
 * خواندن ندارد. ولی پنج جای کد هنوز عضویت را بدون role_id می‌ساختند
 * و روی MySQL با STRICT_TRANS_TABLES — یعنی هر هاست معمولی — خطای
 * «Field 'role_id' doesn't have a default value» می‌گرفتند. پس دعوت
 * عضو، ورود با پیامک و ساخت فضای کاری همگی ۵۰۰ می‌دادند.
 *
 * راه‌حل عمداً ستون پیش‌فرض نیست: پیش‌فرض یعنی مدرسی که role_id‌اش
 * جا افتاده، بی‌صدا مجوزهای زبان‌آموز بگیرد. اینجا اگر نقش پیدا نشود
 * استثنا می‌دهد — عضویتِ نساخته بهتر از عضویتِ با دسترسی اشتباه است.
 */
function system_role_id(string $role): string
{
    static $cache = [];
    if (isset($cache[$role])) return $cache[$role];

    $st = db()->prepare("SELECT id FROM role WHERE role_key = ? AND is_system = 1 AND institute_id = ''");
    $st->execute([$role]);
    $id = $st->fetchColumn();
    if (!$id) {
        throw new RuntimeException("نقش سامانه‌ای «{$role}» در جدول role نیست — مهاجرت ۰۰۶ اجرا شده؟");
    }
    return $cache[$role] = (string)$id;
}
