# 04 — Database Rules

## Principles

1. Every tenant-owned table has `workspace_id` (nullable only for global/system tables).
2. Soft deletes preferred for user content (`deleted_at`).
3. Use ULIDs/UUIDs for public IDs if exposed in URLs; internal auto-increment OK behind the API.
4. Foreign keys required for ownership relations.
5. Indexes on `workspace_id`, status, scheduled times, and lookup keys.
6. Migrations are the only schema source of truth.
7. No raw client-specific seed data in production migrations.

## Naming

- Tables: `snake_case` plural (`workspaces`, `social_posts`)
- Columns: `snake_case`
- Booleans: `is_` / `has_` prefix where clear
- Timestamps: `created_at`, `updated_at`, `deleted_at`

## Multi-tenancy

- Resolve workspace from auth context / header / route — never trust client-only filters without auth check.
- Queries must always scope by workspace for tenant data.

## Seed data

- Local: demo workspace + demo user only
- Never embed real client brands as required seeds
