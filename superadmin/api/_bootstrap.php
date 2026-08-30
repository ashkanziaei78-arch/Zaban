<?php
/**
 * تاکورا — پایهٔ مشترک API
 *
 * روی هاست اشتراکی پلسک با PHP 8 و MySQL اجرا می‌شود.
 * عمداً بدون فریم‌ورک و بدون composer، چون روی هاست اشتراکی
 * نصب وابستگی معمولاً دردسر است.
 *
 * پیکربندی از config.php کنار همین فایل خوانده می‌شود.
 * اگر یک پوشهٔ خصوصی بالاتر از webroot دارید، config.php را آنجا
 * بگذارید و مسیرش را در CONFIG_PATHS اضافه کنید — امن‌تر است.
 */

declare(strict_types=1);

/*
 * محافظ دسترسی مستقیم.
 *
 * .htaccess فقط روی آپاچی کار می‌کند؛ اگر پلسک روی حالت «nginx only»
 * باشد نادیده گرفته می‌شود. این بررسی مستقل از وب‌سرور است.
 */
if (realpath(__FILE__) === realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    http_response_code(404);
    exit;
}


date_default_timezone_set('UTC');   // ذخیره UTC، نمایش تهران — بند P.2
mb_internal_encoding('UTF-8');

const CONFIG_PATHS = [
    __DIR__ . '/../../private/talkora-config.php',   // بیرون از webroot، ترجیح داده می‌شود
    __DIR__ . '/config.php',
];

function cfg(): array
{
    static $c = null;
    if ($c !== null) return $c;
    foreach (CONFIG_PATHS as $p) {
        if (is_file($p)) { $c = require $p; return $c; }
    }
    fail(500, 'server_misconfigured', 'فایل پیکربندی پیدا نشد. config.sample.php را به config.php تغییر نام دهید.');
}

/* ─────────── پاسخ‌ها ─────────── */

function json_out(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ok(array $body = []): never { json_out(200, ['ok' => true] + $body); }

function fail(int $status, string $code, string $message, array $extra = []): never
{
    json_out($status, ['ok' => false, 'error' => $code, 'message' => $message] + $extra);
}

function body_json(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        fail(405, 'method_not_allowed', 'فقط POST.');
    }
}

/* ─────────── پایگاه داده ─────────── */

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $c = cfg();
    $driver = $c['db_driver'] ?? 'mysql';
    try {
        if ($driver === 'sqlite') {
            // فقط برای توسعه و تست؛ روی هاست واقعی mysql است
            $pdo = new PDO('sqlite:' . $c['db_path'], null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            $pdo = new PDO(
                "mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4",
                $c['db_user'], $c['db_pass'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        }
    } catch (Throwable $e) {
        error_log('DB connect failed: ' . $e->getMessage());
        fail(500, 'db_unavailable', 'اتصال به پایگاه داده برقرار نشد.');
    }
    return $pdo;
}

/**
 * زمان همیشه در PHP و به UTC محاسبه می‌شود، نه در SQL.
 * دو دلیل: کد به گویش MySQL گره نمی‌خورد، و ساعتِ پایگاه داده
 * نمی‌تواند اعتبار کد یک‌بارمصرف را خراب کند.
 */
function now_utc(int $plusSeconds = 0): string
{
    return gmdate('Y-m-d H:i:s', time() + $plusSeconds);
}

/* ─────────── شماره موبایل ─────────── */

/** ارقام فارسی و عربی → لاتین، و نرمال‌سازی به شکل 09xxxxxxxxx */
function normalize_phone(string $raw): ?string
{
    $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $en = ['0','1','2','3','4','5','6','7','8','9'];
    $s  = str_replace($fa, $en, $raw);
    $s  = str_replace($ar, $en, $s);
    $s  = preg_replace('/[\s\-()]/u', '', $s) ?? '';
    if (preg_match('/^(?:\+?98|0098|0)?(9\d{9})$/', $s, $m)) return '0' . $m[1];
    return null;
}

/* ─────────── محدودیت نرخ ─────────── */

/**
 * پنجرهٔ ثابت در دیتابیس. برای مقیاس این محصول کافی است و
 * روی هاست اشتراکی که Redis ندارد کار می‌کند.
 */
function rate_ok(string $bucket, string $key, int $limit, int $windowSeconds): bool
{
    $db = db();
    $now = time();
    $windowStart = $now - ($now % $windowSeconds);

    // upsert قابل حمل بین MySQL و SQLite: اول تلاش برای درج، در صورت تکراری‌بودن افزایش
    try {
        $db->prepare('INSERT INTO rate_limit (bucket, rl_key, window_start, hits) VALUES (?, ?, ?, 1)')
           ->execute([$bucket, $key, $windowStart]);
    } catch (PDOException $e) {
        $db->prepare('UPDATE rate_limit SET hits = hits + 1 WHERE bucket=? AND rl_key=? AND window_start=?')
           ->execute([$bucket, $key, $windowStart]);
    }

    $st = $db->prepare('SELECT hits FROM rate_limit WHERE bucket=? AND rl_key=? AND window_start=?');
    $st->execute([$bucket, $key, $windowStart]);
    $hits = (int)($st->fetchColumn() ?: 0);

    // نظافت تنبل
    if (random_int(1, 50) === 1) {
        $db->prepare('DELETE FROM rate_limit WHERE window_start < ?')
           ->execute([$now - 86400]);
    }
    return $hits <= $limit;
}

function client_ip(): string
{
    foreach (['HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $v = explode(',', (string)$_SERVER[$k])[0];
            $v = trim($v);
            if (filter_var($v, FILTER_VALIDATE_IP)) return $v;
        }
    }
    return '0.0.0.0';
}

/* ─────────── نشست ─────────── */

const SESSION_COOKIE = 'tk_session';
const SESSION_TTL_DAYS = 30;

function issue_session(string $userId): string
{
    $token = bin2hex(random_bytes(32));
    $hash  = hash('sha256', $token);
    $exp   = now_utc(SESSION_TTL_DAYS * 86400);

    db()->prepare(
        'INSERT INTO session_token (token_hash, user_id, expires_at, ip, user_agent, created_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        $hash, $userId, $exp, client_ip(),
        mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250), now_utc(),
    ]);

    setcookie(SESSION_COOKIE, $token, [
        'expires'  => time() + SESSION_TTL_DAYS * 86400,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,           // جاوااسکریپت نمی‌تواند بخواند
        'samesite' => 'Lax',
    ]);
    return $token;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

/** کاربر فعلی یا null */
function current_user(): ?array
{
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;

    $st = db()->prepare(
        'SELECT u.id, u.phone, u.full_name, u.role, u.institute_name, u.status
           FROM session_token s
           JOIN app_user u ON u.id = s.user_id
          WHERE s.token_hash = ? AND s.expires_at > ? AND s.revoked_at IS NULL'
    );
    $st->execute([hash('sha256', $token), now_utc()]);
    $u = $st->fetch();
    if (!$u || $u['status'] !== 'active') return null;

    // آخرین استفاده — برای نمایش دستگاه‌های فعال
    db()->prepare('UPDATE session_token SET last_seen_at = ? WHERE token_hash = ?')
        ->execute([now_utc(), hash('sha256', $token)]);
    return $u;
}

function require_user(): array
{
    $u = current_user();
    if (!$u) fail(401, 'unauthenticated', 'ابتدا وارد شوید.');
    return $u;
}

function revoke_current_session(): void
{
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token)) {
        db()->prepare('UPDATE session_token SET revoked_at = ? WHERE token_hash = ?')
            ->execute([now_utc(), hash('sha256', $token)]);
    }
    setcookie(SESSION_COOKIE, '', [
        'expires' => time() - 3600, 'path' => '/',
        'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

/* ─────────── ثبت رویداد ─────────── */

function audit(string $action, ?string $userId, array $meta = []): void
{
    try {
        db()->prepare(
            'INSERT INTO audit_log (actor_user_id, action, ip, meta, created_at) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $userId, $action, client_ip(),
            json_encode($meta, JSON_UNESCAPED_UNICODE), now_utc(),
        ]);
    } catch (Throwable $e) {
        error_log('audit failed: ' . $e->getMessage());   // ثبت رویداد نباید درخواست را بشکند
    }
}
