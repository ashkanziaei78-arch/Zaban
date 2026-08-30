#!/usr/bin/env python3
"""
چرخهٔ کامل پیوستن، از دو سر: متقاضی و مدیر.

آزمون signup.py فقط سمت متقاضی را می‌بیند — درخواست در صف می‌نشیند و
تمام. اینجا دنبالش را می‌گیریم: مدیر کد صادر می‌کند، کسی با کد عضو
می‌شود، کس دیگری در صف می‌نشیند و مدیر تأیید یا ردش می‌کند.

چرا مهم است: تا پیش از این، صف درخواست چاه بی‌ته بود. کاربر ثبت‌نام
می‌کرد، پیام «به‌محض تأیید مدیر…» می‌دید، و هیچ‌کس هیچ‌وقت تأیید
نمی‌کرد چون دکمه‌ای وجود نداشت.

پیش‌نیاز:
  php -S 127.0.0.1:8099 -t panel   (با panel/api/config.php به دیتابیس آزمون)

اجرا:  python tests/joinflow.py
"""
import http.cookiejar
import json
import os
import sys
import time
import urllib.error
import urllib.request

BASE = os.environ.get("TALKORA_TEST_URL", "http://127.0.0.1:8099/api")

_pass = 0
_fail = 0


def session():
    """هر بازیگر نشست خودش را دارد؛ کوکی مدیر نباید به متقاضی برسد."""
    return urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))


def post(op, endpoint, body):
    data = json.dumps(body, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(f"{BASE}/{endpoint}", data=data,
                                 headers={"Content-Type": "application/json"}, method="POST")
    try:
        with op.open(req, timeout=15) as r:
            return r.status, json.loads(r.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read().decode("utf-8"))


def ok(w):
    global _pass
    _pass += 1
    print(f"  ✓ {w}")


def bad(w, d=""):
    global _fail
    _fail += 1
    print(f"  ✗ {w}")
    if d:
        print(f"      {d[:220]}")


def check(c, w, d=""):
    ok(w) if c else bad(w, d)


def national_id(seed):
    n = seed.rjust(9, "0")[-9:]
    t = sum(int(n[i]) * (10 - i) for i in range(9))
    r = t % 11
    return n + str(r if r < 2 else 11 - r)


sfx = str(int(time.time()))[-7:]
MGR, CODER, ASKER = "09" + sfx + "71", "09" + sfx + "72", "09" + sfx + "73"
PROFILE = {"password": "abcd1234", "firstNameFa": "نام", "lastNameFa": "خانوادگی"}

print("\n═══ ۱. مدیر آموزشگاه می‌سازد ═══")
mgr = session()
INST = "آموزشگاه پیوستن " + sfx
r = post(mgr, "signup.php", {"action": "register", "phone": MGR, **PROFILE,
                             "firstNameFa": "مدیر", "nationalId": national_id(sfx),
                             "mode": "manager", "instituteName": INST})
check(r[1].get("outcome") == "manager", "آموزشگاه ساخته شد", str(r[1]))

print("\n═══ ۲. کد پیوستن ═══")
r = post(mgr, "institute.php", {"action": "joinCode"})
check(r[1].get("ok") and r[1].get("code") is None, "در آغاز کدی نیست", str(r[1]))

r = post(mgr, "institute.php", {"action": "joinCodeSet", "role": "student", "active": True})
code = r[1].get("code") or ""
check(len(code) == 6, "کد شش‌نویسه‌ای صادر شد", str(r[1]))
check(not set(code) & set("O0I1L"), "نویسه‌های گیج‌کننده در کد نیست", f"کد: {code}")

r = post(mgr, "institute.php", {"action": "joinCode"})
check(r[1].get("code") == code and r[1].get("active") is True,
      "کد ذخیره و فعال شد", str(r[1]))

print("\n═══ ۳. کسی با کد عضو می‌شود ═══")
coder = session()
r = post(coder, "signup.php", {"action": "checkCode", "code": code})
check(r[1].get("institute", {}).get("name") == INST, "کد به آموزشگاه درست می‌رسد", str(r[1]))

r = post(coder, "signup.php", {"action": "register", "phone": CODER, **PROFILE,
                               "firstNameFa": "کددار", "mode": "code", "code": code})
check(r[1].get("outcome") == "joined", "بی‌درنگ عضو شد", str(r[1]))
check(r[1].get("role") == "student", "با نقشِ کد عضو شد", str(r[1]))

print("\n═══ ۴. چرخاندن کد، کد قبلی را می‌سوزاند ═══")
r = post(mgr, "institute.php", {"action": "joinCodeSet", "role": "student",
                                "active": True, "rotate": True})
newCode = r[1].get("code") or ""
check(newCode and newCode != code, "کد تازه فرق دارد", f"{code} → {newCode}")

r = post(session(), "signup.php", {"action": "checkCode", "code": code})
check(r[1].get("error") == "bad_code", "کد قدیمی دیگر کار نمی‌کند", str(r[1]))

print("\n═══ ۵. خاموش‌کردن کد ═══")
post(mgr, "institute.php", {"action": "joinCodeSet", "role": "student", "active": False})
r = post(session(), "signup.php", {"action": "checkCode", "code": newCode})
check(r[1].get("error") == "bad_code", "کد خاموش پذیرفته نمی‌شود", str(r[1]))

print("\n═══ ۶. صف درخواست ═══")
asker = session()
r = post(asker, "signup.php", {"action": "institutes", "q": sfx})
iid = next((i["id"] for i in r[1].get("institutes", []) if i["name"] == INST), None)
check(iid is not None, "آموزشگاه در فهرست عمومی هست")

r = post(asker, "signup.php", {"action": "register", "phone": ASKER, **PROFILE,
                               "firstNameFa": "متقاضی", "mode": "request",
                               "instituteId": iid, "role": "teacher",
                               "message": "سه سال سابقهٔ تدریس"})
check(r[1].get("outcome") == "pending", "درخواست در صف نشست", str(r[1]))

r = post(mgr, "institute.php", {"action": "requests"})
reqs = r[1].get("requests", [])
mine = next((q for q in reqs if q["phone"] == ASKER), None)
check(mine is not None, "مدیر درخواست را می‌بیند", str(r[1])[:200])
if mine:
    check(mine["role"] == "teacher", "نقش خواسته‌شده مدرس است", str(mine))
    check(mine["message"] == "سه سال سابقهٔ تدریس", "پیام متقاضی رسیده", str(mine))
    check(mine["name"] == "متقاضی خانوادگی", "نام فارسی درست ساخته شد", str(mine))

print("\n═══ ۷. مدیر تأیید می‌کند — با نقشی که خودش می‌گوید ═══")
if mine:
    # متقاضی «مدرس» خواسته بود؛ مدیر «زبان‌آموز» می‌دهد
    r = post(mgr, "institute.php", {"action": "approveRequest", "id": mine["id"],
                                    "role": "student"})
    check(r[1].get("role") == "student", "نقشِ مدیر بر خواستهٔ متقاضی مقدم است", str(r[1]))

    r = post(mgr, "institute.php", {"action": "approveRequest", "id": mine["id"]})
    check(r[1].get("error") == "not_pending", "تأیید دوباره رد شد", str(r[1]))

    r = post(mgr, "institute.php", {"action": "members"})
    got = [m for m in r[1].get("members", []) if m["phone"] == ASKER]
    check(len(got) == 1, "متقاضی حالا عضو است", f"{len(got)} ردیف")
    if got:
        check(got[0]["role"] == "student", "با نقش زبان‌آموز، نه مدرس", str(got[0]))

    r = post(mgr, "institute.php", {"action": "requests", "status": "pending"})
    check(not any(q["phone"] == ASKER for q in r[1].get("requests", [])),
          "درخواست از صف انتظار درآمد")

print("\n═══ ۸. عضو آموزشگاه نمی‌تواند صف را ببیند ═══")
r = post(coder, "institute.php", {"action": "requests"})
check(r[0] in (401, 403), "زبان‌آموز به صف دسترسی ندارد", f"{r[0]} {r[1]}")
r = post(coder, "institute.php", {"action": "joinCodeSet", "role": "teacher", "active": True})
check(r[0] in (401, 403), "زبان‌آموز کد صادر نمی‌کند", f"{r[0]} {r[1]}")

print("\n" + "─" * 58)
print(f"موفق: {_pass}    ناموفق: {_fail}")
sys.exit(0 if _fail == 0 else 1)
