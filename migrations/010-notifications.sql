-- ═══════════════════════════════════════════════════════════════════
--  نسخهٔ ۱۰ — اعلان
--
--  سه فرستنده و سه محدوده:
--
--    مدرس       → کلاس‌های خودش
--    مدیر       → کل آموزشگاه، یا یک نقش، یا یک کلاس
--    سوپرادمین  → همهٔ آموزشگاه‌ها، یا بخشی از آن‌ها
--
--  چرا دو جدول و نه یکی: اعلان یک *رویداد* است و مخاطبش یک *فهرست*.
--  اگر مخاطب را موقع خواندن حساب کنیم — «همهٔ زبان‌آموزان کلاس ب۱» —
--  دو چیز می‌شکند. اول اینکه عضویت عوض می‌شود: زبان‌آموزی که هفتهٔ
--  بعد از کلاس درمی‌آید، اعلان هفتهٔ پیش را از دست می‌دهد، انگار
--  هرگز فرستاده نشده. دوم اینکه «خوانده شد» جایی برای نشستن ندارد
--  مگر ردیفی به‌ازای هر گیرنده.
--
--  پس فهرست همان لحظهٔ ارسال باز می‌شود و ثابت می‌ماند. اعلان یک
--  واقعیت تاریخی است، نه یک پرس‌وجو.
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
