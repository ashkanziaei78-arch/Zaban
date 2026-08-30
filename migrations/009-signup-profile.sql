-- ═══════════════════════════════════════════════════════════════════
--  نسخهٔ ۹ — ثبت‌نام کامل و پروفایل کاربر
--
--  تا اینجا «ثبت‌نام» یعنی ساخت آموزشگاه تازه، و کاربر خودبه‌خود مدیرش
--  می‌شد. مدرس و زبان‌آموز راهی برای عضو شدن نداشتند مگر اینکه مدیر
--  شماره‌شان را دستی دعوت کند.
--
--  از اینجا دو راه ورود هست و کاربر خودش انتخاب می‌کند:
--
--    ۱) کد پیوستن — آموزشگاه یک کد کوتاه دارد و پخشش می‌کند. هرکس
--       کد را وارد کند، مستقیم و بدون معطلی عضو می‌شود. برای کلاسی
--       که سر ترم سی نفر با هم می‌آیند، این تنها راه عملی است.
--
--    ۲) درخواست پیوستن — کد ندارد، آموزشگاه را از فهرست انتخاب
--       می‌کند و در صف تأیید می‌نشیند. کندتر است ولی کنترل کامل به
--       آموزشگاه می‌دهد.
--
--  چرا هر دو: کد سریع است ولی هرکه کد را داشته باشد وارد می‌شود، و
--  کد لو می‌رود. صف امن است ولی برای ثبت‌نام گروهی کمرشکن. با هم،
--  آموزشگاه بسته به موقعیت انتخاب می‌کند.
-- ═══════════════════════════════════════════════════════════════════


-- ── پروفایل کاربر ─────────────────────────────────────────────────
-- نام فارسی و انگلیسی هر دو نگه داشته می‌شود: فارسی برای نمایش در
-- پنل، انگلیسی برای مدرک و گواهی پایان دوره که به لاتین صادر می‌شود.
--
-- تاریخ تولد میلادی ذخیره می‌شود و شمسی نمایش داده — همان قاعدهٔ P.2
-- که برای بقیهٔ تاریخ‌ها هم برقرار است. ذخیرهٔ شمسی یعنی هر محاسبهٔ
-- سنی باید اول تبدیل کند، و مرتب‌سازی تاریخی هم می‌شکند.
ALTER TABLE app_user
  ADD COLUMN first_name_fa VARCHAR(60)  NULL,
  ADD COLUMN last_name_fa  VARCHAR(60)  NULL,
  ADD COLUMN first_name_en VARCHAR(60)  NULL,
  ADD COLUMN last_name_en  VARCHAR(60)  NULL,
  ADD COLUMN national_id   VARCHAR(10)  NULL,
  ADD COLUMN birth_date    DATE         NULL,
  ADD COLUMN gender        VARCHAR(10)  NULL,
  ADD COLUMN email         VARCHAR(160) NULL,
  ADD COLUMN city          VARCHAR(80)  NULL,
  ADD COLUMN signup_role   VARCHAR(16)  NULL,
  ADD COLUMN profile_done  TINYINT(1)   NOT NULL DEFAULT 0;

-- کد ملی یکتاست ولی اختیاری. ایندکس یکتا روی ستون NULL‌پذیر یعنی
-- چند کاربر می‌توانند خالی داشته باشند، ولی دو نفر با یک کد ملی نه.
ALTER TABLE app_user ADD UNIQUE KEY uq_user_national (national_id);
ALTER TABLE app_user ADD KEY ix_user_signup_role (signup_role);


-- ── کد پیوستن آموزشگاه ────────────────────────────────────────────
-- کد روی خود آموزشگاه می‌نشیند نه در جدول جدا، چون هر آموزشگاه در هر
-- لحظه یک کد فعال دارد. چرخاندن کد یعنی نوشتن مقدار تازه — و همان
-- لحظه کد قدیمی بی‌اثر می‌شود، که دقیقاً رفتار مورد انتظار است وقتی
-- کدی لو رفته.
ALTER TABLE institute
  ADD COLUMN join_code        VARCHAR(12) NULL,
  ADD COLUMN join_code_role   VARCHAR(16) NOT NULL DEFAULT 'student',
  ADD COLUMN join_code_active TINYINT(1)  NOT NULL DEFAULT 0,
  ADD COLUMN accepts_requests TINYINT(1)  NOT NULL DEFAULT 1,
  ADD UNIQUE KEY uq_join_code (join_code);


-- ── صف درخواست پیوستن ─────────────────────────────────────────────
-- کسی که کد ندارد اینجا می‌نشیند تا مدیر تأیید یا رد کند.
CREATE TABLE IF NOT EXISTS join_request (
  id           CHAR(32)     NOT NULL PRIMARY KEY,
  institute_id CHAR(32)     NOT NULL,
  user_id      CHAR(32)     NOT NULL,
  wanted_role  VARCHAR(16)  NOT NULL,
  message      VARCHAR(500) NULL,
  status       VARCHAR(16)  NOT NULL DEFAULT 'pending',
  decided_by   CHAR(32)     NULL,
  decided_at   DATETIME     NULL,
  decline_note VARCHAR(255) NULL,
  created_at   DATETIME     NOT NULL,
  UNIQUE KEY uq_join_req (institute_id, user_id, status),
  KEY ix_join_req_inst (institute_id, status, created_at),
  KEY ix_join_req_user (user_id, status),
  CONSTRAINT ck_join_status CHECK (status IN ('pending','approved','declined','withdrawn')),
  CONSTRAINT fk_join_inst FOREIGN KEY (institute_id) REFERENCES institute(id) ON DELETE CASCADE,
  CONSTRAINT fk_join_user FOREIGN KEY (user_id) REFERENCES app_user(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── مجوزهای تازه ──────────────────────────────────────────────────
INSERT INTO permission (perm_key, group_key, label_fa, is_platform, is_write, sort_order) VALUES
('member.joincode',  'member', 'مدیریت کد پیوستن آموزشگاه', 0, 1, 60),
('member.approve',   'member', 'تأیید یا رد درخواست پیوستن', 0, 1, 70);

-- مدیر هر دو را می‌گیرد. از SELECT استفاده نمی‌کنیم چون بستهٔ مدیر
-- در نسخهٔ ۶ یک‌بار و با SELECT پر شد؛ حالا فقط همین دو ردیف کم است.
INSERT INTO role_permission (role_id, perm_key, is_platform, scope) VALUES
('r_manager', 'member.joincode', 0, 'institute'),
('r_manager', 'member.approve',  0, 'institute');
