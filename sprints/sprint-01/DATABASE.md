# Sprint 01 — Database

No application business tables yet.

## Required

- Default Laravel migrations only (`users`, `password_reset_tokens`, `jobs`, `cache`, etc. as created by Laravel)
- Document connection settings in `apps/api/.env.example`:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=marketing_os
DB_USERNAME=marketing
DB_PASSWORD=secret
```

Optional local fallback:

```
DB_CONNECTION=sqlite
# database/database.sqlite
```

## Not in this sprint

- `workspaces`, brand kits, media, posts, SEO tables
