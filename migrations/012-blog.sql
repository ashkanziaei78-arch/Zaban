-- ═══════════════════════════════════════════════════════════════════
--  نسخهٔ ۱۲ — وبلاگ
--
--  هدف سئوست، نه انتشار محتوا برای خودش. یعنی هر تصمیم اینجا از یک
--  پرسش می‌آید: «گوگل این را چطور می‌بیند؟»
--
--  از همین‌جا دو چیز نتیجه می‌شود که در کد هم پیداست:
--
--    نوشته‌ها *سمت سرور* رندر می‌شوند، نه با جاوااسکریپت. پنل‌ها
--    تک‌صفحه‌ای‌اند چون کاربرشان وارد شده و منتظر می‌ماند؛ خزندهٔ گوگل
--    منتظر نمی‌ماند. هر نوشته باید HTML کامل در نشانی خودش باشد.
--
--    نشانی بخشی از محتواست. slug جدا از عنوان ذخیره می‌شود تا عنوان
--    قابل ویرایش بماند بی‌آنکه نشانیِ ایندکس‌شده بشکند — تغییر نشانی
--    یعنی از دست دادن هرچه گوگل تا امروز جمع کرده.
-- ═══════════════════════════════════════════════════════════════════


-- ── دسته‌بندی ─────────────────────────────────────────────────────
-- برای پیوند داخلی، که مهم‌ترین چیزی است که یک سایت کوچک برای سئو
-- دارد: صفحهٔ دسته، نوشته‌های هم‌موضوع را به هم وصل می‌کند.
CREATE TABLE IF NOT EXISTS blog_category (
  id          CHAR(32)     NOT NULL PRIMARY KEY,
  slug        VARCHAR(120) NOT NULL,
  name        VARCHAR(120) NOT NULL,
  description VARCHAR(300) NULL,
  sort_order  SMALLINT     NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL,
  UNIQUE KEY uq_blogcat_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── نوشته ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS blog_post (
  id          CHAR(32)     NOT NULL PRIMARY KEY,

  /*
   * نشانی نوشته. فارسی مجاز است.
   *
   * گوگل نشانی فارسیِ درصدکدشده را می‌فهمد و در نتیجه‌ها بازگشایی‌شده
   * نشان می‌دهد — یعنی «راهنمای-آزمون-آیلتس» هم برای خزنده روشن است
   * هم برای کسی که لینک را می‌بیند. برگرداندنش به فینگلیش، همان
   * کلیدواژه‌ای را که دنبالش هستیم از نشانی حذف می‌کند.
   *
   * ۱۸۰ نویسه چون utf8mb4 هر نویسه را تا چهار بایت می‌گیرد و کلید
   * یکتا در InnoDB سقف ۳۰۷۲ بایت دارد؛ ۱۸۰×۴ جا می‌شود.
   */
  slug        VARCHAR(180) NOT NULL,

  title       VARCHAR(200) NOT NULL,
  excerpt     VARCHAR(400) NULL,     -- چکیده برای فهرست و شبکه‌های اجتماعی
  body        MEDIUMTEXT   NOT NULL, -- HTML پالوده‌شده

  /*
   * تصویر شاخص، با متن جایگزین.
   *
   * alt جدا و اجباری‌نما نیست ولی در ویرایشگر خواسته می‌شود: تصویر بی
   * alt هم برای صفحه‌خوان بی‌معناست هم برای جست‌وجوی تصویر گوگل.
   */
  cover_path  VARCHAR(200) NULL,
  cover_alt   VARCHAR(200) NULL,

  category_id CHAR(32)     NULL,

  /*
   * عنوان و توضیح متا، جدا از عنوان و چکیده.
   *
   * عنوان صفحه برای خواننده نوشته می‌شود، عنوان متا برای نتیجهٔ
   * جست‌وجو — و این دو همیشه یکی نیستند. خالی که باشند، از عنوان و
   * چکیده پر می‌شوند، پس نویسنده مجبور نیست هر بار هر دو را بنویسد.
   */
  meta_title       VARCHAR(200) NULL,
  meta_description VARCHAR(300) NULL,

  -- نام نویسنده مستقل از حساب ذخیره می‌شود تا حذف حساب، نوشته را
  -- بی‌نویسنده نکند
  author_name  VARCHAR(120) NOT NULL DEFAULT 'تیم تاکورا',
  author_admin CHAR(32)     NULL,

  status       VARCHAR(16)  NOT NULL DEFAULT 'draft',   -- draft | published

  /*
   * published_at جدا از created_at.
   *
   * نوشته‌ای که سه هفته پیش‌نویس بوده، تاریخ انتشارش امروز است نه
   * روزی که شروع شده. تاریخ در نتیجهٔ گوگل دیده می‌شود و اشتباهش
   * یعنی محتوای تازه، کهنه به نظر برسد.
   */
  published_at DATETIME     NULL,
  updated_at   DATETIME     NOT NULL,
  created_at   DATETIME     NOT NULL,

  views        INT UNSIGNED NOT NULL DEFAULT 0,
  reading_min  TINYINT UNSIGNED NOT NULL DEFAULT 1,

  UNIQUE KEY uq_blogpost_slug (slug),

  -- پرس‌وجوی داغ: «نوشته‌های منتشرشده، تازه‌ترین اول»
  KEY ix_blogpost_live (status, published_at),
  KEY ix_blogpost_cat (category_id, status, published_at),

  CONSTRAINT ck_blogpost_status CHECK (status IN ('draft','published')),
  CONSTRAINT fk_blogpost_cat FOREIGN KEY (category_id)
    REFERENCES blog_category(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── مجوزها ────────────────────────────────────────────────────────
-- وبلاگ کار پلتفرم است نه آموزشگاه، پس مجوزهایش سطح پلتفرم‌اند و
-- طبق دیوار مالک (نسخهٔ ۶) هرگز به نقش آموزشگاهی نمی‌چسبند.
INSERT INTO permission (perm_key, group_key, label_fa, is_platform, is_write, sort_order) VALUES
('blog.view',    'blog', 'دیدن نوشته‌های وبلاگ',   1, 0, 10),
('blog.write',   'blog', 'نوشتن و ویرایش وبلاگ',  1, 1, 20),
('blog.publish', 'blog', 'انتشار نوشتهٔ وبلاگ',    1, 1, 30);


-- ── دسته‌های آغازین ───────────────────────────────────────────────
-- خالی‌بودن دسته‌ها یعنی اولین نوشته جایی برای نشستن ندارد.
INSERT INTO blog_category (id, slug, name, description, sort_order, created_at) VALUES
('bc_teaching', 'آموزش-زبان',   'آموزش زبان',
 'روش تدریس، طرح درس و کار با کتاب', 10, UTC_TIMESTAMP()),
('bc_manage',   'مدیریت-آموزشگاه', 'مدیریت آموزشگاه',
 'ثبت‌نام، شهریه، برنامهٔ ترم و نگهداشت زبان‌آموز', 20, UTC_TIMESTAMP()),
('bc_exam',     'آزمون‌ها',      'آزمون‌ها',
 'آیلتس، تافل و آزمون‌های تعیین سطح', 30, UTC_TIMESTAMP()),
('bc_product',  'تاکورا',        'تاکورا',
 'قابلیت‌های تازه و راهنمای کار با پنل', 40, UTC_TIMESTAMP());
