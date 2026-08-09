# 07 — API Standards

## Base

- Prefix: `/api`
- JSON request/response
- Auth: Sanctum (Bearer token and/or SPA cookie — decide in Sprint 02 and document)

## Response shape

Success:

```json
{
  "data": {},
  "meta": {}
}
```

Error:

```json
{
  "message": "Human readable",
  "errors": {
    "field": ["Validation message"]
  }
}
```

## Status codes

| Code | Use |
|------|-----|
| 200 | OK |
| 201 | Created |
| 204 | No content |
| 401 | Unauthenticated |
| 403 | Forbidden (wrong workspace / role) |
| 404 | Not found |
| 422 | Validation |
| 429 | Rate limit |
| 500 | Server error |

## Versioning

- No URL versioning in V1 (`/api/v1`) unless we hit a breaking need
- Additive changes preferred

## Health

- `GET /api/health` → `{ "status": "ok", "time": "..." }` (public)

## Workspace context

- After Sprint 03: require workspace membership for tenant routes
- **Primary UI:** session-scoped active workspace via Inertia `/workspaces`
- **Optional API:** nested `/api/workspaces/{id}/...` or `X-Workspace-Id` header
