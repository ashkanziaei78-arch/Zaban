<?php
/**
 * وبلاگ تاکورا — هستهٔ مشترک صفحه‌های عمومی.
 *
 * چرا PHP و نه همان تک‌صفحه‌ایِ بقیهٔ پروژه: تمام هدف این بخش سئوست.
 * پنل‌ها با جاوااسکریپت ساخته می‌شوند چون کاربرشان وارد شده و منتظر
 * می‌ماند؛ خزندهٔ گوگل منتظر نمی‌ماند و صفحهٔ خالی را همان‌طور که
 * می‌رسد ایندکس می‌کند. پس هر نوشته HTML کامل در نشانی خودش است.
 *
 * این فایل خودش صفحه‌ای نیست و مستقیم باز نمی‌شود.
 */
declare(strict_types=1);

if (realpath(__FILE__) === realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../api/_bootstrap.php';

const BLOG_PER_PAGE = 9;

/**
 * نشانی مطلق سایت.
 *
 * برای og:url و canonical و sitemap لازم است و *باید* مطلق باشد؛
 * نشانی نسبی در اشتراک‌گذاری شبکه‌های اجتماعی و در sitemap بی‌معناست.
 */
function site_base(): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'talkora.ir');
    $https = ($_SERVER['HTTPS'] ?? '') === 'on'
          || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https://' : 'http://') . $host;
}

function blog_url(string $path = ''): string
{
    return site_base() . '/blog' . ($path !== '' ? '/' . ltrim($path, '/') : '/');
}

/**
 * نشانیِ یک نوشته.
 *
 * شکل تمیز — /blog/راهنمای-آزمون-آیلتس — به mod_rewrite و خوانده‌شدنِ
 * .htaccess نیاز دارد. روی آپاچی هست؛ اگر پلسک روی حالت nginx-only
 * باشد .htaccess نادیده گرفته می‌شود و *همهٔ* نشانی‌های نوشته ۴۰۴
 * می‌دهند — یعنی کل وبلاگ از دید گوگل ناپدید می‌شود.
 *
 * پس یک کلید در config.php هست. پیش‌فرضش روشن است چون نشانی تمیز
 * خودش بخشی از سئوست، ولی اگر جایی کار نکرد، خاموش‌کردنش سایت را
 * نجات می‌دهد به قیمت زیبایی نشانی. تشخیصش هم دستی نیست: بخش
 * «سلامت سامانه» در پنل پلتفرم یک نشانی واقعی را می‌گیرد و می‌گوید
 * کدام حالت کار می‌کند.
 */
function blog_pretty_urls(): bool
{
    $c = function_exists('cfg') ? cfg() : [];
    return !array_key_exists('blog_pretty_urls', $c) || (bool)$c['blog_pretty_urls'];
}

function post_url(string $slug): string
{
    return blog_pretty_urls()
        ? blog_url(rawurlencode($slug))
        : blog_url('post.php?slug=' . rawurlencode($slug));
}

/** نشانی صفحهٔ یک دسته، با همان قاعده */
function cat_url(string $slug): string
{
    return blog_pretty_urls()
        ? blog_url('c/' . rawurlencode($slug))
        : blog_url('index.php?c=' . rawurlencode($slug));
}

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}


/* ═════════════════════ تاریخ شمسی ═════════════════════
 *
 * بند P.2: میلادی ذخیره، شمسی نمایش. اینجا سمت سرور انجام می‌شود
 * چون خروجی باید در خودِ HTML باشد — هم برای خواننده‌ای که
 * جاوااسکریپتش خاموش است، هم برای گوگل که تاریخ را از متن می‌خواند.
 */

const J_MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
                  'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

/** @return array{0:int,1:int,2:int} */
function to_jalali(int $gy, int $gm, int $gd): array
{
    $gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $jy  = ($gy <= 1600) ? 0 : 979;
    $gy -= ($gy <= 1600) ? 621 : 1600;
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
          + intdiv($gy2 + 399, 400) - 80 + $gd + $gdm[$gm - 1];
    $jy += 33 * intdiv($days, 12053); $days %= 12053;
    $jy += 4 * intdiv($days, 1461);   $days %= 1461;
    if ($days > 365) { $jy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
    $jm = ($days < 186) ? 1 + intdiv($days, 31) : 7 + intdiv($days - 186, 30);
    $jd = 1 + (($days < 186) ? ($days % 31) : (($days - 186) % 30));
    return [$jy, $jm, $jd];
}

/** «۸ مرداد ۱۴۰۵» */
function j_full(?string $iso): string
{
    if (!$iso || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m)) return '';
    [$y, $mo, $d] = to_jalali((int)$m[1], (int)$m[2], (int)$m[3]);
    return fa_digits((string)$d) . ' ' . J_MONTHS[$mo - 1] . ' ' . fa_digits((string)$y);
}

function fa_digits(string $s): string
{
    return strtr($s, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴',
                      '5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
}


/* ═════════════════════ داده ═════════════════════ */

/**
 * وبلاگ فقط وقتی کار می‌کند که پایگاه داده بالا باشد.
 *
 * ولی سایت معرفی نباید به‌خاطر آن بمیرد: اگر اتصال نشد، صفحهٔ وبلاگ
 * پیام کوتاهی می‌دهد و بقیهٔ سایت سرِجایش است. برگرداندن ۵۰۰ به گوگل
 * بدترین کار ممکن است — چند بار پشت‌سرهم، و صفحه از ایندکس می‌افتد.
 */
function blog_db(): ?PDO
{
    static $pdo = null;
    static $tried = false;
    if ($tried) return $pdo;
    $tried = true;
    try { $pdo = db(); } catch (Throwable $e) { $pdo = null; }
    return $pdo;
}

/** @return array<int,array<string,mixed>> */
function blog_posts(int $page = 1, ?string $categoryId = null): array
{
    $db = blog_db();
    if (!$db) return [];
    $off = max(0, ($page - 1) * BLOG_PER_PAGE);
    $where = "p.status = 'published' AND p.published_at IS NOT NULL AND p.published_at <= UTC_TIMESTAMP()";
    $args = [];
    if ($categoryId !== null) { $where .= ' AND p.category_id = ?'; $args[] = $categoryId; }

    // LIMIT/OFFSET داخل رشته چون PDO با emulate=off آن‌ها را رشته
    // می‌فرستد و MySQL رد می‌کند؛ هر دو از قبل عدد صحیح شده‌اند.
    $st = $db->prepare(
        "SELECT p.*, c.name AS cat_name, c.slug AS cat_slug
           FROM blog_post p LEFT JOIN blog_category c ON c.id = p.category_id
          WHERE {$where}
          ORDER BY p.published_at DESC, p.id DESC
          LIMIT " . BLOG_PER_PAGE . " OFFSET {$off}");
    $st->execute($args);
    return $st->fetchAll();
}

function blog_count(?string $categoryId = null): int
{
    $db = blog_db();
    if (!$db) return 0;
    $where = "status = 'published' AND published_at IS NOT NULL AND published_at <= UTC_TIMESTAMP()";
    $args = [];
    if ($categoryId !== null) { $where .= ' AND category_id = ?'; $args[] = $categoryId; }
    $st = $db->prepare("SELECT COUNT(*) FROM blog_post WHERE {$where}");
    $st->execute($args);
    return (int)$st->fetchColumn();
}

function blog_post_by_slug(string $slug): ?array
{
    $db = blog_db();
    if (!$db) return null;
    $st = $db->prepare(
        "SELECT p.*, c.name AS cat_name, c.slug AS cat_slug
           FROM blog_post p LEFT JOIN blog_category c ON c.id = p.category_id
          WHERE p.slug = ? AND p.status = 'published'
            AND p.published_at IS NOT NULL AND p.published_at <= UTC_TIMESTAMP()");
    $st->execute([$slug]);
    $r = $st->fetch();
    return $r ?: null;
}

/** @return array<int,array<string,mixed>> */
function blog_categories(): array
{
    $db = blog_db();
    if (!$db) return [];
    return $db->query(
        "SELECT c.*, (SELECT COUNT(*) FROM blog_post p
                       WHERE p.category_id = c.id AND p.status = 'published'
                         AND p.published_at <= UTC_TIMESTAMP()) AS n
           FROM blog_category c ORDER BY c.sort_order, c.name")->fetchAll();
}

function blog_category_by_slug(string $slug): ?array
{
    $db = blog_db();
    if (!$db) return null;
    $st = $db->prepare('SELECT * FROM blog_category WHERE slug = ?');
    $st->execute([$slug]);
    $r = $st->fetch();
    return $r ?: null;
}

/**
 * نوشته‌های مرتبط: هم‌دسته، تازه‌ترین‌ها.
 *
 * برای خواننده مفید است و برای سئو مفیدتر: پیوند داخلی همان چیزی
 * است که به گوگل می‌گوید کدام صفحه‌ها به هم مربوط‌اند.
 */
function blog_related(array $post, int $limit = 3): array
{
    $db = blog_db();
    if (!$db || !$post['category_id']) return [];
    $st = $db->prepare(
        "SELECT slug, title, excerpt, cover_path, published_at, reading_min
           FROM blog_post
          WHERE category_id = ? AND id <> ? AND status = 'published'
            AND published_at <= UTC_TIMESTAMP()
          ORDER BY published_at DESC LIMIT " . max(1, min(6, $limit)));
    $st->execute([$post['category_id'], $post['id']]);
    return $st->fetchAll();
}

/**
 * شمارندهٔ بازدید، بی‌سروصدا.
 *
 * خطایش نادیده گرفته می‌شود: صفحه‌ای که به‌خاطر یک UPDATE ناموفق ۵۰۰
 * بدهد، عددِ بازدید را به قیمت خودِ صفحه خریده.
 */
function blog_bump_views(string $id): void
{
    $db = blog_db();
    if (!$db) return;
    try {
        $db->prepare('UPDATE blog_post SET views = views + 1 WHERE id = ?')->execute([$id]);
    } catch (Throwable $e) { /* عمداً خاموش */ }
}


/* ═════════════════════ قالب ═════════════════════ */

/**
 * سربرگ صفحه، با همهٔ چیزی که گوگل و شبکه‌های اجتماعی می‌خوانند.
 *
 * @param array{title:string,desc:string,url:string,image?:string,
 *              type?:string,published?:string,modified?:string,
 *              author?:string,jsonld?:array,noindex?:bool} $m
 */
function blog_head(array $m): void
{
    $title = $m['title'];
    $desc  = mb_substr(trim($m['desc']), 0, 160);
    $img   = $m['image'] ?? (site_base() . '/blog/assets/og-default.png');
    ?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<link rel="canonical" href="<?= e($m['url']) ?>">
<?php if (!empty($m['noindex'])): ?>
<meta name="robots" content="noindex,follow">
<?php endif; ?>

<meta property="og:type" content="<?= e($m['type'] ?? 'website') ?>">
<meta property="og:site_name" content="تاکورا">
<meta property="og:locale" content="fa_IR">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:url" content="<?= e($m['url']) ?>">
<meta property="og:image" content="<?= e($img) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($title) ?>">
<meta name="twitter:description" content="<?= e($desc) ?>">
<meta name="twitter:image" content="<?= e($img) ?>">
<?php if (!empty($m['published'])): ?>
<meta property="article:published_time" content="<?= e($m['published']) ?>">
<?php endif; ?>
<?php if (!empty($m['modified'])): ?>
<meta property="article:modified_time" content="<?= e($m['modified']) ?>">
<?php endif; ?>

<link rel="alternate" type="application/rss+xml" title="وبلاگ تاکورا" href="<?= e(blog_url('feed.xml')) ?>">
<link rel="preload" href="../fonts/dana-regular.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/blog.css">
<?php if (!empty($m['jsonld'])): ?>
<script type="application/ld+json"><?= json_encode($m['jsonld'],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php endif; ?>
</head>
<body>
<a class="skip" href="#main">رفتن به محتوا</a>
<header class="top">
  <div class="wrap">
    <a class="brand" href="<?= e(site_base()) ?>/"><span class="mark">ت</span> تاکورا</a>
    <nav>
      <a href="<?= e(blog_url()) ?>">وبلاگ</a>
      <a href="<?= e(site_base()) ?>/#pricing">قیمت‌ها</a>
      <a class="cta" href="https://panel.talkora.ir/#/signup">ثبت‌نام رایگان</a>
    </nav>
  </div>
</header>
<main id="main"><?php
}

function blog_foot(): void
{
    $cats = blog_categories();
    ?></main>
<footer class="foot">
  <div class="wrap">
    <div class="cols">
      <div>
        <b>دسته‌ها</b>
        <ul><?php foreach ($cats as $c): ?>
          <li><a href="<?= e(cat_url((string)$c['slug'])) ?>"><?= e((string)$c['name']) ?></a></li>
        <?php endforeach; ?></ul>
      </div>
      <div>
        <b>تاکورا</b>
        <ul>
          <li><a href="<?= e(site_base()) ?>/">صفحهٔ اصلی</a></li>
          <li><a href="<?= e(site_base()) ?>/#pricing">قیمت‌ها</a></li>
          <li><a href="https://panel.talkora.ir/">ورود به پنل</a></li>
          <li><a href="<?= e(blog_url('feed.xml')) ?>">خوراک RSS</a></li>
        </ul>
      </div>
    </div>
    <p class="fine">سامانهٔ مدیریت آموزشگاه زبان — ساخته‌شده در ایران.</p>
  </div>
</footer>
</body>
</html><?php
}

/** کارت نوشته در فهرست‌ها */
function blog_card(array $p): void
{
    $url = post_url((string)$p['slug']);
    ?>
    <article class="card">
      <?php if ($p['cover_path']): ?>
      <a class="thumb" href="<?= e($url) ?>" tabindex="-1" aria-hidden="true">
        <img src="<?= e('uploads/' . $p['cover_path']) ?>" alt=""
             loading="lazy" decoding="async" width="640" height="360">
      </a>
      <?php endif; ?>
      <div class="body">
        <?php if (!empty($p['cat_name'])): ?>
        <a class="cat" href="<?= e(cat_url((string)$p['cat_slug'])) ?>"><?= e((string)$p['cat_name']) ?></a>
        <?php endif; ?>
        <h2><a href="<?= e($url) ?>"><?= e((string)$p['title']) ?></a></h2>
        <?php if ($p['excerpt']): ?><p><?= e((string)$p['excerpt']) ?></p><?php endif; ?>
        <div class="meta">
          <time datetime="<?= e(substr((string)$p['published_at'], 0, 10)) ?>"><?= e(j_full((string)$p['published_at'])) ?></time>
          <span><?= e(fa_digits((string)$p['reading_min'])) ?> دقیقه خواندن</span>
        </div>
      </div>
    </article>
    <?php
}
