# Talkora — Product Blueprint

**The operating system for language institutes.**

Talkora is a multi-tenant SaaS platform that gives every language institute a private
workspace to run its entire operation: admissions, academics, scheduling, attendance,
exams, tuition, communication, and reporting — in one system instead of a spreadsheet,
a WhatsApp group, a paper ledger, and someone's memory.

This repository contains the **product blueprint** (A–T), a built **Persian marketing site**,
an interactive **prototype of the three dashboards**, and a **working phone-OTP backend** that
runs on ordinary Plesk shared hosting. The order was: define the product → define its structure
→ define its workflows → prove the critical screens → then build the real thing.

---

## How to read this

Documents A–O are the core blueprint; P–R are binding amendments added as the scope
sharpened. Read in order for the full picture; jump directly for a specific working session.

| # | Document | Use it when |
|---|---|---|
| A | [Product summary](docs/A-product-summary.md) | You need the one-page definition, positioning, and non-goals |
| B | [Target users and use cases](docs/B-target-users.md) | Segmenting, writing personas, prioritising features |
| C | [Main business problem](docs/C-business-problem.md) | Writing sales/marketing copy, validating with institutes |
| D | [Key value proposition](docs/D-value-proposition.md) | Landing page copy, demo script, objection handling |
| E | [Information architecture](docs/E-information-architecture.md) | Building navigation, routing, sitemap |
| F | [Page-by-page breakdown](docs/F-page-breakdown.md) | Designing or building any individual screen |
| G | [Main user flows](docs/G-user-flows.md) | Wireframing journeys, writing acceptance criteria |
| H | [Dashboard structure](docs/H-dashboard-structure.md) | Designing the four role dashboards |
| I | [Data and entity model](docs/I-data-model.md) | Schema design, API design, tenancy decisions |
| J | [Permissions model](docs/J-permissions.md) | Auth, RBAC, security review |
| K | [Subscription / monetization](docs/K-monetization.md) | Pricing page, billing logic, plan gating |
| L | [MVP vs future roadmap](docs/L-roadmap.md) | Sprint planning, scope cuts, investor narrative |
| M | [UI / UX direction](docs/M-ui-ux-direction.md) | Design system, tokens, components, RTL rules |
| N | [Risks and edge cases](docs/N-risks-edge-cases.md) | QA planning, defensive design, support playbooks |
| O | [Final recommendation](docs/O-final-recommendation.md) | Deciding what to build first and how to launch |
| **P** | **[تطبیق با بازار ایران](docs/P-iran-market.md)** | **Binding amendment to A–O. Read before building anything.** |
| Q | [کلاس آنلاین](docs/Q-online-classroom.md) | Virtual classroom: provider adapter, BBB vs Meet trade-off, auto-attendance, recordings |
| R | [تکلیف و نمره‌دهی](docs/R-assignments-grading.md) | Assignments, audio submissions, rubrics, the grading screen, gradebook |
| S | [استقرار روی زیرساخت ایران](docs/S-deployment.md) | Iranian hosting, OTP, BBB/Meet wiring, automatic recording upload |
| T | [بالا آوردن سایت و پنل](docs/T-going-live.md) | Step-by-step for the real Plesk host: `talkora.ir` and the `panel.talkora.ir` subdomain with OTP over sms.ir |
| **U** | **[چک‌لیست راه‌اندازی](docs/U-checklist.md)** | **Start here to deploy. 30 tasks in dependency order, six of them gating. Generated with the interactive version from `docs/build-checklist.py`.** |
| V | [پنل سوپرادمین پلتفرم](docs/V-super-admin.md) | Standing up `admin.talkora.ir`: platform-wide institute/role management, impersonation, deploying the third package |
| — | [CUSTOMIZE.md](CUSTOMIZE.md) | What to change before the site goes public: phone, prices, form endpoint |

---

## The five-line version

1. **Who it's for:** language institutes (1–50 branches), independent tutors, and corporate
   language training units.
2. **What it replaces:** Excel registration sheets, paper attendance, WhatsApp announcements,
   handwritten payment ledgers, and a manager who is the only person who knows anything.
3. **How it works:** each institute gets an isolated tenant workspace with its own users,
   roles, branches, terms, classes, students, money, and reports.
4. **Why they pay:** it removes 15–25 hours of admin work per staff member per month, cuts
   tuition leakage, and makes the institute look like a professional organisation to students.
5. **How we make money:** tiered monthly/annual subscription priced on active students and
   branches, plus usage add-ons (SMS credits, online-payment fees) and a paid onboarding
   package for larger institutes.

---

## Market (confirmed)

The launch market is **Iran — Persian-speaking language institutes and Iranian learners**,
and the product surface is **Persian-first**. This is not a cosmetic choice; it shapes the
product core, and the binding specification is [P. تطبیق با بازار ایران](docs/P-iran-market.md),
which overrides A–O wherever they conflict:

- **Jalali (Shamsi) calendar is a first-class citizen**, not a display filter. Terms,
  schedules, invoices, and reports are authored in Jalali and stored in UTC ISO-8601.
- **RTL-first UI** with a fully mirrored layout system; English LTR is the secondary locale.
- **SMS is the primary communication channel**, not email. Email is secondary and optional.
- **Instalment tuition is the default**, not an edge case. Most students do not pay in full
  up front, and debt tracking is a headline feature rather than an accounting afterthought.
- **Local payment rails** (IRR gateways, card-to-card receipts with proof upload, cash at
  the front desk) must all be first-class payment methods alongside online gateways.
- **Term ("ترم") based operation** on ~7–13 week cycles with fixed intake dates, not
  rolling enrolment.

Beyond the list above, section P also settles: the زوج/فرد class-day convention, book-based
level structure (AEF 2A, Top Notch 1B), the ۰–۲۰ grading scale, service-vs-advertising SMS
lines, moving lunar holidays, one-click closure for snow/pollution days, Iranian hosting
constraints, and the eNamad/Samandehi requirements.

Everything is still abstracted behind interfaces (calendar system, payment provider,
messaging provider, locale) so the same product could sell into Turkish or Arabic markets
later — but Iran is the target, not a configuration.
See [I. Data model](docs/I-data-model.md#i10-localisation-and-calendar-strategy).

---

## سایت معرفی — `site/`

The Persian marketing site is built and reviewable.

| File | What it is |
|---|---|
| `site/index.html` | The site. Persian, RTL, light + dark. Fonts loaded from `site/fonts/` — this is the version to deploy |
| `site/fonts/dana-*.woff2` | Dana FaNum, converted to woff2 (~27 KB each) and self-hosted |
| `site/build-standalone.py` | Inlines the fonts as data-URIs → `build/talkora-preview.html`, a single shareable file |

Built to the rules in [M](docs/M-ui-ux-direction.md) and [P](docs/P-iran-market.md):
zero external requests, Persian numerals, `tabular-nums`, RTL logical properties,
44px+ touch targets, `prefers-reduced-motion` honoured, and no reliance on glyphs the
font doesn't ship.

Interactive: the hero is a working attendance widget (tap a student, submit, it times you),
plus role tabs, a pricing calculator, and a validating demo form that accepts Persian digits.

```bash
python3 site/build-standalone.py    # → build/talkora-preview.html
```

**Not real yet:** customer counts, testimonials, and prices are placeholders, flagged by a
banner at the top of the page. Fill them in before this goes public.

---

## پنل‌ها — `app/` و `panel/api/`

Interactive prototype of all three dashboards, in Persian, **with real phone-OTP login**.

| File | What it is |
|---|---|
| `app/index.html` | The panel. Hash-routed, fonts from `site/fonts/` |
| `app/build-standalone.py` | Inlines fonts → `build/talkora-app-preview.html` |
| `panel/api/` | PHP 8 + MySQL auth backend: OTP over sms.ir, sessions, rate limits, audit log |

### Two modes, decided by a request — not a guess

On boot the panel calls `api/health.php`:

- **healthy + `database: true`** → **live mode.** Real SMS login, real session cookie, role
  comes from the server, route guard keeps a student out of `#/m`, no demo bar.
- **no answer, error, or DB down** → **demo mode.** Mock data and the role switcher, so the
  offline preview still works.

So a demo bar on the deployed panel is a precise diagnosis: `api/health.php` didn't answer.

**Four flows are built end to end** — the ones with the highest daily use:

1. **Start an online class** — teacher picks BigBlueButton / Google Meet / Skyroom, with the
   real trade-off stated under each option (see [Q](docs/Q-online-classroom.md)). Live state
   propagates to the student portal and the manager dashboard.
2. **Attendance** — default-present roster, auto-suggested from the meeting for online
   students, submit with an undo toast.
3. **Submit an assignment** — writing (word count, draft autosave) and **speaking**
   (in-browser recording with a timer and waveform).
4. **Grade** — SpeedGrader-style: one student per screen, click-to-score rubric, saved
   phrases, `Ctrl+Enter` to save and advance.

Other sections render an honest "not built in this prototype" state rather than a dead link.

```bash
python3 app/build-standalone.py    # → build/talkora-app-preview.html
```

### The auth backend, and what was verified

`panel/api/` is deliberately framework-free and composer-free so it runs on the shared host the
domain already has — no VPS needed for login to work on day one.

| File | What it does |
|---|---|
| `_bootstrap.php` | Config loading, PDO (MySQL + SQLite), phone normalisation incl. Persian digits, rate limiting, session issue/verify/revoke, audit |
| `_sms.php` | sms.ir **Verify** endpoint — a service line, so it reaches users who blocked advertising SMS (P.4) |
| `otp-request.php` | 5-digit code, 2-minute TTL, HMAC-only storage, 60s resend gap, 5/hour per phone, 20/hour per IP |
| `otp-verify.php` | Constant-time compare, 5 attempts then the code dies, signup-on-first-login |
| `me.php` / `logout.php` / `health.php` | Session read, revoke, and the mode probe |

Verified over real HTTP against a live database, not reasoned about:

- Full signup → login → refresh → logout cycle, including the new-user path where the server
  asks for a name and **deliberately does not burn the code yet** (burning it early made signup
  impossible — that bug was found and fixed here).
- Brute force capped at 5 attempts; 6th is refused and the code is invalidated.
- Rate limits enforced per phone and per IP.
- Forged and short session cookies rejected; `httpOnly` confirmed; logout actually revokes.
- SQL injection in the phone field rejected by normalisation; `GET` on POST-only routes refused.
- Every internal file (`_bootstrap.php`, `_sms.php`, `config.php`, `config.sample.php`) returns
  **404 on direct request** — enforced in PHP itself, not only in `.htaccess`, because Plesk in
  "nginx only" mode ignores `.htaccess` entirely.

`config.php` holds the DB password and the OTP signing key. It is gitignored, the build refuses
to run if it exists, and the installer prefers to write it *outside* the webroot entirely.

**Login works before SMS does.** Getting an sms.ir Verify template approved takes days, and
without a bridge the product is unusable in that window — nobody, including the owner, can log
in. So a `bridge` mode issues codes normally but shows them in the admin panel instead of
sending them; the manager reads the code to the user. The plaintext lives in `otp_code.pending_code`
for at most the code's two-minute lifetime, is wiped the moment the code is consumed, is visible
only to a logged-in admin, and is never written at all once the mode is `smsir`. Switching to
`smsir` without a key and template ID is refused, because that combination locks everyone out
with no way back in.

---

## پنل سوپرادمین — `superadmin/`

The platform-owner console, on its own subdomain (`admin.talkora.ir`) and its own zip package —
see [V. Super-admin panel](docs/V-super-admin.md) for the full picture. Same design language as
the old product admin panel it replaces, but a real superset: it can see and manage **every**
institute, not just count them.

| File | What it is |
|---|---|
| `superadmin/index.html` | The console — institutes, users & roles, impersonation, site settings, system health |
| `superadmin/api/` | PHP backend sharing the panel's MySQL database, with its own session cookie (`tk_platform`) |

What it can do: search/suspend institutes, grant or change a user's role (manager/teacher/
student) at any institute, suspend a membership or a whole account, and — with a mandatory,
audited reason — issue a short-lived, single-use link that logs a platform admin into a tenant
user's session for support (`panel/api/impersonate.php`). What it deliberately can't do: read a
tenant's actual content (students, grades, messages) without going through that impersonation
flow, which is logged both when issued and when consumed.

---

## کد سرور — `server/` و `infra/`

Real code, not prototype. Verified, not assumed.

| Path | What it is | Verified how |
|---|---|---|
| `server/db/001_init.sql` | Schema with row-level tenant isolation, capacity trigger, append-only financial records | Run against PostgreSQL 16; 6 invariants tested including a cross-tenant leakage suite |
| `server/meetings/provider.ts` | Provider interface + failover, so a dead API never cancels a class | `tsc --noEmit` clean |
| `server/meetings/bigbluebutton.ts` | BBB adapter: SHA-1 signed create/join/getMeetingInfo/end/getRecordings | `tsc --noEmit` clean |
| `server/meetings/googlemeet.ts` | Meet adapter, with its real limits encoded as `attendanceReliable = false` | `tsc --noEmit` clean |
| `server/workers/recording.ts` | Recording pipeline: wait, stream-download, multipart upload, publish, delete from BBB | `tsc --noEmit` clean |
| `server/auth/otp.ts` | SMS OTP: hashed codes, rate limits, uniform response so users can't be enumerated | `tsc --noEmit` clean |
| `infra/docker-compose.yml` | app, worker, Postgres, Redis, nginx, encrypted daily backup | Parsed; DB and Redis confirmed to have no exposed ports |
| `infra/nginx.conf` | TLS, HSTS, CSP with no external origins, strict rate limit on the OTP route | — |
| `infra/bootstrap.sh` | Fresh Ubuntu 24.04 → firewall, key-only SSH, fail2ban, UTC clock, Docker | — |

**Tenant isolation, actually tested:** with two institutes seeded, the app role sees 2 rows
for one and 1 for the other, 0 when querying another tenant's ID explicitly, 0 with no
tenant set, and a cross-tenant INSERT is refused.

---

## استقرار — `build-dist.py`

One command produces **three upload-ready packages**, because the site, the panel, and the
platform superadmin console live on three separate subdomains:

```bash
python3 build-dist.py
```

| Output | Goes to | Needs |
|---|---|---|
| `dist/site/` → `talkora-site.zip` | `httpdocs` of `talkora.ir` | Static files only |
| `dist/panel/` → `talkora-panel.zip` | `httpdocs` of `panel.talkora.ir` | PHP 8, MySQL, `curl` |
| `dist/admin/` → `talkora-admin.zip` | `httpdocs` of `admin.talkora.ir` | PHP 8, MySQL — **same database as the panel** |

Splitting them is not tidiness — it buys concrete things:

1. Each subdomain scopes its own session cookie (`tk_session` for tenant users, `tk_platform`
   for the superadmin). The public sales page, which everyone loads, never carries an auth
   cookie, and a leaked platform-admin session can't be confused with a tenant one.
2. The marketing site never touches the database. If the panel 500s or the DB fills up, the
   page that sells the product is still up.
3. Their security posture differs honestly: the panel and superadmin get `X-Frame-Options: DENY`,
   `noindex`, and `no-store`; the site does not — and the superadmin package is the most
   locked-down of the three, since it can see every tenant.

Each zip carries its own Persian, step-by-step upload guide written against this specific
Plesk host — nameservers, `httpdocs` (not `public_html`), the subdomain, the MySQL database,
Let's Encrypt, and an ordered test sequence.

**Setup is a web installer, not a text editor.** After uploading, `panel.talkora.ir/setup/`
probes the server (PHP version, `pdo_mysql`, `curl`, `mbstring`, writability, HTTPS), tests the
database credentials before touching anything, creates the 20+ tables, generates the OTP signing
key itself, creates the admin account, and writes the config file — preferring a `private/`
directory outside the webroot. It then locks itself: once a config file exists, every request
including the harmless server probe is refused, and it will not create a second admin if one
already exists in the database. An attacker cannot complete an install without the database
password, which is the actual protection; the lock and the "delete `setup/`" instruction are
the layers behind it. That same admin account is what logs into `admin.talkora.ir` afterward —
see [V. Super-admin panel](docs/V-super-admin.md).

The schema also ships as `_نصب-آپلود-نکنید/پایگاه-داده.sql` for manual import if the installer
ever fails, and inside `api/_schema.php` for the installer to read — a PHP file, never served
as text even when Plesk ignores `.htaccess`.

**The build fails rather than shipping something broken.** It refuses to run if a real
`config.php` exists, and it aborts on any external reference, on a schema left inside `api/`
as `.sql`, on a generated `_schema.php` with fewer than 20 tables or a broken heredoc
terminator, on a missing installer, on a panel that isn't wired to `api/health.php`, or on a
panel still loading fonts from the parent domain.

---

## Status

| Phase | State |
|---|---|
| Product definition (A–O) | ✅ Complete |
| Iran market spec (P) | ✅ Complete |
| Deployment specs (S, T) | ✅ Complete, written against the real host |
| Online classroom spec (Q) | ✅ Complete |
| Assignments & grading spec (R) | ✅ Complete |
| Marketing site | ✅ Built — needs real content (see CUSTOMIZE.md) |
| Dashboard prototype | ✅ Four core flows built, real login in front of it |
| Phone-OTP auth (shared host) | ✅ Built, wired to sms.ir, verified end to end over HTTP |
| Backend (rest) | ◐ Schema, meeting adapters and recording worker written and verified; business API routes and payment gateway not yet |
| MVP implementation | ⏳ Blocked on design sign-off |

Next action: see [O. Final recommendation](docs/O-final-recommendation.md#o4-the-first-90-days).
