-- تاکورا — اسکیمای احراز هویت برای MySQL هاست اشتراکی
-- در phpMyAdmin پلسک اجرا کنید (Databases → phpMyAdmin → SQL)

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS app_user (
  id                CHAR(32)     NOT NULL PRIMARY KEY,
  phone             VARCHAR(15)  NOT NULL,
  full_name         VARCHAR(120) NOT NULL,
  institute_name    VARCHAR(160) NULL,
  role              VARCHAR(24)  NOT NULL DEFAULT 'student',
  status            VARCHAR(16)  NOT NULL DEFAULT 'active',
  /*
   * نام کاربری و رمز اختیاری‌اند.
   *
   * راه اصلی ورود همچنان شمارهٔ موبایل و کد یک‌بارمصرف است — چیزی که
   * هر زبان‌آموزی دارد و فراموش نمی‌کند. رمز برای کسی است که هر روز
   * وارد می‌شود و نمی‌خواهد هر بار منتظر پیامک بماند، و برای روزی که
   * اعتبار پنل پیامک تمام شده باشد.
   *
   * NULL یعنی این کاربر هنوز رمز نساخته و فقط با پیامک وارد می‌شود.
   */
  username          VARCHAR(64)  NULL,
  pass_hash         VARCHAR(255) NULL,
  phone_verified_at DATETIME     NULL,
  last_login_at     DATETIME     NULL,
  /*
   * نام فارسی و انگلیسی هر دو نگه داشته می‌شود: فارسی برای نمایش در
   * پنل، انگلیسی برای گواهی پایان دوره که به لاتین صادر می‌شود.
   *
   * تاریخ تولد میلادی ذخیره و شمسی نمایش داده می‌شود — بند P.2.
   * ذخیرهٔ شمسی هم محاسبهٔ سن را می‌شکند هم مرتب‌سازی تاریخی را.
   */
  first_name_fa     VARCHAR(60)  NULL,
  last_name_fa      VARCHAR(60)  NULL,
  first_name_en     VARCHAR(60)  NULL,
  last_name_en      VARCHAR(60)  NULL,
  national_id       VARCHAR(10)  NULL,
  birth_date        DATE         NULL,
  gender            VARCHAR(10)  NULL,
  email             VARCHAR(160) NULL,
  city              VARCHAR(80)  NULL,
  signup_role       VARCHAR(16)  NULL,
  profile_done      TINYINT(1)   NOT NULL DEFAULT 0,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_phone (phone),
  -- کد ملی یکتاست ولی اختیاری: چند کاربر می‌توانند خالی داشته باشند،
  -- ولی دو نفر با یک کد ملی نه
  UNIQUE KEY uq_user_national (national_id),
  KEY ix_user_signup_role (signup_role),
  UNIQUE KEY uq_user_username (username),
  KEY ix_user_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS otp_code (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  phone       VARCHAR(15)  NOT NULL,
  -- فقط HMAC کد ذخیره می‌شود؛ خود کد هیچ‌جا نوشته نمی‌شود
  code_hash   CHAR(64)     NOT NULL,
  -- فقط در «حالت پل» پر می‌شود: وقتی هنوز sms.ir راه نیفتاده و کد را
  -- مدیر از پنل سوپرادمین می‌خواند و به کاربر می‌گوید. حداکثر دو دقیقه
  -- زنده می‌ماند و لحظهٔ مصرف‌شدن پاک می‌شود. در حالت پیامک واقعی
  -- همیشه NULL است.
  pending_code VARCHAR(8)  NULL,
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at  DATETIME     NOT NULL,
  consumed_at DATETIME     NULL,
  ip          VARCHAR(45)  NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_otp_lookup (phone, consumed_at, expires_at),
  KEY ix_otp_cleanup (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS session_token (
  token_hash   CHAR(64)    NOT NULL PRIMARY KEY,
  user_id      CHAR(32)    NOT NULL,
  expires_at   DATETIME    NOT NULL,
  last_seen_at DATETIME    NULL,
  revoked_at   DATETIME    NULL,
  ip           VARCHAR(45) NULL,
  user_agent   VARCHAR(255) NULL,
  created_at   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_session_user (user_id),
  KEY ix_session_exp (expires_at),
  CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES app_user(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limit (
  bucket       VARCHAR(32) NOT NULL,
  rl_key       VARCHAR(64) NOT NULL,
  window_start BIGINT      NOT NULL,
  hits         INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket, rl_key, window_start),
  KEY ix_rl_cleanup (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_user_id CHAR(32)    NULL,
  action        VARCHAR(64) NOT NULL,
  ip            VARCHAR(45) NULL,
  meta          TEXT        NULL,
  created_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_audit_time (created_at),
  KEY ix_audit_actor (actor_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ═══════════════════════════════════════════════════════════════════
--  نسخهٔ ۲ — جدول‌های عملیاتی آموزشگاه
--
--  چند نکتهٔ طراحی که عمدی است:
--
--  ۱) روی MySQL هاست اشتراکی، Row Level Security پستگرس را نداریم.
--     پس هر جدول institute_id دارد و *هر* کوئری باید با آن فیلتر شود.
--     این کار در _ctx.php متمرکز شده تا در نقاط پایانی فراموش نشود.
--
--  ۲) تاریخ‌ها میلادی و به شکل DATE ذخیره می‌شوند، نمایش‌شان شمسی است
--     (بند P.2). تبدیل در مرورگر انجام می‌شود، نه در دیتابیس.
--
--  ۳) مبلغ‌ها ریال و صحیح‌اند، هرگز اعشاری. نمایش به تومان با تقسیم بر ۱۰.
-- ═══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS institute (
  id            CHAR(32)     NOT NULL PRIMARY KEY,
  name          VARCHAR(160) NOT NULL,
  owner_user_id CHAR(32)     NOT NULL,
  phone         VARCHAR(20)  NULL,
  city          VARCHAR(80)  NULL,
  term_weeks    TINYINT UNSIGNED NOT NULL DEFAULT 12,
  /*
   * کد پیوستن روی خود آموزشگاه می‌نشیند نه در جدول جدا، چون هر
   * آموزشگاه در هر لحظه یک کد فعال دارد. چرخاندن کد یعنی نوشتن
   * مقدار تازه — و همان لحظه کد قدیمی بی‌اثر می‌شود، که دقیقاً رفتار
   * مورد انتظار است وقتی کدی لو رفته.
   */
  join_code        VARCHAR(12) NULL,
  join_code_role   VARCHAR(16) NOT NULL DEFAULT 'student',
  join_code_active TINYINT(1)  NOT NULL DEFAULT 0,
  accepts_requests TINYINT(1)  NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL,
  UNIQUE KEY uq_join_code (join_code),
  KEY ix_inst_owner (owner_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- کاربر می‌تواند در چند آموزشگاه عضو باشد و در هرکدام نقش دیگری داشته باشد
CREATE TABLE IF NOT EXISTS membership (
  id           CHAR(32)    NOT NULL PRIMARY KEY,
  institute_id CHAR(32)    NOT NULL,
  user_id      CHAR(32)    NOT NULL,
  role         VARCHAR(16) NOT NULL,            -- manager | teacher | student
  status       VARCHAR(16) NOT NULL DEFAULT 'active',
  hourly_rate  BIGINT      NOT NULL DEFAULT 0,  -- ریال، فقط برای مدرس
  created_at   DATETIME    NOT NULL,
  -- قید سه‌تایی (آموزشگاه، کاربر، نقش) پایین‌تر جایگزینش می‌شود؛
  -- اینجا نمی‌تواند بیاید چون role_id هنوز ساخته نشده
  UNIQUE KEY uq_member (institute_id, user_id),
  KEY ix_member_user (user_id),
  KEY ix_member_role (institute_id, role),
  CONSTRAINT fk_member_inst FOREIGN KEY (institute_id) REFERENCES institute(id) ON DELETE CASCADE,
  CONSTRAINT fk_member_user FOREIGN KEY (user_id) REFERENCES app_user(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- مدیر با شماره دعوت می‌کند؛ کاربر هنوز ثبت‌نام نکرده است.
-- اولین باری که با همان شماره وارد شود، عضویتش خودکار ساخته می‌شود.
CREATE TABLE IF NOT EXISTS invite (
  id           CHAR(32)     NOT NULL PRIMARY KEY,
  institute_id CHAR(32)     NOT NULL,
  phone        VARCHAR(15)  NOT NULL,
  full_name    VARCHAR(120) NOT NULL,
  role         VARCHAR(16)  NOT NULL,
  class_id     CHAR(32)     NULL,               -- اگر زبان‌آموز است، مستقیم در این کلاس ثبت شود
  accepted_at  DATETIME     NULL,
  created_at   DATETIME     NOT NULL,
  UNIQUE KEY uq_invite (institute_id, phone),
  KEY ix_invite_phone (phone, accepted_at),
  CONSTRAINT fk_invite_inst FOREIGN KEY (institute_id) REFERENCES institute(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS room (
  id           CHAR(32)    NOT NULL PRIMARY KEY,
  institute_id CHAR(32)    NOT NULL,
  name         VARCHAR(80) NOT NULL,
  capacity     SMALLINT UNSIGNED NOT NULL DEFAULT 12,
  created_at   DATETIME    NOT NULL,
  KEY ix_room_inst (institute_id),
  CONSTRAINT fk_room_inst FOREIGN KEY (institute_id) REFERENCES institute(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS term (
  id           CHAR(32)    NOT NULL PRIMARY KEY,
  institute_id CHAR(32)    NOT NULL,
  name         VARCHAR(80) NOT NULL,
  starts_on    DATE        NOT NULL,
  weeks        TINYINT UNSIGNED NOT NULL DEFAULT 12,
  status       VARCHAR(16) NOT NULL DEFAULT 'active',   -- draft | active | closed
  created_at   DATETIME    NOT NULL,
  KEY ix_term_inst (institute_id, status),
  CONSTRAINT fk_term_inst FOREIGN KEY (institute_id) REFERENCES institute(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS klass (
  id              CHAR(32)     NOT NULL PRIMARY KEY,
  institute_id    CHAR(32)     NOT NULL,
  term_id         CHAR(32)     NULL,
  name            VARCHAR(160) NOT NULL,
  level           VARCHAR(40)  NOT NULL DEFAULT '',
  teacher_user_id CHAR(32)     NULL,
  room_id         CHAR(32)     NULL,
  day_pattern     VARCHAR(16)  NOT NULL DEFAULT 'فرد',   -- فرد | زوج | پنجشنبه | جمعه | فشرده
  start_time      VARCHAR(5)   NOT NULL DEFAULT '18:00', -- HH:MM ۲۴ ساعته، لاتین
  duration_min    SMALLINT UNSIGNED NOT NULL DEFAULT 90,
  capacity        SMALLINT UNSIGNED NOT NULL DEFAULT 12,
  total_sessions  SMALLINT UNSIGNED NOT NULL DEFAULT 20,

  /*
   * بازهٔ کلاس و تاریخ آزمون‌ها.
   *
   * total_sessions می‌گوید کلاس چند جلسه دارد، ولی نه از کِی تا کِی.
   * برای «چند جلسه مانده» باید جلسه‌های برگزارشده شمرده می‌شد، و برای
   * «کلاس تمام شده؟» هیچ راهی نبود جز حدس.
   *
   * ends_on هم تاریخ پایان است هم تاریخ انقضا: بعد از آن کلاس در
   * فهرست‌های جاری نمی‌آید ولی داده‌اش می‌ماند — کارنامه و حضور و غیاب
   * سال‌ها بعد هم باید خوانده شود.
   */
  starts_on       DATE         NULL,
  ends_on         DATE         NULL,
  midterm_on      DATE         NULL,
  final_on        DATE         NULL,

  mode            VARCHAR(16)  NOT NULL DEFAULT 'in_person', -- in_person | online | hybrid
  provider        VARCHAR(16)  NOT NULL DEFAULT 'meet',      -- bbb | meet | skyroom | custom
  join_url        VARCHAR(500) NULL,
  price           BIGINT       NOT NULL DEFAULT 0,           -- ریال
  status          VARCHAR(16)  NOT NULL DEFAULT 'draft',     -- draft | published | closed
  created_at      DATETIME     NOT NULL,
  KEY ix_class_inst (institute_id, status),
  KEY ix_class_teacher (institute_id, teacher_user_id),
  KEY ix_class_window (institute_id, ends_on),
  CONSTRAINT fk_class_inst FOREIGN KEY (institute_id) REFERENCES institute(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS enrolment (
  id              CHAR(32)    NOT NULL PRIMARY KEY,
  institute_id    CHAR(32)    NOT NULL,
  class_id        CHAR(32)    NOT NULL,
  student_user_id CHAR(32)    NOT NULL,
  status          VARCHAR(16) NOT NULL DEFAULT 'active',   -- active | withdrawn
  created_at      DATETIME    NOT NULL,
  UNIQUE KEY uq_enrol (class_id, student_user_id),
  KEY ix_enrol_student (institute_id, student_user_id),
  CONSTRAINT fk_enrol_class FOREIGN KEY (class_id) REFERENCES klass(id) ON DELETE CASCADE,
  CONSTRAINT fk_enrol_user FOREIGN KEY (student_user_id) REFERENCES app_user(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_session (
  id           CHAR(32)    NOT NULL PRIMARY KEY,
  institute_id CHAR(32)    NOT NULL,
  class_id     CHAR(32)    NOT NULL,
  seq          SMALLINT UNSIGNED NOT NULL,
  session_date DATE        NOT NULL,
  start_time   VARCHAR(5)  NOT NULL,
  status       VARCHAR(16) NOT NULL DEFAULT 'scheduled', -- scheduled | live | done | cancelled
  join_url     VARCHAR(500) NULL,
  started_at   DATETIME    NULL,
  ended_at     DATETIME    NULL,
  note         VARCHAR(255) NULL,
  UNIQUE KEY uq_session (class_id, seq),
  KEY ix_session_date (institute_id, session_date),
  KEY ix_session_status (institute_id, status),
  CONSTRAINT fk_session_class FOREIGN KEY (class_id) REFERENCES klass(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance (
  id              CHAR(32)    NOT NULL PRIMARY KEY,
  institute_id    CHAR(32)    NOT NULL,
  session_id      CHAR(32)    NOT NULL,
  student_user_id CHAR(32)    NOT NULL,
  status          VARCHAR(16) NOT NULL,   -- present | absent | late | excused
  note            VARCHAR(255) NULL,
  marked_by       CHAR(32)    NULL,
  marked_at       DATETIME    NOT NULL,
  -- یک ردیف برای هر جلسه و هر زبان‌آموز؛ ثبت دوباره باید به‌روزرسانی باشد نه تکرار
  UNIQUE KEY uq_attendance (session_id, student_user_id),
  KEY ix_att_student (institute_id, student_user_id),
  CONSTRAINT fk_att_session FOREIGN KEY (session_id) REFERENCES class_session(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assignment (
  id           CHAR(32)     NOT NULL PRIMARY KEY,
  institute_id CHAR(32)     NOT NULL,
  class_id     CHAR(32)     NOT NULL,
  title        VARCHAR(200) NOT NULL,
  type         VARCHAR(16)  NOT NULL DEFAULT 'writing',  -- writing | speaking | quiz | file
  description  TEXT         NULL,
  due_at       DATETIME     NULL,
  max_score    SMALLINT UNSIGNED NOT NULL DEFAULT 20,
  status       VARCHAR(16)  NOT NULL DEFAULT 'open',     -- open | closed

  /*
   * تمدید مهلت، جدا از خودِ مهلت.
   *
   * راه ساده‌تر این بود که due_at عوض شود. آن‌وقت سه چیز از دست
   * می‌رفت: مهلت اصلی، اینکه اصلاً تمدید شده، و اینکه چرا — و وقتی
   * زبان‌آموزی می‌پرسید «مگر تا جمعه نبود؟» جوابی نبود.
   *
   * تأخیر از روی مهلت *مؤثر* حساب می‌شود نه اصلی: کسی که در مهلت
   * تمدیدشده تحویل داده، دیرکرد ندارد.
   */
  extended_to  DATETIME     NULL,
  extended_by  CHAR(32)     NULL,
  extended_at  DATETIME     NULL,
  extend_note  VARCHAR(255) NULL,

  created_by   CHAR(32)     NULL,
  created_at   DATETIME     NOT NULL,
  KEY ix_asg_class (institute_id, class_id),
  CONSTRAINT fk_asg_class FOREIGN KEY (class_id) REFERENCES klass(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS submission (
  id              CHAR(32)  NOT NULL PRIMARY KEY,
  institute_id    CHAR(32)  NOT NULL,
  assignment_id   CHAR(32)  NOT NULL,
  student_user_id CHAR(32)  NOT NULL,
  body_text       MEDIUMTEXT NULL,
  file_path       VARCHAR(200) NULL,   -- نام تصادفی روی دیسک، نه نام اصلی کاربر
  file_name       VARCHAR(200) NULL,   -- نام اصلی، فقط برای نمایش و دانلود
  file_size       INT UNSIGNED NULL,
  submitted_at    DATETIME  NOT NULL,
  is_late         TINYINT(1) NOT NULL DEFAULT 0,
  score           DECIMAL(5,2) NULL,
  feedback        TEXT      NULL,
  graded_by       CHAR(32)  NULL,
  graded_at       DATETIME  NULL,
  UNIQUE KEY uq_submission (assignment_id, student_user_id),
  KEY ix_sub_student (institute_id, student_user_id),
  CONSTRAINT fk_sub_asg FOREIGN KEY (assignment_id) REFERENCES assignment(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ═══════════════════════════════════════════════════════════════════
--  نسخهٔ ۳ — سوپرادمین پلتفرم (admin.talkora.ir)
--
--  این‌ها مال شما (صاحب محصول) است، نه مال آموزشگاه‌ها. برای همین
--  institute_id ندارند و ورودشان با نام کاربری و رمز است، نه پیامک:
--  سوپرادمین یک نفر است (یا چند نفر تیم) و شمارهٔ موبایلش نباید تنها
--  کلید سامانه باشد. این جدول‌ها را پنل superadmin/ می‌خواند —
--  دسترسی‌اش به همهٔ آموزشگاه‌ها با همین حساب کنترل می‌شود، نه با
--  membership معمولی.
-- ═══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS admin_user (
  id            CHAR(32)    NOT NULL PRIMARY KEY,
  username      VARCHAR(64) NOT NULL,
  -- password_hash با الگوریتم پیش‌فرض PHP؛ خود رمز هیچ‌جا ذخیره نمی‌شود
  pass_hash     VARCHAR(255) NOT NULL,
  full_name     VARCHAR(120) NOT NULL DEFAULT '',
  status        VARCHAR(16) NOT NULL DEFAULT 'active',
  last_login_at DATETIME    NULL,
  created_at    DATETIME    NOT NULL,
  UNIQUE KEY uq_admin_user (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_session (
  token_hash   CHAR(64)    NOT NULL PRIMARY KEY,
  admin_id     CHAR(32)    NOT NULL,
  expires_at   DATETIME    NOT NULL,
  last_seen_at DATETIME    NULL,
  revoked_at   DATETIME    NULL,
  ip           VARCHAR(45) NULL,
  user_agent   VARCHAR(255) NULL,
  created_at   DATETIME    NOT NULL,
  KEY ix_asession_admin (admin_id),
  CONSTRAINT fk_asession_admin FOREIGN KEY (admin_id) REFERENCES admin_user(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تنظیمات سایت: کلید و مقدار، تا اضافه‌کردن تنظیم تازه مهاجرت نخواهد
CREATE TABLE IF NOT EXISTS site_setting (
  skey       VARCHAR(64) NOT NULL PRIMARY KEY,
  svalue     TEXT        NULL,
  updated_at DATETIME    NOT NULL,
  updated_by CHAR(32)    NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- درخواست‌های دموی رایگان از فرم سایت
CREATE TABLE IF NOT EXISTS demo_lead (
  id         CHAR(32)     NOT NULL PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  phone      VARCHAR(20)  NOT NULL,
  email      VARCHAR(160) NULL,
  institute  VARCHAR(160) NULL,
  students   VARCHAR(40)  NULL,
  note       TEXT         NULL,
  status     VARCHAR(16)  NOT NULL DEFAULT 'new',   -- new | contacted | won | lost
  admin_note TEXT         NULL,
  ip         VARCHAR(45)  NULL,
  mailed     TINYINT(1)   NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL,
  KEY ix_lead_status (status, created_at),
  KEY ix_lead_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ═══════════════════════════════════════════════════════════════════
--  نسخهٔ ۴ — تعلیق آموزشگاه و ورود به‌جای کاربر (impersonation)
--
--  این بخش افزایشی است و روی یک نصب قبلی هم یک‌بار قابل اجراست.
--  دو ستون تازه روی institute برای تعلیق مستأجر توسط سوپرادمین، و
--  یک جدول تک‌مصرفی برای «ورود به‌جای کاربر»: سوپرادمین با دلیل
--  اجباری یک تیکت می‌سازد، پنل آموزشگاه همان تیکت را مصرف و نشست
--  عادی کاربر هدف را صادر می‌کند — بدون اینکه رمز یا کد ورود کاربر
--  جایی رد و بدل شود. عمر تیکت کوتاه (۶۰ ثانیه) و تک‌مصرفی است.
-- ═══════════════════════════════════════════════════════════════════

ALTER TABLE institute ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'active';   -- active | suspended
ALTER TABLE institute ADD COLUMN suspended_reason VARCHAR(255) NULL;

-- بازهٔ قرارداد. active_to خودش چیزی را نمی‌بندد و نباید ببندد؛
-- بستن کار دستور جاروی super.php است. این ستون فقط می‌گوید قرارداد
-- تا کِی است — بدون آن، روزی که قرارداد تمام می‌شود اگر کسی یادش
-- برود، آموزشگاه سال‌ها رایگان کار می‌کند.
ALTER TABLE institute ADD COLUMN active_from DATE NULL;
ALTER TABLE institute ADD COLUMN active_to   DATE NULL;
ALTER TABLE institute ADD KEY ix_inst_active_to (active_to);

CREATE TABLE IF NOT EXISTS impersonation_ticket (
  id             CHAR(32)     NOT NULL PRIMARY KEY,
  super_admin_id CHAR(32)     NOT NULL,
  target_user_id CHAR(32)     NOT NULL,
  institute_id   CHAR(32)     NOT NULL,
  -- چرا این ورود لازم بود؛ در رویدادها هم نشان داده می‌شود، نه فقط اینجا
  reason         VARCHAR(255) NOT NULL,
  expires_at     DATETIME     NOT NULL,
  consumed_at    DATETIME     NULL,
  ip             VARCHAR(45)  NULL,
  created_at     DATETIME     NOT NULL,
  KEY ix_imp_target (target_user_id),
  CONSTRAINT fk_imp_user FOREIGN KEY (target_user_id) REFERENCES app_user(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ═══════════════════════════════════════════════════════════════════
--  نسخهٔ ۵ — پنل میت (Jitsi)
--
--  دسترسیِ «ساخت جلسهٔ میت» آبشاری است، دو سطح:
--    ۱) institute.jitsi_enabled  — کلید اصلی سوپرادمین؛ کل آموزشگاه را
--       روشن/خاموش می‌کند (همان الگوی status/suspended_reason بالا).
--    ۲) membership.can_host_meeting — مجوز شخصی. مدیر از ابتدا دارد
--       (چون خودش آموزشگاه را اداره می‌کند)، مدرس ندارد تا مدیر بدهد.
--       سوپرادمین می‌تواند مجوز هرکسی — حتی مدیر — را هم بگیرد.
--
--  اتاق جیتسی نیازی به ستون تازه ندارد: از join_url موجود روی klass
--  استفاده می‌کند، فقط این‌بار سرور خودش پرش می‌کند (talkora-{classId})
--  نه اینکه مدیر تایپ کند.
-- ═══════════════════════════════════════════════════════════════════

ALTER TABLE institute  ADD COLUMN jitsi_enabled    TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE membership ADD COLUMN can_host_meeting TINYINT(1) NOT NULL DEFAULT 0;

-- این UPDATE فقط مدیرهای همان لحظه را می‌گیرد، نه مدیرهای بعدی.
-- سال‌ها تنها چیزی بود که «مدیر از ابتدا دارد» را پیاده می‌کرد، و
-- برای هر آموزشگاهی که بعداً ساخته شد کار نمی‌کرد (مهاجرت ۰۱۳).
-- حالا مسئولیتش با کد است: default_can_host_meeting($role) در هر
-- INSERT INTO membership، با نگهبانِ tests/membership-meeting-access.py.
UPDATE membership SET can_host_meeting = 1 WHERE role = 'manager';


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


-- ── عضویت: از تک‌نقشی به چند-نقشی ─────────────────────────────────
--
-- قید قدیمی (آموزشگاه، کاربر) یعنی هر کس در هر آموزشگاه فقط یک نقش
-- دارد — و آموزشگاهی که مدیرش خودش هم تدریس می‌کند باید بین دو حساب
-- جابه‌جا شود. قید تازه سه‌تایی است: همان آدم هم مدیر است هم مدرس،
-- ولی عضویت تکراریِ *هم‌نقش* هنوز رد می‌شود.
--
-- ترتیب عمدی است: اول قید تازه، بعد برداشتن قدیمی. برعکسش پنجره‌ای
-- باز می‌کند که هیچ قیدی برقرار نیست.
ALTER TABLE membership ADD UNIQUE KEY uq_member_role (institute_id, user_id, role_id);
ALTER TABLE membership DROP INDEX uq_member;

-- role_id از این پس اجباری است، و به نقش واقعی اشاره می‌کند. کلید
-- خارجی *بعد* از NOT NULL می‌آید تا اگر دادهٔ ناسازگاری بود، در گام
-- قبل معلوم شده باشد نه اینجا.
ALTER TABLE membership MODIFY COLUMN role_id CHAR(32) NOT NULL;
ALTER TABLE membership ADD CONSTRAINT fk_member_role FOREIGN KEY (role_id) REFERENCES role(id);

-- انتخابگر نقش در هر بارگذاری پنل همهٔ عضویت‌های فعال کاربر را
-- می‌خواند؛ بدون این ایندکس، پیمایش کامل جدول است.
ALTER TABLE membership ADD KEY ix_member_active (user_id, status, expires_at);


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

-- ═══════════════════════════════════════════════════════════════════
--  صف درخواست پیوستن
--
--  کسی که کد پیوستن ندارد، آموزشگاه را از فهرست انتخاب می‌کند و اینجا
--  می‌نشیند تا مدیر تأیید یا رد کند.
-- ═══════════════════════════════════════════════════════════════════
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

INSERT INTO permission (perm_key, group_key, label_fa, is_platform, is_write, sort_order) VALUES
('member.joincode',  'member', 'مدیریت کد پیوستن آموزشگاه', 0, 1, 60),
('member.approve',   'member', 'تأیید یا رد درخواست پیوستن', 0, 1, 70);

INSERT INTO role_permission (role_id, perm_key, is_platform, scope) VALUES
('r_manager', 'member.joincode', 0, 'institute'),
('r_manager', 'member.approve',  0, 'institute');


-- ═══════════════════════════════════════════════════════════════════
--  اعلان
--
--  اعلان یک رویداد است و مخاطبش یک فهرست. فهرست همان لحظهٔ ارسال
--  باز می‌شود و ثابت می‌ماند: اگر موقع خواندن حساب می‌شد، زبان‌آموزی
--  که هفتهٔ بعد از کلاس درمی‌آید اعلان هفتهٔ پیش را از دست می‌داد،
--  انگار هرگز فرستاده نشده. ضمن اینکه «خوانده شد» جایی برای نشستن
--  ندارد مگر ردیفی به‌ازای هر گیرنده.
-- ═══════════════════════════════════════════════════════════════════

-- ── خودِ اعلان ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS notification (
  id           CHAR(32)     NOT NULL PRIMARY KEY,

  -- NULL یعنی اعلان سطح پلتفرم؛ سوپرادمین می‌تواند به چند آموزشگاه
  -- هم‌زمان بفرستد و آن‌وقت این ستون به هیچ‌کدام تعلق ندارد
  institute_id CHAR(32)     NULL,

  sender_id    CHAR(32)     NULL,          -- app_user، یا NULL برای سوپرادمین
  sender_admin CHAR(32)     NULL,          -- admin_user، وقتی از پنل پلتفرم آمده
  sender_name  VARCHAR(120) NOT NULL,      -- برای نمایش، مستقل از حذف حساب

  title        VARCHAR(140) NOT NULL,
  body         VARCHAR(2000) NOT NULL,

  -- کجای پنل باز شود وقتی کاربر روی اعلان زد؛ مسیر داخلی مثل m/join
  link         VARCHAR(120) NULL,

  /*
   * لحن اعلان. رنگ و نشان از همین می‌آید.
   *
   * «urgent» عمداً از بقیه جداست: فقط همین‌ها اجازهٔ پیامک دارند.
   * بدون این تفکیک، هر اعلانی وسوسهٔ پیامک‌شدن دارد و کاربر بعد از
   * هفتهٔ اول همه را خاموش می‌کند — و آن‌وقت پیام واقعاً فوری هم
   * نمی‌رسد.
   */
  kind         VARCHAR(16)  NOT NULL DEFAULT 'info',

  -- شرحِ خواناـبرای‌انسانِ مخاطب، برای نشان‌دادن در سابقهٔ فرستنده
  audience     VARCHAR(160) NOT NULL,
  recipients   INT UNSIGNED NOT NULL DEFAULT 0,

  sms_sent     TINYINT(1)   NOT NULL DEFAULT 0,
  created_at   DATETIME     NOT NULL,

  /*
   * ترتیبِ واقعیِ درج، مستقل از ساعت.
   *
   * created_at دقتِ ثانیه دارد. دو اعلانی که در یک ثانیه فرستاده
   * شوند — که در ارسال پشت‌سرهم عادی است — با ORDER BY created_at
   * ترتیب تعریف‌شده‌ای ندارند، پس فهرست «تازه‌ترین اول» گاهی وارونه
   * می‌شود و بین دو نوبت به‌روزرسانی جابه‌جا.
   *
   * seq این را قطعی می‌کند و هزینه‌اش یک ستون هشت‌بایتی است.
   */
  seq          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  UNIQUE KEY uq_notif_seq (seq),

  KEY ix_notif_inst (institute_id, created_at),
  KEY ix_notif_sender (sender_id, created_at),
  CONSTRAINT ck_notif_kind CHECK (kind IN ('info','success','warn','urgent')),
  CONSTRAINT fk_notif_inst FOREIGN KEY (institute_id) REFERENCES institute(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── گیرنده‌ها ─────────────────────────────────────────────────────
-- یک ردیف به‌ازای هر نفر. همین‌جاست که «خوانده شد» می‌نشیند.
CREATE TABLE IF NOT EXISTS notification_target (
  id              CHAR(32) NOT NULL PRIMARY KEY,
  notification_id CHAR(32) NOT NULL,
  user_id         CHAR(32) NOT NULL,
  read_at         DATETIME NULL,

  UNIQUE KEY uq_notif_target (notification_id, user_id),

  -- پرس‌وجوی داغ: «اعلان‌های نخواندهٔ من». ترتیب ستون‌ها عمدی است —
  -- user_id اول چون همیشه در شرط هست، read_at دوم چون گاهی فیلتر
  -- می‌شود، و id آخر برای ترتیب پایدار.
  KEY ix_target_inbox (user_id, read_at, notification_id),

  CONSTRAINT fk_target_notif FOREIGN KEY (notification_id) REFERENCES notification(id) ON DELETE CASCADE,
  CONSTRAINT fk_target_user  FOREIGN KEY (user_id) REFERENCES app_user(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── مجوزها ────────────────────────────────────────────────────────
--
-- notify.send محدوده دارد و همان محدوده تعیین می‌کند چه کسی چه کسانی
-- را می‌بیند: مدرس با own_classes فقط به زبان‌آموزان کلاس‌های خودش
-- می‌رسد، مدیر با institute به همه.
--
-- notify.sms جداست، نه چون کار دیگری می‌کند بلکه چون هزینه دارد و
-- قابل بازگشت نیست. مدرسی که اشتباهی اعلان بفرستد، آن را پاک می‌کند؛
-- پیامکی که رفته، رفته.
INSERT INTO permission (perm_key, group_key, label_fa, is_platform, is_write, sort_order) VALUES
('notify.view', 'notify', 'دیدن اعلان‌ها',          0, 0, 10),
('notify.send', 'notify', 'فرستادن اعلان',           0, 1, 20),
('notify.sms',  'notify', 'فرستادن اعلان با پیامک',  0, 1, 30);

INSERT INTO role_permission (role_id, perm_key, is_platform, scope) VALUES
('r_manager', 'notify.view', 0, 'own'),
('r_manager', 'notify.send', 0, 'institute'),
('r_manager', 'notify.sms',  0, 'institute'),
('r_teacher', 'notify.view', 0, 'own'),
('r_teacher', 'notify.send', 0, 'own_classes'),
('r_student', 'notify.view', 0, 'own');


-- ═══════════════════════════════════════════════════════════════════
--  وبلاگ
--
--  هدف سئوست. نوشته‌ها سمت سرور رندر می‌شوند نه با جاوااسکریپت:
--  پنل‌ها تک‌صفحه‌ای‌اند چون کاربرشان وارد شده و منتظر می‌ماند، ولی
--  خزندهٔ گوگل منتظر نمی‌ماند. هر نوشته باید HTML کامل در نشانی
--  خودش باشد.
--
--  slug جدا از عنوان ذخیره می‌شود تا عنوان قابل ویرایش بماند بی‌آنکه
--  نشانیِ ایندکس‌شده بشکند.
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


UPDATE membership SET role_id = CASE role
  WHEN 'manager' THEN 'r_manager'
  WHEN 'teacher' THEN 'r_teacher'
  ELSE 'r_student' END
 WHERE role_id IS NULL;
