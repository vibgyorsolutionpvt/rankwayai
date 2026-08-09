# 05 — Coding Standards

## General

- Prefer clear names over clever abstractions
- Small PRs / sprint-sized changes
- No drive-by refactors outside the current sprint
- Delete unused code you introduced; don’t leave TODOs that block acceptance

## React / Inertia

- React / Inertia pages in `resources/js/Pages`
- Prefer Inertia form helpers (`useForm`, `router`) over raw fetch

## PHP / Laravel

- PSR-12
- Form Requests for validation
- Policies / gates for workspace authorization
- Jobs for slow work (crawl, publish, resize, AI)
- Prefer Eloquent scopes: `forWorkspace($id)`
- API Resources only for `/api` JSON routes

## Git

- Commit messages: why-focused, short
- Do not commit `.env`, secrets, or large binaries

## Testing minimum

- At least one happy-path feature test per sprint
- Critical auth/tenant boundaries get tests
