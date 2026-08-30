#!/usr/bin/env python3
"""
نگهبانِ role_id در عضویت‌ها.

از مهاجرت ۰۰۶، membership.role_id ناتهی است و پیش‌فرض ندارد. هر
INSERT‌ی که آن را ننویسد، روی MySQL با STRICT_TRANS_TABLES — یعنی هر
هاست معمولی از جمله همین پلسک — خطای

    Field 'role_id' doesn't have a default value

می‌دهد و کاربر ۵۰۰ می‌بیند. این یک بار افتاد و شش دستور را خاموش کرد:
دعوت عضو، ورود با پیامک (هر دو مسیرش)، ساخت فضای کاری، و دو دستور
سوپرادمین. یعنی از لحظهٔ اجرای ۰۰۶ روی سرور زنده، هیچ عضو تازه‌ای
اضافه نمی‌شد.

چرا آزمونِ متن و نه آزمونِ رفتار: رفتار این شش مسیر نشست معتبر و
داده‌های واقعی می‌خواهد. آزمون متن ارزان است و همان چیزی را می‌گیرد که
از دست رفت — دستوری که ستون را جا انداخته.

اجرا:  python tests/membership-role-id.py
"""
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
DIRS = ["panel/api", "superadmin/api"]

# «INSERT INTO membership» تا اولین پرانتزِ بستهٔ فهرست ستون‌ها
PATTERN = re.compile(r"INSERT\s+INTO\s+membership\s*\(([^)]*)\)", re.I | re.S)

bad = []
seen = 0

for d in DIRS:
    for f in sorted((ROOT / d).glob("*.php")):
        src = f.read_text(encoding="utf-8")
        for m in PATTERN.finditer(src):
            seen += 1
            cols = [c.strip() for c in m.group(1).split(",")]
            line = src[:m.start()].count("\n") + 1
            if "role_id" not in cols:
                bad.append(f"{d}/{f.name}:{line}  ستون‌ها: {', '.join(cols)}")
                continue
            # فهرست ستون‌ها و جای‌نگهدارها باید هم‌اندازه باشند؛
            # جا افتادنِ یک «?» همان خطا را با پیام گیج‌کننده‌تر می‌دهد
            tail = src[m.end():m.end() + 400]
            vm = re.search(r"VALUES\s*\(([^)]*)\)", tail, re.I | re.S)
            if vm:
                holes = vm.group(1).count("?")
                if holes != len(cols):
                    bad.append(
                        f"{d}/{f.name}:{line}  {len(cols)} ستون ولی {holes} جای‌نگهدار")

if seen == 0:
    print("!! هیچ INSERT INTO membership پیدا نشد — الگو خراب است؟")
    sys.exit(1)

print(f"بررسی {seen} دستور INSERT INTO membership")
if bad:
    print("\nناموفق:")
    for b in bad:
        print("  ✗ " + b)
    print("\nهر عضویت باید role_id بنویسد. از system_role_id($role) استفاده کنید.")
    sys.exit(1)

print("✓ همه role_id می‌نویسند و شمار جای‌نگهدارها می‌خواند")
