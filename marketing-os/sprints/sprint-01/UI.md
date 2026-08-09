# Sprint 01 — UI

## Pages

1. `/` — simple product shell
   - Brand/name: Project Atlas (or Marketing OS)
   - Short line: multi-tenant SEO + Social SaaS
   - Link to `/login`
   - Show API health status fetched from `NEXT_PUBLIC_API_URL/api/health` (ok / down)

2. `/login` — placeholder only
   - Email + password fields disabled or non-submitting
   - Note: “Auth ships in Sprint 02”

## Design

- Minimal, clean, light background
- No fake dashboards, stats, or marketing card grids
- Mobile-friendly single column

## Env

`apps/web/.env.example`:

```
NEXT_PUBLIC_API_URL=http://localhost:8000
```
