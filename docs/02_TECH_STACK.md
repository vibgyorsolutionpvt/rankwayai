# 02 — Tech Stack

## Locked for V1

| Layer | Choice | Notes |
|-------|--------|-------|
| App | Laravel 12 + PHP 8.2+ | Repo root |
| UI | Inertia.js + React + Vite | Same origin; no CORS |
| Auth | Session (Breeze) + optional Sanctum API | Web session primary |
| DB | MySQL 8 | Local: MySQL or SQLite for smoke |
| Cache / Queue | Redis | Jobs for crawl, publish, resize |
| Storage | S3-compatible (MinIO local) | Media library |
| Mail (local) | log / Mailhog | Dev only |
| Containers | Docker Compose (optional) | MySQL + Redis + Mailhog |

## Explicitly deferred

- GraphQL
- Microservices split
- Self-hosted LLMs (optional later)
- Mobile native apps

## Package policy

- Prefer Laravel / Inertia built-ins
- Add a package only when it removes real complexity
- Document why in the sprint `TASK.md` if adding a dependency

## Environments

| Env | Purpose |
|-----|---------|
| local | Developer machines |
| staging | Pre-prod pilot |
| production | Paying tenants |

Same codebase; env-driven config only.
