#!/usr/bin/env python3
"""
اعلان: چه کسی به چه کسانی می‌رسد.

نکتهٔ اصلی این آزمون امنیت است، نه ویژگی. اعلان تنها جایی است که یک
کاربر مستقیماً به صندوق کاربر دیگری می‌نویسد، و اگر محدوده درست
اعمال نشود، مدرس به کل آموزشگاه پیام می‌دهد یا به کلاس همکارش.

پیش‌نیاز:
  php -S 127.0.0.1:8099 -t panel

اجرا:  python tests/notify.py
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


def sess():
    return urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))


def post(op, ep, body):
    data = json.dumps(body, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(f"{BASE}/{ep}", data=data,
                                 headers={"Content-Type": "application/json"}, method="POST")
    try:
        with op.open(req, timeout=20) as r:
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
        print(f"      {d[:240]}")


def check(c, w, d=""):
    ok(w) if c else bad(w, d)


def nid(seed):
    n = seed.rjust(9, "0")[-9:]
    t = sum(int(n[i]) * (10 - i) for i in range(9))
    r = t % 11
    return n + str(r if r < 2 else 11 - r)


sfx = str(int(time.time()))[-7:]
P = {"password": "abcd1234", "lastNameFa": "آزمون"}

print("\n═══ ۱. یک آموزشگاه با مدیر، دو مدرس و سه زبان‌آموز ═══")
mgr = sess()
r = post(mgr, "signup.php", {"action": "register", "phone": "09" + sfx + "10", **P,
                             "firstNameFa": "مدیر", "nationalId": nid(sfx),
                             "mode": "manager", "instituteName": "آموزشگاه اعلان " + sfx})
check(r[1].get("outcome") == "manager", "آموزشگاه ساخته شد", str(r[1]))

r = post(mgr, "institute.php", {"action": "joinCodeSet", "role": "teacher", "active": True})
tcode = r[1].get("code")
people = {}
for who, phone_sfx, fn in [("t1", "21", "مدرس‌یک"), ("t2", "22", "مدرس‌دو")]:
    s = sess()
    post(s, "signup.php", {"action": "register", "phone": "09" + sfx + phone_sfx, **P,
                           "firstNameFa": fn, "mode": "code", "code": tcode})
    people[who] = s

r = post(mgr, "institute.php", {"action": "joinCodeSet", "role": "student", "active": True})
scode = r[1].get("code")
for who, phone_sfx, fn in [("s1", "31", "زبان‌آموزیک"),
                           ("s2", "32", "زبان‌آموزدو"),
                           ("s3", "33", "زبان‌آموزسه")]:
    s = sess()
    post(s, "signup.php", {"action": "register", "phone": "09" + sfx + phone_sfx, **P,
                           "firstNameFa": fn, "mode": "code", "code": scode})
    people[who] = s

r = post(mgr, "institute.php", {"action": "members"})
members = r[1].get("members", [])
check(len(members) == 6, "شش عضو ساخته شد", f"{len(members)} عضو")
uid = {m["name"]: m["userId"] for m in members}

print("\n═══ ۲. مخاطب‌های مدیر ═══")
r = post(mgr, "notify.php", {"action": "audiences"})
auds = {a["key"]: a for a in r[1].get("audiences", [])}
check("institute" in auds, "مدیر «همهٔ آموزشگاه» را دارد", str(list(auds)))
# پنج، نه شش: فرستنده خودش در فهرست نیست.
#
# آموزشگاه شش عضو دارد و یکی‌شان همین مدیری است که دارد می‌فرستد.
# مسیر کلاس از اول همین کار را می‌کرد (مدرسِ کلاس را وقتی خودش
# فرستاده بود کنار می‌گذاشت) ولی «همهٔ اعضا» نه — پس مدیر زنگ خودش
# را قرمز می‌دید و باید اعلان خودش را «خوانده» می‌کرد.
check(auds.get("institute", {}).get("count") == 5,
      "شمار همهٔ اعضا پنج است — مدیر خودش را نمی‌شمارد",
      str(auds.get("institute")))
check(auds.get("role:student", {}).get("count") == 3, "سه زبان‌آموز", str(auds.get("role:student")))
check(auds.get("role:teacher", {}).get("count") == 2, "دو مدرس", str(auds.get("role:teacher")))
check(r[1].get("canSms") is False, "پیامک وعده داده نمی‌شود", str(r[1].get("canSms")))

print("\n═══ ۳. مدیر به همهٔ زبان‌آموزان می‌فرستد ═══")
r = post(mgr, "notify.php", {"action": "send", "audience": "role:student",
                             "title": "کلاس‌های هفتهٔ آینده",
                             "body": "به‌خاطر تعطیلی، کلاس‌های شنبه به یکشنبه منتقل شد.",
                             "kind": "warn"})
check(r[1].get("recipients") == 3, "به سه نفر رسید", str(r[1]))
check(r[1].get("audience") == "همهٔ زبان‌آموزان", "شرح مخاطب درست است", str(r[1]))

r = post(people["s1"], "notify.php", {"action": "inbox"})
inbox = r[1].get("items", [])
check(r[1].get("unread") == 1, "زبان‌آموز یک نخوانده دارد", str(r[1].get("unread")))
check(inbox and inbox[0]["title"] == "کلاس‌های هفتهٔ آینده", "عنوان رسیده", str(inbox[:1]))
check(inbox and inbox[0]["kind"] == "warn", "لحن اعلان حفظ شد", str(inbox[:1]))
check(inbox and "مدیر آزمون" in inbox[0]["from"], "نام فرستنده درست است", str(inbox[:1]))

r = post(people["t1"], "notify.php", {"action": "inbox"})
check(r[1].get("unread") == 0, "مدرس اعلانِ زبان‌آموزان را نگرفت", str(r[1]))

print("\n═══ ۴. خوانده‌شدن ═══")
tid = inbox[0]["id"]
r = post(people["s1"], "notify.php", {"action": "read", "ids": [tid]})
check(r[1].get("changed") == 1, "یک اعلان خوانده شد", str(r[1]))
r = post(people["s1"], "notify.php", {"action": "inbox"})
check(r[1].get("unread") == 0, "شمار نخوانده صفر شد", str(r[1]))

# کسی نمی‌تواند اعلان دیگری را خوانده اعلام کند
r = post(people["s2"], "notify.php", {"action": "read", "ids": [tid]})
check(r[1].get("changed") == 0, "اعلان دیگری را نمی‌شود خواند", str(r[1]))
r = post(people["s2"], "notify.php", {"action": "inbox"})
check(r[1].get("unread") == 1, "و نخواندهٔ خودش دست‌نخورده ماند", str(r[1]))

print("\n═══ ۵. دو کلاس، هرکدام یک مدرس ═══")
# مدرس‌یک کلاس الف را دارد با دو زبان‌آموز؛ مدرس‌دو کلاس ب را با یکی
cls = {}
for key, cname, teacher, studs in [
        ("a", "کلاس الف", "مدرس‌یک آزمون", ["زبان‌آموزیک آزمون", "زبان‌آموزدو آزمون"]),
        ("b", "کلاس ب",  "مدرس‌دو آزمون", ["زبان‌آموزسه آزمون"])]:
    r = post(mgr, "classes.php", {"action": "create", "name": cname,
                                  "teacherId": uid[teacher], "cap": 10})
    cls[key] = r[1].get("id")
    for st in studs:
        post(mgr, "classes.php", {"action": "enrol",
                                  "classId": cls[key], "studentId": uid[st]})
check(bool(cls.get("a")) and bool(cls.get("b")), "دو کلاس ساخته شد", str(cls))

print("\n═══ ۶. مدرس فقط کلاس‌های خودش ═══")
r = post(people["t1"], "notify.php", {"action": "audiences"})
keys = [a["key"] for a in r[1].get("audiences", [])]
check("institute" not in keys, "مدرس «همهٔ آموزشگاه» را ندارد", str(keys))
check("role:student" not in keys, "مدرس «همهٔ زبان‌آموزان» را ندارد", str(keys))
check("class:" + str(cls["a"]) in keys, "کلاس خودش را می‌بیند", str(keys))
check("class:" + str(cls["b"]) not in keys, "کلاس همکارش را نمی‌بیند", str(keys))

r = post(people["t1"], "notify.php", {"action": "send", "audience": "class:" + str(cls["a"]),
                                      "title": "جلسهٔ جبرانی",
                                      "body": "جلسهٔ این هفته پنجشنبه ساعت ۱۰ برگزار می‌شود."})
check(r[1].get("recipients") == 2, "به دو زبان‌آموز کلاس خودش رسید", str(r[1]))

r = post(people["t1"], "notify.php", {"action": "send", "audience": "class:" + str(cls["b"]),
                                      "title": "نفوذ", "body": "به کلاس همکار"})
check(r[0] == 404, "به کلاس همکارش نمی‌فرستد", str(r[0]) + " " + str(r[1]))

r = post(people["s3"], "notify.php", {"action": "inbox"})
check(not any(i["title"] == "نفوذ" for i in r[1].get("items", [])),
      "زبان‌آموز کلاس ب چیزی نگرفت", str(r[1].get("items"))[:160])

r = post(people["s1"], "notify.php", {"action": "inbox", "unread": True})
titles = [i["title"] for i in r[1].get("items", [])]
check("جلسهٔ جبرانی" in titles, "زبان‌آموز کلاس الف اعلان کلاس را گرفت", str(titles))

r = post(people["t1"], "notify.php", {"action": "inbox"})
check(not any(i["title"] == "جلسهٔ جبرانی" for i in r[1].get("items", [])),
      "فرستنده نسخهٔ خودش را نمی‌گیرد", str(r[1].get("items"))[:160])

r = post(people["t1"], "notify.php", {"action": "send", "audience": "role:student",
                                      "title": "سلام", "body": "به همه"})
check(r[0] == 403, "مدرس به همهٔ زبان‌آموزان نمی‌فرستد", f"{r[0]} {r[1]}")

r = post(people["t1"], "notify.php", {"action": "send", "audience": "institute",
                                      "title": "سلام", "body": "به همه"})
check(r[0] == 403, "مدرس به کل آموزشگاه نمی‌فرستد", f"{r[0]} {r[1]}")

print("\n═══ ۷. زبان‌آموز اصلاً نمی‌فرستد ═══")
r = post(people["s1"], "notify.php", {"action": "send", "audience": "institute",
                                      "title": "س", "body": "م"})
check(r[0] in (401, 403), "زبان‌آموز اعلان نمی‌فرستد", f"{r[0]} {r[1]}")
r = post(people["s1"], "notify.php", {"action": "audiences"})
check(r[0] in (401, 403), "زبان‌آموز فهرست مخاطبان را نمی‌بیند", f"{r[0]} {r[1]}")

print("\n═══ ۸. پیامک ادعا نمی‌شود ═══")
r = post(mgr, "notify.php", {"action": "send", "audience": "role:teacher",
                             "title": "فوری", "body": "جلسهٔ اضطراری",
                             "kind": "urgent", "sms": True})
check(r[1].get("error") == "sms_unavailable", "پیامک صادقانه رد شد", str(r[1]))

r = post(mgr, "notify.php", {"action": "send", "audience": "role:teacher",
                             "title": "فوری", "body": "جلسهٔ اضطراری",
                             "kind": "info", "sms": True})
check(r[1].get("error") == "sms_not_urgent", "پیامک غیرفوری رد شد", str(r[1]))

print("\n═══ ۹. سابقهٔ ارسال ═══")
r = post(mgr, "notify.php", {"action": "sent"})
sent = r[1].get("sent", [])
check(len(sent) == 2, "هر دو اعلان آموزشگاه در سابقهٔ مدیر", str(len(sent)) + " ردیف")
one = next((x for x in sent if x["title"] == "کلاس‌های هفتهٔ آینده"), None)
check(one is not None, "اعلان مدیر در سابقه هست")
if one:
    check(one["recipients"] == 3, "شمار گیرنده ثبت شد", str(one))
    check(one["readCount"] == 1, "شمار خوانده‌شده درست است", str(one))
    check(one["sms"] is False, "پیامکی ثبت نشد", str(one))

r = post(people["t1"], "notify.php", {"action": "sent"})
tsent = r[1].get("sent", [])
check(len(tsent) == 1 and tsent[0]["title"] == "جلسهٔ جبرانی",
      "مدرس فقط فرستادهٔ خودش را می‌بیند", str(tsent)[:200])

print("\n" + "─" * 58)
print(f"موفق: {_pass}    ناموفق: {_fail}")
sys.exit(0 if _fail == 0 else 1)
