# Feature parity checklist — Revision 27 prototype → full-stack

Status key: **Implemented — tested** (automated test passed) · **Schema migrated** (tables exist and migrate cleanly; no API/UI) · **Not built** · **Hosting-dependent** (needs a capability not yet verified on Network Solutions) · **Unsupported at $0** (shown as unavailable, never simulated as real).

The Revision 27 HTML prototype remains the design and behaviour reference for every module not yet built.

| Feature | Phase | Status |
|---|---|---|
| Staff login / logout / secure sessions | 2 | Implemented — automated tests pass |
| Password hashing, rate limiting, CSRF, HttpOnly SameSite cookies | 2 | Implemented — automated tests pass |
| Roles (8) and server-side permissions | 2 | Implemented — automated tests pass (Finance, Advisor, Admin cases) |
| Account deactivation | 2 | Implemented — automated tests pass |
| Password reset | 2 | Administrator-assisted one-time link implemented and tested; email delivery pending SMTP verification |
| Audit log | 2 | Implemented for auth, staff and contacts — tested |
| Contacts: create, view, edit, soft delete, search, pagination | 3 | Implemented — automated tests pass |
| Contact record scoping (advisor sees assigned only; direct-ID access blocked) | 3 | Implemented — tested |
| Duplicate email detection, optimistic concurrency | 3 | Implemented — tested |
| Leads/lead status, birthdays, anniversaries, passport expiry fields | 3 | Stored and editable — tested for persistence |
| Staff directory UI | 2 | Implemented — tested (deactivate/reactivate, reset link) |
| Contact relationships, tags, preferences, loyalty | 3 | Schema migrated; API/UI not built |
| Contact import/export | 3 | Not built (Rev 27 prototype UI exists) |
| Trips, statuses, board/list/calendar | 3 | Schema migrated; API/UI not built |
| Itinerary builder, options, versions, publish | 3 | Schema migrated; API/UI not built |
| Client portal (traveler accounts, approvals, comments) | 3 | Traveler role exists; portal not built |
| Group trips, registrations, sub-trip sync | 4 | Schema migrated; not built |
| Suppliers, bookings | 4 | Schema migrated; not built |
| Forms and submissions | 4 | Schema migrated; not built |
| Documents (private storage) | 4 | Schema + protected storage folder; upload API not built |
| Quotations, invoices, PDF | 5 | Schema migrated; not built |
| Payment schedules, manual payments (bank transfer/cash), refunds | 5 | Schema migrated; not built — no payment processor by design |
| Commissions, reconciliation, payouts | 5 | Schema migrated; not built |
| Tasks | 6 | Schema migrated; not built |
| Automations, workflows, scheduled jobs (cron) | 6 | Schema migrated; engine not ported — hosting-dependent (cron unverified) |
| Email (SMTP), templates, scheduled email | 6 | Schema migrated; hosting-dependent (SMTP unverified) |
| Inbox sync (IMAP) | 6 | Hosting-dependent (IMAP unverified) |
| Softphone Mode A — browser-to-browser WebRTC | 7 | Schema (calls, signals, presence) migrated; not built; TURN not available at $0 (limitation) |
| Softphone Mode B — tel: handoff + manual call logging | 7 | Call button on contact detail uses tel: link; call logging not built |
| Voicemail / call recordings | 7 | Unsupported at $0 without a telephony provider — will be shown as unavailable; recording requires consent/retention policy |
| Reporting dashboards | 8 | Not built |
| AI assistant | 8 | Rule-based only (no paid AI); not yet ported |
| Calendar, appointments, scheduling | 6 | Schema migrated; not built |
| No emojis; no SaaS pricing/seat features | all | Verified in new app (E2E); none built |

**Honest summary:** Phases 0–2 foundations and the contacts slice of Phase 3 are working and tested. Most modules are not yet ported; their database tables exist.
