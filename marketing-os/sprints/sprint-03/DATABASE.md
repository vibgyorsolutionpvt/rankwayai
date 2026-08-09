# Sprint 03 — Database

Tables (indicative):

- `workspaces` (id, name, slug, timestamps, soft deletes)
- `workspace_user` (workspace_id, user_id, role, timestamps)
- `activity_logs` (optional minimal: workspace_id, user_id, action, meta JSON)

Indexes on workspace_id + user_id.
