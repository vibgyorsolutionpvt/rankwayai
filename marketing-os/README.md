# Project Atlas (Marketing OS)

Multi-tenant SaaS for **SEO + Social Media** marketing automation.

**Stack:** Laravel + Inertia (React) — one app at the repo root.

## Start here

1. Read [EXECUTION_PLAN.md](./EXECUTION_PLAN.md)
2. Read [docs/00_READ_FIRST.md](./docs/00_READ_FIRST.md)
3. Implement **one sprint at a time** from `sprints/`

## Local quick start

```bash
cp .env.example .env
composer install && php artisan key:generate && php artisan migrate
npm install

composer run dev
```

Open: **http://localhost:8000**

**Client** (`info@vibgyorsolution.com` / `Password1!`) lands on **Today**:
- Brand · Media · Social · SEO · Workspace

**Superadmin** (`superadmin@atlas.test` / `Password1!`) lands on **Platform**.

## Cursor rule

Never prompt: “Build the whole Marketing SaaS.”

Always: read the current sprint pack only, finish it, pass acceptance, then unlock the next sprint.
