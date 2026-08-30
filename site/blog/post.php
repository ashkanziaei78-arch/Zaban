<?php
/**
 * یک نوشته.
 *
 * مهم‌ترین صفحهٔ کل وبلاگ از دید سئو، پس همه‌چیزش سمت سرور است:
 * عنوان، متا، داده‌های ساختاریافته، و خودِ متن. جاوااسکریپت هیچ نقشی
 * در دیده‌شدن این صفحه ندارد.
 */
declare(strict_types=1);
require __DIR__ . '/_blog.php';

$slug = trim((string)($_GET['slug'] ?? ''));
$post = $slug !== '' ? blog_post_by_slug($slug) : null;

if (!$post) {
    /*
     * ۴۰۴ واقعی، نه ۲۰۰ با متن «پیدا نشد».
     *
     * صفحهٔ نبودنی که ۲۰۰ برمی‌گرداند، «soft 404» است: گوگل ایندکسش
     * می‌کند و بعد کل سایت را کم‌کیفیت‌تر می‌بیند.
     */
    http_response_code(404);
    blog_head(['title' => 'نوشته پیدا نشد — وبلاگ تاکورا',
               'desc'  => 'این نشانی نوشته‌ای ندارد.',
               'url'   => blog_url(), 'noindex' => true]);
    echo '<div class="wrap"><div class="empty"><h1>این نوشته پیدا نشد</h1>'
       . '<p>شاید نشانی عوض شده باشد.</p>'
       . '<p><a class="btn" href="' . e(blog_url()) . '">همهٔ نوشته‌ها</a></p></div></div>';
    blog_foot();
    exit;
}

blog_bump_views((string)$post['id']);

$url   = post_url((string)$post['slug']);
$cover = $post['cover_path'] ? blog_url('uploads/' . $post['cover_path']) : null;
$pubIso = $post['published_at'] ? str_replace(' ', 'T', (string)$post['published_at']) . 'Z' : null;
$modIso = $post['updated_at']   ? str_replace(' ', 'T', (string)$post['updated_at']) . 'Z'   : null;

blog_head([
    'title'     => (string)($post['meta_title'] ?: $post['title'] . ' — وبلاگ تاکورا'),
    'desc'      => (string)($post['meta_description'] ?: $post['excerpt'] ?: $post['title']),
    'url'       => $url,
    'image'     => $cover,
    'type'      => 'article',
    'published' => $pubIso,
    'modified'  => $modIso,
    'jsonld'    => array_filter([
        '@context'      => 'https://schema.org',
        '@type'         => 'BlogPosting',
        'headline'      => (string)$post['title'],
        'description'   => (string)($post['excerpt'] ?: ''),
        'image'         => $cover,
        'datePublished' => $pubIso,
        'dateModified'  => $modIso,
        'inLanguage'    => 'fa-IR',
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
        'author'    => ['@type' => 'Person', 'name' => (string)$post['author_name']],
        'publisher' => ['@type' => 'Organization', 'name' => 'تاکورا', 'url' => site_base()],
    ]),
]);

$related = blog_related($post);
?>
<article class="post">
  <div class="wrap narrow">
    <nav class="crumbs" aria-label="مسیر">
      <a href="<?= e(site_base()) ?>/">تاکورا</a> ›
      <a href="<?= e(blog_url()) ?>">وبلاگ</a>
      <?php if ($post['cat_name']): ?> ›
        <a href="<?= e(cat_url((string)$post['cat_slug'])) ?>"><?= e((string)$post['cat_name']) ?></a>
      <?php endif; ?>
    </nav>

    <h1><?= e((string)$post['title']) ?></h1>

    <div class="post-meta">
      <span><?= e((string)$post['author_name']) ?></span>
      <time datetime="<?= e(substr((string)$post['published_at'], 0, 10)) ?>"><?= e(j_full((string)$post['published_at'])) ?></time>
      <span><?= e(fa_digits((string)$post['reading_min'])) ?> دقیقه خواندن</span>
    </div>

    <?php if ($post['excerpt']): ?>
      <p class="lede"><?= e((string)$post['excerpt']) ?></p>
    <?php endif; ?>
  </div>

  <?php if ($cover): ?>
  <figure class="cover">
    <img src="<?= e('uploads/' . $post['cover_path']) ?>"
         alt="<?= e((string)($post['cover_alt'] ?: $post['title'])) ?>"
         width="1200" height="630" decoding="async">
  </figure>
  <?php endif; ?>

  <div class="wrap narrow">
    <?php
    /*
     * بدنه بدون escape چاپ می‌شود، چون HTML است — ولی HTMLی که
     * هنگام ذخیره پالوده شده. پالایش سرِ *ورودی* انجام می‌شود نه
     * اینجا: اگر سرِ خروجی بود، هر جای دیگری که همین متن را نشان
     * دهد (خوراک RSS، پیش‌نمایش پنل) باید پالایش را تکرار می‌کرد و
     * یکی‌شان بالاخره فراموش می‌شد.
     */
    echo $post['body'];
    ?>
  </div>
</article>

<?php if ($related): ?>
<section class="wrap related">
  <h2>بیشتر در همین موضوع</h2>
  <div class="grid"><?php foreach ($related as $r) blog_card($r + ['cat_name' => null, 'cat_slug' => null]); ?></div>
</section>
<?php endif; ?>

<section class="wrap cta-box">
  <h2>آموزشگاه‌تان را با تاکورا اداره کنید</h2>
  <p>ثبت‌نام، برنامهٔ ترم، حضور و غیاب و شهریه — در یک پنل.</p>
  <a class="btn" href="https://panel.talkora.ir/#/signup">۱۴ روز رایگان</a>
</section>

<?php blog_foot();
