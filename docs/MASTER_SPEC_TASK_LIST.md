# RankwayAI — Master Spec Task List (YOUR plan)

> Source: **RANKWAYAI — COMPLETE CURSOR MASTER IMPLEMENTATION SPECIFICATION** (chat paste).
> This file is the verification checklist. Status is honest against the current codebase.
> **There are no official “Phase 1 / Phase 2” names in your spec.** Those labels were agent-invented from §62 FINAL MVP only.

Status legend:
- `[x]` done enough to use in UI
- `[~]` partial / existing-before / MVP-lite (not full spec)
- `[ ]` not built

---

## Where to open in UI (local)

After login, sidebar:

| Spec item | Sidebar | URL |
|-----------|---------|-----|
| Business Profile | **Account → Settings** | `/settings` |
| Brand Kit | **Work → Brand** | Brand edit |
| Business Card / Brochure / Itinerary / Quotation | **Work → Studio** | `/studio/...` |
| Lead inbox + score + follow-up | **Sell → Leads** | `/crm` |
| Basic analytics | **Sell → Analytics** | `/analytics` |
| WhatsApp | **Sell → WhatsApp** | `/whatsapp` |

Public share examples:
- Card: `/c/{token}`
- Brochure: `/b/{token}`
- Quotation: `/q/{token}` (only if status = **Sent** or **Accepted** + public on)

If **Studio** / **Analytics** missing from sidebar:
1. Hard refresh (Vite / `composer run dev` running).
2. Settings → workspace modules — enable **Studio** + **Analytics**.
3. Platform admin menus — ensure those keys are enabled.
4. Run migrations: `php artisan migrate`

---

## §62 FINAL MVP workflow (your production-ready cut)

This is the only “must ship first” order in your spec.

| # | Spec step | Status | UI / notes |
|---|-----------|--------|------------|
| 1 | Workspace | [x] | Existing |
| 2 | Business Profile | [x] | Settings → Workspace profile fields |
| 3 | Brand Kit | [x] | Brand (+ fonts/tone/header-footer extended) |
| 4 | Business Card | [x] | Studio → Business cards (templates, PDF, share, QR) |
| 5 | Brochure | [x] | Studio → Brochures |
| 6 | AI Itinerary | [x] | Studio → Itineraries (AI + template fallback) |
| 7 | Custom Itinerary Editor | [x] | Itinerary edit (days/reorder/pricing) |
| 8 | Quotation | [x] | Studio → Quotations (+ from itinerary button) |
| 9 | PDF | [x] | Quotation/Brochure/Card PDF download |
| 10 | Share Link | [x] | `/c` `/b` `/q` tokens |
| 11 | Lead Form | [ ] | Spec §21 — **not built** (funnels exist separately) |
| 12 | Lead Inbox | [~] | CRM exists; stages not full Warm/Hot/Quotation Sent set |
| 13 | AI Lead Scoring | [x] | CRM lead → **Score lead** |
| 14 | WhatsApp | [~] | Existing WhatsApp module; not full “quote share automation” |
| 15 | AI Follow-up | [~] | CRM → **Suggest follow-up** (message + due); **not** full automation engine §26 |
| 16 | Basic Analytics | [x] | Analytics sidebar page |

**Honest gap vs your §62:** Lead Form + Landing capture into CRM is still missing. Follow-up is suggestion-only, not Trigger→Wait→Auto-send.

---

## Full master spec sections (your numbered plan)

### Foundation / studio / travel

| § | Title | Status | Where / gap |
|---|-------|--------|-------------|
| 5 | Business Profile | [x] | Settings |
| 6 | Brand Kit | [x] | Brand |
| 7 | Asset Management | [~] | Media library existed; not full “all asset types” redesign |
| 8 | Template Engine | [ ] | Templates still feature-local (card/brochure keys), not global engine |
| 9 | Business Card Builder | [x] | Studio → Cards |
| 10 | Brochure Builder | [x] | Studio → Brochures |
| 11 | Catalogue Builder | [ ] | Not started |
| 12 | AI Document Studio | [ ] | No central “create any doc” studio |
| 13 | Travel Vertical entities | [ ] | No Destination/Hotel/Package models yet (itinerary JSON only) |
| 14 | AI Itinerary Builder | [x] | Studio → Itineraries |
| 15 | Custom Itinerary Editor | [x] | Same |
| 16 | Itinerary Pricing | [x] | Edit → pricing block |
| 17 | Quotation Builder | [x] | Studio → Quotations |
| 18 | Proposal Builder | [ ] | Not started |
| 19 | Proposal Analytics | [ ] | Not started |

### Leads / sales / channels

| § | Title | Status | Where / gap |
|---|-------|--------|-------------|
| 20 | Lead Generation layer | [~] | Partial (CRM + funnels + WA); not unified |
| 21 | Lead Capture Forms | [ ] | Not started |
| 22 | Landing Page Builder | [ ] | Not started (old funnels ≠ this) |
| 23 | Lead Inbox | [~] | CRM; stage set incomplete vs spec |
| 24 | AI Lead Scoring | [x] | CRM lead page |
| 25 | AI Conversation Assistant | [ ] | Not started |
| 26 | Follow-up Automation | [ ] | Only suggested message; no engine |
| 27 | WhatsApp | [~] | Existing module |
| 28 | Email | [~] | Channels/campaigns partial; not quote/proposal email |
| 29 | SMS | [ ] | Not started |
| 30 | Marketing → Lead connection | [ ] | Not wired end-to-end |
| 31 | Campaign Attribution | [ ] | Not started |
| 32 | Customer Management | [ ] | Won stage only; no Customer entity |
| 33 | Booking / Conversion | [ ] | Not started |
| 34 | Payment Tracking | [ ] | Not started |

### Analytics / platform

| § | Title | Status | Where / gap |
|---|-------|--------|-------------|
| 35 | Analytics | [~] | Basic leads/quotes/assets page only |
| 36 | Business Asset Analytics | [~] | Counts on cards/brochures + analytics rollup |
| 37 | Notification System | [ ] | Not started |
| 38 | Task & Reminder System | [ ] | Not started |
| 39 | PDF Engine | [~] | DomPDF per feature; not one central service |
| 40 | Shareable Document Engine | [~] | Token links; no password-protect |
| 41 | QR System | [~] | Card QR only |
| 42 | Search | [ ] | Not started |
| 43 | Version History | [ ] | Not started |
| 44 | Duplicate / Clone | [~] | Card/Brochure/Itinerary/Quotation duplicate |
| 45 | Multi-language | [ ] | Not started |
| 46 | AI Safety / Guardrails | [~] | Partial patterns; not systematic |
| 47 | AI Usage / Credits | [~] | Existing wallet + some logging |
| 48 | Billing / Plan Limits | [~] | Existing billing; not all new limits |
| 49 | Role & Permissions | [~] | Existing roles; not extended sales roles |
| 50 | Admin Panel | [~] | Existing platform admin |
| 51 | Template Library | [ ] | Not started |
| 52 | White-label Foundation | [ ] | Not started |
| 53 | API / Webhook Foundation | [ ] | Not started |
| 54 | Security | [~] | Workspace checks on new routes; ongoing |
| 55 | Audit Log | [ ] | Not started |
| 56 | Background Jobs | [~] | Existing queues; not all heavy ops moved |
| 57 | Error Handling | [~] | Flash success/error; not consistent everywhere |
| 58 | Performance | [~] | Ongoing |
| 59 | Testing | [~] | Feature tests for new studio/CRM pieces |
| 60 | Multi-tenant security tests | [~] | Some isolation tests added |
| 61 | Mobile responsiveness | [~] | Layouts responsive; needs real device pass |
| 62 | Final MVP | [~] | See table above — Lead Form still open |
| 63 | Travel MVP example | [~] | Flow possible except enquiry form → lead |
| 64 | Future verticals | [ ] | Later |
| 65–66 | Positioning / process | — | Process notes |

---

## Agent-invented “Phase 1.x” (DO NOT treat as your plan)

Kept only so you can audit what was claimed:

| Agent label | Mapped to your § | Claim |
|-------------|------------------|-------|
| 1.1 Business Profile | §5 | Done |
| 1.2 Brand Kit | §6 | Done |
| 1.3 Business Card | §9 | Done |
| 1.4 Brochure | §10 | Done |
| 1.5 AI Itinerary | §14–16 | Done |
| 1.6 Quotation | §17 + PDF/share | Done |
| 1.7 Scoring + follow-up + analytics | §24 + lite §26 + lite §35 | Done (lite) |

Going forward: work should be tracked **only by § numbers in this file**, not agent phases.

---

## Next work (recommended = remaining §62 gaps)

1. **§21 Lead Capture Forms** (+ wire into CRM)
2. **§22 Landing** or minimal public enquiry on quotation/itinerary share
3. **§23** expand CRM stages to match spec
4. **§26** real follow-up automation (only after forms work)
5. **§18 Proposal** (after quotation loop is solid)

Update this file when a § changes status. Do not mark `[x]` without a UI path above.
