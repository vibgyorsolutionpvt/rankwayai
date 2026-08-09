# 06 — UI Design System

## Product UI goals

- First screen after login = workspaces (foundation); later **Today** (SEO + posts)
- Brand-first landing; one composition, not a dashboard dump
- Cards only when they hold an interaction

## Visual direction (locked)

**Atlas Signal** — cool mist surfaces + ink navy + electric teal signal.

| Token | Value |
|-------|--------|
| Ink | `#0B1220` |
| Mist | `#F3F5F8` |
| Signal | `#0E9F90` |
| Line | `#D5DCE6` |

- Display font: **Syne**
- Body font: **Plus Jakarta Sans**
- Avoid: AI purple gradients, cream+terracotta, emoji clutter, glow spam

## Motion

- `fade-up` / `fade-in` on page sections
- Soft `float` blobs on marketing/auth shells
- Button hover lift (`-translate-y` + signal shadow)

## Layout

- App shell: left ink sidebar (desktop) + sticky top bar
- Panels: `.atlas-panel`
- Forms: `.atlas-input` / `.atlas-select`
- Primary / secondary / danger buttons in `resources/js/Components`
