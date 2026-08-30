-- ═══════════════════════════════════════════════════════════════════
--  نسخهٔ ۶ — کنترل دسترسی بر پایهٔ مجوز
--
--  تا اینجا دسترسی با نام نقش کنترل می‌شد: کد در سی جا می‌پرسید «آیا
--  مدیر است؟». از اینجا واحد کنترل «مجوز» است و نقش فقط نامی برای
--  بسته‌ای از مجوزهاست. نتیجه‌اش محصولی است، نه فنی: ساخت نقش تازه و
--  دادن یک دسترسی خاص به یک نفر، دیگر نیازمند تغییر کد نیست.
--
--  دو قفل در سطح دیتابیس، چون کد اشتباه می‌شود:
--
--  ۱) مالک پلتفرم یکتاست. ستون owner_lock وقتی مالک نیست NULL می‌شود
--     و در ایندکس یکتا، NULLها با هم تداخل ندارند — پس فقط یک ردیف
--     می‌تواند مالک باشد. مالک دوم حتی با دسترسی مستقیم به دیتابیس
--     ساخته نمی‌شود.
--
--  ۲) مجوزهای سطح پلتفرم به هیچ نقش و هیچ کاربری نمی‌چسبند. اینجا
--     تریگر به‌کار نمی‌آید چون نصب‌کننده فایل را روی نقطه‌ویرگول تکه
--     می‌کند و بدنهٔ تریگر نصفه می‌شود. به‌جایش کلید خارجی مرکب:
--     جدول‌های فرزند ستون is_platform را با CHECK روی صفر قفل می‌کنند
--     و کلید خارجی مرکب اجازه نمی‌دهد به ردیفی با is_platform=1 اشاره
--     کنند. همان تضمین، بدون تریگر.
--
--  این فایل روی نصب تازه توسط install.php اجرا می‌شود (چون به انتهای
--  schema.mysql.sql چسبیده) و روی پایگاه دادهٔ زنده به‌صورت دستی در
--  phpMyAdmin. ترتیب همیشه: اول مهاجرت، بعد انتشار کد.
-- ═══════════════════════════════════════════════════════════════════


-- ── مالک پلتفرم ───────────────────────────────────────────────────
ALTER TABLE admin_user
  ADD COLUMN is_platform_owner TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN owner_lock TINYINT(1)
      GENERATED ALWAYS AS (CASE WHEN is_platform_owner = 1 THEN 1 ELSE NULL END) STORED,
  ADD UNIQUE KEY uq_platform_owner (owner_lock);

-- اولین حسابی که نصب‌کننده ساخته، مالک است. زیرکوئری تودرتو لازم است
-- چون MySQL اجازه نمی‌دهد در UPDATE مستقیم از همان جدول SELECT شود.
UPDATE admin_user SET is_platform_owner = 1
 WHERE id = (SELECT id FROM (SELECT id FROM admin_user ORDER BY created_at ASC LIMIT 1) AS first_admin);


-- ── کاتالوگ مجوزها ────────────────────────────────────────────────
-- فقط با seed پر می‌شود؛ کاربر نمی‌سازدش. is_write برای حالت
-- فقط-خواندنی (پایان دمو) لازم است: مجوز نوشتنی رد می‌شود، خواندنی نه.
CREATE TABLE IF NOT EXISTS permission (
  perm_key    VARCHAR(64)  NOT NULL PRIMARY KEY,
  group_key   VARCHAR(32)  NOT NULL,
  label_fa    VARCHAR(120) NOT NULL,
  is_platform TINYINT(1)   NOT NULL DEFAULT 0,
  is_write    TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order  SMALLINT     NOT NULL DEFAULT 0,
  UNIQUE KEY uq_perm_platform (perm_key, is_platform),
  KEY ix_perm_group (group_key, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── نقش ───────────────────────────────────────────────────────────
-- institute_id تهی‌رشته یعنی نقش سیستمی و در دسترس همهٔ آموزشگاه‌ها.
-- از NULL استفاده نمی‌کنیم چون در ایندکس یکتا NULLها تداخل ندارند و
-- آن‌وقت دو نقش سیستمی هم‌نام ساخته می‌شد.
CREATE TABLE IF NOT EXISTS role (
  id            CHAR(32)     NOT NULL PRIMARY KEY,
  institute_id  CHAR(32)     NOT NULL DEFAULT '',
  role_key      VARCHAR(32)  NOT NULL,
  name_fa       VARCHAR(80)  NOT NULL,
  description   VARCHAR(255) NULL,
  default_scope VARCHAR(20)  NOT NULL DEFAULT 'own',
  is_system     TINYINT(1)   NOT NULL DEFAULT 0,
  created_by    CHAR(32)     NULL,
  created_at    DATETIME     NOT NULL,
  UNIQUE KEY uq_role (institute_id, role_key),
  KEY ix_role_inst (institute_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── بستهٔ مجوزهای نقش ─────────────────────────────────────────────
-- is_platform اینجا همیشه صفر است و CHECK قفلش می‌کند؛ کلید خارجی
-- مرکب باعث می‌شود فقط به مجوزهای غیرپلتفرمی وصل شود.
CREATE TABLE IF NOT EXISTS role_permission (
  role_id     CHAR(32)    NOT NULL,
  perm_key    VARCHAR(64) NOT NULL,
  is_platform TINYINT(1)  NOT NULL DEFAULT 0,
  scope       VARCHAR(20) NOT NULL DEFAULT 'institute',
  PRIMARY KEY (role_id, perm_key),
  CONSTRAINT ck_rp_not_platform CHECK (is_platform = 0),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES role(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (perm_key, is_platform)
    REFERENCES permission(perm_key, is_platform) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── اعطا و سلب موردی روی یک کاربر ─────────────────────────────────
-- سلب همیشه بر اعطا مقدم است؛ این قاعده در موتور اعمال می‌شود.
CREATE TABLE IF NOT EXISTS user_permission (
  id           CHAR(32)     NOT NULL PRIMARY KEY,
  institute_id CHAR(32)     NOT NULL,
  user_id      CHAR(32)     NOT NULL,
  perm_key     VARCHAR(64)  NOT NULL,
  is_platform  TINYINT(1)   NOT NULL DEFAULT 0,
  effect       VARCHAR(8)   NOT NULL,
  scope        VARCHAR(20)  NULL,
  expires_at   DATETIME     NULL,
  granted_by   CHAR(32)     NOT NULL,
  reason       VARCHAR(255) NULL,
  created_at   DATETIME     NOT NULL,
  UNIQUE KEY uq_uperm (institute_id, user_id, perm_key),
  KEY ix_uperm_user (user_id, institute_id),
  KEY ix_uperm_expiry (expires_at),
  CONSTRAINT ck_up_not_platform CHECK (is_platform = 0),
  CONSTRAINT ck_up_effect CHECK (effect IN ('allow','deny')),
  CONSTRAINT fk_up_inst FOREIGN KEY (institute_id) REFERENCES institute(id) ON DELETE CASCADE,
  CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES app_user(id) ON DELETE CASCADE,
  CONSTRAINT fk_up_perm FOREIGN KEY (perm_key, is_platform)
    REFERENCES permission(perm_key, is_platform) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── سقف واگذاری ───────────────────────────────────────────────────
-- دو شرط جدا، نه یکی: کدام نقش‌ها را می‌تواند بدهد، و کدام مجوزهای
-- موردی را. این‌طور مالک می‌تواند مدیری را که خودش مجوزی دارد، از
-- واگذاری همان مجوز منع کند.
CREATE TABLE IF NOT EXISTS role_grantable (
  granter_role_id   CHAR(32) NOT NULL,
  grantable_role_id CHAR(32) NOT NULL,
  PRIMARY KEY (granter_role_id, grantable_role_id),
  CONSTRAINT fk_rg_from FOREIGN KEY (granter_role_id)   REFERENCES role(id) ON DELETE CASCADE,
  CONSTRAINT fk_rg_to   FOREIGN KEY (grantable_role_id) REFERENCES role(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_grantable_perm (
  granter_role_id CHAR(32)    NOT NULL,
  perm_key        VARCHAR(64) NOT NULL,
  PRIMARY KEY (granter_role_id, perm_key),
  CONSTRAINT fk_rgp_role FOREIGN KEY (granter_role_id) REFERENCES role(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── شعبه ──────────────────────────────────────────────────────────
-- خالی می‌ماند تا وقتی لازم شود. ساختنش حالا تقریباً رایگان است، ولی
-- افزودن یک سطح محدوده بعد از نوشتن سی کوئری یعنی بازنویسی هر سی‌تا.
-- تا وقتی شعبه‌ای تعریف نشده، محدودهٔ branch معادل institute کار می‌کند.
CREATE TABLE IF NOT EXISTS branch (
  id           CHAR(32)     NOT NULL PRIMARY KEY,
  institute_id CHAR(32)     NOT NULL,
  name         VARCHAR(120) NOT NULL,
  status       VARCHAR(16)  NOT NULL DEFAULT 'active',
  created_at   DATETIME     NOT NULL,
  KEY ix_branch_inst (institute_id),
  CONSTRAINT fk_branch_inst FOREIGN KEY (institute_id) REFERENCES institute(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── عضویت: چند-نقشی، زمان‌دار، قابل ردیابی ────────────────────────
-- قید یکتای تک‌نقشی در فاز ۴ برداشته می‌شود، نه حالا. ستون role قدیمی
-- هم می‌ماند و دوگانه نوشته می‌شود تا بازگشت به کد قبلی در هر لحظه
-- ممکن باشد.
ALTER TABLE membership
  ADD COLUMN role_id        CHAR(32)     NULL,
  ADD COLUMN branch_id      CHAR(32)     NULL,
  ADD COLUMN expires_at     DATETIME     NULL,
  ADD COLUMN granted_by     CHAR(32)     NULL,
  ADD COLUMN granted_reason VARCHAR(255) NULL,
  ADD KEY ix_member_role_id (role_id),
  ADD KEY ix_member_expiry (expires_at);


-- ── نشست: زمینهٔ فعال ─────────────────────────────────────────────
-- بدون این، سوییچ نقش فقط یک تغییر ظاهری در مرورگر است و کسی که در
-- نمای زبان‌آموز نشسته می‌تواند با درخواست دستی دادهٔ مدرس را بخواند.
ALTER TABLE session_token
  ADD COLUMN active_institute_id CHAR(32) NULL,
  ADD COLUMN active_role_id      CHAR(32) NULL,
  ADD COLUMN context_set_at      DATETIME NULL;


-- ── آموزشگاه: وضعیت اشتراک ────────────────────────────────────────
-- readonly یعنی پایان دمو: کاربر همه‌چیز را می‌بیند ولی چیزی نمی‌نویسد.
-- قفل کامل مشتری را می‌پراند؛ فقط-خواندنی انگیزهٔ خرید می‌سازد.
ALTER TABLE institute
  ADD COLUMN plan          VARCHAR(16) NOT NULL DEFAULT 'active',
  ADD COLUMN trial_ends_at DATETIME    NULL,
  ADD KEY ix_inst_plan (plan, trial_ends_at);


-- ── درخواست دمو: اتصال به آموزشگاه ساخته‌شده ──────────────────────
ALTER TABLE demo_lead
  ADD COLUMN institute_id CHAR(32) NULL,
  ADD COLUMN user_id      CHAR(32) NULL,
  ADD COLUMN trial_days   SMALLINT NULL,
  ADD COLUMN approved_by  CHAR(32) NULL,
  ADD COLUMN approved_at  DATETIME NULL;


-- ═══════════════════════════════════════════════════════════════════
--  داده‌های پایه — کاتالوگ مجوزها
--
--  گروه‌بندی همان چیزی است که در پنل مالک به‌صورت بخش‌بندی دیده می‌شود.
--  is_write یعنی این کار در حالت فقط-خواندنی رد شود.
-- ═══════════════════════════════════════════════════════════════════

INSERT INTO permission (perm_key, group_key, label_fa, is_platform, is_write, sort_order) VALUES
('institute.view',          'institute',  'دیدن اطلاعات آموزشگاه',        0, 0, 10),
('institute.edit',          'institute',  'ویرایش اطلاعات آموزشگاه',      0, 1, 20),
('term.view',               'term',       'دیدن ترم‌ها',                  0, 0, 10),
('term.create',             'term',       'ساخت ترم',                     0, 1, 20),
('term.edit',               'term',       'ویرایش ترم',                   0, 1, 30),
('term.delete',             'term',       'حذف ترم',                      0, 1, 40),
('room.view',               'room',       'دیدن کلاس‌های فیزیکی',         0, 0, 10),
('room.manage',             'room',       'مدیریت کلاس‌های فیزیکی',       0, 1, 20),
('class.view',              'class',      'دیدن کلاس‌ها',                 0, 0, 10),
('class.create',            'class',      'ساخت کلاس',                    0, 1, 20),
('class.edit',              'class',      'ویرایش کلاس',                  0, 1, 30),
('class.delete',            'class',      'حذف کلاس',                     0, 1, 40),
('class.assign_teacher',    'class',      'انتساب مدرس به کلاس',          0, 1, 50),
('enrolment.view',          'enrolment',  'دیدن ثبت‌نام‌ها',              0, 0, 10),
('enrolment.create',        'enrolment',  'ثبت‌نام زبان‌آموز در کلاس',    0, 1, 20),
('enrolment.delete',        'enrolment',  'حذف ثبت‌نام',                  0, 1, 30),
('member.view',             'member',     'دیدن اعضای آموزشگاه',          0, 0, 10),
('member.invite',           'member',     'دعوت و افزودن عضو',            0, 1, 20),
('member.edit',             'member',     'ویرایش عضو',                   0, 1, 30),
('member.remove',           'member',     'حذف عضویت',                    0, 1, 40),
('member.grant',            'member',     'تغییر نقش و دسترسی اعضا',      0, 1, 50),
('attendance.view',         'attendance', 'دیدن حضور و غیاب',             0, 0, 10),
('attendance.write',        'attendance', 'ثبت حضور و غیاب',              0, 1, 20),
('assignment.view',         'assignment', 'دیدن تکالیف',                  0, 0, 10),
('assignment.create',       'assignment', 'ساخت تکلیف',                   0, 1, 20),
('assignment.edit',         'assignment', 'ویرایش تکلیف',                 0, 1, 30),
('assignment.delete',       'assignment', 'حذف تکلیف',                    0, 1, 40),
('assignment.submit',       'assignment', 'تحویل تکلیف',                  0, 1, 50),
('assignment.grade',        'assignment', 'تصحیح و ثبت نمره',             0, 1, 60),
('session.view',            'session',    'دیدن جلسات',                   0, 0, 10),
('session.create',          'session',    'ساخت جلسه',                    0, 1, 20),
('session.edit',            'session',    'ویرایش جلسه',                  0, 1, 30),
('session.start_meeting',   'session',    'شروع کلاس آنلاین',             0, 1, 40),
('finance.tuition.view',    'finance',    'دیدن شهریه و پرداخت‌ها',       0, 0, 10),
('finance.tuition.record',  'finance',    'ثبت پرداخت شهریه',             0, 1, 20),
('finance.payout.view',     'finance',    'دیدن حقوق مدرسین',             0, 0, 30),
('finance.payout.record',   'finance',    'ثبت پرداخت به مدرس',           0, 1, 40),
('finance.report.view',     'finance',    'دیدن گزارش مالی',              0, 0, 50),
('shop.browse',             'shop',       'دیدن کتاب‌ها',                 0, 0, 10),
('shop.purchase',           'shop',       'خرید کتاب',                    0, 1, 20),
('shop.manage',             'shop',       'مدیریت کتاب‌ها و سفارش‌ها',    0, 1, 30),
('chat.participate',        'chat',       'گفت‌وگو',                      0, 1, 10),
('chat.moderate',           'chat',       'نظارت بر گفت‌وگوها',           0, 1, 20),
('audit.view',              'audit',      'دیدن لاگ ورود و رویدادها',     0, 0, 10),
('report.view',             'report',     'دیدن گزارش‌های آموزشی',        0, 0, 10),
('platform.institute.view',   'platform', 'دیدن همهٔ آموزشگاه‌ها',        1, 0, 10),
('platform.institute.create', 'platform', 'ساخت آموزشگاه',                1, 1, 20),
('platform.institute.suspend','platform', 'تعلیق آموزشگاه',               1, 1, 30),
('platform.institute.plan',   'platform', 'تغییر وضعیت اشتراک',           1, 1, 40),
('platform.user.view',        'platform', 'جست‌وجوی کاربر در کل پلتفرم',  1, 0, 50),
('platform.user.manage',      'platform', 'مدیریت کاربران پلتفرم',        1, 1, 60),
('platform.role.manage',      'platform', 'ساخت و ویرایش نقش‌ها',         1, 1, 70),
('platform.permission.grant', 'platform', 'اعطا و سلب مجوز',              1, 1, 80),
('platform.impersonate',      'platform', 'ورود به‌جای کاربر',            1, 1, 90),
('platform.demo.manage',      'platform', 'مدیریت درخواست‌های دمو',       1, 1, 100),
('platform.settings',         'platform', 'تنظیمات پلتفرم',               1, 1, 110),
('platform.sms',              'platform', 'تنظیمات پیامک',                1, 1, 120),
('platform.health',           'platform', 'سلامت سامانه',                 1, 0, 130),
('platform.audit',            'platform', 'گزارش رویدادهای پلتفرم',       1, 0, 140);


-- ═══════════════════════════════════════════════════════════════════
--  سه نقش سیستمی
--
--  شناسه‌ها ثابت و خوانا هستند تا در مهاجرت و در کد قابل ارجاع باشند.
--  default_scope فقط مقدار پیش‌فرض هنگام ساخت نقش تازه است؛ محدودهٔ
--  واقعی روی تک‌تک مجوزها نوشته می‌شود.
-- ═══════════════════════════════════════════════════════════════════

INSERT INTO role (id, institute_id, role_key, name_fa, description, default_scope, is_system, created_at) VALUES
('r_manager', '', 'manager', 'مدیر آموزشگاه',
 'دسترسی کامل به همهٔ فرایندهای آموزشگاه خودش', 'institute', 1, UTC_TIMESTAMP()),
('r_teacher', '', 'teacher', 'مدرس',
 'کلاس‌های خودش: حضور و غیاب، تکلیف، نمره، کلاس آنلاین', 'own_classes', 1, UTC_TIMESTAMP()),
('r_student', '', 'student', 'زبان‌آموز',
 'کلاس‌های ثبت‌نام‌شده، تکالیف خودش، پرداخت و خرید', 'own', 1, UTC_TIMESTAMP());


-- ── بستهٔ مدیر ────────────────────────────────────────────────────
-- مدیر همهٔ مجوزهای غیرپلتفرمی را در محدودهٔ آموزشگاه خودش دارد، جز
-- سه موردی که ذاتاً مال زبان‌آموز است. از SELECT استفاده می‌کنیم تا
-- افزودن مجوز تازه در آینده خودبه‌خود به مدیر برسد — همان چیزی که
-- «مدیر همه‌کارهٔ آموزشگاه خودش است» یعنی.
INSERT INTO role_permission (role_id, perm_key, is_platform, scope)
SELECT 'r_manager', perm_key, 0, 'institute' FROM permission
 WHERE is_platform = 0
   AND perm_key NOT IN ('assignment.submit', 'shop.purchase', 'shop.browse');


-- ── بستهٔ مدرس ────────────────────────────────────────────────────
-- محدوده‌ها عمداً مخلوط‌اند: کلاس و حضور و تکلیف فقط مال خودش، ولی
-- دیدن ترم و کلاس فیزیکی در سطح آموزشگاه لازم است وگرنه هنگام ساخت
-- کلاس چیزی برای انتخاب ندارد.
INSERT INTO role_permission (role_id, perm_key, is_platform, scope) VALUES
('r_teacher', 'institute.view',        0, 'institute'),
('r_teacher', 'term.view',             0, 'institute'),
('r_teacher', 'room.view',             0, 'institute'),
('r_teacher', 'class.view',            0, 'own_classes'),
('r_teacher', 'class.create',          0, 'own_classes'),
('r_teacher', 'class.edit',            0, 'own_classes'),
('r_teacher', 'enrolment.view',        0, 'own_classes'),
('r_teacher', 'enrolment.create',      0, 'own_classes'),
('r_teacher', 'member.view',           0, 'assigned_students'),
('r_teacher', 'attendance.view',       0, 'own_classes'),
('r_teacher', 'attendance.write',      0, 'own_classes'),
('r_teacher', 'assignment.view',       0, 'own_classes'),
('r_teacher', 'assignment.create',     0, 'own_classes'),
('r_teacher', 'assignment.edit',       0, 'own_classes'),
('r_teacher', 'assignment.delete',     0, 'own_classes'),
('r_teacher', 'assignment.grade',      0, 'own_classes'),
('r_teacher', 'session.view',          0, 'own_classes'),
('r_teacher', 'session.create',        0, 'own_classes'),
('r_teacher', 'session.edit',          0, 'own_classes'),
('r_teacher', 'session.start_meeting', 0, 'own_classes'),
('r_teacher', 'finance.payout.view',   0, 'own'),
('r_teacher', 'chat.participate',      0, 'assigned_students'),
('r_teacher', 'report.view',           0, 'own_classes');


-- ── بستهٔ زبان‌آموز ───────────────────────────────────────────────
-- همه‌چیز در محدودهٔ own، جز دیدن کلاس و جلسه که باید کلاس‌های
-- ثبت‌نام‌شده‌اش را ببیند — که موتور با enrolment فیلترش می‌کند.
INSERT INTO role_permission (role_id, perm_key, is_platform, scope) VALUES
('r_student', 'institute.view',       0, 'institute'),
('r_student', 'class.view',           0, 'own'),
('r_student', 'session.view',         0, 'own'),
('r_student', 'attendance.view',      0, 'own'),
('r_student', 'assignment.view',      0, 'own'),
('r_student', 'assignment.submit',    0, 'own'),
('r_student', 'finance.tuition.view', 0, 'own'),
('r_student', 'shop.browse',          0, 'institute'),
('r_student', 'shop.purchase',        0, 'own'),
('r_student', 'chat.participate',     0, 'own');


-- ── سقف واگذاری مدیر ──────────────────────────────────────────────
-- مدیر می‌تواند نقش مدرس و زبان‌آموز بدهد، ولی نه نقش مدیر. اگر
-- آموزشگاهی به مدیر دوم نیاز داشت، مالک پلتفرم اضافه‌اش می‌کند. این
-- پیش‌فرض است و مالک می‌تواند عوضش کند.
INSERT INTO role_grantable (granter_role_id, grantable_role_id) VALUES
('r_manager', 'r_teacher'),
('r_manager', 'r_student');

-- و مجوزهای موردی‌ای که مدیر اجازهٔ واگذاری‌شان را دارد. عمداً کوتاه
-- است: مالی و نظارت بیرون مانده‌اند تا مدیر نتواند دسترسی حساس را
-- دست‌به‌دست کند. مالک می‌تواند این فهرست را گسترده کند.
INSERT INTO role_grantable_perm (granter_role_id, perm_key) VALUES
('r_manager', 'class.create'),
('r_manager', 'class.edit'),
('r_manager', 'class.delete'),
('r_manager', 'class.assign_teacher'),
('r_manager', 'enrolment.create'),
('r_manager', 'enrolment.delete'),
('r_manager', 'attendance.write'),
('r_manager', 'assignment.create'),
('r_manager', 'assignment.edit'),
('r_manager', 'assignment.grade'),
('r_manager', 'session.create'),
('r_manager', 'session.edit'),
('r_manager', 'session.start_meeting'),
('r_manager', 'member.view'),
('r_manager', 'member.invite'),
('r_manager', 'chat.participate'),
('r_manager', 'chat.moderate'),
('r_manager', 'report.view');


-- ═══════════════════════════════════════════════════════════════════
--  نگاشت عضویت‌های موجود
--
--  ستون role قدیمی دست‌نخورده می‌ماند و تا پایان فاز ۳ دوگانه نوشته
--  می‌شود. یعنی اگر لازم شد، بازگشت به کد قبلی در هر لحظه ممکن است
--  بدون از‌دست‌رفتن داده.
-- ═══════════════════════════════════════════════════════════════════

UPDATE membership SET role_id = CASE role
  WHEN 'manager' THEN 'r_manager'
  WHEN 'teacher' THEN 'r_teacher'
  ELSE 'r_student' END
 WHERE role_id IS NULL;
