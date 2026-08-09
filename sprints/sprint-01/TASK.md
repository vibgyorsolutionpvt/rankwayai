# Sprint 01 — Monorepo + Environments

## Goal

Runnable SaaS skeleton: Next.js web + Laravel API + local env + health checks + CI stub.

## In scope

- `apps/web` Next.js (App Router) + TypeScript scaffold
- `apps/api` Laravel scaffold
- Root `docker-compose.yml` for MySQL, Redis, Mailhog (apps may run native if Docker unavailable)
- `.env.example` for web + api
- `GET /api/health`
- Minimal web home + placeholder login page
- GitHub Actions CI stub (api php syntax / web typecheck if possible)
- Root README already exists — keep it accurate

## Out of scope

- Real auth (Sprint 02)
- Workspaces (Sprint 03)
- Any SEO/Social/AI features
- Production deploy

## Implementation notes

- Prefer Laravel installer / `composer create-project laravel/laravel`
- Prefer `create-next-app` with TypeScript, App Router, ESLint
- CORS: allow `http://localhost:3000` to call API
- If Docker is missing on the machine, native `php artisan serve` + `npm run dev` must still work
- Use MySQL in compose; for zero-deps smoke, SQLite is acceptable **only** as documented local fallback

## Deliverables checklist

- [ ] `apps/web` runs on :3000
- [ ] `apps/api` runs on :8000
- [ ] Health endpoint returns OK
- [ ] Placeholder `/login` page renders
- [ ] `docker-compose.yml` present for db/redis/mailhog
- [ ] CI workflow file present
