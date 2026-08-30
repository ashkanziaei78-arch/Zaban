#!/usr/bin/env python3
"""
آزمون ثبت‌نام روی سرور PHP محلی.

پیش‌نیاز:
  1. MariaDB آزمون بالا باشد و مهاجرت‌های ۰۰۶ تا ۰۰۹ اعمال شده
  2. panel/api/config.php به همان دیتابیس اشاره کند
  3. php -S 127.0.0.1:8099 -t panel

چرا پایتون و نه bash: نسخهٔ اول این آزمون با curl در Git Bash نوشته
شده بود و روی ویندوز، آرگومان‌های فارسی پیش از رسیدن به curl به «?»
تبدیل می‌شدند. آزمون شکست می‌خورد و به‌نظر می‌رسید محصول یونیکد را
خراب می‌کند، در حالی که مشکل از خود آزمون بود. پایتون UTF-8 را
مستقیم و بدون واسطهٔ پوسته می‌فرستد.
"""
import json
import os
import sys
import time
import urllib.request
import urllib.error

BASE = os.environ.get("TALKORA_TEST_URL", "http://127.0.0.1:8099/api")

_pass = 0
_fail = 0


def post(endpoint: str, body: dict) -> tuple:
    """(کد وضعیت، بدنهٔ رمزگشایی‌شده)"""
    data = json.dumps(body, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(
        f"{BASE}/{endpoint}", data=data,
        headers={"Content-Type": "application/json"}, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=15) as r:
            return r.status, json.loads(r.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read().decode("utf-8"))


def ok(what: str) -> None:
    global _pass
    _pass += 1
    print(f"  ✓ {what}")


def bad(what: str, detail: str = "") -> None:
    global _fail
    _fail += 1
    print(f"  ✗ {what}")
    if detail:
        print(f"      {detail[:200]}")


def check(cond: bool, what: str, detail: str = "") -> None:
    ok(what) if cond else bad(what, detail)


def err_is(resp: tuple, code: str) -> bool:
    return resp[1].get("error") == code


def make_national_id(seed: str) -> str:
    """
    کد ملی معتبرِ یکتا برای هر اجرا.

    نسخهٔ اول این آزمون یک کد ملی ثابت داشت، پس اجرای دوم به
    nid_taken می‌خورد — آزمونی که فقط یک‌بار سبز می‌شود.

    الگوریتم رسمی: مجموع وزنی نه رقم اول، باقی‌مانده بر ۱۱، و
    رقم کنترلی از روی آن.
    """
    nine = seed.rjust(9, "0")[-9:]
    total = sum(int(nine[i]) * (10 - i) for i in range(9))
    r = total % 11
    return nine + str(r if r < 2 else 11 - r)


sfx = str(int(time.time()))[-7:]
NID = make_national_id(sfx)
MGR = "09" + sfx + "11"
TCH = "09" + sfx + "22"
STU = "09" + sfx + "33"
ALT = "09" + sfx + "44"

base_user = {
    "password": "abcd1234",
    "firstNameFa": "سارا", "lastNameFa": "محمدی",
    "firstNameEn": "Sara", "lastNameEn": "Mohammadi",
}

print("\n═══ ۱. اعتبارسنجی ورودی ═══")

r = post("signup.php", {"action": "register", "phone": "123", **base_user})
check(err_is(r, "bad_phone"), "شمارهٔ نامعتبر رد شد", str(r[1]))

r = post("signup.php", {"action": "register", "phone": MGR,
                        **{**base_user, "password": "kutah"}})
check(err_is(r, "weak_pass"), "رمز کوتاه رد شد", str(r[1]))

r = post("signup.php", {"action": "register", "phone": MGR,
                        **{**base_user, "firstNameFa": "", "lastNameFa": ""}})
check(err_is(r, "invalid"), "نام خالی رد شد", str(r[1]))

# ده رقم دارد ولی چک‌سام کد ملی را رد می‌کند
for nid, label in [("1234567890", "چک‌سام غلط"), ("1111111111", "ارقام یکسان")]:
    r = post("signup.php", {"action": "register", "phone": MGR, **base_user,
                            "nationalId": nid, "mode": "manager", "instituteName": "x"})
    check(err_is(r, "bad_national_id"), f"کد ملی با {label} رد شد", str(r[1]))

r = post("signup.php", {"action": "register", "phone": MGR, **base_user,
                        "birthDate": "1375-13-45", "mode": "manager", "instituteName": "x"})
check(err_is(r, "bad_birth"), "تاریخ تولد نامعتبر رد شد", str(r[1]))

r = post("signup.php", {"action": "register", "phone": MGR, **base_user,
                        "email": "not-an-email", "mode": "manager", "instituteName": "x"})
check(err_is(r, "bad_email"), "ایمیل نامعتبر رد شد", str(r[1]))

print("\n═══ ۲. مدیر: ثبت‌نام و ساخت آموزشگاه ═══")

INST_NAME = "زبان‌سرای آزمون " + sfx
r = post("signup.php", {"action": "register", "phone": MGR, **base_user,
                        "nationalId": NID, "birthDate": "1370-05-12",
                        "email": "sara@example.com", "city": "تهران",
                        "mode": "manager", "instituteName": INST_NAME})
check(r[1].get("outcome") == "manager", "مدیر ثبت‌نام شد", str(r[1]))
check(r[1].get("institute") == INST_NAME,
      "نام فارسی آموزشگاه سالم برگشت", f"برگشت: {r[1].get('institute')!r}")

r = post("signup.php", {"action": "register", "phone": MGR, **base_user,
                        "mode": "manager", "instituteName": "z"})
check(err_is(r, "phone_taken"), "شمارهٔ تکراری رد شد", str(r[1]))

r = post("signup.php", {"action": "register", "phone": ALT, **base_user,
                        "nationalId": NID, "mode": "manager", "instituteName": "z"})
check(err_is(r, "nid_taken"), "کد ملی تکراری رد شد", str(r[1]))

print("\n═══ ۳. فهرست عمومی آموزشگاه‌ها ═══")

r = post("signup.php", {"action": "institutes", "q": sfx})
lst = r[1].get("institutes", [])
check(any(i["name"] == INST_NAME for i in lst), "آموزشگاه تازه در فهرست هست",
      f"{len(lst)} مورد")
leaked = [k for i in lst for k in i if k not in ("id", "name", "city")]
check(not leaked, "هیچ فیلد اضافه‌ای نشت نکرده", f"نشتی: {leaked}")

iid = next((i["id"] for i in lst if i["name"] == INST_NAME), None)

print("\n═══ ۴. کد پیوستن ═══")

r = post("signup.php", {"action": "checkCode", "code": "NOPE99"})
check(err_is(r, "bad_code"), "کد ناموجود رد شد", str(r[1]))

r = post("signup.php", {"action": "register", "phone": TCH, **base_user,
                        "mode": "code", "code": "NOPE99"})
check(err_is(r, "bad_code"), "ثبت‌نام با کد غلط رد شد", str(r[1]))

print("\n═══ ۵. درخواست پیوستن ═══")

if iid:
    r = post("signup.php", {"action": "register", "phone": TCH, **base_user,
                            "firstNameFa": "رضا", "lastNameFa": "کریمی",
                            "mode": "request", "instituteId": iid,
                            "role": "teacher", "message": "مدرس زبان انگلیسی"})
    check(r[1].get("outcome") == "pending", "درخواست مدرس در صف نشست", str(r[1]))
    check(r[1].get("role") == "teacher", "نقش خواسته‌شده مدرس است", str(r[1]))

    r = post("signup.php", {"action": "register", "phone": STU, **base_user,
                            "firstNameFa": "مینا", "lastNameFa": "احمدی",
                            "mode": "request", "instituteId": iid, "role": "student"})
    check(r[1].get("outcome") == "pending", "درخواست زبان‌آموز در صف نشست", str(r[1]))
else:
    bad("شناسهٔ آموزشگاه پیدا نشد — بقیهٔ بخش ۵ رد شد")

print("\n═══ ۶. ورود با همان اطلاعات ═══")

r = post("login.php", {"username": MGR, "password": "abcd1234"})
check(r[1].get("ok") is True, "مدیر با شماره و رمز وارد شد", str(r[1]))
check(r[1].get("user", {}).get("role") == "manager", "نقش مدیر درست تشخیص داده شد", str(r[1]))

r = post("login.php", {"username": MGR, "password": "ghalat123"})
check(err_is(r, "bad_credentials"), "رمز اشتباه رد شد", str(r[1]))

print("\n" + "─" * 58)
print(f"موفق: {_pass}    ناموفق: {_fail}")
sys.exit(0 if _fail == 0 else 1)
