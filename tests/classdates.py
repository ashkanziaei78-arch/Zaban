#!/usr/bin/env python3
"""
فاز ج: تاریخ‌های کلاس، تمدید مهلت تکلیف، و شمار غیبت.

سه چیز که تا امروز جایی برای نشستن نداشتند و هرکدام در ذهن مدیر
جبران می‌شدند. آزمون بیشتر روی *ترتیب* تاریخ‌هاست تا وجودشان: تاریخِ
بی‌ترتیب خطای تایپی است و اگر همین‌جا گرفته نشود، بعداً به شکل
«۰ جلسه مانده» یا کارنامهٔ وارونه بیرون می‌زند.

پیش‌نیاز:
  php -S 127.0.0.1:8099 -t panel

اجرا:  python tests/classdates.py
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
P = {"password": "abcd1234", "lastNameFa": "ج"}

print("\n═══ ۱. آموزشگاه، مدرس و دو زبان‌آموز ═══")
mgr = sess()
r = post(mgr, "signup.php", {"action": "register", "phone": "09" + sfx + "80", **P,
                             "firstNameFa": "مدیر", "nationalId": nid(sfx),
                             "mode": "manager", "instituteName": "آموزشگاه فاز ج " + sfx})
if r[1].get("error") == "rate_limited":
    print("\n  سقف نرخ ثبت‌نام پر است. پیش از اجرا:  DELETE FROM rate_limit;")
    sys.exit(2)
check(r[1].get("outcome") == "manager", "آموزشگاه ساخته شد", str(r[1]))

people = {}
for role, tag, names in [("teacher", "81", "مدرس"), ("student", "82", "زبان‌آموزیک"),
                         ("student", "83", "زبان‌آموزدو")]:
    code = post(mgr, "institute.php",
                {"action": "joinCodeSet", "role": role, "active": True})[1].get("code")
    s = sess()
    post(s, "signup.php", {"action": "register", "phone": "09" + sfx + tag, **P,
                           "firstNameFa": names, "mode": "code", "code": code})
    people[names] = s   # کلید: نام کوچک

members = post(mgr, "institute.php", {"action": "members"})[1].get("members", [])
uid = {m["name"]: m["userId"] for m in members}
check(len(members) == 4, "چهار عضو", f"{len(members)}")

print("\n═══ ۲. تاریخ‌های کلاس ═══")
GOOD = {"startsOn": "2026-09-01", "endsOn": "2026-12-20",
        "midtermOn": "2026-10-20", "finalOn": "2026-12-18"}
r = post(mgr, "classes.php", {"action": "create", "name": "کلاس ب۱",
                              "teacherId": uid["مدرس ج"], "cap": 10,
                              "totalSessions": 20, **GOOD})
cid = r[1].get("id")
check(bool(cid), "کلاس با تاریخ‌ها ساخته شد", str(r[1]))

for label, bad_dates, why in [
        ("پایان پیش از شروع", {"startsOn": "2026-12-01", "endsOn": "2026-09-01"}, "endsOn"),
        ("میان‌ترم پیش از شروع", {"startsOn": "2026-09-01", "midtermOn": "2026-08-01"}, "midterm"),
        ("پایان‌ترم بعد از پایان کلاس", {"endsOn": "2026-12-20", "finalOn": "2027-01-05"}, "final"),
        ("پایان‌ترم پیش از میان‌ترم", {"midtermOn": "2026-11-01", "finalOn": "2026-10-01"}, "order")]:
    r = post(mgr, "classes.php", {"action": "create", "name": "x", **bad_dates})
    check(r[1].get("error") == "bad_dates", f"رد شد: {label}", str(r[1]))

print("\n═══ ۳. ویرایش جزئی تاریخ‌ها را پاک نمی‌کند ═══")
post(mgr, "classes.php", {"action": "update", "id": cid, "cap": 12})
r = post(mgr, "bootstrap.php", {})
cls = next((c for c in r[1].get("classes", []) if c["id"] == cid), None)
check(cls is not None, "کلاس در bootstrap هست")
if cls:
    check(cls.get("startsOn") == GOOD["startsOn"], "تاریخ شروع مانده", str(cls.get("startsOn")))
    check(cls.get("finalOn") == GOOD["finalOn"], "تاریخ پایان‌ترم مانده", str(cls.get("finalOn")))
    check(cls.get("cap") == 12, "ظرفیت عوض شد", str(cls.get("cap")))
    check(cls.get("remaining") == 20, "بیست جلسه مانده", str(cls.get("remaining")))
    check(cls.get("expired") is False, "کلاس منقضی نیست", str(cls.get("expired")))

# پاک‌کردن عمدی: کلیدِ خالی
post(mgr, "classes.php", {"action": "update", "id": cid, "midtermOn": ""})
r = post(mgr, "bootstrap.php", {})
cls = next((c for c in r[1].get("classes", []) if c["id"] == cid), None)
check(cls.get("midtermOn") is None, "کلید خالی، تاریخ را پاک می‌کند", str(cls.get("midtermOn")))
check(cls.get("finalOn") == GOOD["finalOn"], "و بقیه دست‌نخورده", str(cls.get("finalOn")))

print("\n═══ ۴. کلاس منقضی ═══")
r = post(mgr, "classes.php", {"action": "create", "name": "کلاس تمام‌شده",
                              "startsOn": "2025-01-01", "endsOn": "2025-06-01"})
old = r[1].get("id")
r = post(mgr, "bootstrap.php", {})
oc = next((c for c in r[1].get("classes", []) if c["id"] == old), None)
check(oc and oc.get("expired") is True, "کلاسِ گذشته منقضی علامت خورد", str(oc and oc.get("expired")))

print("\n═══ ۵. تمدید مهلت تکلیف ═══")
for st in ("زبان‌آموزیک ج", "زبان‌آموزدو ج"):
    post(mgr, "classes.php", {"action": "enrol", "classId": cid, "studentId": uid[st]})

tch = people["مدرس"]
r = post(tch, "assignments.php", {"action": "create", "classId": cid,
                                  "title": "مقالهٔ هفتهٔ اول",
                                  "dueDate": "2026-08-20", "dueTime": "23:59"})
aid = r[1].get("id")
check(bool(aid), "تکلیف با مهلتِ گذشته ساخته شد", str(r[1]))

# مهلت گذشته → تحویل دیر است
s1 = people["زبان‌آموزیک"]
r = post(s1, "assignments.php", {"action": "submit", "id": aid, "text": "پاسخ من"})
check(r[1].get("late") is True, "تحویل بعد از مهلت، دیر ثبت شد", str(r[1]))

r = post(tch, "assignments.php", {"action": "extend", "id": aid, "toDate": "2026-08-19"})
check(r[1].get("error") == "not_later", "تمدید به عقب رد شد", str(r[1]))

r = post(tch, "assignments.php", {"action": "extend", "id": aid,
                                  "toDate": "2027-01-10", "note": "به‌خاطر قطعی سامانه"})
check(r[1].get("ok") is True, "تمدید ثبت شد", str(r[1]))
check(r[1].get("noLongerLate") == 1, "تحویلِ قبلی دیگر دیر نیست", str(r[1]))

# حالا تحویل تازه در مهلت تمدیدشده
s2 = people["زبان‌آموزدو"]
r = post(s2, "assignments.php", {"action": "submit", "id": aid, "text": "پاسخ دو"})
check(r[1].get("late") is False, "تحویل در مهلت تمدیدشده، دیر نیست", str(r[1]))

print("\n═══ ۶. کی داده، کی نداده ═══")
r = post(tch, "assignments.php", {"action": "status", "id": aid})
d = r[1]
check(d.get("submitted") == 2, "دو نفر داده‌اند", str(d.get("submitted")))
check(d.get("missing") == 0, "کسی نمانده", str(d.get("missing")))
check(d.get("assignment", {}).get("extendNote") == "به‌خاطر قطعی سامانه",
      "دلیل تمدید ثبت شده", str(d.get("assignment")))
check(d.get("assignment", {}).get("dueAt", "").startswith("2026-08-20"),
      "مهلت اصلی دست‌نخورده مانده", str(d.get("assignment")))
check(not any(s["late"] for s in d.get("students", [])), "هیچ‌کس دیرکرد ندارد",
      str([(s["name"], s["late"]) for s in d.get("students", [])]))

# یک زبان‌آموز تازه که نداده
code = post(mgr, "institute.php",
            {"action": "joinCodeSet", "role": "student", "active": True})[1].get("code")
s3 = sess()
post(s3, "signup.php", {"action": "register", "phone": "09" + sfx + "84", **P,
                        "firstNameFa": "زبان‌آموزسه", "mode": "code", "code": code})
m3 = [m for m in post(mgr, "institute.php", {"action": "members"})[1]["members"]
      if m["name"] == "زبان‌آموزسه ج"][0]
post(mgr, "classes.php", {"action": "enrol", "classId": cid, "studentId": m3["userId"]})

r = post(tch, "assignments.php", {"action": "status", "id": aid})
check(r[1].get("missing") == 1, "زبان‌آموز تازه در «نداده‌ها»", str(r[1].get("missing")))
nots = [s["name"] for s in r[1]["students"] if not s["submitted"]]
check(nots == ["زبان‌آموزسه ج"], "و نامش درست است", str(nots))

print("\n═══ ۷. زبان‌آموز نمی‌تواند تمدید کند یا فهرست را ببیند ═══")
r = post(s1, "assignments.php", {"action": "extend", "id": aid, "toDate": "2027-06-01"})
check(r[0] in (401, 403), "زبان‌آموز تمدید نمی‌کند", f"{r[0]} {r[1]}")


print("\n═══ ۸. شمار غیبت کنار هر نام ═══")
# ترم لازم است تا کلاس منتشر شود و جلسه ساخته شود
post(mgr, "institute.php", {"action": "setup", "termName": "ترم آزمون",
                            "termStart": "2026-08-01", "weeks": 8})
r = post(mgr, "classes.php", {"action": "publish", "id": cid, "from": "2026-08-01"})
check(r[1].get("ok") is True, "کلاس منتشر شد و جلسه‌ها ساخته شدند", str(r[1])[:160])

r = post(tch, "sessions.php", {"action": "list", "classId": cid})
ses = r[1].get("sessions", [])
check(len(ses) >= 3, "دست‌کم سه جلسه هست", f"{len(ses)} جلسه")

if len(ses) >= 3:
    absent_uid = uid["زبان‌آموزیک ج"]
    present_uid = uid["زبان‌آموزدو ج"]
    # زبان‌آموز یک در دو جلسهٔ اول غایب، در سومی حاضر
    for i, st in enumerate(["absent", "absent"]):
        post(tch, "attendance.php", {"action": "save", "id": ses[i]["id"], "marks": [
            {"id": absent_uid, "status": st},
            {"id": present_uid, "status": "late" if i == 0 else "present"}]})

    r = post(tch, "attendance.php", {"action": "get", "id": ses[2]["id"]})
    roster = {x["name"]: x for x in r[1].get("roster", [])}
    a = roster.get("زبان‌آموزیک ج", {})
    b = roster.get("زبان‌آموزدو ج", {})
    check(a.get("absences") == 2, "دو غیبت شمرده شد", str(a))
    check(b.get("absences") == 0, "آن یکی غیبت ندارد", str(b))
    check(b.get("lates") == 1, "یک تأخیر شمرده شد", str(b))
    check(a.get("marked") == 2, "دو جلسه برایش ثبت شده", str(a))

    # جلسهٔ جاری در شمارش نمی‌آید — وگرنه پیش‌فرضِ «حاضر» عدد را تغییر می‌داد
    post(tch, "attendance.php", {"action": "save", "id": ses[2]["id"],
                                 "marks": [{"id": absent_uid, "status": "absent"}]})
    r = post(tch, "attendance.php", {"action": "get", "id": ses[2]["id"]})
    a2 = {x["name"]: x for x in r[1].get("roster", [])}.get("زبان‌آموزیک ج", {})
    check(a2.get("absences") == 2, "غیبتِ همین جلسه در شمار خودش نمی‌آید", str(a2))

print("\n" + "─" * 58)
print(f"موفق: {_pass}    ناموفق: {_fail}")
sys.exit(0 if _fail == 0 else 1)
