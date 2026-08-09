# 10 — Deployment

## Hostinger shared (SSH + cron)

Step-by-step with copy-paste commands: **[11_HOSTINGER_DEPLOY.md](./11_HOSTINGER_DEPLOY.md)**

Local prepare script:

```bash
chmod +x scripts/hostinger-prepare.sh
./scripts/hostinger-prepare.sh
# → /tmp/rankwayai-release.zip
```

---

## Targets

| Env | Notes |
|-----|-------|
| local | `composer run dev` (or `artisan serve` + `npm run dev`) |
| staging | Single VPS; shared Redis/MySQL |
| production | Secrets, backups, queue workers |
| Hostinger shared | See [11_HOSTINGER_DEPLOY.md](./11_HOSTINGER_DEPLOY.md) |

## Production (one app, no CORS)

```
https://app.example.com/     → Laravel (php-fpm) + Vite build assets
https://app.example.com/api  → same app (optional JSON)
```

Build assets: `npm ci && npm run build`

### Env

```
APP_URL=https://app.example.com
APP_ENV=production
```

## Required process components (production)

- `php-fpm` (or Octane later)
- Queue worker (`queue:work` or Horizon)
- Scheduler (`schedule:run` cron)
- Built Vite assets (`public/build`)
- Object storage for media

## Do not

- Deploy CRM or billing before V1 pilot
- Use production OAuth apps from local without separate credentials
- Create `public/brand/` on the server (breaks `/brand` route)

## V1 pilot checklist (operators)

1. `php artisan migrate --force` and seed demo + festivals (`db:seed`)
2. Health: `GET /up` returns 200
3. Login as `info@vibgyorsolution.com` / `Password1!` (local demo)
4. Brand kit → Media upload → Social connect (sandbox) → schedule/publish
5. SEO: Add + audit a reachable site; confirm issues match live crawl only
6. AI studio: Generate today’s posts; confirm drafts + cost log; budget blocks when exhausted
7. Queue + scheduler running (`social:publish-due`, `seo:run-due`)
8. Optional: set `OPENAI_API_KEY` and disable template-first only after budget is set
9. V2: add CRM leads → Channels campaign (sandbox OK without Zavu key)
10. Optional live WA/Email: set `ZAVUDEV_API_KEY`; Billing plan is manual until Stripe
