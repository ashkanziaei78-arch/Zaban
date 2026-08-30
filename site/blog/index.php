<?php
/**
 * فهرست نوشته‌ها، و صفحهٔ دسته.
 *
 * هر دو یک قالب دارند چون تفاوتشان فقط یک فیلتر است — و صفحهٔ دسته
 * برای سئو مهم‌تر از آن است که نسخهٔ دست‌دومِ فهرست باشد: عنوان و
 * توضیح خودش را دارد.
 */
declare(strict_types=1);
require __DIR__ . '/_blog.php';

$catSlug = isset($_GET['c']) ? trim((string)$_GET['c']) : '';
$page    = max(1, (int)($_GET['page'] ?? 1));

$cat = null;
if ($catSlug !== '') {
    $cat = blog_category_by_slug($catSlug);
    if (!$cat) {
        http_response_code(404);
        blog_head(['title' => 'دسته پیدا نشد — وبلاگ تاکورا',
                   'desc'  => 'این دسته وجود ندارد.',
                   'url'   => blog_url(), 'noindex' => true]);
        echo '<div class="wrap"><div class="empty"><h1>دسته پیدا نشد</h1>'
           . '<p><a href="' . e(blog_url()) . '">برگردید به وبلاگ</a></p></div></div>';
        blog_foot();
        exit;
    }
}

$catId = $cat ? (string)$cat['id'] : null;
$posts = blog_posts($page, $catId);
$total = blog_count($catId);
$pages = max(1, (int)ceil($total / BLOG_PER_PAGE));

$title = $cat
    ? $cat['name'] . ' — وبلاگ تاکورا'
    : 'وبلاگ تاکورا — آموزش زبان و مدیریت آموزشگاه';
$desc = $cat
    ? (string)($cat['description'] ?: $cat['name'])
    : 'نوشته‌هایی دربارهٔ تدریس زبان، ادارهٔ آموزشگاه و آماده‌شدن برای آزمون‌ها.';
$url = $cat ? cat_url((string)$cat['slug']) : blog_url();
if ($page > 1) $url .= '?page=' . $page;

/*
 * صفحه‌های دوم به بعد noindex می‌گیرند.
 *
 * محتوایشان تکراری نیست ولی ارزش مستقلی هم ندارد؛ گوگل ترجیح می‌دهد
 * خود نوشته‌ها را ایندکس کند نه صفحهٔ سومِ فهرست. follow می‌ماند تا
 * لینک‌ها دنبال شوند.
 */
blog_head([
    'title'   => $title,
    'desc'    => $desc,
    'url'     => $url,
    'noindex' => $page > 1,
    'jsonld'  => [
        '@context' => 'https://schema.org',
        '@type'    => 'Blog',
        'name'     => 'وبلاگ تاکورا',
        'url'      => blog_url(),
        'inLanguage' => 'fa-IR',
        'publisher' => ['@type' => 'Organization', 'name' => 'تاکورا',
                        'url' => site_base()],
    ],
]);
?>
<div class="wrap">
  <nav class="crumbs" aria-label="مسیر">
    <a href="<?= e(site_base()) ?>/">تاکورا</a> ›
    <?php if ($cat): ?><a href="<?= e(blog_url()) ?>">وبلاگ</a> › <span><?= e((string)$cat['name']) ?></span>
    <?php else: ?><span>وبلاگ</span><?php endif; ?>
  </nav>

  <header class="page-h">
    <h1><?= e($cat ? (string)$cat['name'] : 'وبلاگ تاکورا') ?></h1>
    <p><?= e($desc) ?></p>
  </header>

  <?php $cats = blog_categories(); if ($cats): ?>
  <nav class="chips" aria-label="دسته‌ها">
    <a class="<?= $cat ? '' : 'on' ?>" href="<?= e(blog_url()) ?>">همه</a>
    <?php foreach ($cats as $c): if (!(int)$c['n']) continue; ?>
      <a class="<?= ($cat && $cat['id'] === $c['id']) ? 'on' : '' ?>"
         href="<?= e(cat_url((string)$c['slug'])) ?>"><?= e((string)$c['name']) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>

  <?php if (!$posts): ?>
    <div class="empty">
      <h2>هنوز نوشته‌ای منتشر نشده</h2>
      <p>به‌زودی اینجا دربارهٔ تدریس زبان و ادارهٔ آموزشگاه می‌نویسیم.</p>
    </div>
  <?php else: ?>
    <div class="grid"><?php foreach ($posts as $p) blog_card($p); ?></div>

    <?php if ($pages > 1): ?>
    <nav class="pager" aria-label="صفحه‌بندی">
      <?php
      $base = $cat ? cat_url((string)$cat['slug']) : blog_url();
      for ($i = 1; $i <= $pages; $i++):
        $href = $base . ($i > 1 ? '?page=' . $i : '');
      ?>
        <a class="<?= $i === $page ? 'on' : '' ?>" href="<?= e($href) ?>"
           <?= $i === $page ? 'aria-current="page"' : '' ?>><?= e(fa_digits((string)$i)) ?></a>
      <?php endfor; ?>
    </nav>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php blog_foot();
