#!/usr/bin/env python3
"""
نصب تازه باید با پایگاهِ مهاجرت‌شده یکی باشد.

این مخزن دو مسیر به یک ساختار دارد:

    نصب تازه   → panel/api/schema.mysql.sql  (install.php اجرایش می‌کند)
    سرور موجود → migrations/*.sql            (دستی در phpMyAdmin)

هر تغییر ساختار باید در *هر دو* بنشیند. اگر فقط مهاجرت نوشته شود،
سرورهای موجود درست می‌شوند و نصب‌های تازه با ساختار قدیمی بالا می‌آیند
— و کدی که ستون تازه می‌خواهد، روی مشتری تازه ۵۰۰ می‌دهد.

این دقیقاً دو بار افتاد:

    نسخهٔ ۴ ستون institute.status را افزود ولی فقط در مهاجرت.
    سرور زنده ماه‌ها بدون آن ماند تا مهاجرت ۰۰۸ ترمیمش کرد.

    مهاجرت ۰۰۷ قید تک‌نقشی را برداشت و ۰۰۹ پروفایل را افزود، ولی
    schema.mysql.sql هیچ‌کدام را نگرفت. نصب تازه membership.role_id
    نداشت — یعنی هیچ عضوی ساخته نمی‌شد.

آزمون هر دو مسیر را روی دو پایگاه خالی می‌سازد و ساختارشان را
مقایسه می‌کند. هر ستون، هر ایندکس، هر پیش‌فرض.

اجرا (به mysql/mariadb روی PATH یا در TALKORA_MYSQL نیاز دارد):
    python tests/schema-drift.py
"""
import os
import pathlib
import shutil
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
HOST = os.environ.get("TALKORA_TEST_HOST", "127.0.0.1")
PORT = os.environ.get("TALKORA_TEST_PORT", "3399")
USER = os.environ.get("TALKORA_TEST_USER", "root")
PASS = os.environ.get("TALKORA_TEST_PASS", "")

MYSQL = os.environ.get("TALKORA_MYSQL") or shutil.which("mariadb") or shutil.which("mysql")
if not MYSQL:
    print("!! mariadb/mysql پیدا نشد. TALKORA_MYSQL را تنظیم کنید.")
    sys.exit(2)

A, B = "talkora_drift_fresh", "talkora_drift_migrated"


def run(db, sql=None, path=None, quiet=False):
    cmd = [MYSQL, f"--host={HOST}", f"--port={PORT}", f"--user={USER}"]
    if PASS:
        cmd.append(f"--password={PASS}")
    cmd += ["--default-character-set=utf8mb4", "-N", "-B"]
    if db:
        cmd.append(db)
    data = path.read_bytes() if path else (sql or "").encode("utf-8")
    r = subprocess.run(cmd, input=data, capture_output=True)
    err = r.stderr.decode("utf-8", "replace")
    # هشدار SSL روی لاگین بی‌رمز همیشه می‌آید و خطا نیست
    err = "\n".join(l for l in err.splitlines() if "ssl-verify-server-cert" not in l)
    if r.returncode != 0 and not quiet:
        print(f"!! SQL ناموفق روی {db or '(بدون پایگاه)'}:\n{err.strip()[:600]}")
        sys.exit(1)
    return r.stdout.decode("utf-8", "replace")


def fingerprint(db):
    """ستون‌ها و ایندکس‌ها، به شکلی که بشود خط‌به‌خط مقایسه کرد."""
    cols = run(None, f"""
        SELECT CONCAT(TABLE_NAME,'.',COLUMN_NAME,'  ',COLUMN_TYPE,'  ',
                      IS_NULLABLE,'  ',IFNULL(COLUMN_DEFAULT,'—'),'  ',EXTRA)
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='{db}' ORDER BY 1;""")
    idx = run(None, f"""
        SELECT CONCAT(TABLE_NAME,'::',INDEX_NAME,'  ',
                      GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX),'  uniq=',
                      IF(MIN(NON_UNIQUE)=0,'1','0'))
        FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='{db}'
        GROUP BY TABLE_NAME, INDEX_NAME ORDER BY 1;""")
    # قیدها جدا خوانده می‌شوند چون در ایندکس‌ها دیده نمی‌شوند: کلید
    # خارجی روی ستونی که از قبل ایندکس دارد، ایندکس تازه نمی‌سازد.
    # همین نکته یک بار fk_member_role را از دید این آزمون پنهان کرد.
    con = run(None, f"""
        SELECT CONCAT(c.TABLE_NAME,'::',c.CONSTRAINT_NAME,'  ',c.CONSTRAINT_TYPE)
        FROM information_schema.TABLE_CONSTRAINTS c
        WHERE c.CONSTRAINT_SCHEMA='{db}' ORDER BY 1;""")
    return (sorted(l for l in cols.splitlines() if l.strip()),
            sorted(l for l in idx.splitlines() if l.strip()),
            sorted(l for l in con.splitlines() if l.strip()))


print("ساخت دو پایگاه خالی…")
for d in (A, B):
    run(None, f"DROP DATABASE IF EXISTS {d}; "
              f"CREATE DATABASE {d} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;")

print("مسیر ۱ — نصب تازه از schema.mysql.sql")
run(A, path=ROOT / "panel/api/schema.mysql.sql")

print("مسیر ۲ — نصب قدیمی + همهٔ مهاجرت‌ها به ترتیب")
run(B, path=ROOT / "panel/api/schema.mysql.sql")
migs = sorted((ROOT / "migrations").glob("*.sql"))
for m in migs:
    # مهاجرت روی ساختاری که از قبل شاملش هست، «Duplicate» می‌دهد و
    # این درست است: یعنی schema.mysql.sql آن را گرفته. فقط خطاهای
    # دیگر مهم‌اند.
    out = run(B, path=m, quiet=True)
    print(f"   {m.name}")

fa, ia, ca = fingerprint(A)
fb, ib, cb = fingerprint(B)

bad = False
for label, x, y in (("ستون", fa, fb), ("ایندکس", ia, ib), ("قید", ca, cb)):
    only_a = [l for l in x if l not in y]
    only_b = [l for l in y if l not in x]
    if only_a or only_b:
        bad = True
        print(f"\n✗ اختلاف در {label}:")
        for l in only_b:
            print(f"    فقط بعد از مهاجرت‌ها: {l}")
        for l in only_a:
            print(f"    فقط در نصب تازه:      {l}")

print("\n" + "─" * 58)
if bad:
    print("نصب تازه با پایگاه مهاجرت‌شده یکی نیست.")
    print("هر ستون یا ایندکسی که در مهاجرت افزوده‌اید، در")
    print("panel/api/schema.mysql.sql هم باید باشد.")
    sys.exit(1)

print(f"✓ هر دو مسیر یک ساختار می‌سازند — {len(fa)} ستون، {len(ia)} ایندکس، {len(ca)} قید")
for d in (A, B):
    run(None, f"DROP DATABASE IF EXISTS {d};")
