# 00 — Read First

## What this product is

**Project Atlas** is a **generic multi-tenant SaaS**.

- Any business can register and use it.
- Each customer gets workspaces (companies/brands).
- V1 = SEO OS + Social Media OS + Brand Kit + Media Library + AI assist.
- Vibgyor / CityConnect / any other client = **later tenants**, not special code paths.

## What this product is not (V1)

- Not a CRM
- Not a website / funnel builder
- Not WhatsApp or Email marketing (V2)
- Not a “guarantee #1 on Google” tool
- Not a single-client custom project

## How to work in this repo

1. Read `docs/MASTER_SPEC_TASK_LIST.md` — **your** master-spec checklist + UI map (verify here).
2. Read `EXECUTION_PLAN.md` once for the big picture (if present).
3. Open **only** the current sprint folder under `sprints/` when using sprint packs.
4. Follow `TASK.md` + `ACCEPTANCE.md` for sprints.
5. Do not invent V2 features outside the active checklist item.
6. Do not hardcode any client brand, domain, or niche calendar.
7. After acceptance passes, stop and wait for the next sprint unlock.
8. **One Laravel app at the repo root** (Inertia + React). Docs live in `docs/` + `sprints/`.


## Current priority order

Foundation → Media → Social publish → SEO engine → AI glue → Pilot

## Golden prompts for Cursor

```
Read docs/00_READ_FIRST.md, docs/01_PROJECT_RULES.md, docs/05_CODING_STANDARDS.md,
and the current sprint pack. Implement ONLY that sprint.
```
