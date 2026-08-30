#!/usr/bin/env python3
"""
وبلاگ: پالایش HTML، نشانی فارسی، و صفحه‌های عمومی.

بیشترِ این آزمون دربارهٔ پالایش است. نویسنده سوپرادمین است و به او
اعتماد داریم — ولی اعتماد به آدم، اعتماد به مرورگرِ اوست: متنی که از
Word کپی شده صد تگ اضافه دارد، و اگر روزی حسابی لو برود، اسکریپت
ذخیره‌شده به *همهٔ* بازدیدکننده‌ها می‌رسد نه فقط به مهاجم.

پیش‌نیاز:
  php -S 127.0.0.1:8101 -t superadmin   (پنل پلتفرم)
  php -S 127.0.0.1:8102 -t site         (سایت عمومی)

اجرا:  python tests/blog.py
"""
import http.cookiejar
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

SUPER = os.environ.get("TALKORA_SUPER_URL", "http://127.0.0.1:8101/api")
SITE = os.environ.get("TALKORA_SITE_URL", "http://127.0.0.1:8102")
_pass = 0
_fail = 0


def sess():
    return urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))


def call(op, body):
    data = json.dumps(body, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(f"{SUPER}/super.php", data=data,
                                 headers={"Content-Type": "application/json"}, method="POST")
    try:
        with op.open(req, timeout=25) as r:
            return r.status, json.loads(r.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read().decode("utf-8"))


def get(path):
    """(کد وضعیت، متن) — برای صفحه‌های عمومی"""
    try:
        with urllib.request.urlopen(SITE + path, timeout=25) as r:
            return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


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


print("\n═══ ۱. ورود سوپرادمین ═══")
sa = sess()
r = call(sa, {"action": "me"})
if not r[1].get("authenticated"):
    r = call(sa, {"action": "login",
                  "username": os.environ.get("TALKORA_SUPER_USER", "owner"),
                  "password": os.environ.get("TALKORA_SUPER_PASS", "admin12345")})
check(r[1].get("ok") is True, "وارد شد", str(r[1]))
if not r[1].get("ok"):
    print("\n  php tests/schema.php سوپرادمینی با owner / admin12345 می‌سازد.")
    sys.exit(1)

print("\n═══ ۲. دسته‌های آغازین ═══")
r = call(sa, {"action": "blog.list"})
cats = {c["slug"]: c for c in r[1].get("categories", [])}
check(len(cats) >= 4, "چهار دستهٔ آغازین هست", str(list(cats)))
check("آموزش-زبان" in cats, "نشانی دسته فارسی است", str(list(cats)))
check(r[1].get("uploads", {}).get("ok") is not None, "وضعیت پوشهٔ تصویر گزارش می‌شود",
      str(r[1].get("uploads")))

print("\n═══ ۳. پالایش HTML ═══")
DIRTY = """
<h2>عنوان بخش</h2>
<p>متن سالم با <strong>پررنگ</strong> و <a href="https://example.com">لینک بیرونی</a>.</p>
<script>alert('نفوذ')</script>
<p onclick="alert(1)" style="color:red">پاراگراف با صفت خطرناک</p>
<img src="javascript:alert(1)" alt="بد">
<a href="javascript:alert(1)">لینک خطرناک</a>
<iframe src="https://evil.example"></iframe>
<font face="Tahoma"><span>متن داخل تگ ناشناخته</span></font>
<p>تصویر سالم: <img src="uploads/x.png" alt="نمونه"></p>
<ul><li>مورد یک</li><li>مورد دو</li></ul>
"""
sfx = str(int(time.time()))[-6:]
r = call(sa, {"action": "blog.save", "title": "راهنمای آزمون آیلتس " + sfx,
              "body": DIRTY, "categoryId": cats["آموزش-زبان"]["id"],
              "excerpt": "هرچه برای شروع آیلتس لازم است.",
              "author": "سردبیر تاکورا"})
pid = r[1].get("id")
slug = r[1].get("slug")
check(bool(pid), "نوشته ذخیره شد", str(r[1]))
check(slug == "راهنمای-آزمون-آیلتس-" + sfx, "نشانی فارسی و تمیز ساخته شد", str(slug))
check(r[1].get("readingMin", 0) >= 1, "زمان خواندن حساب شد", str(r[1]))

body = call(sa, {"action": "blog.get", "id": pid})[1]["post"]["body"]
for frag, why in [("<script", "تگ script"), ("onclick", "صفت رویداد"),
                  ("javascript:", "نشانی javascript"), ("<iframe", "قاب تودرتو"),
                  ("style=", "صفت style"), ("<font", "تگ ناشناخته")]:
    check(frag not in body, f"حذف شد: {why}", body[:200])

check("<h2>" in body, "عنوان بخش ماند", body[:200])
check("<strong>" in body, "پررنگ ماند", body[:200])
check("متن داخل تگ ناشناخته" in body, "متنِ داخل تگ ناشناخته حفظ شد", body[:200])
check("نفوذ" not in body, "محتوای script هم رفت، نه فقط تگش", body[:200])
check('rel="nofollow noopener"' in body, "لینک بیرونی rel امن گرفت", body[:300])
check('loading="lazy"' in body, "تصویر تنبل شد", body[:300])
check("uploads/x.png" in body, "تصویر سالم ماند", body[:300])

print("\n═══ ۳.۵ دور زدن اسکیم ═══")
# مرورگر «java<tab>script:» را اجرا می‌کند، پس پالایش هم باید همان‌طور
# بخواندش. و «uploads/x.png» — ساده‌ترین مسیر نسبی ممکن — نباید قربانی
# سخت‌گیری شود؛ یک بار همین شد و تصویرِ سالمِ خودِ وبلاگ حذف می‌شد.
SCHEMES = [("uploads/a.png", True, "نسبی ساده"),
           ("/blog/uploads/b.png", True, "از ریشه"),
           ("https://talkora.ir/c.png", True, "مطلق https"),
           ("javascript:alert(1)", False, "javascript"),
           ("JaVaScRiPt:alert(1)", False, "با حروف مخلوط"),
           ("java\tscript:alert(1)", False, "با تب میان اسکیم"),
           ("  javascript:alert(1)", False, "با فاصلهٔ ابتدایی"),
           ("data:text/html;base64,PHNjcmlwdD4=", False, "data"),
           ("vbscript:msgbox(1)", False, "vbscript")]
mixed = "<p>متن.</p>" + "".join(
    '<p><img src="%s" alt="s%d"></p>' % (u.replace('"', "&quot;"), i)
    for i, (u, _, _) in enumerate(SCHEMES))
rs = call(sa, {"action": "blog.save", "title": "آزمون اسکیم " + sfx, "body": mixed})
mb = call(sa, {"action": "blog.get", "id": rs[1]["id"]})[1]["post"]["body"]
for i, (u, want, label) in enumerate(SCHEMES):
    m = re.search(r'<img[^>]*alt="s%d"[^>]*>' % i, mb)
    has = "src=" in (m.group(0) if m else "")
    check(has == want, ("ماند: " if want else "رفت: ") + label,
          m.group(0) if m else "تصویر پیدا نشد")

print("\n═══ ۴. پیش از انتشار، عمومی نیست ═══")
enc = urllib.parse.quote(slug, safe="")
c, html = get(f"/blog/post.php?slug={enc}")
check(c == 404, "پیش‌نویس ۴۰۴ می‌دهد", f"کد {c}")
check("noindex" in html, "و noindex می‌گیرد", html[:200])

c, html = get("/blog/index.php")
check(slug not in html, "در فهرست هم نیست")

print("\n═══ ۵. انتشار ═══")
r = call(sa, {"action": "blog.publish", "id": pid, "on": True})
check(r[1].get("status") == "published", "منتشر شد", str(r[1]))

c, html = get(f"/blog/post.php?slug={enc}")
check(c == 200, "صفحه بالا آمد", f"کد {c}")
check("راهنمای آزمون آیلتس" in html, "عنوان در HTML هست — نه با جاوااسکریپت", html[:300])
check("<h2>عنوان بخش</h2>" in html, "متن نوشته رندر شد")
check('rel="canonical"' in html, "canonical دارد")
check('property="og:title"' in html, "برچسب‌های اوپن‌گراف دارد")
check('"@type":"BlogPosting"' in html.replace(" ", ""), "دادهٔ ساختاریافته دارد", html[:400])
check('"datePublished"' in html.replace(" ", ""), "تاریخ انتشار در JSON-LD هست")
check("سردبیر تاکورا" in html, "نام نویسنده هست")
m = re.search(r'<time datetime="[^"]+">([^<]+)</time>', html)
check(bool(m) and any(x in m.group(1) for x in
      ["فروردین","اردیبهشت","خرداد","تیر","مرداد","شهریور",
       "مهر","آبان","آذر","دی","بهمن","اسفند"]),
      "تاریخ شمسی نمایش داده شد", m.group(1) if m else "—")

print("\n═══ ۶. فهرست، دسته، خوراک و نقشه ═══")
c, html = get("/blog/index.php")
check(c == 200 and "راهنمای آزمون آیلتس" in html, "در فهرست آمد", f"کد {c}")

c, html = get("/blog/index.php?c=" + urllib.parse.quote("آموزش-زبان", safe=""))
check(c == 200 and "راهنمای آزمون آیلتس" in html, "در صفحهٔ دسته آمد", f"کد {c}")
check("آموزش زبان" in html, "عنوان دسته هست")

c, html = get("/blog/index.php?c=" + urllib.parse.quote("دستهٔ-ناموجود", safe=""))
check(c == 404, "دستهٔ ناموجود ۴۰۴ می‌دهد", f"کد {c}")

c, xml = get("/blog/feed.php")
check(c == 200 and "<rss" in xml, "خوراک RSS ساخته شد", f"کد {c}")
check(slug in urllib.parse.unquote(xml), "نوشته در خوراک هست")

c, xml = get("/blog/sitemap.php")
check(c == 200 and "<urlset" in xml, "نقشهٔ سایت ساخته شد", f"کد {c}")
check(slug in urllib.parse.unquote(xml), "نوشته در نقشه هست")
check("<lastmod>" in xml, "تاریخ آخرین تغییر دارد")

print("\n═══ ۷. نشانی تکراری ═══")
r2 = call(sa, {"action": "blog.save", "title": "راهنمای آزمون آیلتس " + sfx,
               "body": "<p>نوشتهٔ دوم با همان عنوان.</p>"})
check(r2[1].get("slug") == slug + "-2", "نشانی دوم شمارنده گرفت", str(r2[1].get("slug")))

print("\n═══ ۸. ورودی نامعتبر ═══")
for label, body, err in [
        ("عنوان خالی", {"action": "blog.save", "title": "", "body": "<p>x</p>"}, "invalid"),
        # فقط <script> یعنی بعد از پالایش هیچ نمی‌ماند
        ("متنی که همه‌اش پالوده می‌شود",
         {"action": "blog.save", "title": "ت", "body": "<script>x</script>"}, "invalid"),
        ("دستهٔ ناموجود", {"action": "blog.save", "title": "ت", "body": "<p>x</p>",
                           "categoryId": "0" * 32}, "not_found")]:
    r = call(sa, body)
    check(r[1].get("error") == err, f"رد شد: {label}", str(r[1]))

r = call(sa, {"action": "blog.upload", "name": "x.png", "data": "data:image/png;base64,bm90LWFuLWltYWdl"})
check(r[1].get("error") in ("bad_image", "no_upload_dir"),
      "فایلی که تصویر نیست رد شد", str(r[1]))

print("\n═══ ۹. از انتشار درآوردن ═══")
r = call(sa, {"action": "blog.publish", "id": pid, "on": False})
check(r[1].get("status") == "draft", "پیش‌نویس شد", str(r[1]))
c, _ = get(f"/blog/post.php?slug={enc}")
check(c == 404, "دیگر عمومی نیست", f"کد {c}")

r = call(sa, {"action": "blog.publish", "id": pid, "on": True})
p = call(sa, {"action": "blog.get", "id": pid})[1]["post"]
check(p["publishedAt"] is not None, "انتشار دوباره، تاریخ اصلی را نگه داشت", str(p["publishedAt"]))

print("\n═══ ۱۰. بدون نشست، هیچ ═══")
r = call(sess(), {"action": "blog.save", "title": "ت", "body": "<p>x</p>"})
check(r[0] in (401, 403), "بدون ورود نمی‌شود نوشت", f"{r[0]} {r[1]}")

print("\n" + "─" * 58)
print(f"موفق: {_pass}    ناموفق: {_fail}")
sys.exit(0 if _fail == 0 else 1)
