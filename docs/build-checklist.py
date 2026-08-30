#!/usr/bin/env python3
"""
چک‌لیست راه‌اندازی را از یک منبع می‌سازد: هم Markdown برای مخزن، هم
صفحهٔ HTML تعاملی برای انتشار.

چرا یک منبع؟ چون نسخهٔ قبلی دو فایل جدا بود و به محض اینکه نصب‌کنندهٔ
تحت وب آمد، گام‌های دستی در یکی عوض شد و در دیگری ماند. کاربری که
نسخهٔ اشتباه را باز کند، دنبال فایلی می‌گردد که دیگر وجود ندارد.

اجرا:  python3 docs/build-checklist.py
"""
import html
import pathlib
import re

HERE = pathlib.Path(__file__).resolve().parent
ROOT = HERE.parent

PLESK = "https://ir-plesk02.ihglobaldns.com:8443"
SITE = "talkora.ir"
PANEL = "panel.talkora.ir"
ADMIN = "admin.talkora.ir"

# (عنوان, زمان, دروازه؟, [بندها])
# بند می‌تواند:  ("t", متن)  متن ساده
#                ("c", مقدار) مقدار قابل کپی
#                ("w", متن)   هشدار
#                ("v", متن)   چطور مطمئن شوم
PHASES = [
    ("مرحلهٔ صفر — دامنه", "۱۰ دقیقه کار، تا ۲۴ ساعت انتظار", [
        ("نیم‌سرورهای دامنه را تنظیم کنید", "۱۰ دقیقه", True, [
            ("t", "کجا: جایی که دامنه را خریده‌اید (ایرنیک یا نمایندگی فروش) — نه پلسک."),
            ("c", "ns1.ihglobaldns.com"),
            ("c", "ns2.ihglobaldns.com"),
            ("w", "این گام هیچ جایگزینی ندارد و تا ۲۴ ساعت طول می‌کشد. "
                  "همین امروز انجامش دهید، حتی اگر بقیه را بعداً می‌کنید."),
            ("v", "در whatsmydns.net آدرس talkora.ir را جستجو کنید. وقتی به آی‌پی هاست "
                  "رسید، اعمال شده است."),
        ]),
    ]),

    ("مرحلهٔ یک — سایت معرفی", "۳۰ دقیقه", [
        ("وارد پلسک شوید", "", False, [
            ("c", PLESK),
        ]),
        ("پوشهٔ httpdocs را خالی کنید", "۳ دقیقه", False, [
            ("t", f"Websites & Domains ← {SITE} ← File Manager"),
            ("w", "در پلسک اسم پوشه httpdocs است، نه public_html. این رایج‌ترین اشتباه "
                  "کسانی است که قبلاً سی‌پنل داشته‌اند."),
        ]),
        ("فایل talkora-site.zip را آپلود و استخراج کنید", "۵ دقیقه", False, [
            ("t", "بعد از استخراج باید این‌ها را ببینید: index.html، پوشهٔ api، "
                  "پوشهٔ fonts، فایل‌های .htaccess و robots.txt و sitemap.xml."),
            ("w", "‏.htaccess فایل مخفی است. اگر نمی‌بینیدش، در File Manager از منوی "
                  "Settings گزینهٔ «Show hidden files» را روشن کنید — آپلود شده، فقط دیده نمی‌شود."),
        ]),
        ("گواهی SSL رایگان بگیرید", "۵ دقیقه", False, [
            ("t", f"SSL/TLS Certificates ← Install a free basic certificate ← {SITE} و www"),
        ]),
        ("هدایت خودکار به HTTPS را روشن کنید", "", False, [
            ("t", "Hosting Settings ← Permanent SEO-safe 301 redirect from HTTP to HTTPS"),
        ]),
        ("سایت را باز کنید و ببینید", "", False, [
            ("c", f"https://{SITE}"),
            ("v", "قفل سبز مرورگر، فونت فارسی درست، و صفحه بدون خطا بالا بیاید."),
        ]),
    ]),

    ("مرحلهٔ دو — پنل", "۴۰ دقیقه", [
        ("زیردامنهٔ panel را بسازید", "۳ دقیقه", False, [
            ("t", "Websites & Domains ← Add Subdomain"),
            ("c", "panel"),
            ("t", f"دامنهٔ والد: {SITE} — مسیر پیش‌فرض پلسک را دست نزنید."),
        ]),
        ("نسخهٔ PHP و افزونه‌ها را تنظیم کنید", "۵ دقیقه", False, [
            ("t", f"روی زیردامنهٔ {PANEL} ← PHP Settings ← نسخهٔ ۸.۱ یا بالاتر"),
            ("t", "مطمئن شوید pdo_mysql و curl و mbstring روشن‌اند."),
            ("w", "اگر curl خاموش باشد همه‌چیز درست به نظر می‌رسد ولی هیچ پیامکی فرستاده "
                  "نمی‌شود. نصب‌کننده در همان صفحهٔ اول این را به شما می‌گوید."),
        ]),
        ("یک پایگاه دادهٔ خالی بسازید", "۵ دقیقه", False, [
            ("t", "Databases ← Add Database — یک کاربر هم برایش بسازید و رمزش را نگه دارید."),
            ("w", "پلسک معمولاً به نام دیتابیس و نام کاربر پیشوند اضافه می‌کند. همان چیزی "
                  "را یادداشت کنید که در فهرست Databases می‌بینید، نه چیزی که تایپ کردید. "
                  "این رایج‌ترین دلیل «اتصال برقرار نشد» است."),
            ("t", "جدول‌ها را نسازید؛ نصب‌کننده خودش می‌سازد."),
        ]),
        ("بستهٔ talkora-panel.zip را در httpdocs زیردامنه آپلود کنید", "۵ دقیقه", False, [
            ("t", "باید این‌ها را ببینید: index.html، پوشه‌های api و setup و fonts. "
                  "(پنل سوپرادمین اینجا نیست — بستهٔ جداگانهٔ خودش را دارد، در مرحلهٔ سه.)"),
            ("w", "پوشهٔ _نصب-آپلود-نکنید را آپلود نکنید. فقط نسخهٔ پشتیبان دستی است."),
        ]),
        ("گواهی SSL زیردامنهٔ پنل را بگیرید", "۵ دقیقه", True, [
            ("t", f"SSL/TLS Certificates ← {PANEL}"),
            ("w", "کوکی ورود پرچم Secure دارد. روی http کاربر بلافاصله بعد از ورود بیرون "
                  "می‌افتد و هیچ پیام خطایی هم نمی‌بیند. این را قبل از نصب انجام دهید."),
        ]),
        ("صفحهٔ نصب را باز کنید و سه فرم را پر کنید", "۱۰ دقیقه", True, [
            ("c", f"https://{PANEL}/setup/"),
            ("t", "صفحهٔ اول خودش سرور را بررسی می‌کند. صفحهٔ دوم چهار مقدار دیتابیس گام "
                  "قبل را می‌خواهد و دکمهٔ «آزمایش اتصال» پیش از نصب می‌گوید درست است یا نه. "
                  "صفحهٔ سوم نام کاربری و رمز پنل سوپرادمین را می‌سازد."),
            ("t", "نصب‌کننده خودش جدول‌ها را می‌سازد، کلید امضای کدهای ورود را تصادفی "
                  "تولید می‌کند، و فایل پیکربندی را — اگر بتواند — بیرون از پوشهٔ وب می‌نویسد."),
            ("w", "رمز پنل سوپرادمین بازیابی خودکار ندارد. همان‌جا جایی امن ذخیره‌اش کنید."),
            ("v", "پیام «نصب تمام شد» و شمارش جدول‌های ساخته‌شده."),
        ]),
        ("پوشهٔ setup را پاک کنید و دو آزمون امنیتی بدهید", "۵ دقیقه", False, [
            ("t", "در File Manager پوشهٔ setup را حذف کنید."),
            ("c", f"https://{PANEL}/api/config.php"),
            ("t", "باید ۴۰۴ یا ۴۰۳ یا صفحهٔ خالی بدهد. اگر محتوای فایل را دیدید، فوراً "
                  "رمز دیتابیس را عوض کنید."),
            ("c", f"https://{PANEL}/setup/"),
            ("t", "باید ۴۰۴ بدهد (چون پاکش کردید) یا بگوید «از قبل نصب شده»."),
        ]),
    ]),

    ("مرحلهٔ سه — اولین ورود", "۱۵ دقیقه", [
        ("بستهٔ سوپرادمین را آپلود و وارد شوید", "۱۰ دقیقه", False, [
            ("t", "پنل سوپرادمین زیردامنهٔ جداگانه‌ای است، نه زیرپوشهٔ پنل. یک بار "
                  "زیردامنهٔ admin را در پلسک بسازید (Add Subdomain، دامنهٔ والد "
                  f"{SITE})، بستهٔ talkora-admin.zip را در httpdocs همان زیردامنه "
                  "استخراج کنید، config.sample.php را به config.php تغییر نام دهید "
                  "و چهار مقدار دیتابیس را دقیقاً مثل پنل وارد کنید — جزئیات کامل در "
                  "راهنمای-سوپرادمین.txt داخل همان بسته."),
            ("c", f"https://{ADMIN}/"),
            ("t", "با همان نام کاربری و رمزی که در نصب پنل ساختید — جدول admin_user "
                  "مشترک است، پس اینجا فرم ورود می‌بینید نه فرم ساخت حساب."),
        ]),
        ("سربرگ «سلامت سامانه» را باز کنید", "۵ دقیقه", False, [
            ("t", "هرچه سبز نبود، توضیح و راه درست‌کردنش کنار خودش نوشته شده."),
            ("v", "همه سبز، جز «پیامک» که تا مرحلهٔ شش زرد می‌ماند و عمدی است."),
        ]),
        ("با شمارهٔ خودتان وارد پنل آموزشگاه شوید", "۵ دقیقه", True, [
            ("c", f"https://{PANEL}/"),
            ("t", "شماره و نام و نام آموزشگاه را بزنید. کد ورود پیامک نمی‌شود — در پنل "
                  "سوپرادمین، سربرگ «ورود و پیامک» ظاهر می‌شود. همان را وارد کنید."),
            ("t", "اولین کسی که با نام آموزشگاه ثبت‌نام کند، نقش «مدیر» می‌گیرد."),
            ("v", "پیشخوان آموزشگاه بالا می‌آید و نام آموزشگاه‌تان را می‌بینید. "
                  "از این لحظه سامانه واقعاً قابل استفاده است."),
        ]),
    ]),

    ("مرحلهٔ چهار — محتوای سایت", "۲۰ دقیقه", [
        ("قیمت‌ها را تنظیم کنید", "۵ دقیقه", False, [
            ("t", "پنل سوپرادمین ← قیمت‌ها. اعداد را به تومان وارد کنید؛ سایت همان لحظه عوض می‌شود."),
        ]),
        ("شمارهٔ تماس و ایمیل مقصد را وارد کنید", "۵ دقیقه", False, [
            ("t", "پنل سوپرادمین ← تماس و ایمیل. «ایمیل درخواست دمو» جایی است که فرم سایت "
                  "به آن می‌رسد."),
        ]),
        ("متن‌های صفحهٔ اول را بازخوانی کنید", "۱۰ دقیقه", False, [
            ("t", "پنل سوپرادمین ← متن‌های سایت. تیتر و توضیح و متن دکمه."),
        ]),
    ]),

    ("مرحلهٔ پنج — ایمیل با دامنهٔ خودتان", "۳۰ دقیقه کار، تا ۲۴ ساعت انتظار", [
        ("سرویس ایمیل دامنه را روشن کنید", "", False, [
            ("t", f"Mail ← Mail Settings ← {SITE} ← Activate mail service"),
        ]),
        ("صندوق‌های info@ و admin@ را بسازید", "۵ دقیقه", False, [
            ("c", f"info@{SITE}"),
            ("c", f"admin@{SITE}"),
        ]),
        ("رکوردهای SPF و DKIM و DMARC را بگذارید", "۱۰ دقیقه", True, [
            ("t", "Mail Settings ← DKIM را روشن کنید. بعد در DNS Settings این رکورد را اضافه کنید:"),
            ("c", "_dmarc  TXT  v=DMARC1; p=none; rua=mailto:admin@" + SITE),
            ("w", "بدون این سه رکورد، ایمیل‌های شما در پوشهٔ اسپم می‌افتند — از جمله "
                  "اعلان درخواست دموی مشتری‌ها."),
        ]),
        ("ایمیل آزمایشی بفرستید", "", False, [
            ("t", "پنل سوپرادمین ← تماس و ایمیل ← «ارسال ایمیل آزمایشی»."),
            ("v", "ایمیل در صندوق ورودی برسد، نه اسپم."),
        ]),
    ]),

    ("مرحلهٔ شش — پیامک", "۳۰ دقیقه کار، چند روز انتظار تأیید", [
        ("در sms.ir ثبت‌نام کنید و اعتبار بخرید", "۱۵ دقیقه", False, [
            ("t", "احراز هویت و خرید اعتبار اولیه."),
        ]),
        ("قالب «ارسال وریفای» بسازید", "۱۰ دقیقه", False, [
            ("t", "پنل sms.ir ← ارسال وریفای ← افزودن قالب، دقیقاً با این متن:"),
            ("c", "کد ورود شما به تاکورا: #CODE#"),
            ("w", "حتماً «وریفای» و نه ارسال معمولی. پیامک وریفای از خط خدماتی می‌رود، "
                  "پس به کسی که «لغو تبلیغات» را فعال کرده هم می‌رسد. با خط تبلیغاتی، کد "
                  "ورودِ بخشی از کاربران هرگز نمی‌رسد و شما هم خبردار نمی‌شوید."),
            ("t", "تأیید قالب معمولاً چند روز طول می‌کشد. در این مدت سامانه کار می‌کند و "
                  "کد ورود را از پنل سوپرادمین می‌گیرید."),
        ]),
        ("کلید و شناسهٔ قالب را در پنل سوپرادمین بگذارید", "۵ دقیقه", True, [
            ("t", "پنل سوپرادمین ← ورود و پیامک. کلید API، شناسهٔ عددی قالب، و نام پارامتر (CODE). "
                  "بعد حالت را روی «با پیامک از sms.ir» بگذارید و ذخیره کنید."),
            ("t", "به هیچ فایلی دست نمی‌زنید."),
            ("v", "از یک شمارهٔ دیگر وارد شوید؛ پیامک باید برسد. اگر نرسید، حالت را به "
                  "«کد در پنل» برگردانید تا کسی پشت در نماند."),
        ]),
    ]),

    ("مرحلهٔ هفت — قبل از عمومی‌کردن", "چند هفته انتظار", [
        ("درخواست نماد اعتماد الکترونیکی بدهید", "", False, [
            ("t", "enamad.ir — چند هفته طول می‌کشد."),
            ("w", "بدون نماد اعتماد هیچ درگاه پرداخت ایرانی به شما درگاه نمی‌دهد. "
                  "این گام آخر فهرست است ولی زودتر شروعش کنید."),
        ]),
        ("ساماندهی را بگیرید", "", False, [
            ("t", "samandehi.ir — کد نماد را در پنل سوپرادمین ← نمادها و وضعیت بگذارید."),
        ]),
        ("آموزشگاه واقعی‌تان را راه بیندازید", "", False, [
            ("t", "ترم بسازید، استاد دعوت کنید، کلاس بسازید و منتشرش کنید، زبان‌آموز "
                  "اضافه کنید. اولین جلسه را واقعاً برگزار کنید."),
            ("t", "تا وقتی یک ترم کامل را با آدم‌های واقعی نگذرانده‌اید، سراغ فروش به "
                  "آموزشگاه دوم نروید."),
        ]),
    ]),
]

CLOSING = [
    ("پرداخت آنلاین شهریه",
     "نیازمند نماد اعتماد و قرارداد با یک درگاه است. کد پول باید فقط-افزودنی و "
     "قابل حسابرسی نوشته شود؛ عجله در آن گران‌ترین اشتباه ممکن است."),
    ("حضور و غیاب خودکار و ضبط کلاس",
     "به یک سرور اختصاصی BigBlueButton نیاز دارد، نه هاست اشتراکی."),
    ("ایمیل با صف و تلاش دوباره",
     "الان از mail() خود PHP استفاده می‌شود. برای اعلان «یک نفر دمو خواست» کافی است "
     "و شکستش هم ثبت می‌شود، ولی اگر روزی رسید پرداخت ایمیل شد، باید به SMTP با صف تبدیل شود."),
]


def fa(n):
    return str(n).translate(str.maketrans("0123456789", "۰۱۲۳۴۵۶۷۸۹"))


def count():
    return sum(len(items) for _, _, items in PHASES)


def gates():
    return sum(1 for _, _, items in PHASES for it in items if it[2])


# ─────────────────────────── Markdown ───────────────────────────

def build_md() -> str:
    out = ["# U. چک‌لیست راه‌اندازی — کارهایی که باید انجام دهید", ""]
    out += [
        "> نسخهٔ تعاملی این چک‌لیست با تیک‌های ماندگار جداگانه منتشر شده.",
        "> هر دو از `docs/build-checklist.py` ساخته می‌شوند، پس هیچ‌وقت با هم اختلاف پیدا نمی‌کنند.",
        "",
        f"**{fa(count())} کار، در ترتیبی که هر کدام به قبلی وابسته است.**",
        f"{fa(gates())} گام «دروازه»اند: تا انجام نشوند هیچ گام بعدی معنی ندارد.",
        "",
        "کل کار حدود دو تا سه ساعت است، ولی سه جا باید منتظر بمانید — انتشار نیم‌سرور",
        "(تا ۲۴ ساعت)، تأیید قالب پیامک (چند روز)، و نماد اعتماد (چند هفته).",
        "",
        "**سامانه از پایان مرحلهٔ سه واقعاً قابل استفاده است.** مرحله‌های چهار به بعد",
        "لازم‌اند ولی جلوی کار را نمی‌گیرند: تا وقتی پیامک راه بیفتد، کد ورود را از",
        "پنل سوپرادمین می‌خوانید و به کاربر می‌گویید.",
        "",
    ]

    n = 0
    for phase, dur, items in PHASES:
        out += [f"## {phase}", f"*{fa(len(items))} کار · {dur}*", ""]
        for title, t, gate, lines in items:
            n += 1
            head = f"- [ ] **{fa(n)} {title}**"
            if t:
                head += f" — {t}"
            if gate:
                head += "  ← **دروازه**"
            out.append(head)
            for kind, text in lines:
                if kind == "c":
                    out.append(f"  - `{text}`")
                elif kind == "w":
                    out.append(f"  - ⚠ **توجه**: {text}")
                elif kind == "v":
                    out.append(f"  - ✓ چطور مطمئن شوم؟ {text}")
                else:
                    out.append(f"  - {text}")
            out.append("")

    out += ["", "## چیزهایی که هنوز نمی‌توانید انجام دهید", "",
            "این‌ها عمداً ساخته نشده‌اند، نه اینکه جا مانده باشند:", ""]
    for title, why in CLOSING:
        out += [f"- **{title}** — {why}"]
    out.append("")
    return "\n".join(out)


# ─────────────────────────── HTML ───────────────────────────

def font_face() -> str:
    """
    فونت به‌صورت data URI داخل خود صفحه می‌رود.

    صفحهٔ منتشرشده اجازهٔ درخواست به هیچ دامنهٔ بیرونی ندارد، و روی
    ویندوز فارسی هیچ فونت پیش‌فرض قابل اتکایی نیست. اگر فونت را جا
    بگذاریم، متن با فونت جایگزین سیستم رندر می‌شود که در فارسی
    اتصال حروف را خراب می‌کند.
    """
    import base64
    out = []
    for weight, name in ((400, "dana-regular"), (700, "dana-bold")):
        f = ROOT / "site" / "fonts" / f"{name}.woff2"
        b64 = base64.b64encode(f.read_bytes()).decode()
        out.append(
            f'@font-face{{font-family:"Dana";'
            f'src:url("data:font/woff2;base64,{b64}") format("woff2");'
            f"font-weight:{weight};font-display:swap}}"
        )
    return "\n".join(out)


CSS = """
:root{
  --paper:#F4F6F9; --surface:#FFFFFF; --surface-2:#F1F4F8;
  --ink:#0B1220; --ink-2:#2C3A56; --ink-3:#5A6A85;
  --line:#E1E7EF; --brand:#2563C7; --brand-2:#1B4FA8; --brand-soft:#E9F0FB;
  --ok:#0A7048; --ok-soft:#E4F4EC; --warn:#A65F00; --warn-soft:#FBF1E0;
  --bad:#B3261E; --ring:rgba(37,99,199,.28);
}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){
  --paper:#0B1220; --surface:#131C2E; --surface-2:#1A2438;
  --ink:#EEF3FA; --ink-2:#C3CEDF; --ink-3:#8C9BB4;
  --line:#26324A; --brand:#5C93E8; --brand-2:#8FB6F0; --brand-soft:#16233C;
  --ok:#54C79A; --ok-soft:#0F2A22; --warn:#DFA24A; --warn-soft:#2A200F; --bad:#F08A82;
}}
:root[data-theme="dark"]{
  --paper:#0B1220; --surface:#131C2E; --surface-2:#1A2438;
  --ink:#EEF3FA; --ink-2:#C3CEDF; --ink-3:#8C9BB4;
  --line:#26324A; --brand:#5C93E8; --brand-2:#8FB6F0; --brand-soft:#16233C;
  --ok:#54C79A; --ok-soft:#0F2A22; --warn:#DFA24A; --warn-soft:#2A200F; --bad:#F08A82;
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Dana","Segoe UI",Tahoma,system-ui,sans-serif;background:var(--paper);
  color:var(--ink);line-height:1.8;font-size:15.5px;padding:30px 16px 80px}
.num{font-variant-numeric:tabular-nums}
.wrap{max-inline-size:820px;margin-inline:auto}
h1{font-size:25px;line-height:1.4;margin-block-end:8px}
.lede{color:var(--ink-2);font-size:15px;margin-block-end:6px}
.lede b{color:var(--ink)}
.bar{position:sticky;inset-block-start:0;z-index:5;background:var(--paper);
  padding:12px 0 14px;margin-block-end:8px;border-block-end:1px solid var(--line)}
.track{block-size:8px;background:var(--surface-2);border-radius:100px;overflow:hidden}
.fill{block-size:100%;inline-size:0;background:var(--brand);border-radius:100px;
  transition:inline-size .3s ease}
.barrow{display:flex;justify-content:space-between;align-items:center;gap:12px;
  margin-block-end:8px;flex-wrap:wrap}
.barrow b{font-size:14px}
.reset{font:inherit;font-size:13px;background:none;border:0;color:var(--ink-3);
  cursor:pointer;text-decoration:underline;padding:2px 4px}
h2{font-size:18px;margin-block:30px 4px;padding-block-end:8px;border-block-end:2px solid var(--brand)}
.dur{color:var(--ink-3);font-size:13px;margin-block-end:14px}
.task{background:var(--surface);border:1px solid var(--line);border-radius:14px;
  padding:14px 16px;margin-block-end:11px;display:flex;gap:13px;align-items:flex-start}
.task.gate{border-inline-start:4px solid var(--warn);background:var(--warn-soft)}
.task.done{opacity:.55}
.task.done .ttl{text-decoration:line-through}
.box{inline-size:24px;block-size:24px;flex:none;border:2px solid var(--line);border-radius:7px;
  background:var(--surface-2);cursor:pointer;display:grid;place-items:center;
  margin-block-start:3px;font-size:15px;color:transparent;padding:0}
.box:focus-visible{outline:2px solid var(--brand);outline-offset:2px}
.box[aria-checked="true"]{background:var(--ok);border-color:var(--ok);color:#fff}
.body{flex:1;min-inline-size:0}
.ttl{font-weight:700;font-size:15.5px}
.n{color:var(--ink-3);font-weight:400;margin-inline-end:5px}
.t{display:inline-block;font-size:12px;color:var(--ink-3);margin-inline-start:7px}
.gpill{display:inline-block;font-size:11.5px;font-weight:700;color:var(--warn);
  background:var(--surface);border:1px solid currentColor;border-radius:100px;
  padding:0 8px;margin-inline-start:7px;vertical-align:1px}
.body p{font-size:14px;color:var(--ink-2);margin-block-start:7px}
.warn{font-size:13.5px;color:var(--warn);background:var(--surface);border:1px solid currentColor;
  border-radius:9px;padding:8px 11px;margin-block-start:9px}
.ver{font-size:13.5px;color:var(--ok);margin-block-start:9px}
.copy{display:flex;gap:8px;align-items:center;margin-block-start:9px;flex-wrap:wrap}
.copy code{flex:1;min-inline-size:0;font-family:ui-monospace,Menlo,Consolas,monospace;
  font-size:13px;background:var(--surface-2);border:1px solid var(--line);border-radius:9px;
  padding:7px 11px;direction:ltr;text-align:start;overflow-x:auto;white-space:nowrap}
.copy button{font:inherit;font-size:12.5px;font-weight:700;border:1px solid var(--line);
  background:var(--surface-2);color:var(--ink-2);border-radius:9px;padding:6px 12px;cursor:pointer;flex:none}
.copy button:hover{background:var(--brand-soft);color:var(--brand-2)}
.end{background:var(--surface);border:1px solid var(--line);border-radius:14px;
  padding:18px 20px;margin-block-start:34px}
.end h3{font-size:16px;margin-block-end:6px}
.end p{font-size:14px;color:var(--ink-3);margin-block-end:14px}
.end dt{font-weight:700;font-size:14.5px;margin-block-start:12px}
.end dd{font-size:14px;color:var(--ink-2);margin-inline-start:0}
@media (max-width:520px){
  body{padding:20px 12px 60px} h1{font-size:21px} .copy code{font-size:12px}
}
"""

JS = """
(function(){
  var K="talkora-checklist-v2";
  var done={};
  try{done=JSON.parse(localStorage.getItem(K)||"{}")||{};}catch(e){done={};}

  function fa(n){return String(n).replace(/[0-9]/g,function(d){return "۰۱۲۳۴۵۶۷۸۹"[+d];});}

  function paint(){
    var boxes=document.querySelectorAll(".box");
    var n=0;
    Array.prototype.forEach.call(boxes,function(b){
      var on=!!done[b.dataset.id];
      b.setAttribute("aria-checked",on?"true":"false");
      b.textContent=on?"✓":"";
      b.closest(".task").classList.toggle("done",on);
      if(on)n++;
    });
    var pct=boxes.length?Math.round(n*100/boxes.length):0;
    document.querySelector(".fill").style.inlineSize=pct+"%";
    document.getElementById("cnt").textContent=fa(n)+" از "+fa(boxes.length)+" — "+fa(pct)+"٪";
  }

  document.addEventListener("click",function(e){
    var b=e.target.closest(".box");
    if(b){
      done[b.dataset.id]=!done[b.dataset.id];
      try{localStorage.setItem(K,JSON.stringify(done));}catch(err){}
      paint();
      return;
    }
    var r=e.target.closest(".reset");
    if(r){
      done={};
      try{localStorage.removeItem(K);}catch(err){}
      paint();
      return;
    }
    var c=e.target.closest("[data-copy]");
    if(c){
      var txt=c.previousElementSibling.textContent;
      var old=c.textContent;
      function ok(){c.textContent="کپی شد";setTimeout(function(){c.textContent=old;},1400);}
      if(navigator.clipboard&&navigator.clipboard.writeText){
        navigator.clipboard.writeText(txt).then(ok,function(){});
      }else{
        /* بدون clipboard API — روی http یا مرورگر قدیمی */
        var ta=document.createElement("textarea");
        ta.value=txt;document.body.appendChild(ta);ta.select();
        try{document.execCommand("copy");ok();}catch(err){}
        ta.remove();
      }
    }
  });

  document.addEventListener("keydown",function(e){
    if((e.key===" "||e.key==="Enter")&&e.target.classList.contains("box")){
      e.preventDefault();e.target.click();
    }
  });

  paint();
})();
"""


def esc(s):
    return html.escape(str(s), quote=True)


def build_html() -> str:
    parts = [
        # عنوان عمداً همان عنوان نسخهٔ منتشرشده می‌ماند: کاربر این صفحه را
        # با همین نام در فهرست پیدا می‌کند و عوض‌کردنش یعنی یک صفحهٔ تازه.
        '<title>تاکورا | کارهایی که باید انجام دهید</title>',

        '<script>document.documentElement.lang="fa";document.documentElement.dir="rtl";</script>',
        f"<style>{font_face()}\n{CSS}</style>",
        '<div class="wrap">',
        "<h1>چک‌لیست راه‌اندازی تاکورا</h1>",
        f'<p class="lede"><b>{fa(count())} کار</b>، در ترتیبی که هر کدام به قبلی وابسته است. '
        f'{fa(gates())} گام «دروازه»اند: تا انجام نشوند هیچ گام بعدی معنی ندارد.</p>',
        '<p class="lede">سامانه از <b>پایان مرحلهٔ سه</b> واقعاً قابل استفاده است. '
        'مرحله‌های بعدی لازم‌اند ولی جلوی کار را نمی‌گیرند — تا وقتی پیامک راه بیفتد، '
        'کد ورود را از پنل سوپرادمین می‌خوانید و به کاربر می‌گویید.</p>',
        '<p class="lede">تیک‌ها در همین مرورگر ذخیره می‌شوند؛ می‌توانید ببندید و فردا ادامه بدهید.</p>',
        '<div class="bar">',
        '<div class="barrow"><b id="cnt">—</b>'
        '<button class="reset" type="button">پاک‌کردن همهٔ تیک‌ها</button></div>',
        '<div class="track"><div class="fill"></div></div>',
        "</div>",
    ]

    n = 0
    for phase, dur, items in PHASES:
        parts.append(f"<h2>{esc(phase)}</h2>")
        parts.append(f'<p class="dur">{fa(len(items))} کار · {esc(dur)}</p>')
        for title, t, gate, lines in items:
            n += 1
            parts.append(f'<div class="task{" gate" if gate else ""}">')
            parts.append(f'<button class="box" type="button" role="checkbox" aria-checked="false" '
                         f'data-id="s{n}" aria-label="گام {fa(n)}"></button>')
            parts.append('<div class="body">')
            head = (f'<div class="ttl"><span class="n num">{fa(n)}.</span>{esc(title)}'
                    + (f'<span class="t num">{esc(t)}</span>' if t else "")
                    + ('<span class="gpill">دروازه</span>' if gate else "")
                    + "</div>")
            parts.append(head)
            for kind, text in lines:
                if kind == "c":
                    parts.append('<div class="copy"><code>' + esc(text) + "</code>"
                                 '<button type="button" data-copy>کپی</button></div>')
                elif kind == "w":
                    parts.append(f'<div class="warn">{esc(text)}</div>')
                elif kind == "v":
                    parts.append(f'<div class="ver">✓ چطور مطمئن شوم؟ {esc(text)}</div>')
                else:
                    parts.append(f"<p>{esc(text)}</p>")
            parts.append("</div></div>")

    parts.append('<div class="end"><h3>چیزهایی که هنوز نمی‌توانید انجام دهید</h3>'
                 '<p>این‌ها عمداً ساخته نشده‌اند، نه اینکه جا مانده باشند.</p><dl>')
    for title, why in CLOSING:
        parts.append(f"<dt>{esc(title)}</dt><dd>{esc(why)}</dd>")
    parts.append("</dl></div>")
    parts.append("</div>")
    parts.append(f"<script>{JS}</script>")
    return "\n".join(parts)


def main() -> int:
    md = HERE / "U-checklist.md"
    md.write_text(build_md(), encoding="utf-8")

    out_html = ROOT / "build" / "checklist.html"
    out_html.parent.mkdir(exist_ok=True)
    out_html.write_text(build_html(), encoding="utf-8")

    # هر گام باید در هر دو خروجی باشد، وگرنه یکی از دو نسخه ناقص است
    n_md = md.read_text(encoding="utf-8").count("- [ ] **")
    n_html = out_html.read_text(encoding="utf-8").count('class="box"')
    if n_md != count() or n_html != count():
        print(f"خطا: شمارش نخواند — منبع {count()}، md {n_md}، html {n_html}")
        return 1

    print(f"{fa(count())} گام، {fa(gates())} دروازه")
    print(f"  {md.relative_to(ROOT)}")
    print(f"  {out_html.relative_to(ROOT)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
