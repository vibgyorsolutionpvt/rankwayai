# Project Atlas — Execution Plan

**Internal name:** Project Atlas  
**Public name (later):** MarketingOS / GrowthOS / BrandPilot / CampaignOS  
**Product type:** Multi-tenant SaaS (any business can sign up)  
**Known future tenants (later, not in core build):** Vibgyor Solution, CityConnect24 — baad me workspace/customer ke tor pe add  
**Doc version:** 1.1  
**Date:** 2026-08-03

---

## 1. What we are building

Ye **ek generic SaaS platform** hai — kisi ek client ke liye custom tool nahi.

Koi bhi business owner signup kare → apna workspace banaye → SEO + Social marketing automate kare.

Vibgyor Solution / CityConnect24 jaise accounts **baad me** tenants ki tarah onboard honge. Unke brand, niche calendar, ya domain-specific logic ko product ke andar hardcode nahi karna.

| V1 (MVP) | Later (V2+) |
|----------|-------------|
| SEO OS | WhatsApp campaigns |
| Social Media OS | Email marketing |
| Brand Kit + Media Library | CRM |
| AI content + posters (template engine) | Landing / funnel builder |
| Rank tracking + daily SEO tasks | Billing / subscriptions / agency mode |

**Hard rules**
- CRM pehle nahi. Pehle SEO + Social ship karna hai.
- Client-specific features hardcode mat karo — sab workspace-configurable rakho.
- Platform kisi bhi industry (agency, local services, ecommerce, SaaS) ke liye kaam kare.

**Tagline direction:** *One Platform. Complete Digital Marketing.*

---

## 2. How execution will work (Cursor method)

Hum kabhi bhi Cursor ko ye nahi bolenge:

> “Build a Marketing SaaS”

Hum **sprint-by-sprint** chalenge. Har sprint ke liye alag folder + fixed prompt.

### 2.1 Repo structure (docs + code)

```
marketing-os/
├── EXECUTION_PLAN.md          ← ye file
├── docs/
│   ├── 00_READ_FIRST.md
│   ├── 01_PROJECT_RULES.md
│   ├── 02_TECH_STACK.md
│   ├── 03_FOLDER_STRUCTURE.md
│   ├── 04_DATABASE_RULES.md
│   ├── 05_CODING_STANDARDS.md
│   ├── 06_UI_DESIGN_SYSTEM.md
│   ├── 07_API_STANDARDS.md
│   ├── 08_AI_RULES.md
│   ├── 09_SECURITY_RULES.md
│   └── 10_DEPLOYMENT.md
├── sprints/
│   ├── sprint-01/
│   │   ├── TASK.md
│   │   ├── DATABASE.md
│   │   ├── API.md
│   │   ├── UI.md
│   │   ├── TESTING.md
│   │   └── ACCEPTANCE.md
│   ├── sprint-02/
│   └── ...
└── (Laravel app at repo root: app/, routes/, resources/, …)
```

### 2.2 Har sprint pe Cursor ko ye prompt dena

```
Read these files first:

docs/00_READ_FIRST.md
docs/01_PROJECT_RULES.md
docs/05_CODING_STANDARDS.md
sprints/sprint-XX/TASK.md
sprints/sprint-XX/DATABASE.md
sprints/sprint-XX/API.md
sprints/sprint-XX/UI.md
sprints/sprint-XX/ACCEPTANCE.md

Rules:
- Complete ONLY this sprint.
- Do not start the next sprint.
- Do not change architecture.
- Do not install unnecessary packages.
- Do not modify unrelated files.
- If a requirement is missing, ask before coding.
- After done, list what was built and how to test it.
```

### 2.3 Review gate (har sprint ke baad)

1. Acceptance checklist pass  
2. Manual smoke test  
3. DB migrate clean on fresh DB  
4. Tabhi next sprint unlock  

---

## 3. Product decisions (locked for V1)

| Decision | Choice | Why |
|----------|--------|-----|
| Scope | SEO + Social only | Faster launch, clear USP |
| Tenancy | Multi-tenant workspaces | Har customer apna workspace; multiple brands per account |
| Social platforms | FB, IG, LinkedIn, X | Core reach; Pinterest/Threads later |
| Instagram | Business/Creator + Meta API only | Personal accounts unreliable |
| Images | Upload OR AI background + template overlay | OpenAI size fail rate fix |
| Resize | Deterministic engine (1080×1080, 1080×1350, 1080×1920, 1200×630) | Platform-safe posts |
| AI usage | Templates first, AI polish last | Cost control |
| Approval | Optional human approve before publish | Trust for client work |
| Ranking guarantee | None | Google uncontrollable; improve probability only |

---

## 4. Recommended tech stack

| Layer | Choice |
|-------|--------|
| Frontend | Laravel Inertia + React (Vite) — repo root |
| Backend API | Laravel (PHP) — same app |
| DB | MySQL / PostgreSQL |
| Queue / Jobs | Redis + Laravel queues / Horizon |
| Storage / CDN | S3-compatible (images, exports) |
| Auth | Session (Breeze/Inertia) + optional Sanctum JSON |
| Crawl / SEO jobs | Background workers |
| Social publish | Official Meta / LinkedIn / X APIs |
| AI text | OpenAI / compatible API (pluggable) |
| AI image | Optional; template engine is source of truth for final post |
| Deploy | Docker + staging + production |

**AI cost rule:** har request pe full blog/image mat banana. Keyword DB + templates + reuse pehle; AI sirf gap fill / polish.

Rough budget guide (per tenant usage):

- Light (1–2 sites): ~₹800–₹3,500/month AI  
- Agency scale (~50 workspaces full auto): ~₹15k–₹60k+/month → SaaS pricing must cover  

---

## 5. Phase map (high level)

| Phase | Weeks | Outcome |
|-------|-------|---------|
| **P0 Foundation** ✅ | 1–2 | Auth, workspace, roles, brand kit, settings, CI |
| **P1 Media** ✅ | 3 | Upload, folders, tags, resize pipeline |
| **P2 Social Scheduler** ✅ | 4–7 | Connect accounts, calendar, queue, publish, analytics stub |
| **P3 SEO Engine** ✅ | 8–11 | GSC/GA connect, crawl, audit, keywords, rank track, daily tasks |
| **P4 AI Layer** ✅ | 12–14 | Captions, blog assist, SEO suggestions, festival calendar |
| **P5 Polish + Pilot** ✅ | 15–16 | Demo/test tenants live pilot, reports, bugfix |
| **P6 V2 backlog** ✅ | later | WhatsApp, Email, CRM, billing, funnels, provider hooks |

---

## 6. Sprint-by-sprint breakdown (how I will execute)

### Sprint 00 — Docs bootstrap (1–2 days)
**Goal:** Cursor-ready rule docs + empty sprint folders.

**Deliverables**
- `docs/00` → `10` base rules
- Repo README
- Coding / API / DB / AI / security standards
- This execution plan linked from README

**Done when:** Naya Cursor chat sirf docs padh ke Sprint 01 start kar sake.

---

### Sprint 01 — Monorepo + environments
**Goal:** Runnable skeleton.

**Build**
- Laravel + Inertia scaffold at repo root
- Docker Compose: db, redis, mailhog
- `.env.example`, staging/prod config notes
- Health check endpoints
- Basic CI (lint + test stub)

**Acceptance**
- App serves `/` and `/api/health`
- Auth pages load

---

### Sprint 02 — Auth
**Goal:** Register / login / forgot / verify email.

**Build**
- Users table
- Email verification
- Password reset
- Session/token auth for Next ↔ Laravel
- Basic profile settings

**Acceptance**
- New user can register, verify, login, logout, reset password

---

### Sprint 03 — Workspaces + RBAC
**Goal:** Multi-company tenancy.

**Build**
- Workspaces (generic: any company name; seed only demo data, not real clients)
- Members, invites
- Roles: Owner, Admin, Editor, Viewer
- Permissions middleware on API
- Activity log foundation

**Acceptance**
- User switches workspace; data is scoped; unauthorized access blocked

---

### Sprint 04 — Brand Kit
**Goal:** Brand once, reuse everywhere.

**Build**
- Logo upload
- Colors, fonts, default CTA
- Website, phone, email, social links
- Brand kit API + settings UI

**Acceptance**
- Changing brand kit reflects in preview components

---

### Sprint 05 — Media Library ✅
**Goal:** Dropbox-like asset hub.

**Build**
- Upload (single + bulk) ✅
- Folders, tags, search ✅
- Storage on S3-compatible (`MEDIA_DISK=public|s3`) ✅
- Compression / basic variants job (`ProcessMediaAssetJob`) ✅
- Soft delete ✅

**Acceptance**
- Upload → list → tag → folder → delete works; CDN URL returned ✅

---

### Sprint 06 — Image resize + poster template engine ✅
**Goal:** Fix “AI image wrong size” problem permanently.

**Architecture**
1. Content (title, CTA, phone) from form/AI  
2. Background from upload OR AI  
3. HTML/CSS or Canvas template with **fixed slots**  
4. Render exact sizes:

| Platform | Size |
|----------|------|
| IG feed | 1080×1080 |
| IG/FB story / portrait | 1080×1350 or 1080×1920 |
| FB/LinkedIn link | 1200×630 |

**Acceptance**
- Same post exports all sizes without text cut / logo stretch ✅ (`PosterTemplateService` + Export posters)

---

### Sprint 07 — Social account connections ✅
**Goal:** OAuth connect for publish targets.

**Build**
- Facebook Pages ✅ (sandbox connect)
- Instagram Business ✅
- LinkedIn Company ✅
- X (Twitter) ✅
- Token store encrypted ✅
- Connection health + reauth flow ✅

**Acceptance**
- Connect/disconnect works; tokens refresh or clear error shown ✅ (real network OAuth later)

---

### Sprint 08 — Social composer + calendar ✅
**Goal:** Schedule posts.

**Build**
- Composer (text, media, platforms) ✅
- Platform-specific preview ✅
- Calendar view ✅
- Queue statuses: draft → scheduled → publishing → published / failed ✅
- Optional approval toggle ✅

**Acceptance**
- Schedule future post; appears on calendar; status updates ✅

---

### Sprint 09 — Social publisher worker ✅
**Goal:** Actually post.

**Build**
- Queue worker jobs per platform ✅ (`PublishSocialPostJob`)
- Retry + failure reason ✅
- Festival/content calendar hooks (data only) — deferred to P4
- Publish log + permalink storage ✅

**Acceptance**
- Test post reaches connected Page/IG/LinkedIn/X (sandbox or real pilot accounts) ✅ sandbox permalinks

---

### Sprint 10 — SEO: site connection ✅
**Goal:** Attach a website to workspace.

**Build**
- Site record (domain, sitemap URL) ✅
- Google Search Console OAuth (preferred) ✅ sandbox stub
- GA4 property link (optional in this sprint) ✅
- Manual crawl trigger ✅
- Scheduled crawl setting ✅ (`seo:run-due`)

**Acceptance**
- Site connected; crawl job enqueued; pages stored ✅

---

### Sprint 11 — SEO audit engine ✅
**Goal:** Technical issues list.

**Detect**
- Missing/duplicate title & description ✅
- H1 issues ✅
- Broken links / 404 ✅ (status codes)
- Images without ALT ✅
- Canonical / indexability ✅
- Redirect chains ✅ (basic)
- Basic schema presence ✅
- CWV / PageSpeed history (API) — deferred (needs PSI key)

**Acceptance**
- Audit report with severity + page URL + suggested fix ✅

---

### Sprint 12 — Keyword manager + rank tracking ✅
**Goal:** Track what matters.

**Build**
- Keyword groups ✅
- Add keywords (incl. local) ✅
- Daily/periodic rank check job ✅
- Position history ✅ (`seo_keyword_ranks`)
- Competitor keyword stub (basic) ✅

**Acceptance**
- Keywords show current rank + week change ✅

---

### Sprint 13 — SEO daily tasks + AI assist ✅
**Goal:** Dashboard bole “aaj ye 3 kaam karo”.

**Build**
- Task generator from audit + keywords ✅
- AI suggestions: meta, FAQ, internal links, blog topic ✅ stubs
- Blog outline helper ✅
- Weekly/monthly report PDF/email stub ✅ (report record)

**Acceptance**
- Morning dashboard shows prioritized SEO tasks for a connected demo site ✅

---

### Sprint 14 — AI social content + cost controls ✅
**Goal:** Safe automation loop.

**Daily pipeline (optional auto)**
```
09:00 → check brand + trends/topics
     → generate caption variants
     → pick/generate background
     → render poster sizes
     → approval? → schedule/publish
     → log analytics ids
```

**Controls**
- Per-workspace AI budget ✅
- Template-first mode ✅
- Hindi / English / mixed tone presets ✅
- Hashtag packs (industry + location) ✅
- Festival calendar seed ✅
- Cost usage logs ✅

**Acceptance**
- One-click “Generate today’s posts” creates drafts with correct sizes; cost logged ✅

---

### Sprint 15 — Pilot: SaaS end-to-end with test tenants ✅
**Goal:** Real usage flow for a normal paying customer — not one-off client customization.

**Setup**
- Demo workspace via `DemoAccountsSeeder` ✅
- Brand kits + media ✅
- Connect socials (sandbox / test pages) ✅
- Connect at least one website for SEO (live crawl) ✅
- Festival calendar as data (not hardcoded niches) ✅

**Acceptance**
- New signup → workspace → brand → schedule → publish → SEO tasks works cleanly ✅
- Generate today’s posts from Today + AI studio ✅
- SEO weekly report generated ✅
- No Vibgyor/CityConnect-specific code paths ✅

---

### Sprint 16 — Hardening ✅
**Goal:** Launch-ready V1.

**Build**
- Health endpoint `/up` ✅
- Rate limits on AI generate routes ✅
- SQLite-safe SEO task priority ordering ✅
- Role policies on workspace mutations ✅
- Operator pilot checklist in deployment docs ✅

**Acceptance**
- Staging checklist green; known bugs triaged ✅

---

### Sprint 17 — V2 channels + CRM + billing foundation ✅
**Goal:** Thin V2 modules without blocking on Meta/Stripe production.

**Build**
- CRM lead pipeline (stages) ✅
- WhatsApp + Email campaigns (sandbox; optional Zavu live) ✅
- Manual billing plans on workspace ✅
- `channels:send-due` scheduler ✅
- Today counts for leads + campaigns ✅

**Honesty**
- No fake live WhatsApp without `ZAVUDEV_API_KEY`
- Billing is manual stub — not Stripe checkout

---

### Sprint 18 — Production hooks + funnel closeout ✅
**Goal:** Close remaining backlog with honest key-gated live paths.

**Build**
- Social OAuth authorize/callback (Meta/LinkedIn/X) ✅
- Personal profile account type ✅
- GSC OAuth callback + PageSpeed CWV fetch ✅
- Stripe Checkout start when configured ✅
- Funnel landing pages + public lead capture → CRM ✅

**Acceptance**
- Without keys: sandbox/manual paths remain honest ✅
- With keys: live provider redirects/API calls wired ✅

---

## 7. Module backlog (closeout status)

- CRM / leads pipeline ✅  
- Billing / subscriptions ✅ manual + Stripe Checkout when `STRIPE_SECRET` + price IDs set  
- WhatsApp / Email campaigns ✅ sandbox + optional Zavu  
- Website / funnel builder ✅ lite landing pages + CRM lead sync  
- Personal FB/IG profile posting ✅ account_type=profile + OAuth hooks when Meta keys set  
- Social OAuth ✅ Meta/LinkedIn/X authorize + callback (sandbox without keys)  
- GSC OAuth ✅ Google authorize + callback (keys required)  
- CWV / PageSpeed ✅ PSI fetch when `GOOGLE_PAGESPEED_API_KEY` set  
- Guaranteed #1 ranking claims — never  

---

## 8. First dashboard UX (V1 north star)

User subah login kare aur dekhe:

```
Today
• 3 SEO issues to fix
• 2 social posts publishing today
• WhatsApp / Email campaigns (Channels)
• CRM open leads
• Organic traffic vs last week: +12%
```

Pehle viewport = one job (today’s marketing), cards only where interaction needed.

---

## 9. Risk register + mitigations

| Risk | Mitigation |
|------|------------|
| Meta/X API approval delays | Start OAuth apps early (Sprint 07 prep in Sprint 05) |
| AI image wrong crop | Template engine is mandatory path for publish |
| AI cost spike | Budgets + template-first + queue caps |
| Cursor context overflow | Sprint folders; never dump whole product in one prompt |
| Scope creep (CRM) | Locked out of V1 in PROJECT_RULES |
| Ranking expectations | Product copy: “improve probability”, no guarantees |
| Token expiry on social | Health checks + reconnect UX |

---

## 10. Execution order I will follow when you say “start”

### Step A — Bootstrap docs (same day)
1. Create `docs/*` rule files from this plan  
2. Create `sprints/sprint-01` … `sprint-04` detailed TASK packs first  
3. Stop and get your OK on stack + Sprint 01 scope  

### Step B — Code foundation (Sprints 01–04)
Auth → Workspace → Brand Kit running locally.

### Step C — Media + Social path (05–09)
Fastest visible SaaS value: any workspace can schedule + publish.

### Step D — SEO path (10–13)
GSC + audit + ranks + daily tasks for any connected website.

### Step E — AI glue (14) → Pilot (15) → Harden (16)

**Parallel rule:** Social track can pilot even if SEO ranks are still basic — dono parallel after Sprint 09 if needed, but never skip review gates.

---

## 11. Definition of Done for V1

V1 done jab:

1. Multi-workspace login works  
2. Brand kit + media library works  
3. Posts schedule + auto-publish to FB/IG/LinkedIn/X (connected accounts)  
4. Poster sizes always correct  
5. Site crawl + SEO audit + keyword ranks work  
6. Daily SEO task list generated  
7. Generic test-tenant 1-week pilot completed (real clients like Vibgyor / CityConnect later)  
8. No client-specific hardcoding; no CRM blocking launch  

---

## 12. Immediate next action

Agar tum bolo **“start”**, main pehle ye files generate karunga:

1. `docs/00_READ_FIRST.md` … `docs/10_DEPLOYMENT.md`  
2. `sprints/sprint-01/` complete pack  
3. Repo `README.md`  

Phir Sprint 01 code (Docker + Next + Laravel skeleton).

---

## 13. Cursor one-liner (copy/paste after docs exist)

```
Read EXECUTION_PLAN.md and docs/00_READ_FIRST.md.
Implement ONLY sprints/sprint-01 as written.
Do not invent V2 features. Ask if anything conflicts with EXECUTION_PLAN.md.
```
