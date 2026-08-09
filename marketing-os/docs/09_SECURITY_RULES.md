# 09 — Security Rules

## Must

- Hash passwords (Laravel defaults)
- Encrypt social OAuth tokens at rest
- HTTPS in staging/production
- CSRF protection for cookie SPA auth
- Authorize every workspace-scoped action
- Rate-limit auth and AI endpoints
- Validate/sanitize all uploads (type, size)
- Never log secrets or raw tokens

## Uploads

- Allowlist MIME types
- Store outside public web root when possible; serve via signed URLs
- Virus scan later if needed; at least extension + MIME checks in V1

## Dependencies

- Keep Composer/npm audits in CI when feasible
- No committing `vendor/` or `node_modules/`

## Privacy

- Tenant data isolation is a P0 bug if broken
- Soft-deleted content not visible cross-tenant
