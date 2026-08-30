<?php
/**
 * نقشهٔ سایتِ وبلاگ.
 *
 * جدا از sitemap.xml ایستای سایت است و پویا، چون نوشته‌ها اضافه
 * می‌شوند و هیچ‌کس قرار نیست هر بار فایل XML را دستی به‌روز کند —
 * همان کاری که اگر لازم باشد، بعد از نوشتهٔ سوم فراموش می‌شود.
 */
declare(strict_types=1);
require __DIR__ . '/_blog.php';

header('Content-Type: application/xml; charset=utf-8');
$db = blog_db();

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

printf("  <url><loc>%s</loc><changefreq>daily</changefreq><priority>0.8</priority></url>\n",
       e(blog_url()));

foreach (blog_categories() as $c) {
    if (!(int)$c['n']) continue;   // دستهٔ خالی در نقشه جایی ندارد
    printf("  <url><loc>%s</loc><changefreq>weekly</changefreq><priority>0.6</priority></url>\n",
           e(cat_url((string)$c['slug'])));
}

if ($db) {
    $st = $db->query(
        "SELECT slug, updated_at FROM blog_post
          WHERE status = 'published' AND published_at <= UTC_TIMESTAMP()
          ORDER BY published_at DESC LIMIT 2000");
    foreach ($st->fetchAll() as $p) {
        printf("  <url><loc>%s</loc><lastmod>%s</lastmod><changefreq>monthly</changefreq><priority>0.7</priority></url>\n",
               e(post_url((string)$p['slug'])),
               e(substr((string)$p['updated_at'], 0, 10)));
    }
}
echo "</urlset>\n";
