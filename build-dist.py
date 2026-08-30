#!/usr/bin/env python3
"""
سه بستهٔ آمادهٔ آپلود می‌سازد، چون سایت، پنل و سوپرادمین روی سه دامنهٔ
جدا بالا می‌آیند:

  dist/site/    →  httpdocs دامنهٔ اصلی        talkora.ir
  dist/panel/   →  httpdocs زیردامنهٔ پنل       panel.talkora.ir
  dist/admin/   →  httpdocs زیردامنهٔ سوپرادمین admin.talkora.ir

چرا جدا؟ پنل و سوپرادمین بک‌اند PHP و پایگاه داده دارند و سایت معرفی
هیچ‌کدام را ندارد. با جداکردن‌شان، کوکی نشست فقط روی زیردامنهٔ خودش
ست می‌شود، صفحهٔ فروش هیچ‌وقت به دیتابیس دست نمی‌زند، و اگر یکی خطا
داد بقیه پایین نمی‌آیند. پنل و سوپرادمین هرکدام کوکی نشست جدای خودشان
را دارند (tk_session در برابر tk_platform) و به یک پایگاه دادهٔ مشترک
وصل می‌شوند — سوپرادمین دیتابیس یا جدول تازه نمی‌سازد، همان چیزی را
می‌خواند و می‌نویسد که نصب‌کنندهٔ پنل ساخته.

اجرا:  python3 build-dist.py
"""
import pathlib
import re
import shutil
import sys
import zipfile

ROOT = pathlib.Path(__file__).resolve().parent
DIST = ROOT / "dist"

SITE_DOMAIN = "talkora.ir"
PANEL_DOMAIN = "panel.talkora.ir"
ADMIN_DOMAIN = "admin.talkora.ir"
PANEL_URL = f"https://{PANEL_DOMAIN}/"
ADMIN_URL = f"https://{ADMIN_DOMAIN}/"

# تنها مقصد بیرونی مجاز: سایت به پنل لینک می‌دهد، سوپرادمین به پنل
# (لینک «ورود به‌جای کاربر») — و برعکس هیچ‌کدام. هر ارجاع دیگری به
# دامنهٔ خارجی، ساخت را متوقف می‌کند — قاعدهٔ P.7
ALLOWED_EXTERNAL = (
    f"https://{SITE_DOMAIN}", f"https://{PANEL_DOMAIN}", f"https://www.{SITE_DOMAIN}",
    f"https://{ADMIN_DOMAIN}",
)

# ─────────────────────────── تنظیمات وب‌سرور ───────────────────────────

_COMMON_SECURITY = """
<IfModule mod_headers.c>
  Header set X-Content-Type-Options "nosniff"
  Header set Referrer-Policy "strict-origin-when-cross-origin"
  Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
  <FilesMatch "\\.woff2$">
    Header set Cache-Control "public, max-age=31536000, immutable"
  </FilesMatch>
</IfModule>

<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/html text/css application/javascript
  AddOutputFilterByType DEFLATE text/plain text/xml application/json image/svg+xml
</IfModule>

<IfModule mod_expires.c>
  ExpiresActive On
  ExpiresByType text/html "access plus 0 seconds"
  ExpiresByType font/woff2 "access plus 1 year"
  ExpiresByType text/css "access plus 1 month"
  ExpiresByType application/javascript "access plus 1 month"
  ExpiresByType image/svg+xml "access plus 1 month"
</IfModule>

<IfModule mod_mime.c>
  AddType font/woff2 .woff2
</IfModule>

Options -Indexes
"""

_HTTPS_REDIRECT = """RewriteEngine On
RewriteCond %{HTTPS} !=on
RewriteCond %{HTTP:X-Forwarded-Proto} !https
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
"""

SITE_HTACCESS = f"""# تاکورا — سایت معرفی ({SITE_DOMAIN})

{_HTTPS_REDIRECT}
<IfModule mod_headers.c>
  Header set X-Frame-Options "SAMEORIGIN"
  Header set Content-Security-Policy "default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'"
</IfModule>
{_COMMON_SECURITY}
ErrorDocument 404 /index.html
"""

PANEL_HTACCESS = f"""# تاکورا — پنل ({PANEL_DOMAIN})

{_HTTPS_REDIRECT}
<IfModule mod_headers.c>
  # پنل هرگز نباید داخل iframe سایت دیگری باز شود
  Header always set X-Frame-Options "DENY"
  Header set Content-Security-Policy "default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'"
  # خود صفحهٔ پنل کش نشود؛ فونت‌ها با قاعدهٔ بالا یک‌ساله کش می‌شوند
  <FilesMatch "^index\\.html$">
    Header set Cache-Control "no-store"
  </FilesMatch>
</IfModule>
{_COMMON_SECURITY}
# پنل تک‌صفحه‌ای است و مسیریابی با hash انجام می‌شود
ErrorDocument 404 /index.html
"""

ADMIN_HTACCESS = f"""# تاکورا — سوپرادمین پلتفرم ({ADMIN_DOMAIN})

{_HTTPS_REDIRECT}
<IfModule mod_headers.c>
  # این پنل هرگز نباید داخل iframe جایی باز شود — حتی خود سایت
  Header always set X-Frame-Options "DENY"
  Header set Content-Security-Policy "default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'"
  <FilesMatch "^index\\.html$">
    Header set Cache-Control "no-store"
  </FilesMatch>
</IfModule>
{_COMMON_SECURITY}
ErrorDocument 404 /index.html
"""

SITE_API_HTACCESS = """# فقط public.php از بیرون قابل صداکردن است
<FilesMatch "^(_bootstrap|_settings|config|config\\.sample)\\.php$">
  Require all denied
</FilesMatch>
<FilesMatch "\\.(sql|md|log|bak|sample)$">
  Require all denied
</FilesMatch>
<IfModule mod_headers.c>
  Header set X-Content-Type-Options "nosniff"
</IfModule>
Options -Indexes
"""

SITE_ROBOTS = f"""User-agent: *
Allow: /

Sitemap: https://{SITE_DOMAIN}/sitemap.xml
"""

# پنل محتوای عمومی ندارد؛ ایندکس‌شدنش فقط صفحهٔ ورود را وارد گوگل می‌کند
PANEL_ROBOTS = """User-agent: *
Disallow: /
"""

# سوپرادمین از پنل هم حساس‌تر است — به‌هیچ‌وجه نباید ایندکس شود
ADMIN_ROBOTS = """User-agent: *
Disallow: /
"""

# نقشهٔ اصلی، که به نقشهٔ پویای وبلاگ اشاره می‌کند.
#
# نوشته‌ها را اینجا نمی‌شود فهرست کرد چون در زمان ساخت وجود ندارند؛
# blog/sitemap.php آن‌ها را از دیتابیس می‌سازد. فهرست نقشه‌ها راه
# استانداردِ گفتنِ همین چیز به گوگل است.
SITEMAP = f"""<?xml version="1.0" encoding="UTF-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <sitemap><loc>https://{SITE_DOMAIN}/sitemap-pages.xml</loc></sitemap>
  <sitemap><loc>https://{SITE_DOMAIN}/blog/sitemap.xml</loc></sitemap>
</sitemapindex>
"""

SITEMAP_PAGES = f"""<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://{SITE_DOMAIN}/</loc><changefreq>weekly</changefreq><priority>1.0</priority></url>
  <url><loc>https://{SITE_DOMAIN}/blog/</loc><changefreq>daily</changefreq><priority>0.8</priority></url>
</urlset>
"""

# ─────────────────────────── راهنماها ───────────────────────────

SITE_GUIDE = f"""راهنمای آپلود سایت معرفی — {SITE_DOMAIN}
════════════════════════════════════════════════

این پوشه فقط سایت معرفی است. پنل بستهٔ جداگانه‌ای دارد
(فایل talkora-panel.zip، راهنمای خودش را دارد).

گام ۰) نیم‌سرور دامنه  ← اول این، چون تا ۲۴ ساعت طول می‌کشد
   در پنل جایی که دامنه را خریده‌اید نیم‌سرورها را بگذارید:
        ns1.ihglobaldns.com
        ns2.ihglobaldns.com
   تا وقتی این کار را نکنید {SITE_DOMAIN} به هیچ‌جا وصل نیست.

گام ۱) وارد پلسک شوید
   https://ir-plesk02.ihglobaldns.com:8443

گام ۲) Websites & Domains ← {SITE_DOMAIN} ← File Manager

گام ۳) وارد پوشهٔ httpdocs شوید
   ⚠ در پلسک اسم پوشه httpdocs است، نه public_html

گام ۴) فایل‌های پیش‌فرض پلسک را پاک کنید
   index.html و صفحهٔ "Web Server's Default Page"

گام ۵) فایل talkora-site.zip را آپلود و استخراج کنید
   Upload ← فایل زیپ ← بعد روی آن کلیک و Extract Files
   بعد از استخراج خود زیپ را پاک کنید.

   باید این‌ها را ببینید:
        httpdocs/index.html
        httpdocs/.htaccess
        httpdocs/robots.txt
        httpdocs/sitemap.xml
        httpdocs/fonts/

   ⚠ .htaccess مخفی است. از منوی Settings گزینهٔ
     "Show hidden files" را روشن کنید.

گام ۶) گواهی SSL رایگان
   Websites & Domains ← SSL/TLS Certificates
   ← "Install a free basic certificate provided by Let's Encrypt"
   ← هم {SITE_DOMAIN} و هم www.{SITE_DOMAIN} را تیک بزنید

گام ۷) هدایت خودکار به HTTPS
   Hosting & DNS ← Hosting Settings
   ← تیک "Permanent SEO-safe 301 redirect from HTTP to HTTPS"

گام ۸) باز کنید:  https://{SITE_DOMAIN}

════════════════════════════════════════════════
دکمه‌های «ورود» در این سایت به {PANEL_URL} می‌روند.
تا وقتی بستهٔ پنل را بالا نیاورید، آن دکمه‌ها به صفحهٔ خطا می‌رسند.

اگر خطای ۵۰۰ گرفتید: پلسک ممکن است روی حالت "nginx only" باشد و
.htaccess را نخواند. اسمش را به .htaccess.bak تغییر دهید؛ سایت بالا
می‌آید، فقط بدون فشرده‌سازی و کش.

قبل از عمومی‌کردن، فایل CUSTOMIZE.md را بخوانید: شمارهٔ تماس، قیمت‌ها
و آمار مشتری هنوز جای‌نگهدارند.
"""

PANEL_GUIDE = f"""راهنمای بالا آوردن پنل — {PANEL_DOMAIN}
════════════════════════════════════════════════════
پنل، برخلاف سایت معرفی، بک‌اند دارد: PHP ۸ و MySQL و اتصال به sms.ir.

خبر خوب: دیگر لازم نیست هیچ فایلی را دستی ویرایش کنید و هیچ SQL‌ای را
در phpMyAdmin بچسبانید. یک نصب‌کنندهٔ تحت وب همهٔ این‌ها را انجام می‌دهد.
شما فقط چهار مقدار اتصال دیتابیس را که خود پلسک نشان داده کپی می‌کنید.


گام ۱) ساخت زیردامنه در پلسک
   Websites & Domains ← Add Subdomain
        Subdomain name : panel
        Parent domain  : {SITE_DOMAIN}
        Document root  : پیش‌فرض پلسک را دست نزنید
   ← OK


گام ۲) نسخهٔ PHP
   روی زیردامنهٔ {PANEL_DOMAIN} ← PHP Settings
        PHP version : ۸.۱ یا بالاتر
        اطمینان از فعال بودن افزونه‌های  pdo_mysql  و  curl
   اگر curl خاموش باشد همه‌چیز درست به نظر می‌رسد ولی هیچ پیامکی
   فرستاده نمی‌شود. نصب‌کننده این را در همان صفحهٔ اول به شما می‌گوید.


گام ۳) ساخت پایگاه دادهٔ خالی
   Databases ← Add Database
        Database name : talkora_panel
        User          : talkora_user
        Password      : یک رمز قوی بسازید

   ⚠ پلسک معمولاً به هر دو نام پیشوند اضافه می‌کند. همان چیزی را که
     در فهرست Databases می‌بینید یادداشت کنید، نه چیزی که تایپ کردید.
     این رایج‌ترین دلیل «اتصال برقرار نشد» است.

   جدول‌ها را نسازید — نصب‌کننده خودش می‌سازد.


گام ۴) آپلود
   محتویات این بسته را در  httpdocs  زیردامنهٔ پنل بریزید:
        httpdocs/index.html
        httpdocs/.htaccess
        httpdocs/robots.txt
        httpdocs/fonts/
        httpdocs/api/
        httpdocs/setup/

   ⚠ پنل سوپرادمین اینجا نیست. بستهٔ جداگانه‌ای است
     (talkora-admin.zip، برای زیردامنهٔ {ADMIN_DOMAIN}) — راهنمای
     خودش را دارد، چون نشستش عمداً از این پنل کاملاً جداست.

   ⚠ پوشهٔ  _نصب-آپلود-نکنید  را آپلود نکنید. فقط نسخهٔ پشتیبانِ
     دستیِ ساخت جدول‌هاست، برای وقتی که نصب‌کننده به هر دلیلی کار نکند.


گام ۵) گواهی SSL  ← قبل از نصب
   SSL/TLS Certificates ← Let's Encrypt ← {PANEL_DOMAIN}
   ⚠ کوکی نشست پرچم Secure دارد. روی http، کاربر بلافاصله بعد از ورود
     بیرون می‌افتد و علتش هم هیچ‌جا نوشته نمی‌شود.


گام ۶) نصب  ← همهٔ کار در همین‌جاست
   باز کنید:  https://{PANEL_DOMAIN}/setup/

   سه صفحه است:
     ۱. بررسی سرور — خودکار. هرچه قرمز بود، همان‌جا نوشته کجای پلسک
        را باید عوض کنید.
     ۲. پایگاه داده — چهار مقدار گام ۳. دکمهٔ «آزمایش اتصال» پیش از
        نصب می‌گوید درست است یا نه.
     ۳. حساب مدیر — نام کاربری و رمز پنل سوپرادمین. حداقل ۱۰ نویسه، و
        جایی امن نگهش دارید؛ بازیابی خودکار ندارد. با همین حساب در گام ۷
        وارد {ADMIN_DOMAIN} هم می‌شوید.

   بعد از زدن «نصب کن»:
     • ۲۰ جدول ساخته می‌شود
     • کلید امضای کدهای ورود به‌صورت تصادفی ساخته می‌شود
     • فایل پیکربندی نوشته می‌شود — اگر ممکن باشد بیرون از پوشهٔ وب،
       در  private/talkora-config.php  کنار httpdocs

   ⚠ بعد از پایان، پوشهٔ  setup  را از روی هاست پاک کنید.
     نصب‌کننده از لحظه‌ای که فایل پیکربندی ساخته شد خودش را قفل می‌کند
     و هر درخواستی را رد می‌کند، ولی نگه‌داشتنش دلیلی ندارد.


گام ۷) پنل سوپرادمین را هم بالا بیاورید
   جدول‌ها همین الان با نصب‌کنندهٔ بالا ساخته شدند. حالا بستهٔ
   talkora-admin.zip را طبق راهنمای خودش روی زیردامنهٔ {ADMIN_DOMAIN}
   بریزید — همان چهار مقدار پایگاه داده را دوباره وارد می‌کنید،
   ولی نصب‌کننده را دوباره اجرا نمی‌کنید؛ جدول‌ها از قبل هستند.

   بعد از آن، سربرگ «سلامت سامانه» در پنل سوپرادمین را باز کنید.
   هرچه سبز نبود، توضیحش کنار خودش نوشته شده.


گام ۸) پیامک — می‌شود بعداً
   سامانه بعد از نصب در «حالت پل» است: همه‌چیز کار می‌کند، ولی به‌جای
   پیامک، کد ورود در سربرگ «ورود و پیامک» پنل سوپرادمین دیده می‌شود و
   شما آن را به کاربر می‌گویید.

   این عمدی است. تأیید قالب در sms.ir چند روز طول می‌کشد و بدون این
   حالت، سامانه در تمام آن روزها بلااستفاده بود — حتی خودتان هم
   نمی‌توانستید وارد شوید.

   وقتی قالب آماده شد:
     پنل sms.ir ← «ارسال وریفای» ← افزودن قالب، با همین متن:

        کد ورود شما به تاکورا: #CODE#
        این کد تا ۲ دقیقه معتبر است.

     بعد در پنل سوپرادمین ← «ورود و پیامک»:
        کلید API، شناسهٔ عددی قالب، و نام پارامتر (اینجا CODE)
        و حالت را روی «با پیامک از sms.ir» بگذارید.

   نیازی به دست‌زدن به هیچ فایلی نیست.

   چرا «وریفای» و نه ارسال معمولی: پیامک وریفای از خط خدماتی می‌رود،
   پس به کسی که «لغو تبلیغات» را فعال کرده هم می‌رسد. با خط تبلیغاتی،
   کد ورودِ بخشی از کاربران هرگز نمی‌رسد و شما هم خبردار نمی‌شوید.


گام ۹) دو آزمایش امنیتی که نباید رد کنید
   ۱. باز کنید:  https://{PANEL_DOMAIN}/api/config.php
      انتظار: ۴۰۴ یا ۴۰۳ یا صفحهٔ خالی.
      اگر محتوای فایل را دیدید، فوراً رمز دیتابیس را عوض کنید.
      (اگر نصب‌کننده توانسته باشد پیکربندی را بیرون از webroot بنویسد،
       این فایل اصلاً وجود ندارد و ۴۰۴ می‌گیرید — همان درست است.)

   ۲. باز کنید:  https://{PANEL_DOMAIN}/setup/
      انتظار: «تاکورا از قبل نصب شده».
      اگر فرم نصب را دیدید یعنی فایل پیکربندی ساخته نشده — نصب ناقص است.


════════════════════════════════════════════════════
مشکلات رایج

نصب‌کننده می‌گوید «سرور پاسخ درستی نداد»
    PHP روی این دامنه اجرا نمی‌شود. گام ۲ را انجام دهید.

«فایل پیکربندی نوشته نشد»
    در File Manager به پوشهٔ  httpdocs/api  اجازهٔ نوشتن بدهید و
    دکمهٔ نصب را دوباره بزنید.

«این کاربر به آن پایگاه داده دسترسی ندارد»
    در پلسک، کاربر دیتابیس به همان دیتابیس وصل نشده است.

پنل بالا می‌آید ولی نوار «نسخهٔ نمایشی» دارد
    یعنی api/health.php جواب نداده. یعنی نصب انجام نشده یا پیکربندی
    خراب است. سربرگ «سلامت سامانه» در پنل ادمین را ببینید.

کد ورود می‌گیرید ولی «کد منقضی شده»
    ساعت سرور جلو یا عقب است. از پشتیبانی هاست بخواهید با NTP
    هماهنگش کند.

خطای ۵۰۰ روی کل پنل
    پلسک روی حالت "nginx only" است و .htaccess خوانده نمی‌شود.
    مهم: کد PHP خودش هم جلوی دسترسی مستقیم به فایل‌های داخلی را
    می‌گیرد، پس رمزها لو نمی‌رود — ولی بهتر است در
    Apache & nginx Settings حالت proxy را روشن کنید.

می‌خواهم از نو نصب کنم
    فایل پیکربندی را پاک کنید (private/talkora-config.php یا
    api/config.php) و دوباره به setup/ بروید. اگر ادمین از قبل در
    دیتابیس باشد، نصب‌کننده حساب تازه نمی‌سازد و همان رمز قبلی
    معتبر می‌ماند.

قبل از استفادهٔ واقعی: بندهای S.8 (قواعد کد یک‌بارمصرف) و P.4
(خط خدماتی در برابر تبلیغاتی) در پوشهٔ docs را بخوانید.
"""

EMAIL_GUIDE = f"""راهنمای ایمیل کاری با دامنهٔ خودتان
════════════════════════════════════════════════════
هدف: آدرس‌هایی مثل  info@{SITE_DOMAIN}  و  admin@{SITE_DOMAIN}
که هم بتوانید با آن‌ها ایمیل بگیرید و بفرستید، هم درخواست‌های دموی
سایت به آن‌ها برسد.

⚠ این بخش کد نیست — کارِ پنل پلسک است. باید خودتان انجامش دهید.


─────────────────────────────────────────────────
گام ۱) سرویس ایمیل دامنه را روشن کنید

   Websites & Domains ← {SITE_DOMAIN} ← Mail ← Mail Settings
   تیک «Activate mail service on domain» را بزنید.

   اگر این گزینه را ندارید، پکیج هاست‌تان ایمیل ندارد و باید از
   شرکت هاست بخواهید فعالش کند.


گام ۲) صندوق‌ها را بسازید

   Mail ← Create Email Address

        info@{SITE_DOMAIN}    ← تماس عمومی و فرستندهٔ سایت
        admin@{SITE_DOMAIN}   ← شخصی خودتان

   برای هرکدام رمز قوی بگذارید. اندازهٔ صندوق پیش‌فرض کافی است.

   پیشنهاد: به‌جای صندوق دوم می‌توانید «Forwarding» بسازید تا همه‌چیز
   به یک صندوق برسد و مجبور نباشید دو جا را چک کنید.


گام ۳) رکوردهای DNS  ← این گام را رد نکنید

   بدون این‌ها ایمیل‌هایتان به پوشهٔ اسپم می‌رود یا اصلاً نمی‌رسد.
   پلسک معمولاً خودش می‌سازدشان؛ فقط بررسی کنید که باشند:

   Websites & Domains ← {SITE_DOMAIN} ← DNS Settings

     MX     {SITE_DOMAIN}         →  mail.{SITE_DOMAIN}   (اولویت ۱۰)
     A      mail.{SITE_DOMAIN}    →  آی‌پی سرور
     TXT    {SITE_DOMAIN}         →  v=spf1 a mx ~all
     DKIM                          ← از Mail Settings روشنش کنید
     TXT    _dmarc                 →  v=DMARC1; p=none; rua=mailto:admin@{SITE_DOMAIN}

   SPF می‌گوید «فقط سرور من حق دارد با نام دامنهٔ من ایمیل بفرستد».
   DKIM ایمیل را امضا می‌کند. DMARC می‌گوید با ایمیل جعلی چه کنند.
   هر سه رایگان‌اند و نبودشان یعنی ایمیل‌تان اسپم حساب می‌شود.


گام ۴) به پنل سوپرادمین وصلش کنید

   وارد  {ADMIN_URL}  شوید
   ← بخش «تماس و ایمیل»
   ← در «درخواست دموی رایگان به این آدرس برود» بنویسید:
        info@{SITE_DOMAIN}
   ← ذخیره
   ← دکمهٔ «ارسال ایمیل آزمایشی» را بزنید

   اگر رسید، تمام است. اگر نه، به بخش «مشکلات» پایین نگاه کنید.


گام ۵) خواندن ایمیل‌ها

   وب‌میل:   https://webmail.{SITE_DOMAIN}
   یا در موبایل و Outlook با این تنظیمات:

        IMAP   mail.{SITE_DOMAIN}   پورت ۹۹۳   SSL/TLS
        SMTP   mail.{SITE_DOMAIN}   پورت ۴۶۵   SSL/TLS
        نام کاربری: آدرس کامل ایمیل، نه فقط بخش اولش


─────────────────────────────────────────────────
مشکلات

ایمیل آزمایشی نمی‌رسد
    ۱. صندوق info@{SITE_DOMAIN} واقعاً ساخته شده؟
    ۲. در Mail Settings، گزینهٔ mail service روشن است؟
    ۳. پوشهٔ اسپم را نگاه کنید.
    ۴. بعضی هاست‌های اشتراکی تابع mail() را می‌بندند. اگر چنین است،
       از پشتیبانی هاست بخواهید بازش کنند.

ایمیل می‌رود ولی در اسپم می‌افتد
    گام ۳ ناقص است. مخصوصاً SPF و DKIM.
    بعد از اضافه‌کردن، تا ۲۴ ساعت طول می‌کشد.

ایمیل به Gmail نمی‌رسد ولی به بقیه می‌رسد
    گوگل سخت‌گیرترین است و بدون DKIM و DMARC رد می‌کند.

هیچ‌کدام کار نکرد
    درخواست‌های دمو با شکست ایمیل گم نمی‌شوند — همه در پنل سوپرادمین،
    بخش «درخواست‌های دمو» ثبت می‌شوند و ستون «ایمیل نرفت» نشان
    می‌دهد کدام‌ها ایمیل نشده‌اند. پس مشتری از دست نمی‌رود، فقط
    باید خودتان پنل را چک کنید تا ایمیل درست شود.

─────────────────────────────────────────────────
نکتهٔ صادقانه دربارهٔ ارسال ایمیل

کد از تابع mail() خود PHP استفاده می‌کند. برای اعلان «یک نفر دمو
خواست» کافی است، ولی صف و تلاش دوباره ندارد: اگر لحظهٔ ارسال سرور
ایمیل مشکل داشته باشد، آن یک ایمیل از دست می‌رود (خود درخواست نه).

اگر بعداً ایمیل برایتان حیاتی شد — مثل رسید پرداخت — باید به SMTP
با صف تبدیل شود. آن موقع سراغش می‌رویم.
"""

ADMIN_GUIDE = f"""راهنمای بالا آوردن پنل سوپرادمین — {ADMIN_DOMAIN}
════════════════════════════════════════════════════
این پنل مال شماست، نه مال آموزشگاه‌ها: کل پلتفرم را از اینجا اداره
می‌کنید — آموزشگاه‌ها، نقش کاربرها، ورود به‌جای کاربر برای پشتیبانی،
قیمت‌ها و متن‌های سایت، حالت پیامک، و سلامت سامانه.

⚠ این پنل دیتابیس یا جدول تازه نمی‌سازد. باید *قبلش* بستهٔ پنل
  ({PANEL_DOMAIN}) را نصب کرده باشید — همان نصب‌کننده هر ۲۰+ جدول را
  می‌سازد، از جمله جدول‌های این پنل. اینجا فقط با همان پایگاه داده
  از یک در دیگر وارد می‌شود.


گام ۱) ساخت زیردامنه در پلسک
   Websites & Domains ← Add Subdomain
        Subdomain name : admin
        Parent domain  : {SITE_DOMAIN}
        Document root  : پیش‌فرض پلسک را دست نزنید
   ← OK


گام ۲) نسخهٔ PHP
   روی زیردامنهٔ {ADMIN_DOMAIN} ← PHP Settings
        PHP version : ۸.۱ یا بالاتر، افزونهٔ pdo_mysql فعال
        اگر می‌خواهید «پیامک آزمایشی» از همین پنل هم کار کند، curl هم فعال باشد


گام ۳) پایگاه داده — نسازید، همان پایگاه دادهٔ پنل را استفاده کنید
   دیتابیس تازه‌ای لازم نیست. چهار مقداری که در نصب پنل
   ({PANEL_DOMAIN}) استفاده کردید را همین‌جا هم لازم دارید:
        db_host / db_name / db_user / db_pass

   اگر این‌ها را یادداشت نکرده بودید، در پلسک:
   Databases ← نام دیتابیس پنل ← Details


گام ۴) آپلود
   محتویات این بسته را در  httpdocs  زیردامنهٔ {ADMIN_DOMAIN} بریزید:
        httpdocs/index.html
        httpdocs/.htaccess
        httpdocs/robots.txt
        httpdocs/fonts/
        httpdocs/api/

   بعد  httpdocs/api/config.sample.php  را به  config.php  تغییر نام
   دهید و با یک ویرایشگر متن پر کنید:
        db_host / db_name / db_user / db_pass   ← دقیقاً مثل پنل
        otp_pepper                              ← دقیقاً مثل پنل، حرف به حرف
        admin_setup_key                          ← یک رشتهٔ تصادفی تازه (فقط برای حالت اضطراری گام ۶)

   ⚠ اگر otp_pepper با پنل یکی نباشد، کدهایی که از بخش «ورود و پیامک»
     این پنل می‌سازید هیچ‌وقت در پنل آموزشگاه تأیید نمی‌شوند — نه خطای
     واضحی می‌دهد، فقط کد همیشه «اشتباه» اعلام می‌شود.

   بهتر: config.php را در پوشهٔ private/ بالاتر از httpdocس همین
   زیردامنه بگذارید با نام talkora-config.php — آن‌وقت از وب اصلاً
   قابل خواندن نیست، حتی اگر پلسک .htaccess را نادیده بگیرد.


گام ۵) گواهی SSL  ← قبل از اولین ورود
   SSL/TLS Certificates ← Let's Encrypt ← {ADMIN_DOMAIN}
   کوکی نشست این پنل (tk_platform) هم پرچم Secure دارد، درست مثل پنل.


گام ۶) ورود
   باز کنید:  {ADMIN_URL}

   نصب‌کنندهٔ پنل (گام ۳ همان‌جا، «حساب مدیر») از قبل یک حساب در جدول
   admin_user ساخته — همان دیتابیس مشترک است، پس همین‌جا هم همان
   نام‌کاربری و رمز کار می‌کند. فرم ورود را می‌بینید، نه فرم ساخت حساب؛
   با همان‌ها وارد شوید.

   ⚠ فرم «ساخت حساب سوپرادمین» را فقط در یک حالت می‌بینید: دیتابیسی که
   به آن وصل شده‌اید هنوز هیچ ردیف admin_user ندارد (مثلاً نصب‌کنندهٔ
   پنل را دور زده‌اید، یا آن حساب را دستی از دیتابیس پاک کرده‌اید). آن
   وقت admin_setup_key گام ۴ همان کلیدی است که وارد می‌کنید.


گام ۷) بررسی
   سربرگ «سلامت سامانه» را باز کنید:
        پایگاه داده  باید «متصل — N جدول (مشترک با پنل آموزشگاه‌ها)» بگوید
        کلید امضای کدها  باید سالم باشد — یعنی otp_pepper درست کپی شده

   بعد سربرگ «آموزشگاه‌ها» را باز کنید. اگر آموزشگاهی از قبل در پنل
   ثبت‌نام کرده، باید همین‌جا هم دیده شود — نشانهٔ اینکه واقعاً به
   همان پایگاه داده وصل هستید، نه یک دیتابیس خالی تازه.


════════════════════════════════════════════════════
مشکلات رایج

«سوپرادمین از قبل ساخته شده. از صفحهٔ ورود استفاده کنید»
    طبیعی است — نصب‌کنندهٔ پنل همان حساب را ساخته. با نام‌کاربری و رمزی
    که آنجا («حساب مدیر») ساختید وارد شوید؛ اگر همان‌ها را وارد کردید و
    باز هم رد شد، db_host/db_name را با پنل مقایسه کنید — شاید به
    دیتابیس دیگری وصل شده‌اید.

سربرگ «آموزشگاه‌ها» همیشه خالی است ولی در پنل آموزشگاه‌ها داده هست
    db_name (یا db_host) با پنل یکی نیست — دو دیتابیس جدا شده‌اید.

کد ورودی که از این پنل می‌سازید در پنل آموزشگاه رد می‌شود
    otp_pepper دو طرف یکی نیست. حرف‌به‌حرف با هم مقایسه کنید؛ یک
    فاصلهٔ اضافه در کپی-پیست کافی است که خراب شود.

لینک «ورود به‌جای کاربر» باز می‌شود ولی به صفحهٔ ورود پنل می‌رسد
    تیکت ۶۰ ثانیه‌ای منقضی شده — دوباره از «ورود به‌جای کاربر» بسازید
    و این‌بار سریع‌تر باز کنید. اگر بازهم همین شد، ساعت سرور این
    زیردامنه با پنل هماهنگ نیست.
"""

# ─────────────────────────── ساخت ───────────────────────────


def copy_tree_filtered(src: pathlib.Path, dst: pathlib.Path, skip: set) -> None:
    dst.mkdir(parents=True, exist_ok=True)
    for f in sorted(src.iterdir()):
        if f.name in skip:
            continue
        if f.is_dir():
            copy_tree_filtered(f, dst / f.name, skip)
        else:
            shutil.copy2(f, dst / f.name)


def check_external(folder: pathlib.Path) -> list:
    bad = []
    for f in folder.rglob("*.html"):
        text = f.read_text(encoding="utf-8")
        for m in re.findall(r'(?:src|href)=["\'](https?://[^"\']+)', text):
            if not m.startswith(ALLOWED_EXTERNAL):
                bad.append(f"{f.relative_to(folder)}: {m}")
        for m in re.findall(r'url\(["\']?(https?://[^"\')]+)', text):
            bad.append(f"{f.relative_to(folder)}: url({m})")
    return bad


def check_inline_handlers(folder: pathlib.Path) -> list:
    """
    شنوندهٔ درون‌خطی که تابعِ داخل IIFE را صدا می‌زند، بی‌صدا می‌شکند.

    هر سه پنل، کل جاوااسکریپتشان را در یک (function(){...})() می‌پیچند.
    صفت‌هایی مثل onchange="foo()" اسم را فقط در دامنهٔ سراسری می‌گردند،
    و foo آنجا نیست — پس ReferenceError می‌دهد که مرورگر فقط در کنسول
    می‌نویسد و کاربر هیچ نشانه‌ای نمی‌بیند.

    یک‌بار همین افتاد: در فرم کلاس، onchange="onClProvChange()" هرگز
    اجرا نشد. مدیری که «جلسهٔ میت» را انتخاب می‌کرد همچنان فیلد «لینک
    ثابت جلسه» را می‌دید — لینکی که سرور خودش می‌سازد — با راهنمای
    «برای کلاس آنلاین لازم است»، و متن راهنما هم مال ارائه‌دهندهٔ قبلی
    می‌ماند.

    فراخوانی‌هایی که فقط به سراسری‌های خودِ مرورگر دست می‌زنند (مثل
    event.stopPropagation()) مشکلی ندارند و رد می‌شوند.
    """
    safe = re.compile(r"^\s*(?:event|this|window|return\s+false)\b[\w.()\s;]*$")
    bad = []
    for f in sorted(folder.rglob("*.html")):
        text = f.read_text(encoding="utf-8")
        lines = text.split("\n")
        for m in re.finditer(r'\bon[a-z]+\s*=\s*"([^"]*)"', text):
            body = m.group(1)
            if safe.match(body):
                continue
            line = text[: m.start()].count("\n") + 1
            # توضیحِ خودِ همین قاعده نباید به آن گیر کند: خطی که با
            # * یا // شروع می‌شود کامنت است، نه نشانه‌گذاری
            if re.match(r"\s*(\*|//|/\*)", lines[line - 1]):
                continue
            bad.append(f"{f.relative_to(folder)}:{line}  {m.group(0)[:70]}")
    return bad


def check_permissions(root: pathlib.Path) -> list:
    """
    یکپارچگی داده‌های پایهٔ دسترسی را می‌سنجد.

    این خطاها وگرنه فقط موقع نصب واقعی معلوم می‌شوند — یک کلید مجوز
    تایپی یعنی نصب نیمه‌تمام با خطای کلید خارجی. مهم‌تر از همه، بند
    آخر: مجوز سطح پلتفرم هرگز نباید به نقشی بچسبد، چون تنها راه
    رسیدن به سطح مالک همین است.
    """
    sql_file = root / "panel" / "api" / "schema.mysql.sql"
    php_file = root / "panel" / "api" / "_perm.php"
    if not sql_file.exists() or not php_file.exists():
        return []                      # هنوز نسخهٔ ۶ اعمال نشده

    sql = sql_file.read_text(encoding="utf-8")
    bad = []

    # همهٔ بلوک‌ها، نه فقط اولی: کاتالوگ در یک INSERT شروع می‌شود ولی
    # هر نسخهٔ بعدی چند مجوز تازه در بلوک جدا اضافه می‌کند. با
    # re.search فقط بلوک اول دیده می‌شد و مجوزهای تازه «ناشناخته»
    # اعلام می‌شدند — خطایی که ساخت را می‌خواباند بی‌آنکه چیزی خراب باشد.
    perms = {}
    for blk in re.finditer(r"INSERT INTO permission .*?VALUES\s*(.*?);", sql, re.S):
        for g in re.findall(
            r"\('([a-z0-9_.]+)',\s*'[a-z]+',\s*'[^']*',\s*(\d),", blk.group(1)
        ):
            perms[g[0]] = int(g[1])
    if not perms:
        return ["کاتالوگ مجوزها در اسکیما نیست"]

    roles = set()
    mr = re.search(r"INSERT INTO role \(.*?VALUES\s*(.*?);", sql, re.S)
    if mr:
        roles = set(re.findall(r"\('(r_[a-z_]+)'", mr.group(1)))

    known_scopes = set()
    ms = re.search(r"PERM_SCOPES = \[(.*?)\]", php_file.read_text(encoding="utf-8"))
    if ms:
        known_scopes = {s.strip().strip("'") for s in ms.group(1).split(",")}

    for blk in re.finditer(r"INSERT INTO role_permission .*?VALUES(.*?);", sql, re.S):
        for role, perm, scope in re.findall(
            r"\('(r_[a-z_]+)',\s*'([a-z0-9_.]+)',\s*\d,\s*'([a-z_]+)'\)", blk.group(1)
        ):
            if role not in roles:
                bad.append(f"نقش ناشناخته در role_permission: {role}")
            if perm not in perms:
                bad.append(f"مجوز ناشناخته در role_permission: {perm} (نقش {role})")
            elif perms[perm] == 1:
                bad.append(f"مجوز سطح پلتفرم به نقش چسبیده — دیوار مالک شکسته: {perm} → {role}")
            if known_scopes and scope not in known_scopes:
                bad.append(f"محدودهٔ ناشناخته برای موتور: {scope} ({perm})")

    return bad


def zip_folder(folder: pathlib.Path, out: pathlib.Path) -> None:
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for f in sorted(folder.rglob("*")):
            if f.is_file():
                z.write(f, f.relative_to(folder).as_posix())


def main() -> int:
    site_src = ROOT / "site" / "index.html"
    app_src = ROOT / "app" / "index.html"
    fonts = ROOT / "site" / "fonts"
    api_src = ROOT / "panel" / "api"
    admin_src = ROOT / "superadmin" / "index.html"
    admin_api_src = ROOT / "superadmin" / "api"

    for p in (site_src, app_src, fonts, api_src, admin_src, admin_api_src):
        if not p.exists():
            print(f"خطا: {p} پیدا نشد", file=sys.stderr)
            return 1

    # config.php واقعی نباید هرگز داخل بسته برود
    if (api_src / "config.php").exists():
        print("خطا: panel/api/config.php وجود دارد و ممکن است رمز واقعی داشته باشد.\n"
              "      قبل از ساخت بسته پاکش کنید؛ فقط config.sample.php منتشر می‌شود.",
              file=sys.stderr)
        return 1

    if (admin_api_src / "config.php").exists():
        print("خطا: superadmin/api/config.php وجود دارد و ممکن است رمز واقعی داشته باشد.\n"
              "      قبل از ساخت بسته پاکش کنید؛ فقط config.sample.php منتشر می‌شود.",
              file=sys.stderr)
        return 1

    if DIST.exists():
        shutil.rmtree(DIST)

    # ── بستهٔ سایت ─────────────────────────────────────────────
    site_out = DIST / "site"
    site_out.mkdir(parents=True)

    site_html = site_src.read_text(encoding="utf-8")

    # لینک‌های ورود و ثبت‌نام به زیردامنهٔ پنل می‌روند، نه به لنگر خالی.
    #
    # لنگرِ کوتاه (#login) برای وقتی است که سایت را محلی باز می‌کنید؛
    # اینجا به نشانی واقعی تبدیل می‌شود. ولی جاهایی نشانی کامل از قبل
    # در HTML نوشته شده و آنجا چیزی برای جایگزینی نیست — که درست است،
    # نه نشانهٔ خرابی. هشدار فقط وقتی معنا دارد که *هیچ‌کدام* نباشد،
    # وگرنه هر ساخت دو هشدار همیشگی می‌دهد و هشدارِ همیشگی دیده نمی‌شود.
    for anchor, target, what in (
        ("#login",  PANEL_URL,                 "ورود"),
        ("#signup", f"{PANEL_URL}#/signup",    "ثبت‌نام"),
    ):
        site_html, n = re.subn(f'href="{anchor}"', f'href="{target}"', site_html)
        if n == 0 and target not in site_html:
            print(f"هشدار: نه لینک {anchor} در سایت هست نه نشانی کامل؛ "
                  f"دکمهٔ {what} بررسی شود.", file=sys.stderr)
    (site_out / "index.html").write_text(site_html, encoding="utf-8")
    shutil.copytree(fonts, site_out / "fonts")
    (site_out / ".htaccess").write_text(SITE_HTACCESS, encoding="utf-8")
    (site_out / "robots.txt").write_text(SITE_ROBOTS, encoding="utf-8")
    (site_out / "sitemap.xml").write_text(SITEMAP, encoding="utf-8")
    (site_out / "sitemap-pages.xml").write_text(SITEMAP_PAGES, encoding="utf-8")

    # وبلاگ: صفحه‌های سمت‌سرور، شیوه‌نامه، و پوشهٔ تصویرها.
    #
    # uploads خالی کپی می‌شود ولی محافظش می‌رود: پوشه‌ای که فایل
    # آپلودی می‌گیرد و PHP اجرا می‌کند، بدترین سوراخ ممکن است.
    #
    # بازنویسی نشانی‌ها هر دو صورت را دارد — .htaccess برای آپاچی و
    # web.config برای IIS. هاست فعلی ویندوز است و .htaccess را
    # نمی‌خواند؛ اگر فقط یکی را می‌فرستادیم، روی نصب بعدی بی‌سروصدا
    # بی‌اثر می‌شد.
    #
    # ولی uploads فقط .htaccess دارد. نسخهٔ IIS‌اش امتحان شد و روی
    # این هاست کل پوشه را ۵۰۰ کرد: تفویضِ requestFiltering در آن سطح
    # بسته است — با <remove> هم درست نشد. یک ۵۰۰ در پوشهٔ تصویرها
    # هر تصویر شاخصِ هر نوشته را می‌شکند، که بدتر از نداشتنِ آن لایه
    # است. محافظ اصلی جای دیگری است: blog_safe_file() نام فایل را
    # محدود می‌کند و آپلود فقط تصویر می‌پذیرد.
    blog_src = ROOT / "site" / "blog"
    blog_out = site_out / "blog"
    blog_out.mkdir()
    for f in sorted(blog_src.glob("*.php")):
        shutil.copy2(f, blog_out / f.name)
    shutil.copy2(blog_src / ".htaccess", blog_out / ".htaccess")
    shutil.copy2(blog_src / "web.config", blog_out / "web.config")
    shutil.copytree(blog_src / "assets", blog_out / "assets")
    (blog_out / "uploads").mkdir()
    shutil.copy2(blog_src / "uploads" / ".htaccess", blog_out / "uploads" / ".htaccess")
    (site_out / "راهنمای-آپلود.txt").write_text(SITE_GUIDE, encoding="utf-8")

    # سایت هم یک api کوچک می‌گیرد: تنظیماتی که ادمین عوض می‌کند و فرم دمو.
    # بقیهٔ سایت هنوز HTML ایستاست؛ اگر PHP بمیرد صفحه سالم بالا می‌آید.
    site_api = site_out / "api"
    site_api.mkdir()
    for f in ("_bootstrap.php", "_settings.php", "public.php", "config.sample.php"):
        shutil.copy2(api_src / f, site_api / f)
    (site_api / ".htaccess").write_text(SITE_API_HTACCESS, encoding="utf-8")

    # ── بستهٔ پنل ─────────────────────────────────────────────
    panel_out = DIST / "panel"
    panel_out.mkdir(parents=True)

    # فونت‌ها کنار خود پنل می‌آیند: زیردامنه به فایل‌های دامنهٔ اصلی دسترسی ندارد
    app_html = app_src.read_text(encoding="utf-8").replace("../site/fonts/", "fonts/")
    (panel_out / "index.html").write_text(app_html, encoding="utf-8")
    shutil.copytree(fonts, panel_out / "fonts")
    # schema فقط در phpMyAdmin کپی می‌شود و هرگز روی سرور لازم نیست.
    # جدا نگهش می‌داریم تا اگر پلسک روی حالت nginx-only بود و .htaccess را
    # نخواند، ساختار جدول‌ها از روی وب قابل خواندن نباشد.
    copy_tree_filtered(api_src, panel_out / "api", skip={"config.php", "schema.mysql.sql"})

    # اسکیما باید روی سرور باشد تا نصب‌کننده بتواند جدول‌ها را بسازد، ولی
    # فایل .sql در پوشهٔ وب اگر پلسک روی nginx-only باشد به‌صورت متن خوانده
    # می‌شود. پس همان SQL را داخل یک فایل PHP می‌گذاریم: PHP هرگز به‌صورت
    # متن سرو نمی‌شود، حتی وقتی .htaccess نادیده گرفته شده.
    schema_sql = (api_src / "schema.mysql.sql").read_text(encoding="utf-8")
    (panel_out / "api" / "_schema.php").write_text(
        "<?php\n"
        "// اگر مستقیم از مرورگر باز شود، چیزی برنگرداند\n"
        "if (realpath(__FILE__) === realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')))"
        " { http_response_code(404); exit; }\n"
        "/* ساختار پایگاه داده — از panel/api/schema.mysql.sql تولید شده. دستی ویرایش نکنید. */\n"
        "return <<<'TALKORA_SQL'\n" + schema_sql.rstrip() + "\nTALKORA_SQL;\n",
        encoding="utf-8")

    # نصب‌کنندهٔ تحت وب
    shutil.copytree(ROOT / "panel" / "setup", panel_out / "setup")

    setup = panel_out / "_نصب-آپلود-نکنید"
    setup.mkdir()
    shutil.copy2(api_src / "schema.mysql.sql", setup / "پایگاه-داده.sql")
    (setup / "بخوانید.txt").write_text(
        "این پوشه نسخهٔ پشتیبان است و لازم نیست آپلودش کنید.\n\n"
        "راه معمول ساخت جدول‌ها، صفحهٔ /setup/ روی خود هاست است؛ همه‌چیز را\n"
        "خودش انجام می‌دهد. فقط اگر آن صفحه به هر دلیلی کار نکرد، محتویات\n"
        "پایگاه-داده.sql را در phpMyAdmin پلسک (تب SQL) بچسبانید و Go بزنید.\n"
        "بعد از آن، صفحهٔ /setup/ بقیهٔ کار — پیکربندی و ساخت حساب مدیر — را\n"
        "انجام می‌دهد و جدول‌های موجود را دوباره نمی‌سازد.\n",
        encoding="utf-8")
    (panel_out / ".htaccess").write_text(PANEL_HTACCESS, encoding="utf-8")
    (panel_out / "robots.txt").write_text(PANEL_ROBOTS, encoding="utf-8")
    (panel_out / "راهنمای-پنل.txt").write_text(PANEL_GUIDE, encoding="utf-8")
    (panel_out / "راهنمای-ایمیل.txt").write_text(EMAIL_GUIDE, encoding="utf-8")

    # ── بستهٔ سوپرادمین ───────────────────────────────────────
    # مسیر جدا (زیردامنهٔ خودش، نه زیرپوشهٔ پنل)، نشست جدا (tk_platform)،
    # رمز جدا (نام کاربری/رمز، نه پیامک). دیتابیس مشترک با پنل است —
    # جدول تازه نمی‌سازد، فقط کدش را می‌خواند، پس اینجا _schema.php لازم
    # نیست.
    admin_out = DIST / "admin"
    admin_out.mkdir(parents=True)

    (admin_out / "index.html").write_text(admin_src.read_text(encoding="utf-8"), encoding="utf-8")
    shutil.copytree(fonts, admin_out / "fonts")
    copy_tree_filtered(admin_api_src, admin_out / "api", skip={"config.php"})
    (admin_out / ".htaccess").write_text(ADMIN_HTACCESS, encoding="utf-8")
    (admin_out / "robots.txt").write_text(ADMIN_ROBOTS, encoding="utf-8")
    (admin_out / "راهنمای-سوپرادمین.txt").write_text(ADMIN_GUIDE, encoding="utf-8")

    # ── بررسی‌ها ───────────────────────────────────────────────
    perm_problems = check_permissions(ROOT)
    if perm_problems:
        print("خطا: داده‌های پایهٔ دسترسی ناسازگارند:", file=sys.stderr)
        for p in perm_problems:
            print("   ", p, file=sys.stderr)
        return 1

    bad = check_external(site_out) + check_external(panel_out) + check_external(admin_out)
    if bad:
        print("خطا: ارجاع به دامنهٔ خارجی:", file=sys.stderr)
        for b in bad:
            print("   ", b, file=sys.stderr)
        return 1

    inline = (check_inline_handlers(site_out) + check_inline_handlers(panel_out)
              + check_inline_handlers(admin_out))
    if inline:
        print("خطا: شنوندهٔ درون‌خطی که تابعِ داخل IIFE را صدا می‌زند:", file=sys.stderr)
        for b in inline:
            print("   ", b, file=sys.stderr)
        print("    با addEventListener ببندید — صفت درون‌خطی آن تابع را نمی‌بیند.",
              file=sys.stderr)
        return 1

    if (panel_out / "api" / "config.php").exists():
        print("خطا: config.php داخل بستهٔ پنل رفته است.", file=sys.stderr)
        return 1

    if (panel_out / "api" / "schema.mysql.sql").exists():
        print("خطا: schema داخل پوشهٔ api مانده است.", file=sys.stderr)
        return 1

    # پنل باید بداند بک‌اند کجاست، وگرنه همیشه نمایشی می‌ماند
    if "api/health.php" not in (panel_out / "index.html").read_text(encoding="utf-8"):
        print("خطا: پنل به api/health.php وصل نیست.", file=sys.stderr)
        return 1

    if "../site/fonts/" in (panel_out / "index.html").read_text(encoding="utf-8"):
        print("خطا: پنل هنوز فونت را از دامنهٔ اصلی می‌خواند.", file=sys.stderr)
        return 1

    if not (panel_out / "setup" / "index.html").exists():
        print("خطا: نصب‌کننده در بسته نیست؛ راه‌اندازی دستی می‌شود.", file=sys.stderr)
        return 1

    # نصب‌کننده بدون اسکیما فقط یک فرم بی‌فایده است
    if not (panel_out / "api" / "_schema.php").exists():
        print("خطا: _schema.php ساخته نشد؛ نصب‌کننده نمی‌تواند جدول بسازد.", file=sys.stderr)
        return 1

    made_schema = (panel_out / "api" / "_schema.php").read_text(encoding="utf-8")
    n_tables = made_schema.count("CREATE TABLE")
    if n_tables < 20:
        print(f"خطا: _schema.php فقط {n_tables} جدول دارد؛ ناقص تولید شده.", file=sys.stderr)
        return 1
    # هیچ‌جای SQL نباید پایان‌بند heredoc را قطع کند
    if "\nTALKORA_SQL" in made_schema[:-20]:
        print("خطا: متن اسکیما پایان‌بند heredoc را می‌شکند.", file=sys.stderr)
        return 1

    if not (site_out / "api" / "public.php").exists():
        print("خطا: api سایت در بسته نیست؛ فرم دمو و قیمت‌ها کار نمی‌کند.", file=sys.stderr)
        return 1

    if "api/public.php" not in (site_out / "index.html").read_text(encoding="utf-8"):
        print("خطا: سایت به api/public.php وصل نیست.", file=sys.stderr)
        return 1

    # دکمه‌های ورود و ثبت‌نام باید به پنل برسند، نه به لنگر خالی همان صفحه
    built_site = (site_out / "index.html").read_text(encoding="utf-8")
    for anchor in ('href="#login"', 'href="#signup"'):
        if anchor in built_site:
            print(f"خطا: {anchor} در بستهٔ سایت بازنویسی نشده؛ دکمه به جایی نمی‌رود.", file=sys.stderr)
            return 1
    if f'{PANEL_URL}#/signup' not in built_site:
        print("خطا: دکمهٔ ثبت‌نام در بستهٔ سایت نیست.", file=sys.stderr)
        return 1

    # میان‌بر ثبت‌نام باید در خود پنل هم پیاده شده باشد، وگرنه لینک به صفحهٔ ورود ساده می‌افتد
    if "signup" not in (panel_out / "index.html").read_text(encoding="utf-8"):
        print("خطا: پنل مسیر #/signup را نمی‌شناسد.", file=sys.stderr)
        return 1

    # سوپرادمین باید به api/super.php وصل باشد، وگرنه هیچ درخواستی نمی‌رود
    if "super.php" not in (admin_out / "index.html").read_text(encoding="utf-8"):
        print("خطا: پنل سوپرادمین به api/super.php وصل نیست.", file=sys.stderr)
        return 1

    if not (admin_out / "api" / "super.php").exists():
        print("خطا: api/super.php در بستهٔ سوپرادمین نیست.", file=sys.stderr)
        return 1

    if "../site/fonts/" in (admin_out / "index.html").read_text(encoding="utf-8"):
        print("خطا: پنل سوپرادمین فونت را از دامنهٔ دیگری می‌خواند.", file=sys.stderr)
        return 1

    for pkg in (site_out, panel_out, admin_out):
        if (pkg / "api" / "config.php").exists():
            print(f"خطا: config.php واقعی داخل {pkg.name} رفته است.", file=sys.stderr)
            return 1
        if (pkg / "api" / "schema.mysql.sql").exists():
            print(f"خطا: schema.mysql.sql داخل {pkg.name}/api مانده است.", file=sys.stderr)
            return 1

    # ── زیپ‌ها ────────────────────────────────────────────────
    site_zip = ROOT / "talkora-site.zip"
    panel_zip = ROOT / "talkora-panel.zip"
    admin_zip = ROOT / "talkora-admin.zip"
    zip_folder(site_out, site_zip)
    zip_folder(panel_out, panel_zip)
    zip_folder(admin_out, admin_zip)

    packages = (
        ("سایت", site_out, site_zip),
        ("پنل", panel_out, panel_zip),
        ("سوپرادمین", admin_out, admin_zip),
    )
    for label, folder, zf in packages:
        total = sum(f.stat().st_size for f in folder.rglob("*") if f.is_file())
        print(f"\n── بستهٔ {label}  →  {folder.relative_to(ROOT)}")
        for f in sorted(folder.rglob("*")):
            if f.is_file():
                print(f"   {f.relative_to(folder)}  ({f.stat().st_size / 1024:.0f} کیلوبایت)")
        print(f"   مجموع {total / 1024:.0f} کیلوبایت  →  {zf.name} ({zf.stat().st_size / 1024:.0f} کیلوبایت)")

    print(f"\nبستهٔ سایت را در httpdocs دامنهٔ {SITE_DOMAIN} بریزید.")
    print(f"بستهٔ پنل را در httpdocs زیردامنهٔ {PANEL_DOMAIN} بریزید (راهنمای-پنل.txt را بخوانید).")
    print(f"بستهٔ سوپرادمین را در httpdocs زیردامنهٔ {ADMIN_DOMAIN} بریزید — بعد از پنل "
          f"(راهنمای-سوپرادمین.txt را بخوانید).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
