-- ۰۱۳ — مجوز جلسهٔ میت برای مدیرها
--
-- کد از دو جا صریح می‌گوید مدیر این مجوز را «از ابتدا دارد»:
-- schema.mysql.sql بند نسخهٔ ۵، و کامنت setMeetingAccess در
-- institute.php («نه به خودش، که از ابتدا دارد»).
--
-- ولی پیاده‌سازی‌اش فقط یک UPDATE یک‌باره در همان بند نسخهٔ ۵ بود:
--
--   ALTER TABLE membership ADD COLUMN can_host_meeting TINYINT(1) NOT NULL DEFAULT 0;
--   UPDATE membership SET can_host_meeting = 1 WHERE role = 'manager';
--
-- یعنی مدیرهایی که آن روز وجود داشتند مجوز گرفتند و بس. هر مدیری که
-- از آن به بعد ساخته شد — هر ثبت‌نام تازه، هر آموزشگاهی که سوپرادمین
-- ساخت — پیش‌فرضِ ستون را گرفت که صفر است.
--
-- نتیجه‌اش این بود که مدیرِ یک آموزشگاه تازه نمی‌توانست کلاس آنلاین
-- جیتسی بسازد، و پیام خطا هم او را به «مدیر آموزشگاه» ارجاع می‌داد.
--
-- این مهاجرت داده را ترمیم می‌کند. جلوگیری از تکرارش در کد است: هر ۹
-- جای INSERT INTO membership حالا can_host_meeting را می‌نویسد و
-- tests/membership-meeting-access.py نگهبانش است.

START TRANSACTION;

-- فقط مدیرهایی که هنوز صفرند. عمداً به آموزشگاهِ فعال محدود نشده:
-- آموزشگاه معلق هم روزی برمی‌گردد و آن‌وقت مدیرش باید مجوز داشته باشد.
UPDATE membership
   SET can_host_meeting = 1
 WHERE role = 'manager'
   AND can_host_meeting = 0;

-- مدرس و زبان‌آموز عمداً دست‌نخورده می‌مانند: مجوز مدرس را مدیر
-- می‌دهد (institute.php: setMeetingAccess) و زبان‌آموز اصلاً میزبان
-- نمی‌شود.

COMMIT;

-- بررسی پس از اجرا — باید صفر باشد:
--   SELECT COUNT(*) FROM membership WHERE role='manager' AND can_host_meeting=0;
