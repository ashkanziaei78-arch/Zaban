#!/usr/bin/env python3
"""
فهرست کاربران سوپرادمین.

پیش از این فقط users.search بود: دست‌کم دو حرف می‌خواست و تا کسی چیزی
تایپ نمی‌کرد هیچ نمی‌داد. برای «این کاربر را پیدا کن» کار می‌کرد، ولی
برای سوپرادمینی که می‌خواست بداند اصلاً چند نفر ثبت‌نام کرده‌اند — یا
چه کسانی هیچ آموزشگاهی ندارند — بی‌فایده بود: باید نام کسی را حدس
می‌زد تا چیزی ببیند.

users.list بی‌شرط شروع می‌کند و پالایه‌ها باریکش می‌کنند. چیزهایی که
اینجا سنجیده می‌شوند و به‌راحتی می‌شکنند:

  • صفحه‌بندی واقعی، نه LIMIT خالی. با ده هزار کاربر، «۵۰ تای اول»
    یعنی بقیه اصلاً وجود ندارند.
  • صفحهٔ خارج از بازه باید به آخرین صفحه بچسبد، نه خالی برگردد.
  • ارقام فارسی در جست‌وجوی شماره — کاربر شماره را از پنل کپی می‌کند
    و آنجا فارسی است.
  • پالایهٔ «بدون آموزشگاه»، که تنها راه دیدن کاربران سرگردان است.
  • جمعِ پالایه‌ها باید AND باشد، نه OR.

پیش‌نیاز:
  php -S 127.0.0.1:8099 -t panel        (برای ساخت کاربر آزمایشی)
  php -S 127.0.0.1:8101 -t superadmin

اجرا:  python tests/superadmin-users.py
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


def fa_digits(s):
    return s.translate(str.maketrans("0123456789", "۰۱۲۳۴۵۶۷۸۹"))


print("\n═══ ۱. ورود سوپرادمین ═══")
sa = sess()
# me عمداً برای مهمان هم ok=true می‌دهد و با authenticated:false
# جواب می‌دهد — پس شرط باید همان را ببیند، نه ok را. اگر ok را ببیند،
# ورود رد می‌شود و بقیهٔ آزمون روی نشستِ ناموجود اجرا می‌شود؛ آن‌وقت
# مقایسه‌های None==None سبز می‌شوند و آزمون دروغ می‌گوید.
r = call(sa, SUPER, "super.php", {"action": "me"})
if not r[1].get("authenticated"):
    u = os.environ.get("TALKORA_SUPER_USER", "owner")
    pw = os.environ.get("TALKORA_SUPER_PASS", "admin12345")
    r = call(sa, SUPER, "super.php", {"action": "login", "username": u, "password": pw})
if not r[1].get("ok"):
    print("  ✗ ورود سوپرادمین نشد — سرور ۸۱۰۱ بالاست؟")
    print("  (owner / admin12345)، یا TALKORA_SUPER_USER و TALKORA_SUPER_PASS را بدهید.")
    sys.exit(1)
ok("وارد شد")

print("\n═══ ۲. آمار بالای صفحه ═══")
r = call(sa, SUPER, "super.php", {"action": "users.stats"})
st = r[1].get("stats", {})
check(r[1].get("ok") is True, "users.stats پاسخ داد", str(r[1])[:150])
for k in ("total", "active", "suspended", "managers", "teachers", "students", "orphans"):
    check(isinstance(st.get(k), int), f"«{k}» عدد است", str(st))
check(st.get("total", 0) >= st.get("active", 0), "کل از فعال کمتر نیست", str(st))

print("\n═══ ۳. پیش‌فرض: بی هیچ پالایه، همه می‌آیند ═══")
r = call(sa, SUPER, "super.php", {"action": "users.list"})
d = r[1]
check(d.get("ok") is True, "users.list بدون هیچ ورودی کار می‌کند", str(d)[:150])
check(d.get("total") == st.get("total"),
      "شمار کل با users.stats می‌خواند", f"list={d.get('total')} stats={st.get('total')}")
check(len(d.get("users", [])) > 0, "دست‌کم یک کاربر برگشت", str(d)[:150])

u0 = (d.get("users") or [{}])[0]
for k in ("id", "phone", "name", "status", "createdAt", "memberships", "roles"):
    check(k in u0, f"ردیف «{k}» دارد", str(u0)[:200])
check(isinstance(u0.get("roles"), list), "roles آرایه است", str(u0.get("roles")))

print("\n═══ ۴. صفحه‌بندی ═══")
r = call(sa, SUPER, "super.php", {"action": "users.list", "limit": 10, "page": 1})
p1 = r[1]
check(p1.get("limit") == 10, "limit اعمال شد", str(p1.get("limit")))
check(len(p1.get("users", [])) <= 10, "بیش از limit برنمی‌گرداند", str(len(p1.get("users", []))))
expected_pages = -(-p1.get("total", 0) // 10)
check(p1.get("pages") == expected_pages,
      "شمار صفحه‌ها درست حساب شده", f"pages={p1.get('pages')} انتظار={expected_pages}")

if p1.get("pages", 1) > 1:
    r = call(sa, SUPER, "super.php", {"action": "users.list", "limit": 10, "page": 2})
    p2 = r[1]
    check(p2.get("page") == 2, "صفحهٔ دوم آمد", str(p2.get("page")))
    ids1 = {u["id"] for u in p1.get("users", [])}
    ids2 = {u["id"] for u in p2.get("users", [])}
    check(not (ids1 & ids2), "صفحه‌ها هم‌پوشانی ندارند",
          f"مشترک: {len(ids1 & ids2)}")

# صفحهٔ خیلی جلوتر از آخر: باید به آخرین صفحه بچسبد، نه خالی برگردد.
# اگر خالی برگردد، کاربر فکر می‌کند کاربری نیست.
r = call(sa, SUPER, "super.php", {"action": "users.list", "limit": 10, "page": 9999})
pl = r[1]
check(pl.get("page") == pl.get("pages"),
      "صفحهٔ خارج از بازه به آخرین صفحه می‌چسبد",
      f"page={pl.get('page')} pages={pl.get('pages')}")
check(len(pl.get("users", [])) > 0, "و خالی برنمی‌گردد", str(len(pl.get("users", []))))

print("\n═══ ۵. پالایهٔ نقش ═══")
for role, key in (("manager", "managers"), ("teacher", "teachers"), ("student", "students")):
    r = call(sa, SUPER, "super.php", {"action": "users.list", "role": role, "limit": 200})
    got = r[1].get("total")
    check(got == st.get(key), f"شمار «{role}» با آمار می‌خواند",
          f"list={got} stats={st.get(key)}")
    rows = r[1].get("users", [])
    check(all(role in (u.get("roles") or []) for u in rows),
          f"همهٔ ردیف‌ها واقعاً «{role}» دارند",
          str([u.get("roles") for u in rows[:4]]))

print("\n═══ ۶. کاربرانِ بدون آموزشگاه ═══")
r = call(sa, SUPER, "super.php", {"action": "users.list", "role": "none", "limit": 200})
orph = r[1]
check(orph.get("total") == st.get("orphans"),
      "شمار سرگردان‌ها با آمار می‌خواند",
      f"list={orph.get('total')} stats={st.get('orphans')}")
check(all((u.get("memberships") == 0) and not u.get("roles")
          for u in orph.get("users", [])),
      "هیچ‌کدام عضویت فعالی ندارند",
      str([(u.get("memberships"), u.get("roles")) for u in orph.get("users", [])[:4]]))

print("\n═══ ۷. جست‌وجو، با ارقام فارسی ═══")
sample = (d.get("users") or [{}])[0]
phone = sample.get("phone", "")
if len(phone) >= 6:
    part = phone[:6]
    r_en = call(sa, SUPER, "super.php", {"action": "users.list", "q": part, "limit": 200})
    r_fa = call(sa, SUPER, "super.php", {"action": "users.list",
                                         "q": fa_digits(part), "limit": 200})
    check(r_en[1].get("total", -1) == r_fa[1].get("total", -2),
          "ارقام فارسی همان نتیجهٔ لاتین را می‌دهد",
          f"لاتین={r_en[1].get('total')} فارسی={r_fa[1].get('total')}")
    check(r_en[1].get("total", 0) >= 1, "شمارهٔ موجود پیدا می‌شود", str(r_en[1].get("total")))
else:
    print("  (شمارهٔ نمونه کوتاه بود — از این بند گذشتیم)")

# برخلاف users.search، یک حرف هم خطا نیست
r = call(sa, SUPER, "super.php", {"action": "users.list", "q": "0"})
check(r[1].get("ok") is True, "یک نویسه هم رد نمی‌شود", str(r[1])[:150])

print("\n═══ ۸. پالایه‌ها با AND جمع می‌شوند، نه OR ═══")
r_all = call(sa, SUPER, "super.php", {"action": "users.list",
                                      "role": "manager", "limit": 200})
r_both = call(sa, SUPER, "super.php", {"action": "users.list", "role": "manager",
                                       "status": "active", "limit": 200})
check(r_both[1].get("total", 0) <= r_all[1].get("total", 0),
      "افزودن پالایه فهرست را بزرگ‌تر نمی‌کند",
      f"role={r_all[1].get('total')} role+status={r_both[1].get('total')}")

print("\n═══ ۹. ورودی نامعتبر باعث خطا یا نشتی نمی‌شود ═══")
r = call(sa, SUPER, "super.php", {"action": "users.list", "role": "hacker"})
check(r[1].get("ok") is True and r[1].get("total") == st.get("total"),
      "نقش ناشناخته به «همه» برمی‌گردد، نه خطای ۵۰۰", str(r[1])[:150])
r = call(sa, SUPER, "super.php", {"action": "users.list", "status": "'; DROP TABLE app_user;--"})
check(r[1].get("ok") is True, "ورودی مخرب در status بی‌اثر است", str(r[1])[:150])
r = call(sa, SUPER, "super.php", {"action": "users.list", "instituteId": "0" * 32})
check(r[1].get("ok") is True and r[1].get("total") == 0,
      "آموزشگاه ناموجود صفر می‌دهد", str(r[1])[:150])

print("\n═══ ۱۰. بدون نشست، هیچ ═══")
r = call(sess(), SUPER, "super.php", {"action": "users.list"})
check(r[1].get("ok") is not True, "بدون ورود فهرست نمی‌آید", str(r[1])[:150])
r = call(sess(), SUPER, "super.php", {"action": "users.stats"})
check(r[1].get("ok") is not True, "آمار هم نه", str(r[1])[:150])

print("\n" + "─" * 58)
print(f"موفق: {_pass}    ناموفق: {_fail}")
sys.exit(1 if _fail else 0)
