<?php
/**
 * نصب‌کنندهٔ تاکورا — یک‌بار اجرا، بعد خودش را قفل می‌کند.
 *
 * ═══ چرا این فایل وجود دارد ═══
 *
 * تا پیش از این، راه‌اندازی یعنی: ساخت دیتابیس در پلسک، باز کردن
 * phpMyAdmin، چسباندن ۳۰۰ خط SQL، تغییر نام config.sample.php،
 * ویرایش دستی‌اش با FTP، ساختن دو رشتهٔ تصادفی با openssl، و بعد
 * زدن کلید راه‌اندازی در پنل سوپرادمین. هر کدام از این‌ها یک‌جا برای
 * خراب‌شدن دارد و رایج‌ترین‌شان — پیشوندی که پلسک به نام دیتابیس
 * اضافه می‌کند — تا مرحلهٔ آخر خودش را نشان نمی‌دهد.
 *
 * حالا همهٔ این‌ها یک فرم است. کاربر فقط چهار مقدار اتصال دیتابیس
 * را که پلسک نشان داده کپی می‌کند.
 *
 * ═══ چرا این فایل خطرناک نیست ═══
 *
 * نگرانی طبیعی: «نصب‌کنندهٔ باز روی اینترنت یعنی هرکسی سایت را
 * بردارد.» سه چیز جلویش را می‌گیرد:
 *
 *  ۱) نصب بدون رمز دیتابیس ممکن نیست. مهاجم آن را ندارد.
 *  ۲) به محض اینکه فایل پیکربندی ساخته شد، این فایل هر درخواستی را
 *     رد می‌کند — حتی اگر پاکش نکنید.
 *  ۳) اگر پیکربندی نبود ولی جدول admin_user کاربر داشت (یعنی یک نصب
 *     قبلی)، باز هم رد می‌کند.
 *
 * با این حال صفحهٔ پایان می‌گوید پوشهٔ setup/ را پاک کنید — دفاع در
 * لایه، نه اتکا به یک بررسی.
 *
 * این فایل عمداً _bootstrap.php را require نمی‌کند: آن فایل به
 * config.php نیاز دارد که هنوز وجود ندارد.
 */

declare(strict_types=1);

/*
 * محافظ دسترسی مستقیم اینجا *برعکس* بقیهٔ فایل‌هاست: install.php
 * قرار است مستقیم صدا زده شود. ولی همچنان فقط POST با JSON.
 */
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

const INSTALL_PRIVATE = __DIR__ . '/../../private/talkora-config.php';
const INSTALL_LOCAL   = __DIR__ . '/config.php';

function ins_out(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ins_fail(int $status, string $code, string $msg, array $extra = []): never
{
    ins_out($status, ['ok' => false, 'error' => $code, 'message' => $msg] + $extra);
}

/** مسیر فایل پیکربندیِ موجود، یا null */
function ins_config_path(): ?string
{
    foreach ([INSTALL_PRIVATE, INSTALL_LOCAL] as $p) {
        if (is_file($p)) return $p;
    }
    return null;
}

/* ─────────── قفل ─────────── */

/*
 * هر مسیری از این فایل — حتی «بررسی سرور» — بعد از نصب بسته است.
 * دلیلش این است که probe نسخهٔ PHP و فهرست افزونه‌ها را می‌گوید و
 * لازم نیست این را برای همیشه به دنیا نشان دهیم.
 */
$existing = ins_config_path();
if ($existing !== null) {
    ins_out(200, [
        'ok'        => false,
        'error'     => 'already_installed',
        'installed' => true,
        'message'   => 'تاکورا از قبل نصب شده است. پوشهٔ setup/ را از روی هاست پاک کنید.',
    ]);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    ins_fail(405, 'method_not_allowed', 'فقط POST.');
}

$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true);
if (!is_array($in)) $in = [];
$action = trim((string)($in['action'] ?? ''));

/* ─────────── ابزارها ─────────── */

function ins_pdo(array $d): PDO
{
    $host = trim((string)($d['host'] ?? 'localhost'));
    $name = trim((string)($d['name'] ?? ''));
    $user = (string)($d['user'] ?? '');
    $pass = (string)($d['pass'] ?? '');
    $port = (int)($d['port'] ?? 3306) ?: 3306;

    if ($name === '' || $user === '') {
        ins_fail(400, 'invalid', 'نام پایگاه داده و نام کاربری را وارد کنید.');
    }

    /*
     * پلسک گاهی «localhost:3306» یا یک IP می‌دهد. اگر پورت داخل نام
     * میزبان آمده بود جدایش می‌کنیم، وگرنه اتصال با پیام گنگ می‌شکند.
     */
    if (preg_match('/^(.+):(\d+)$/', $host, $m)) {
        $host = $m[1];
        $port = (int)$m[2];
    }

    return new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user, $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 8,
        ]
    );
}

/**
 * پیام‌های خطای MySQL انگلیسی و برای کاربر بی‌معنی‌اند. رایج‌ترین‌ها
 * را به چیزی ترجمه می‌کنیم که بگوید *کجا* را درست کند.
 */
function ins_db_hint(Throwable $e): string
{
    $m = $e->getMessage();
    if (str_contains($m, '1045')) {
        return 'نام کاربری یا رمز پایگاه داده درست نیست. در پلسک → Databases → کاربر را ببینید. '
             . 'اگر رمز را یادتان نیست، همان‌جا «Change Password» بزنید و رمز تازه را اینجا بگذارید.';
    }
    if (str_contains($m, '1049')) {
        return 'پایگاه داده‌ای با این نام پیدا نشد. پلسک معمولاً به نامی که تایپ کرده‌اید پیشوند اضافه می‌کند؛ '
             . 'دقیقاً همان نامی را بنویسید که در فهرست Databases می‌بینید.';
    }
    if (str_contains($m, '2002') || str_contains($m, '2003') || stripos($m, 'refused') !== false) {
        return 'به سرور پایگاه داده وصل نشد. میزبان را localhost بگذارید مگر اینکه پلسک چیز دیگری نوشته باشد.';
    }
    if (str_contains($m, '1044')) {
        /*
         * MySQL برای «دیتابیس وجود ندارد» و «کاربر به آن دسترسی ندارد»
         * همین یک خطا را می‌دهد، و روی پلسک تقریباً همیشه علت اولی است:
         * نام دیتابیس پیشوند دارد و کاربر بدون پیشوند تایپش کرده.
         */
        return 'این کاربر به آن پایگاه داده دسترسی ندارد — که تقریباً همیشه یعنی نام دیتابیس دقیق نیست. '
             . 'در پلسک → Databases همان نامی را که در فهرست می‌بینید کپی کنید (پلسک معمولاً پیشوند اضافه می‌کند). '
             . 'اگر نام درست است، در همان صفحه کاربر را به این دیتابیس وصل کنید.';
    }
    return 'اتصال برقرار نشد: ' . mb_substr($m, 0, 200);
}

/** متن اسکیما — از _schema.php (بستهٔ منتشرشده) یا فایل .sql (مخزن) */
function ins_schema_sql(): string
{
    if (is_file(__DIR__ . '/_schema.php')) {
        $sql = require __DIR__ . '/_schema.php';
        if (is_string($sql) && $sql !== '') return $sql;
    }
    if (is_file(__DIR__ . '/schema.mysql.sql')) {
        return (string)file_get_contents(__DIR__ . '/schema.mysql.sql');
    }
    ins_fail(500, 'no_schema', 'فایل ساختار پایگاه داده کنار install.php نیست. بستهٔ پنل را کامل آپلود کنید.');
}

/**
 * تقسیم به دستورهای جدا.
 *
 * اسکیمای ما نه رویه دارد نه تریگر نه رشته‌ای که «;» داشته باشد،
 * پس حذف توضیح‌های «--» و شکستن روی «;» کافی و درست است.
 */
function ins_split(string $sql): array
{
    /*
     * عمداً \R نیست.
     *
     * PCRE بدون پرچم u، \R را بایت‌به‌بایت می‌بیند و بایت 0x85 را هم
     * پایان خط می‌شمارد. در متن فارسی 0x85 بایت دومِ «م» است
     * (U+0645 → D9 85)، پس \R وسط حرف می‌شکست و توضیح‌های فارسی نیمه‌کاره
     * وارد SQL می‌شدند. نتیجه: خطای 1064 و نصب نیمه‌تمام.
     */
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

function ins_random(int $bytes): string { return bin2hex(random_bytes($bytes)); }

/**
 * فایل پیکربندی را می‌سازد.
 *
 * اول تلاش می‌کند بیرون از webroot بنویسد (private/ کنار httpdocs).
 * آنجا حتی اگر پیکربندی وب خراب شود و PHP اجرا نشود، رمزها از مرورگر
 * خوانده نمی‌شوند. اگر نشد، کنار خود api می‌نویسد — که با .htaccess و
 * محافظ داخل خود فایل‌ها همچنان بسته است.
 *
 * @return array{path:string, outside:bool}
 */
function ins_write_config(array $cfg): array
{
    $php = "<?php\n"
         . "// اگر مستقیم از مرورگر باز شود، چیزی برنگرداند\n"
         . "if (realpath(__FILE__) === realpath((string)(\$_SERVER['SCRIPT_FILENAME'] ?? ''))) { http_response_code(404); exit; }\n"
         . "/**\n"
         . " * پیکربندی تاکورا — ساختهٔ نصب‌کننده در " . gmdate('Y-m-d H:i') . " UTC.\n"
         . " *\n"
         . " * این فایل رمز پایگاه داده و کلید امضای کدهای ورود را دارد.\n"
         . " * هرگز در گیت نگذاریدش و برای کسی نفرستید.\n"
         . " */\n"
         . "return " . var_export($cfg, true) . ";\n";

    $privateDir = dirname(INSTALL_PRIVATE);
    if (!is_dir($privateDir)) @mkdir($privateDir, 0750, true);
    if (is_dir($privateDir) && is_writable($privateDir)
        && @file_put_contents(INSTALL_PRIVATE, $php) !== false) {
        @chmod(INSTALL_PRIVATE, 0600);
        return ['path' => INSTALL_PRIVATE, 'outside' => true];
    }

    if (@file_put_contents(INSTALL_LOCAL, $php) === false) {
        ins_fail(500, 'not_writable',
            'فایل پیکربندی نوشته نشد. در پلسک → File Manager روی پوشهٔ api دسترسی نوشتن بدهید و دوباره تلاش کنید.');
    }
    @chmod(INSTALL_LOCAL, 0600);
    return ['path' => INSTALL_LOCAL, 'outside' => false];
}

/* ─────────── کنش‌ها ─────────── */

switch ($action) {

/*
 * بررسی سرور — قبل از اینکه کاربر چیزی تایپ کند.
 * هدف این است که «چرا کار نمی‌کند؟» پیش از نصب پیدا شود، نه بعدش.
 */
case 'probe':
    $checks = [];
    $add = function (string $key, string $title, bool $pass, string $detail, bool $fatal = true)
                 use (&$checks): void {
        $checks[] = ['key' => $key, 'title' => $title, 'pass' => $pass, 'detail' => $detail, 'fatal' => $fatal];
    };

    $php = PHP_VERSION;
    $add('php', 'نسخهٔ PHP', PHP_VERSION_ID >= 80000, PHP_VERSION_ID >= 80000
        ? "PHP {$php} — مناسب"
        : "PHP {$php} است و تاکورا PHP 8 می‌خواهد. در پلسک → PHP Settings نسخه را روی ۸ بگذارید.");

    $add('pdo_mysql', 'اتصال به MySQL', extension_loaded('pdo_mysql'),
        extension_loaded('pdo_mysql') ? 'افزونهٔ pdo_mysql فعال است'
        : 'افزونهٔ pdo_mysql خاموش است. در پلسک → PHP Settings روشنش کنید.');

    $add('mbstring', 'پردازش متن فارسی', extension_loaded('mbstring'),
        extension_loaded('mbstring') ? 'افزونهٔ mbstring فعال است'
        : 'افزونهٔ mbstring خاموش است؛ بدون آن نام‌های فارسی بریده می‌شوند.');

    $add('curl', 'ارسال پیامک', extension_loaded('curl'),
        extension_loaded('curl') ? 'افزونهٔ curl فعال است'
        : 'افزونهٔ curl خاموش است. بدون آن همه‌چیز درست به نظر می‌رسد ولی هیچ پیامکی فرستاده نمی‌شود.',
        false);

    $add('openssl', 'ساخت کلید تصادفی', function_exists('random_bytes'),
        function_exists('random_bytes') ? 'random_bytes در دسترس است'
        : 'تولید عدد تصادفی امن در دسترس نیست.');

    $privateDir = dirname(INSTALL_PRIVATE);
    $canPrivate = (is_dir($privateDir) && is_writable($privateDir))
        || (!is_dir($privateDir) && is_writable(dirname($privateDir)));
    $add('private', 'جای امن پیکربندی', $canPrivate, $canPrivate
        ? 'پیکربندی بیرون از پوشهٔ وب نوشته می‌شود — امن‌ترین حالت'
        : 'پوشهٔ بیرون از وب قابل نوشتن نیست؛ پیکربندی کنار api ساخته می‌شود که هنوز امن است.',
        false);

    $add('writable', 'اجازهٔ نوشتن', is_writable(__DIR__) || $canPrivate,
        (is_writable(__DIR__) || $canPrivate) ? 'می‌توانم فایل پیکربندی را بسازم'
        : 'هیچ‌کدام از دو مسیر قابل نوشتن نیست. در File Manager به پوشهٔ api اجازهٔ نوشتن بدهید.');

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $add('https', 'گواهی SSL', $https, $https
        ? 'صفحه روی https باز شده'
        : 'این صفحه روی http باز شده. کوکی ورود پرچم Secure دارد، پس تا SSL روشن نشود '
        . 'کاربران بلافاصله از پنل بیرون می‌افتند. در پلسک → SSL/TLS Certificates گواهی Let\'s Encrypt بگیرید.',
        false);

    $fatal = 0;
    foreach ($checks as $c) if ($c['fatal'] && !$c['pass']) $fatal++;

    ins_out(200, [
        'ok'      => true,
        'checks'  => $checks,
        'ready'   => $fatal === 0,
        'host'    => (string)($_SERVER['HTTP_HOST'] ?? ''),
        'docroot' => __DIR__,
    ]);

/* آزمایش اتصال، بدون دست‌زدن به چیزی */
case 'testDb':
    try {
        $pdo = ins_pdo((array)($in['db'] ?? []));
    } catch (Throwable $e) {
        ins_fail(400, 'db_failed', ins_db_hint($e));
    }

    $tables = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $r) $tables[] = (string)$r[0];

    $ours   = array_intersect($tables, ['app_user', 'institute', 'admin_user']);
    $admins = 0;
    if (in_array('admin_user', $tables, true)) {
        $admins = (int)$pdo->query('SELECT COUNT(*) FROM admin_user')->fetchColumn();
    }

    ins_out(200, [
        'ok'       => true,
        'version'  => (string)$pdo->query('SELECT VERSION()')->fetchColumn(),
        'tables'   => count($tables),
        'hasOurs'  => count($ours) > 0,
        'admins'   => $admins,
        'message'  => count($ours) > 0
            ? 'اتصال برقرار شد. جدول‌های تاکورا از قبل در این پایگاه داده هستند؛ دوباره ساخته نمی‌شوند.'
            : 'اتصال برقرار شد و پایگاه داده خالی است.',
    ]);

/* نصب واقعی */
case 'run':
    try {
        $pdo = ins_pdo((array)($in['db'] ?? []));
    } catch (Throwable $e) {
        ins_fail(400, 'db_failed', ins_db_hint($e));
    }

    /*
     * اگر جدول ادمین قبلاً کاربر دارد یعنی یک نصب سالم روی همین
     * دیتابیس هست و فقط فایل پیکربندی گم شده. اجازهٔ ساخت ادمین تازه
     * نمی‌دهیم — وگرنه هرکسی که به این صفحه برسد می‌تواند خودش را
     * ادمین کند بدون اینکه رمز ادمینِ واقعی را بداند.
     */
    $hasAdmin = false;
    try {
        $hasAdmin = (int)$pdo->query('SELECT COUNT(*) FROM admin_user')->fetchColumn() > 0;
    } catch (Throwable $e) {
        // جدول هنوز نیست — یعنی نصب تازه
    }

    $user = strtolower(trim((string)($in['username'] ?? '')));
    $pass = (string)($in['password'] ?? '');

    if (!$hasAdmin) {
        if (!preg_match('/^[a-z0-9._-]{3,64}$/', $user)) {
            ins_fail(400, 'invalid_user',
                'نام کاربری فقط حروف انگلیسی کوچک، رقم، نقطه، زیرخط و خط تیره — بین ۳ تا ۶۴ نویسه.');
        }
        if (strlen($pass) < 10) {
            ins_fail(400, 'weak_pass', 'رمز باید حداقل ۱۰ نویسه باشد.');
        }
    }

    /* ── ساخت جدول‌ها ── */
    $made = 0;
    try {
        $pdo->exec('SET NAMES utf8mb4');
        foreach (ins_split(ins_schema_sql()) as $stmt) {
            if (stripos($stmt, 'SET NAMES') === 0) continue;
            $pdo->exec($stmt);
            $made++;
        }
    } catch (Throwable $e) {
        ins_fail(500, 'schema_failed',
            'ساخت جدول‌ها نیمه‌کاره ماند: ' . mb_substr($e->getMessage(), 0, 300));
    }

    /*
     * ستون pending_code در نسخه‌های اول نبود. CREATE TABLE IF NOT
     * EXISTS روی دیتابیسِ از قبل ساخته‌شده کاری نمی‌کند، پس جدا اضافه‌اش
     * می‌کنیم. اگر باشد، خطا را نادیده می‌گیریم.
     */
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM otp_code LIKE 'pending_code'")->fetchAll();
        if (!$cols) $pdo->exec('ALTER TABLE otp_code ADD COLUMN pending_code VARCHAR(8) NULL AFTER code_hash');
    } catch (Throwable $e) {
        error_log('pending_code alter: ' . $e->getMessage());
    }

    /*
     * نام کاربری و رمز هم بعداً اضافه شدند. روی نصب‌های موجود ستون‌ها
     * نیستند و بدون این‌ها ورود با رمز خطای SQL می‌دهد.
     */
    foreach ([
        'username'  => "ALTER TABLE app_user ADD COLUMN username VARCHAR(64) NULL AFTER status",
        'pass_hash' => "ALTER TABLE app_user ADD COLUMN pass_hash VARCHAR(255) NULL AFTER username",
    ] as $col => $sql) {
        try {
            if (!$pdo->query("SHOW COLUMNS FROM app_user LIKE '{$col}'")->fetchAll()) $pdo->exec($sql);
        } catch (Throwable $e) {
            error_log("{$col} alter: " . $e->getMessage());
        }
    }
    try {
        $idx = $pdo->query("SHOW INDEX FROM app_user WHERE Key_name = 'uq_user_username'")->fetchAll();
        if (!$idx) $pdo->exec('ALTER TABLE app_user ADD UNIQUE KEY uq_user_username (username)');
    } catch (Throwable $e) {
        error_log('username index: ' . $e->getMessage());
    }

    /* ── ادمین ── */
    if (!$hasAdmin) {
        $dup = $pdo->prepare('SELECT COUNT(*) FROM admin_user WHERE username = ?');
        $dup->execute([$user]);
        if ((int)$dup->fetchColumn() > 0) {
            ins_fail(409, 'dup_user', 'این نام کاربری از قبل هست. یکی دیگر انتخاب کنید.');
        }
        /*
         * این حساب مالک پلتفرم است — نه یک نقش، بلکه تنها حساب محافظت‌شدهٔ
         * کل سامانه. اینجا ساخته می‌شود و هیچ‌جای دیگری نه: پنل راهی برای
         * ساخت مالک دوم ندارد و ایندکس یکتای owner_lock هم اجازه‌اش را
         * نمی‌دهد.
         *
         * چرا اینجا و نه در اسکیما: اسکیما پیش از این نقطه اجرا می‌شود و
         * آن موقع جدول admin_user هنوز خالی است، پس UPDATE پایان اسکیما
         * (که برای پایگاه دادهٔ موجود نوشته شده) روی نصب تازه هیچ ردیفی
         * را نمی‌گیرد.
         */
        $pdo->prepare(
            'INSERT INTO admin_user (id, username, pass_hash, full_name, status, is_platform_owner, created_at)
             VALUES (?,?,?,?,?,1,?)'
        )->execute([
            bin2hex(random_bytes(16)), $user, password_hash($pass, PASSWORD_DEFAULT),
            mb_substr(trim((string)($in['fullName'] ?? '')), 0, 120), 'active', gmdate('Y-m-d H:i:s'),
        ]);
    }

    /* ── تنظیمات اولیه ── */
    /*
     * حالت پیامک روی «پل» می‌ماند: تا وقتی قالب sms.ir تأیید نشده،
     * کدهای ورود در پنل سوپرادمین دیده می‌شوند و آموزشگاه می‌تواند از همین
     * امروز کار کند. این تنها راهی است که سایت بین سفارش قالب و تأیید
     * آن — که چند روز طول می‌کشد — بلااستفاده نماند.
     */
    $seed = [
        'sms_mode'      => 'bridge',
        'otp_ttl'       => '300',
        'contact_email' => trim((string)($in['email'] ?? '')) ?: 'info@talkora.ir',
        'demo_email'    => trim((string)($in['email'] ?? '')) ?: 'info@talkora.ir',
        'contact_phone' => trim((string)($in['phone'] ?? '')),
    ];
    $ins = $pdo->prepare('INSERT INTO site_setting (skey, svalue, updated_at) VALUES (?,?,?)');
    $upd = $pdo->prepare('UPDATE site_setting SET svalue = ?, updated_at = ? WHERE skey = ?');
    foreach ($seed as $k => $v) {
        if ($v === '') continue;
        try { $ins->execute([$k, $v, gmdate('Y-m-d H:i:s')]); }
        catch (PDOException $e) { $upd->execute([$v, gmdate('Y-m-d H:i:s'), $k]); }
    }

    /* ── فایل پیکربندی ── */
    $d = (array)($in['db'] ?? []);
    $host = trim((string)($d['host'] ?? 'localhost'));
    $port = (int)($d['port'] ?? 3306) ?: 3306;
    if (preg_match('/^(.+):(\d+)$/', $host, $m)) { $host = $m[1]; $port = (int)$m[2]; }

    $written = ins_write_config([
        'db_host' => $port === 3306 ? $host : "{$host};port={$port}",
        'db_name' => trim((string)($d['name'] ?? '')),
        'db_user' => (string)($d['user'] ?? ''),
        'db_pass' => (string)($d['pass'] ?? ''),

        'otp_pepper'      => ins_random(32),
        'admin_setup_key' => ins_random(24),

        'smsir_api_key'     => '',
        'smsir_template_id' => 0,
        'smsir_param_name'  => 'CODE',
        'sms_dry_run'       => false,

        'resend_cooldown'  => 60,
        'otp_max_attempts' => 5,
        'rate_phone_hour'  => 5,
        'rate_ip_hour'     => 20,
    ]);

    try {
        $pdo->prepare('INSERT INTO audit_log (action, ip, meta, created_at) VALUES (?,?,?,?)')
            ->execute(['install.completed',
                       (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                       json_encode(['tables' => $made, 'outside' => $written['outside']]),
                       gmdate('Y-m-d H:i:s')]);
    } catch (Throwable $e) { /* ثبت رویداد نباید نصب را بشکند */ }

    ins_out(200, [
        'ok'          => true,
        'statements'  => $made,
        'configPath'  => $written['path'],
        'outside'     => $written['outside'],
        'adminExists' => $hasAdmin,
        'username'    => $hasAdmin ? null : $user,
    ]);

default:
    ins_fail(400, 'unknown_action', 'درخواست نامشخص.');
}
