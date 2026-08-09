# 01 — Project Rules

## Product identity

- Name (internal): Project Atlas
- Type: Multi-tenant SaaS
- V1 modules: Auth, Workspaces/RBAC, Brand Kit, Media, Social Scheduler, SEO Engine, AI assist
- Out of V1: CRM, billing complexity, WhatsApp, Email, landing builders

## Non-negotiable rules

1. **No client hardcoding** — no Vibgyor/CityConnect special cases in code.
2. **Workspace scoping** — every business row belongs to a `workspace_id`.
3. **One sprint at a time** — do not pull future sprint work forward.
4. **Official APIs only** for social publish (Meta Business, LinkedIn Company, X).
5. **Template engine owns final post images** — AI may supply background/content, not final crop.
6. **No ranking guarantees** in UI copy or marketing strings.
7. **Ask before architecture changes** (stack, DB engine, monorepo shape).
8. **Secrets stay in env** — never commit API keys or OAuth secrets.

## Acceptance culture

A sprint is done only when `ACCEPTANCE.md` is fully checked and smoke-tested.

## Language

- Code, commits, API fields: English
- Product UI: English first (i18n later)
- Internal docs may be Hinglish where helpful
