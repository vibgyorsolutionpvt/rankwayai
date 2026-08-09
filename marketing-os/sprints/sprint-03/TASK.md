# Sprint 03 — Workspaces + RBAC

## Goal

Multi-tenant workspaces with roles: Owner, Admin, Editor, Viewer.

## In scope

- Create/list/switch workspace
- Invite member (email invite or add-by-email local)
- Role permissions middleware/policies
- Activity log foundation (basic)

## Out of scope

- Brand kit fields beyond workspace name (Sprint 04)
- Billing

Hard rule: all future tenant tables will use `workspace_id`.
