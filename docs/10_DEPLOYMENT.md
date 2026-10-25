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
10. Optional live WhatsApp/Email: set `ZAVUDEV_API_KEY`; Billing plan is manual until Stripe
11. For live email campaigns, connect one or more providers in Settings → Providers with an API token and verified sender: Brevo (300/day), Resend (100/day, 3,000/month), MailerSend (100/day, 500/month), or Sender (15,000/month). Leave Email provider set to Automatic free-provider rotation to try configured free API providers in order and continue with the next provider when the app's tracked daily/monthly allowance is reached or the provider returns a quota/rate-limit response. The app tracks its own sends by API credential; sends made directly in a provider dashboard are not visible locally and may cause the provider to refuse sends earlier. SMTP and Zavu remain available as explicit provider choices and are not included in free-tier rotation. Provider quotas and API eligibility can change; verify the current account terms and sender/domain status with each provider. MailerLite's campaign APIs target its own subscriber lists, while EmailOctopus does not expose a direct one-to-one send endpoint, so neither is suitable for this CRM recipient-by-recipient fallback path.
