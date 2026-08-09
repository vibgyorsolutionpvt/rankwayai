# 03 — Folder Structure

```
marketing-os/
├── README.md
├── EXECUTION_PLAN.md
├── docker-compose.yml          # MySQL + Redis + Mailhog
├── .github/workflows/ci.yml
├── docs/                       # permanent rules
├── sprints/                    # sprint packs
├── app/                        # Laravel
├── resources/js/               # Inertia React pages
├── routes/web.php              # Primary UI
├── routes/api.php              # Optional JSON API
├── database/
├── tests/
├── artisan
├── composer.json
└── package.json
```

## Rules

- Business logic lives in Laravel (`app/` services, models, jobs).
- Inertia pages stay thin: forms + display; mutations via web controllers.
- Product docs stay in `docs/` and `sprints/` — not mixed into `app/`.
