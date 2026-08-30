#!/usr/bin/env python3
"""
نگهبانِ can_host_meeting در عضویت‌ها.

کد از دو جا صریح می‌گوید مدیر مجوز جلسهٔ میت را «از ابتدا دارد»:
بند نسخهٔ ۵ در schema.mysql.sql، و کامنت setMeetingAccess در
institute.php («نه به خودش، که از ابتدا دارد»).

ولی آن حرف هیچ‌وقت در کد پیاده نشده بود. تنها چیزی که وجود داشت یک
UPDATE یک‌باره کنار ALTER بود:

    ALTER TABLE membership ADD COLUMN can_host_meeting TINYINT(1) NOT NULL DEFAULT 0;
    UPDATE membership SET can_host_meeting = 1 WHERE role = 'manager';

یعنی مدیرهای آن روز مجوز گرفتند و بس. هر مدیری که بعد از آن ساخته شد
پیش‌فرضِ ستون را گرفت که صفر است — و نتیجه‌اش این بود که مدیرِ هر
آموزشگاه تازه‌ای نمی‌توانست کلاس آنلاین جیتسی بسازد، با پیام خطایی که
او را به «مدیر آموزشگاه» ارجاع می‌داد؛ یعنی به خودش.

برخلاف role_id، این ستون پیش‌فرض دارد، پس هیچ خطایی نمی‌دهد و هیچ ۵۰۰
نمی‌شود. بی‌صدا صفر می‌نویسد و قابلیت را خاموش نگه می‌دارد. همین
بی‌صدا بودن است که آزمون می‌خواهد.

اجرا:  python tests/membership-meeting-access.py
"""
import io
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
DIRS = ["panel/api", "superadmin/api"]

PATTERN = re.compile(r"INSERT\s+INTO\s+membership\s*\(([^)]*)\)", re.I | re.S)

bad = []
seen = 0

for d in DIRS:
    for f in sorted((ROOT / d).glob("*.php")):
        src = io.open(f, encoding="utf-8").read()
        for m in PATTERN.finditer(src):
            seen += 1
            cols = [c.strip() for c in m.group(1).split(",")]
            if "can_host_meeting" not in cols:
                line = src[: m.start()].count("\n") + 1
                bad.append(f"{f.relative_to(ROOT)}:{line}  ستون‌ها: {', '.join(cols)}")

if seen == 0:
    print("خطا: هیچ INSERT INTO membership پیدا نشد — الگوی آزمون کهنه شده؟")
    sys.exit(1)

# هر دو نسخهٔ کمکی باید وجود داشته باشند و یک قاعده بدهند. بستهٔ
# سوپرادمین جدا منتشر می‌شود و panel/api/ را نمی‌بیند، پس تابع عمداً
# تکراری است — ولی نباید از هم دور بیفتد.
HELPER = re.compile(
    r"function\s+default_can_host_meeting\s*\(\s*string\s+\$role\s*\)\s*:\s*int\s*\{\s*"
    r"return\s+\$role\s*===\s*'manager'\s*\?\s*1\s*:\s*0\s*;\s*\}",
    re.S,
)
homes = ["panel/api/_perm.php", "superadmin/api/_platform_ctx.php"]
missing = [p for p in homes if not HELPER.search(io.open(ROOT / p, encoding="utf-8").read())]

if missing:
    print("خطا: default_can_host_meeting در این فایل‌ها نیست یا قاعده‌اش فرق کرده:")
    for p in missing:
        print("   ", p)
    print("\nهر دو نسخه باید یک چیز بگویند: مدیر ۱، بقیه ۰.")
    sys.exit(1)

if bad:
    print("خطا: این INSERTها can_host_meeting را نمی‌نویسند و بی‌صدا صفر می‌گیرند:\n")
    for b in bad:
        print("  ", b)
    print("\nمقدارش را از default_can_host_meeting($role) بگیرید.")
    sys.exit(1)

print(f"می‌گذرد — هر {seen} دستور INSERT INTO membership، can_host_meeting را می‌نویسد.")
print("هر دو نسخهٔ default_can_host_meeting هم هست و هم‌قاعده‌اند.")
