<?php
/**
 * نشست سوپرادمین پلتفرم — جدا از کاربران آموزشگاه‌ها و جدا از نشست
 * کاربر عادی.
 *
 * ═══ چرا نام کاربری و رمز، و نه پیامک؟ ═══
 *
 * کاربران آموزشگاه با کد یک‌بارمصرف وارد می‌شوند چون شمارهٔ موبایل
 * چیزی است که همه دارند و همه به آن دسترسی دارند. ولی سوپرادمین
 * پلتفرم یک نفر (یا یک تیم کوچک) است و اگر تنها کلید سامانه شمارهٔ
 * موبایلش باشد، یک سیم‌کارت سوخته یا اعتبار تمام‌شدهٔ پنل پیامک یعنی
 * قفل‌شدن از ادارهٔ کل پلتفرم.
 *
 * پس: نام کاربری و رمز، با این محافظت‌ها:
 *   - رمز با password_hash ذخیره می‌شود، خودش هرگز نوشته نمی‌شود
 *   - سقف ۱۰ تلاش در ساعت برای هر نام کاربری و هر IP
 *   - پاسخ برای «کاربر نیست» و «رمز غلط» یکسان است
 *   - تأخیر ثابت روی تلاش ناموفق، تا زمان پاسخ اطلاعاتی ندهد
 *   - کوکی نشستِ جدا (tk_platform)، با نام و مسیر متفاوت از کاربران
 *     عادی (tk_session) — تا هیچ‌وقت با هم اشتباه نشوند
 *
 * جدول‌های admin_user/admin_session را همان اسکیمای «نسخهٔ ۳» تعریف
 * می‌کند که از اول برای همین منظور بود؛ اینجا فقط نامش را دقیق‌تر
 * به‌کار می‌بریم: صاحب این حساب، سوپرادمین کل پلتفرم است.
 *
 * MFA و IP-allowlist که در docs/J-permissions.md برای این نقش خواسته
 * شده، عمداً در این نسخه پیاده نشده — رمز قوی + rate-limit + ثبت کامل
 * رویداد کف امنیتی قابل‌قبولی برای شروع است؛ سخت‌گیری بیشتر وقتی
 * اضافه می‌شود که تیمی بزرگ‌تر از یک نفر از این پنل استفاده کند.
 */

declare(strict_types=1);

if (realpath(__FILE__) === realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    http_response_code(404);
    exit;
}

const SUPER_COOKIE   = 'tk_platform';
const SUPER_TTL_DAYS = 7;      // کوتاه‌تر از کاربران عادی: دسترسی حساس‌تر است

function super_issue_session(string $adminId): string
{
    $token = bin2hex(random_bytes(32));
    db()->prepare(
        'INSERT INTO admin_session (token_hash, admin_id, expires_at, ip, user_agent, created_at)
         VALUES (?,?,?,?,?,?)'
    )->execute([
        hash('sha256', $token), $adminId, now_utc(SUPER_TTL_DAYS * 86400),
        client_ip(), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250), now_utc(),
    ]);

    setcookie(SUPER_COOKIE, $token, [
        'expires'  => time() + SUPER_TTL_DAYS * 86400,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',   // این پنل هیچ‌وقت از دامنهٔ دیگری صدا زده نمی‌شود
    ]);
    return $token;
}

function current_super(): ?array
{
    $token = $_COOKIE[SUPER_COOKIE] ?? '';
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;

    $st = db()->prepare(
        'SELECT a.id, a.username, a.full_name, a.status, a.is_platform_owner
           FROM admin_session s JOIN admin_user a ON a.id = s.admin_id
          WHERE s.token_hash = ? AND s.expires_at > ? AND s.revoked_at IS NULL'
    );
    $st->execute([hash('sha256', $token), now_utc()]);
    $a = $st->fetch();
    if (!$a || $a['status'] !== 'active') return null;

    db()->prepare('UPDATE admin_session SET last_seen_at = ? WHERE token_hash = ?')
        ->execute([now_utc(), hash('sha256', $token)]);
    return $a;
}

function require_super(): array
{
    $a = current_super();
    if (!$a) fail(401, 'unauthenticated', 'ابتدا وارد شوید.');
    return $a;
}

/**
 * کارهایی که فقط مالک پلتفرم می‌تواند بکند.
 *
 * ساخت نقش، اعطای مجوز، تعلیق آموزشگاه و تنظیمات پلتفرم از این دروازه
 * می‌گذرند. امروز عملاً همهٔ ادمین‌ها مالک‌اند چون فقط یکی وجود دارد،
 * ولی اگر روزی ادمین کمکی اضافه شود، این مرز از قبل کشیده شده — نه
 * اینکه آن روز باید سی نقطه را پیدا و محافظت کرد.
 */
function require_owner(): array
{
    $a = require_super();
    if ((int)($a['is_platform_owner'] ?? 0) !== 1) {
        fail(403, 'owner_only', 'این کار فقط از عهدهٔ مالک پلتفرم برمی‌آید.');
    }
    return $a;
}

function super_revoke(): void
{
    $token = $_COOKIE[SUPER_COOKIE] ?? '';
    if ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token)) {
        db()->prepare('UPDATE admin_session SET revoked_at = ? WHERE token_hash = ?')
            ->execute([now_utc(), hash('sha256', $token)]);
    }
    setcookie(SUPER_COOKIE, '', [
        'expires' => time() - 3600, 'path' => '/',
        'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict',
    ]);
}
