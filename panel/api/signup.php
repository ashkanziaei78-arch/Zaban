<?php
/**
 * ثبت‌نام — جدا از ورود.
 *
 * ═══ چرا جدا از login.php ═══
 *
 * ورود و ثبت‌نام دو کار متفاوت با دو حالت ذهنی متفاوت‌اند. کسی که
 * می‌خواهد وارد شود دو فیلد می‌خواهد و تمام؛ کسی که ثبت‌نام می‌کند
 * باید بفهمد چه انتخاب‌هایی دارد. چپاندن هر دو در یک فرم یعنی هر دو
 * گروه گیج می‌شوند.
 *
 * ═══ دو راه پیوستن ═══
 *
 * کد پیوستن   — فوری. آموزشگاه کدی پخش کرده و هرکس واردش کند عضو
 *               می‌شود. برای ثبت‌نام گروهی سر ترم، تنها راه عملی.
 * درخواست     — کندتر ولی امن. در صف تأیید مدیر می‌نشیند.
 *
 * مدیر آموزشگاه هیچ‌کدام را لازم ندارد: خودش آموزشگاه می‌سازد.
 *
 * ═══ چرا شماره هم نام کاربری است ═══
 *
 * چون هر کاربر ایرانی شماره‌اش را از بر است و فراموشش نمی‌کند، و چون
 * کد یک‌بارمصرف هم روی همان شماره می‌رود. یک شناسه به‌جای دو تا.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_ctx.php';
require __DIR__ . '/_creds.php';

require_post();

$in     = body_json();
$action = s_in($in, 'action', 40);

switch ($action) {

/* ─────────── فهرست آموزشگاه‌هایی که درخواست می‌پذیرند ─────────── */
/*
 * برای صفحهٔ ثبت‌نام لازم است تا کاربرِ بی‌کد بتواند آموزشگاهش را
 * انتخاب کند. فقط نام و شهر برمی‌گردد — نه شماره، نه شمار اعضا، نه
 * هیچ چیزی که فهرست را به دادهٔ قابل‌برداشت تبدیل کند.
 */
case 'institutes':
    $q    = s_in($in, 'q', 80);
    $sql  = "SELECT id, name, city FROM institute
              WHERE status = 'active' AND accepts_requests = 1";
    $args = [];
    if ($q !== '') {
        $sql .= ' AND (name LIKE ? OR city LIKE ?)';
        $like = '%' . $q . '%';
        array_push($args, $like, $like);
    }
    $sql .= ' ORDER BY name LIMIT 40';
    $st = db()->prepare($sql);
    $st->execute($args);

    ok(['institutes' => array_map(fn($r) => [
        'id'   => (string)$r['id'],
        'name' => (string)$r['name'],
        'city' => $r['city'],
    ], $st->fetchAll())]);

/* ─────────── بررسی کد پیوستن پیش از ثبت‌نام ─────────── */
/*
 * تا کاربر پیش از پرکردن کل فرم بداند کدش درست است. بدون این، تمام
 * فرم را پر می‌کند و در آخر می‌فهمد کد اشتباه بوده.
 */
case 'checkCode':
    $code = normalize_code(s_in($in, 'code', 12));
    if ($code === '') fail(400, 'invalid', 'کد را وارد کنید.');

    $inst = find_by_code($code);
    if (!$inst) fail(404, 'bad_code', 'چنین کدی پیدا نشد یا دیگر فعال نیست.');

    ok(['institute' => [
        'name' => (string)$inst['name'],
        'city' => $inst['city'],
        'role' => (string)$inst['join_code_role'],
    ]]);

/* ─────────── ثبت‌نام ─────────── */
case 'register':
    $phone = normalize_phone(s_in($in, 'phone', 20));
    if ($phone === null) fail(400, 'bad_phone', 'شمارهٔ موبایل درست نیست.');

    $pass = (string)($in['password'] ?? '');
    if (mb_strlen($pass) < 8) {
        fail(400, 'weak_pass', 'رمز عبور باید دست‌کم ۸ نویسه باشد.');
    }

    $mode = enum_in($in, 'mode', ['manager', 'code', 'request'], 'request');

    /*
     * سقف تلاش روی IP، نه روی شماره. سقف روی شماره جلوی مهاجم را
     * نمی‌گیرد چون هر بار شمارهٔ تازه‌ای می‌فرستد؛ سقف روی IP می‌گیرد.
     *
     * ولی سقف یکسان برای همهٔ مسیرها غلط است: آموزشگاهی که سر ترم سی
     * زبان‌آموز را با وای‌فای خودش ثبت‌نام می‌کند، همه از یک IP می‌آیند
     * و با سقف تنگ بعد از نفر دهم قفل می‌شوند — یعنی محدودکننده به‌جای
     * مهاجم، مشتری را می‌گیرد.
     *
     * پس سقف به مسیر بستگی دارد. مسیر کد، خودش پشت یک راز است: بدون
     * دانستن کد آموزشگاه هیچ ثبت‌نامی انجام نمی‌شود، پس سخاوتمندانه‌تر
     * است. مسیرهای باز — ساخت آموزشگاه و درخواست پیوستن — تنگ می‌مانند
     * چون هرکسی می‌تواند صدایشان بزند.
     */
    $capPerHour = $mode === 'code' ? 60 : 15;
    if (!rate_ok('signup_' . $mode, client_ip(), $capPerHour, 3600)) {
        fail(429, 'rate_limited',
            $mode === 'code'
              ? 'ثبت‌نام‌های زیاد از این شبکه. کمی صبر کنید و دوباره امتحان کنید.'
              : 'تلاش‌های زیاد. یک ساعت دیگر امتحان کنید، یا اگر کد پیوستن دارید از آن استفاده کنید.');
    }

    // ── نام ──
    $fnFa = s_in($in, 'firstNameFa', 60);
    $lnFa = s_in($in, 'lastNameFa', 60);
    if ($fnFa === '' || $lnFa === '') {
        fail(400, 'invalid', 'نام و نام خانوادگی فارسی را وارد کنید.');
    }
    $fnEn = s_in($in, 'firstNameEn', 60);
    $lnEn = s_in($in, 'lastNameEn', 60);

    // ── کد ملی ──
    $nid = preg_replace('/\D/', '', en_digits(s_in($in, 'nationalId', 12)));
    if ($nid !== '' && !national_id_ok($nid)) {
        fail(400, 'bad_national_id', 'کد ملی معتبر نیست.');
    }

    // ── تاریخ تولد: شمسی می‌آید، میلادی ذخیره می‌شود ──
    $birth = null;
    $bRaw  = s_in($in, 'birthDate', 12);   // ۱۳۷۵-۰۴-۲۲ یا 1375-04-22
    if ($bRaw !== '') {
        $birth = jalali_to_gregorian_date(en_digits($bRaw));
        if ($birth === null) fail(400, 'bad_birth', 'تاریخ تولد درست نیست.');
    }

    $email = s_in($in, 'email', 160);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail(400, 'bad_email', 'آدرس ایمیل درست نیست.');
    }

    // ── مقصد، پیش از ساخت هر چیزی ──
    $inst = null;
    if ($mode === 'code') {
        $inst = find_by_code(normalize_code(s_in($in, 'code', 12)));
        if (!$inst) fail(404, 'bad_code', 'کد پیوستن پیدا نشد یا دیگر فعال نیست.');
    } elseif ($mode === 'request') {
        $iid = s_in($in, 'instituteId', 32);
        if ($iid === '') fail(400, 'invalid', 'آموزشگاه را انتخاب کنید.');
        $st = db()->prepare("SELECT * FROM institute WHERE id = ? AND status = 'active' AND accepts_requests = 1");
        $st->execute([$iid]);
        $inst = $st->fetch() ?: null;
        if (!$inst) fail(404, 'no_institute', 'این آموزشگاه درخواست نمی‌پذیرد.');
    }

    $wanted = enum_in($in, 'role', ['manager', 'teacher', 'student'], 'student');
    if ($mode === 'code')    $wanted = (string)$inst['join_code_role'];
    if ($mode === 'manager') $wanted = 'manager';

    $instName = $mode === 'manager' ? s_in($in, 'instituteName', 160) : '';
    if ($mode === 'manager' && $instName === '') {
        fail(400, 'invalid', 'نام آموزشگاه را وارد کنید.');
    }

    /*
     * شمارهٔ تکراری: پیام عمداً مبهم نیست. پنهان‌کردنش کاربری را که
     * فراموش کرده قبلاً ثبت‌نام کرده سرگردان می‌کند، و مهاجم هم با
     * تلاش ورود همین را می‌فهمد — پس چیزی را پنهان نکرده‌ایم.
     */
    $dup = db()->prepare('SELECT id FROM app_user WHERE phone = ?');
    $dup->execute([$phone]);
    if ($dup->fetchColumn()) {
        fail(409, 'phone_taken', 'با این شماره قبلاً ثبت‌نام شده. از صفحهٔ ورود وارد شوید یا رمزتان را بازیابی کنید.');
    }

    if ($nid !== '') {
        $dn = db()->prepare('SELECT id FROM app_user WHERE national_id = ?');
        $dn->execute([$nid]);
        if ($dn->fetchColumn()) fail(409, 'nid_taken', 'این کد ملی قبلاً ثبت شده.');
    }

    $db  = db();
    $now = now_utc();
    $uid = new_id();

    $db->beginTransaction();
    try {
        $db->prepare(
            'INSERT INTO app_user
               (id, phone, full_name, first_name_fa, last_name_fa, first_name_en, last_name_en,
                national_id, birth_date, gender, email, city, role, signup_role, status,
                username, pass_hash, profile_done, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)'
        )->execute([
            $uid, $phone, trim($fnFa . ' ' . $lnFa),
            $fnFa, $lnFa, $fnEn ?: null, $lnEn ?: null,
            $nid ?: null, $birth,
            enum_in($in, 'gender', ['male', 'female', 'other'], 'other'),
            $email ?: null, s_in($in, 'city', 80) ?: null,
            $wanted, $wanted, 'active',
            $phone, password_hash($pass, PASSWORD_DEFAULT), $now,
        ]);

        if ($mode === 'manager') {
            $iid = new_id();
            $db->prepare('INSERT INTO institute (id, name, owner_user_id, phone, created_at) VALUES (?,?,?,?,?)')
               ->execute([$iid, $instName, $uid, $phone, $now]);
            $db->prepare(
                'INSERT INTO membership (id, institute_id, user_id, role, role_id, status, can_host_meeting, created_at)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([new_id(), $iid, $uid, 'manager', system_role_id('manager'), 'active',
                        default_can_host_meeting('manager'), $now]);
            $outcome = 'manager';

        } elseif ($mode === 'code') {
            $db->prepare(
                'INSERT INTO membership (id, institute_id, user_id, role, role_id, status, can_host_meeting, granted_reason, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([new_id(), (string)$inst['id'], $uid, $wanted, system_role_id($wanted),
                        'active', default_can_host_meeting($wanted), 'پیوستن با کد', $now]);
            $outcome = 'joined';

        } else {
            $db->prepare(
                'INSERT INTO join_request (id, institute_id, user_id, wanted_role, message, status, created_at)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([new_id(), (string)$inst['id'], $uid, $wanted,
                        s_in($in, 'message', 500) ?: null, 'pending', $now]);
            $outcome = 'pending';
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('signup failed: ' . $e->getMessage());
        fail(500, 'signup_failed', 'ثبت‌نام انجام نشد. دوباره تلاش کنید.');
    }

    audit('user.registered', $uid, [
        'mode' => $mode, 'role' => $wanted,
        'institute' => $inst ? (string)$inst['id'] : ($iid ?? null),
    ]);

    // نشست بلافاصله صادر می‌شود؛ حتی کسی که در صف است باید بتواند
    // وارد شود و وضعیت درخواستش را ببیند
    issue_session($uid);

    ok([
        'outcome'   => $outcome,
        'institute' => $inst ? (string)$inst['name'] : $instName,
        'role'      => $wanted,
    ]);

default:
    fail(400, 'unknown_action', 'درخواست نامشخص.');
}

/* ═════════════════════ کمکی ═════════════════════ */

function normalize_code(string $c): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', en_digits($c)));
}

function find_by_code(string $code): ?array
{
    if ($code === '') return null;
    $st = db()->prepare(
        "SELECT * FROM institute
          WHERE join_code = ? AND join_code_active = 1 AND status = 'active'"
    );
    $st->execute([$code]);
    return $st->fetch() ?: null;
}

/**
 * کد ملی ایرانی: ده رقم با رقم کنترلی.
 *
 * شمردن رقم‌ها کافی نیست — «۱۲۳۴۵۶۷۸۹۰» ده رقم دارد ولی کد ملی
 * نیست. الگوریتم رسمی: مجموع وزنی نه رقم اول، باقی‌مانده بر ۱۱، و
 * مقایسه با رقم دهم.
 */
function national_id_ok(string $nid): bool
{
    if (!preg_match('/^\d{10}$/', $nid)) return false;

    // ارقام یکسان (۰۰۰۰۰۰۰۰۰۰ تا ۹۹۹۹۹۹۹۹۹۹) از فیلتر چک‌سام رد
    // می‌شوند ولی کد ملی واقعی نیستند
    if (preg_match('/^(\d)\1{9}$/', $nid)) return false;

    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $sum += (int)$nid[$i] * (10 - $i);
    }
    $r = $sum % 11;
    $check = (int)$nid[9];

    return $r < 2 ? $check === $r : $check === 11 - $r;
}

/**
 * تاریخ شمسی «۱۳۷۵-۰۴-۲۲» به میلادی «1996-07-12».
 *
 * ذخیره میلادی است و نمایش شمسی (بند P.2). اگر شمسی ذخیره می‌شد، هر
 * محاسبهٔ سنی و هر مرتب‌سازی تاریخی باید اول تبدیل می‌کرد.
 */
function jalali_to_gregorian_date(string $s): ?string
{
    if (!preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', trim($s), $m)) return null;
    [$jy, $jm, $jd] = [(int)$m[1], (int)$m[2], (int)$m[3]];

    if ($jy < 1200 || $jy > 1500 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) return null;
    if ($jm > 6 && $jd > 30) return null;

    /*
     * اسفند ۳۰ فقط در سال کبیسه هست.
     *
     * بدون این بررسی، «۱۳۷۶-۱۲-۳۰» رد نمی‌شد بلکه بی‌صدا به فروردین
     * ۱۳۷۷ تبدیل می‌شد — یعنی تاریخ تولدِ غلط ذخیره می‌شد و کاربر
     * هیچ‌وقت نمی‌فهمید. خطای خاموش بدتر از خطای پیداست.
     *
     * چرخهٔ ۳۳ ساله، همان که تبدیل پایین بر پایه‌اش نوشته شده: سالی
     * کبیسه است که باقی‌ماندهٔ آن بر ۴ صفر شود، جز سال سی‌ودوم چرخه که
     * مرزِ چرخه آن را می‌خورد.
     */
    if ($jm === 12 && $jd === 30) {
        $r = (($jy - 979) % 33 + 33) % 33;
        if ($r % 4 !== 0 || $r === 32) return null;
    }

    $jy -= 979;
    $jd_total = 365 * $jy + intdiv($jy, 33) * 8 + intdiv(($jy % 33) + 3, 4);
    for ($i = 0; $i < $jm - 1; $i++) {
        $jd_total += $i < 6 ? 31 : 30;
    }
    $jd_total += $jd - 1;

    $gd_total = $jd_total + 79;
    $gy = 1600 + 400 * intdiv($gd_total, 146097);
    $gd_total %= 146097;

    $leap = true;
    if ($gd_total >= 36525) {
        $gd_total--;
        $gy += 100 * intdiv($gd_total, 36524);
        $gd_total %= 36524;
        if ($gd_total >= 365) $gd_total++;
        else $leap = false;
    }
    $gy += 4 * intdiv($gd_total, 1461);
    $gd_total %= 1461;
    if ($gd_total >= 366) {
        $leap = false;
        $gd_total--;
        $gy += intdiv($gd_total, 365);
        $gd_total %= 365;
    }

    $months = [31, ($leap ? 29 : 28), 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $gm = 0;
    while ($gm < 12 && $gd_total >= $months[$gm]) {
        $gd_total -= $months[$gm];
        $gm++;
    }

    return sprintf('%04d-%02d-%02d', $gy, $gm + 1, $gd_total + 1);
}
