# Sprint 01 — API

## Endpoints

### `GET /api/health` (public)

Response `200`:

```json
{
  "data": {
    "status": "ok",
    "service": "marketing-os-api",
    "time": "2026-08-03T00:00:00+00:00"
  }
}
```

### Optional `GET /api/health/ready`

May include DB/redis ping later; not required for acceptance if basic health exists.

## CORS

Allow origin `http://localhost:3000` with credentials support prepared for Sprint 02.

## Not in this sprint

- Auth routes
- CRUD resources
