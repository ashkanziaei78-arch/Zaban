<?php
/**
 * خوراک RSS.
 *
 * برای خوانندهٔ انسانی کم‌مصرف است، ولی گوگل و ابزارهای گردآور از
 * همین‌جا نوشتهٔ تازه را زودتر از خزش معمول می‌بینند — و انتشار
 * سریع‌ترِ محتوای تازه یعنی احتمال بیشترِ دیده‌شدن پیش از رقیب.
 */
declare(strict_types=1);
require __DIR__ . '/_blog.php';

header('Content-Type: application/rss+xml; charset=utf-8');

$posts = blog_posts(1);
$now   = gmdate('D, d M Y H:i:s') . ' GMT';

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
  <title>وبلاگ تاکورا</title>
  <link><?= e(blog_url()) ?></link>
  <description>آموزش زبان و مدیریت آموزشگاه</description>
  <language>fa-IR</language>
  <lastBuildDate><?= $now ?></lastBuildDate>
  <atom:link href="<?= e(blog_url('feed.xml')) ?>" rel="self" type="application/rss+xml"/>
<?php foreach ($posts as $p):
  $u = post_url((string)$p['slug']);
  $d = $p['published_at']
     ? gmdate('D, d M Y H:i:s', strtotime((string)$p['published_at'] . ' UTC')) . ' GMT'
     : $now;
?>
  <item>
    <title><?= e((string)$p['title']) ?></title>
    <link><?= e($u) ?></link>
    <guid isPermaLink="true"><?= e($u) ?></guid>
    <pubDate><?= $d ?></pubDate>
    <?php if ($p['cat_name']): ?><category><?= e((string)$p['cat_name']) ?></category><?php endif; ?>
    <description><?= e((string)($p['excerpt'] ?: $p['title'])) ?></description>
  </item>
<?php endforeach; ?>
</channel>
</rss>
