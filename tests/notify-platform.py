#!/usr/bin/env python3
"""
اعلان پلتفرم: بخش‌بندی مخاطبان از پنل سوپرادمین.

اعلان سوپرادمین به آموزشگاه تعلق ندارد و از مرز چند-مستأجری رد
می‌شود — تنها جای سامانه که این اتفاق عمداً می‌افتد. پس دو چیز باید
ثابت شود: بخش‌بندی درست کار می‌کند، و اعلانِ پلتفرم در سابقهٔ هیچ
آموزشگاهی ظاهر نمی‌شود.

پیش‌نیاز:
  php -S 127.0.0.1:8099 -t panel       (پنل)
  php -S 127.0.0.1:8101 -t superadmin  (سوپرادمین، با همان config)

اجرا:  python tests/notify-platform.py
"""
import http.cookiejar
import json
import os
import sys
import time
import urllib.error
import urllib.request

PANEL = os.environ.get("TALKORA_TEST_URL", "http://127.0.0.1:8099/api")
SUPER = os.environ.get("TALKORA_SUPER_URL", "http://127.0.0.1:8101/api")
_pass = 0
_fail = 0


def sess():
    return urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))


def call(op, base, ep, body):
    data = json.dumps(body, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(f"{base}/{ep}", data=data,
                                 headers={"Content-Type": "application/json"}, method="POST")
    try:
        with op.open(req, timeout=25) as r:
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


# ثانیه به‌تنهایی کافی نیست: وقتی این آزمون بلافاصله بعد از بقیه
# اجرا می‌شود، همان ثانیه است و همان کد ملی ساخته می‌شود — آن‌وقت
# ثبت‌نام با nid_taken رد می‌شود و سه گزارهٔ بعدی بی‌ربط شکست می‌خورند.
# میکروثانیه آن را یکتا می‌کند بی‌آنکه شکل هفت‌رقمی عوض شود.
sfx = str(int(time.time() * 1000))[-7:]
P = {"password": "abcd1234", "lastNameFa": "پلتفرم"}

print("\n═══ ۱. دو آموزشگاه با مدیر و زبان‌آموز ═══")
actors = {}
for k, n2, iname in [("a", "50", "آموزشگاه الف "), ("b", "60", "آموزشگاه ب ")]:
    m = sess()
    r = call(m, PANEL, "signup.php", {"action": "register", "phone": "09" + sfx + n2, **P,
                                      "firstNameFa": "مدیر" + k,
                                      "nationalId": nid(sfx[:-1] + ("1" if k == "a" else "2")),
                                      "mode": "manager", "instituteName": iname + sfx})
    if r[1].get("error") == "rate_limited":
        # سقف ۱۵ ثبت‌نامِ مدیر در ساعت روی هر IP. آزمون‌هایی که پشت‌سرهم
        # اجرا می‌شوند به آن می‌خورند و بعد ده بررسی بی‌ربط قرمز می‌شود.
        # بهتر است همین‌جا با دلیل روشن بایستد.
        print("\n  سقف نرخ ثبت‌نام پر است. پیش از اجرا:")
        print("      DELETE FROM rate_limit;")
        sys.exit(2)
    check(r[1].get("outcome") == "manager", f"{iname}{sfx} ساخته شد", str(r[1]))
    actors["mgr_" + k] = m
    code = call(m, PANEL, "institute.php",
                {"action": "joinCodeSet", "role": "student", "active": True})[1].get("code")
    st = sess()
    call(st, PANEL, "signup.php", {"action": "register", "phone": "09" + sfx + str(int(n2) + 1), **P,
                                   "firstNameFa": "زبان‌آموز" + k, "mode": "code", "code": code})
    actors["stu_" + k] = st

print("\n═══ ۲. ورود سوپرادمین ═══")
sa = sess()
r = call(sa, SUPER, "super.php", {"action": "me"})
if not r[1].get("authenticated"):
    # پیش‌فرض‌ها همان چیزی است که tests/schema.php می‌سازد
    u = os.environ.get("TALKORA_SUPER_USER", "owner")
    pw = os.environ.get("TALKORA_SUPER_PASS", "admin12345")
    r = call(sa, SUPER, "super.php", {"action": "login", "username": u, "password": pw})
check(r[1].get("ok") is True, "سوپرادمین وارد شد", str(r[1]))
if not r[1].get("ok"):
    print("\n  سوپرادمینِ قابل‌ورود لازم است. php tests/schema.php یکی می‌سازد")
    print("  (owner / admin12345)، یا TALKORA_SUPER_USER و TALKORA_SUPER_PASS را بدهید.")
    sys.exit(1)

print("\n═══ ۳. بخش‌های مخاطب ═══")
r = call(sa, SUPER, "super.php", {"action": "notify.audiences"})
segs = {x["key"]: x for x in r[1].get("segments", [])}
insts = r[1].get("institutes", [])
check("all" in segs and "managers" in segs, "بخش‌ها آمدند", str(list(segs)))
check(segs.get("managers", {}).get("count", 0) >= 2, "دست‌کم دو مدیر شمرده شد", str(segs.get("managers")))
check(segs.get("students", {}).get("count", 0) >= 2, "دست‌کم دو زبان‌آموز شمرده شد", str(segs.get("students")))
check(segs["all"]["count"] >= segs["managers"]["count"] + segs["students"]["count"],
      "«همه» از مجموع نقش‌ها کمتر نیست", str(segs["all"]))

mine = [i for i in insts if sfx in i["name"]]
check(len(mine) == 2, "هر دو آموزشگاه تازه در فهرست‌اند", f"{len(mine)} مورد")

print("\n═══ ۴. اعلان به همهٔ مدیران ═══")
r = call(sa, SUPER, "super.php", {"action": "notify.send", "segment": "managers",
                                  "title": "به‌روزرسانی سامانه",
                                  "body": "جمعه شب سامانه نیم‌ساعت در دسترس نیست.",
                                  "kind": "warn"})
check(r[1].get("ok") is True, "فرستاده شد", str(r[1]))
check(r[1].get("audience") == "همهٔ مدیران آموزشگاه‌ها", "برچسب مخاطب درست است", str(r[1]))

for k in ("a", "b"):
    q = call(actors["mgr_" + k], PANEL, "notify.php", {"action": "inbox"})
    items = q[1].get("items", [])
    got = [i for i in items if i["title"] == "به‌روزرسانی سامانه"]
    check(bool(got), f"مدیر {k} گرفت", str([i["title"] for i in items]))
    if got:
        check(got[0]["from"] not in ("", None), f"نام فرستنده برای مدیر {k} خالی نیست", str(got[0]))

for k in ("a", "b"):
    q = call(actors["stu_" + k], PANEL, "notify.php", {"action": "inbox"})
    titles = [i["title"] for i in q[1].get("items", [])]
    check("به‌روزرسانی سامانه" not in titles, f"زبان‌آموز {k} نگرفت", str(titles))

print("\n═══ ۵. اعلان به یک آموزشگاه ═══")
target = mine[0]
r = call(sa, SUPER, "super.php", {"action": "notify.send",
                                  "segment": "inst:" + target["id"],
                                  "title": "قرارداد شما تمدید شد",
                                  "body": "تا پایان سال آینده فعال است.",
                                  "kind": "success"})
check(r[1].get("ok") is True, "به یک آموزشگاه فرستاده شد", str(r[1]))
check(r[1].get("recipients") == 2, "به هر دو عضو همان آموزشگاه رسید", str(r[1]))
check("آموزشگاه" in (r[1].get("audience") or ""), "برچسب نام آموزشگاه دارد", str(r[1]))

hit = "الف" in target["name"]
got = call(actors["mgr_a" if hit else "mgr_b"], PANEL, "notify.php", {"action": "inbox"})
miss = call(actors["mgr_b" if hit else "mgr_a"], PANEL, "notify.php", {"action": "inbox"})
check(any(i["title"] == "قرارداد شما تمدید شد" for i in got[1].get("items", [])),
      "آموزشگاه هدف گرفت")
check(not any(i["title"] == "قرارداد شما تمدید شد" for i in miss[1].get("items", [])),
      "آموزشگاه دیگر نگرفت")

print("\n═══ ۶. اعلان پلتفرم در سابقهٔ آموزشگاه نیست ═══")
# مدیر «سابقهٔ ارسال» خودش را می‌بیند؛ اعلان سوپرادمین نباید آنجا باشد
r = call(actors["mgr_a"], PANEL, "notify.php", {"action": "sent"})
titles = [x["title"] for x in r[1].get("sent", [])]
check("به‌روزرسانی سامانه" not in titles, "اعلان پلتفرم در سابقهٔ مدیر نیست", str(titles))
check("قرارداد شما تمدید شد" not in titles, "اعلانِ آموزشگاه‌محورِ پلتفرم هم نیست", str(titles))

print("\n═══ ۷. سابقهٔ سوپرادمین ═══")
r = call(sa, SUPER, "super.php", {"action": "notify.sent"})
sent = r[1].get("sent", [])
check(len(sent) >= 2, "هر دو اعلان در سابقه", f"{len(sent)} ردیف")
top = sent[0] if sent else {}
check(top.get("title") == "قرارداد شما تمدید شد", "تازه‌ترین اول است", str(top))
check(top.get("readCount") == 0, "هنوز کسی نخوانده", str(top))

print("\n═══ ۸. ورودی نامعتبر ═══")
r = call(sa, SUPER, "super.php", {"action": "notify.send", "segment": "nope",
                                  "title": "س", "body": "م"})
check(r[1].get("error") == "bad_audience", "بخش ناشناخته رد شد", str(r[1]))
r = call(sa, SUPER, "super.php", {"action": "notify.send", "segment": "managers",
                                  "title": "", "body": "م"})
check(r[1].get("error") == "invalid", "عنوان خالی رد شد", str(r[1]))
r = call(sa, SUPER, "super.php", {"action": "notify.send", "segment": "inst:" + "0" * 32,
                                  "title": "س", "body": "م"})
check(r[0] == 404, "آموزشگاه ناموجود رد شد", f"{r[0]} {r[1]}")

print("\n═══ ۹. بدون نشست، هیچ ═══")
r = call(sess(), SUPER, "super.php", {"action": "notify.send", "segment": "all",
                                      "title": "س", "body": "م"})
check(r[0] in (401, 403), "بدون ورود نمی‌شود فرستاد", f"{r[0]} {r[1]}")

print("\n" + "─" * 58)
print(f"موفق: {_pass}    ناموفق: {_fail}")
sys.exit(0 if _fail == 0 else 1)
