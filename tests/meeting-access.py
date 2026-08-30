#!/usr/bin/env python3
"""
کلاس آنلاین: چه کسی می‌تواند بسازد و شروع کند.

دو باگ واقعی که این آزمون نگهبانشان است:

۱) مدیرِ هر آموزشگاهِ تازه نمی‌توانست کلاس جیتسی بسازد.

   کد از دو جا می‌گفت مدیر مجوز را «از ابتدا دارد»، ولی تنها چیزی که
   آن را پیاده می‌کرد یک UPDATE یک‌باره کنار ALTER بود. مدیرهای همان
   روز مجوز گرفتند و بس؛ هر مدیری که بعد ساخته شد پیش‌فرضِ ستون را
   گرفت که صفر است. پیام خطا هم او را به «مدیر آموزشگاه» ارجاع می‌داد
   — یعنی به خودش.

۲) کلید خاموشیِ سوپرادمین جلوی مدیر را نمی‌گرفت.

   super.php صریح نوشته «خاموش‌کردن این، فوراً جلوی ساخت جلسهٔ تازه را
   می‌گیرد، حتی برای مدیر». ولی sessions.php هرکسی را که محدوده‌اش
   institute بود کاملاً معاف می‌کرد و دیگر به jitsi_enabled نگاه
   نمی‌کرد.

هر دو از یک ریشه بودند: ساخت و شروع، دو جا و دو جور تصمیم می‌گرفتند.
حالا هر دو از jitsi_allowed() رد می‌شوند و این آزمون هر دو مسیر را
با هم می‌سنجد — چون اگر فقط یکی را بسنجد، همان واگرایی دوباره ممکن
است.

پیش‌نیاز:
  php -S 127.0.0.1:8099 -t panel
  و دسترسی به همان دیتابیس برای خاموش‌کردن کلید آموزشگاه:
  TALKORA_TEST_DSN_DB

اجرا:  python tests/meeting-access.py
"""
import http.cookiejar
import json
import os
import subprocess
import sys
import time
import urllib.error
import urllib.request

BASE = os.environ.get("TALKORA_TEST_URL", "http://127.0.0.1:8099/api")
DSN = os.environ.get("TALKORA_TEST_DSN_DB",
                     "mysql:host=127.0.0.1;port=3399;dbname=talkora_test;charset=utf8mb4")
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
        print(f"      {d[:260]}")


def check(c, w, d=""):
    ok(w) if c else bad(w, d)


def sql(stmt):
    """یک دستور روی همان دیتابیس، از راه PHP — تا وابستگی تازه‌ای اضافه نشود."""
    # بدون تگ <?php — در php -r کد از همان اول PHP است
    php = ('$p=new PDO(getenv("DSN"),"root","");'
           '$p->exec(getenv("STMT"));')
    env = dict(os.environ, DSN=DSN, STMT=stmt)
    r = subprocess.run([os.environ.get("PHP_BIN", "php"), "-r", php],
                       capture_output=True, text=True, env=env)
    if r.returncode != 0:
        print("      SQL ناموفق:", (r.stderr or r.stdout)[:200])
    return r.returncode == 0


def nid(seed):
    n = seed.rjust(9, "0")[-9:]
    t = sum(int(n[i]) * (10 - i) for i in range(9))
    r = t % 11
    return n + str(r if r < 2 else 11 - r)


# سقف نرخِ ثبت‌نام برای این IP از اجراهای قبلیِ همین مجموعه پر
# می‌ماند. این آزمون پنج‌شش حساب می‌سازد و اگر پاک نشود، وسط راه به
# rate_limited می‌خورد و همهٔ گزاره‌های بعدی بی‌معنا می‌شوند.
sql("DELETE FROM rate_limit")

sfx = str(int(time.time()))[-7:]
P = {"password": "abcd1234", "lastNameFa": "آزمون"}
CLS = {"mode": "online", "provider": "jitsi", "dayPattern": "فرد",
       "startTime": "18:00", "durationMin": 90, "capacity": 12, "totalSessions": 4}

print("\n═══ ۱. آموزشگاه تازه با مدیر و یک مدرس ═══")
mgr = sess()
r = post(mgr, "signup.php", {"action": "register", "phone": "09" + sfx + "10", **P,
                             "firstNameFa": "مدیر", "nationalId": nid(sfx),
                             "mode": "manager", "instituteName": "آموزشگاه میت " + sfx})
check(r[1].get("outcome") == "manager", "آموزشگاه ساخته شد", str(r[1]))

r = post(mgr, "institute.php", {"action": "joinCodeSet", "role": "teacher", "active": True})
tcode = r[1].get("code")
tch = sess()
post(tch, "signup.php", {"action": "register", "phone": "09" + sfx + "21", **P,
                         "firstNameFa": "مدرس", "mode": "code", "code": tcode})

r = post(mgr, "institute.php", {"action": "members"})
members = r[1].get("members", [])
mrow = [m for m in members if m["role"] == "manager"]
trow = [m for m in members if m["role"] == "teacher"]
check(len(mrow) == 1 and len(trow) == 1, "مدیر و مدرس هر دو هستند", str(members))
inst_id = r[1].get("instituteId") or ""

print("\n═══ ۲. مدیرِ تازه، بی هیچ دست‌کاری، کلاس جیتسی می‌سازد ═══")
# این همان چیزی است که پیش از رفع باگ شکست می‌خورد
r = post(mgr, "classes.php", {"action": "create", "name": "کلاس آنلاین", **CLS})
cid = r[1].get("id", "")
check(r[1].get("ok") is True, "مدیر کلاس جیتسی می‌سازد", str(r[1]))
check(bool(cid), "شناسهٔ کلاس برگشت", str(r[1]))

print("\n═══ ۳. لینک را خود سرور می‌سازد، مدیر تایپ نمی‌کند ═══")
r = post(mgr, "classes.php", {"action": "roster", "id": cid})
# roster لینک نمی‌دهد؛ از فهرست کلاس‌ها می‌گیریم
r = post(mgr, "bootstrap.php", {})
check(r[1].get("ok") is True, "bootstrap سالم است", str(r[1])[:120])

print("\n═══ ۴. مدرسِ بی‌مجوز نمی‌تواند ═══")
r = post(tch, "classes.php", {"action": "create", "name": "کلاس مدرس", **CLS})
check(r[1].get("error") == "meeting_not_allowed", "مدرس بی‌مجوز رد شد", str(r[1]))
check("مدیر آموزشگاه" in r[1].get("message", ""),
      "پیام مدرس را به مدیر ارجاع می‌دهد", r[1].get("message", ""))

print("\n═══ ۵. مدیر مجوز را به مدرس می‌دهد ═══")
r = post(mgr, "institute.php", {"action": "setMeetingAccess", "id": trow[0]["id"], "on": True})
check(r[1].get("ok") is True, "مجوز داده شد", str(r[1]))
r = post(tch, "classes.php", {"action": "create", "name": "کلاس مدرس", **CLS})
check(r[1].get("ok") is True, "حالا مدرس هم می‌سازد", str(r[1]))

print("\n═══ ۶. کلید خاموشیِ سوپرادمین بر همه غالب است ═══")
if not inst_id:
    # institute.members شناسه نمی‌دهد؛ از روی نام پیدایش می‌کنیم
    php = ('$p=new PDO(getenv("DSN"),"root","");'
           '$s=$p->prepare("SELECT id FROM institute WHERE name=?");'
           '$s->execute([getenv("NM")]); echo (string)$s->fetchColumn();')
    env = dict(os.environ, DSN=DSN, NM="آموزشگاه میت " + sfx)
    inst_id = subprocess.run([os.environ.get("PHP_BIN", "php"), "-r", php],
                             capture_output=True, text=True, env=env).stdout.strip()
check(len(inst_id) == 32, "شناسهٔ آموزشگاه پیدا شد", inst_id)

sql(f"UPDATE institute SET jitsi_enabled=0 WHERE id='{inst_id}'")
r = post(mgr, "classes.php", {"action": "create", "name": "کلاس ۳", **CLS})
check(r[1].get("error") == "meeting_not_allowed",
      "با کلید خاموش، مدیر هم نمی‌سازد", str(r[1]))
check("آموزشگاه شما فعال نیست" in r[1].get("message", ""),
      "پیام می‌گوید مشکل از سطح آموزشگاه است، نه از مجوز شخصی",
      r[1].get("message", ""))

r = post(tch, "classes.php", {"action": "create", "name": "کلاس ۴", **CLS})
check(r[1].get("error") == "meeting_not_allowed",
      "مدرسِ بامجوز هم نمی‌سازد", str(r[1]))

print("\n═══ ۷. با روشن‌شدن دوباره، همه‌چیز برمی‌گردد ═══")
sql(f"UPDATE institute SET jitsi_enabled=1 WHERE id='{inst_id}'")
r = post(mgr, "classes.php", {"action": "create", "name": "کلاس ۵", **CLS})
check(r[1].get("ok") is True, "مدیر دوباره می‌سازد", str(r[1]))

print("\n═══ ۸. شروع جلسه هم همان قاعده را دارد ═══")
# کلاس را منتشر می‌کنیم تا جلسه بسازد
post(mgr, "institute.php", {"action": "setup", "termName": "ترم میت",
                            "termStart": "2026-08-01", "weeks": 12})
post(mgr, "classes.php", {"action": "update", "id": cid,
                          "teacherId": trow[0]["userId"]})
r = post(mgr, "classes.php", {"action": "publish", "id": cid})
check(r[1].get("sessions", 0) > 0, "کلاس منتشر شد و جلسه ساخت", str(r[1]))

r = post(mgr, "sessions.php", {"action": "list", "classId": cid})
ss = r[1].get("sessions", [])
check(len(ss) > 0, "جلسه‌ها آمدند", str(r[1])[:150])
sid = ss[0]["id"] if ss else ""
check(ss and "meet.jit.si" in (ss[0].get("joinUrl") or ""),
      "لینک جیتسی خودکار روی جلسه نشسته", str(ss[0] if ss else ""))

r = post(mgr, "sessions.php", {"action": "start", "id": sid})
check(r[1].get("ok") is True, "مدیر جلسه را شروع می‌کند", str(r[1]))

# و همان کلید، شروع را هم می‌بندد — همان باگی که sessions.php داشت
sql(f"UPDATE class_session SET status='scheduled' WHERE id='{sid}'")
sql(f"UPDATE institute SET jitsi_enabled=0 WHERE id='{inst_id}'")
r = post(mgr, "sessions.php", {"action": "start", "id": sid})
check(r[1].get("error") == "meeting_not_allowed",
      "با کلید خاموش، شروع جلسه هم بسته است — نه فقط ساخت کلاس",
      str(r[1]))

sql(f"UPDATE institute SET jitsi_enabled=1 WHERE id='{inst_id}'")

print("\n" + "─" * 58)
print(f"موفق: {_pass}    ناموفق: {_fail}")
sys.exit(1 if _fail else 0)
