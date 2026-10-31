# Feature parity checklist — after Phase 3

The complete Revision 27 interface is restored inside the full-stack application. **Live** = reads/writes the PHP API and MySQL. **Restored — prototype** = the original Revision 27 screen and behaviour, running on sample data stored in the user’s browser, with a visible label on every such page naming its planned backend phase.

| Module | Status | Notes |
|---|---|---|
| Sign-in, sign-out, password reset page | Live (PHP/MySQL) | React screens; real sessions |
| Role-based navigation and permissions | Live | Real account role drives the Revision 27 navigation and guards; demo role switching disabled |
| Contacts — database list, search, type filter, pagination | Live | Restored Contacts screen, “Database contacts” tab |
| Contact create / edit / delete with server validation | Live | Prototype-style dialogs; saved to MySQL; conflict and duplicate checks |
| Contact detail (database contacts) | Live — partial | Details, edit, delete, phone-app link. Trips, communication, documents, tasks and automations for database contacts arrive in Phases 3–7 |
| Demo CRM records (full 11-tab profile, relationships, import, duplicates) | Restored — prototype | Second tab of Contacts; browser-local sample data |
| Staff accounts (list, deactivate, reactivate, reset link) | Live | New Back office › Settings page |
| Dashboard | Restored — prototype | Figures from sample data; labelled |
| Trips: board, list, calendar, search, filters, create, edit, archive | **Live (Phase 3)** | Database trips only; sample trips no longer shown here |
| Trip detail: overview, travelers, proposal, itinerary, notes | **Live (Phase 3)** | Other sections labelled with their phase |
| Itinerary editor: options, days, activities, drag/reorder, publish, version history | **Live (Phase 3)** | Whole-draft save with revision check |
| Client portal (Primary / Full / Limited): published itinerary, option choice, approve, request changes, comments | **Live (Phase 3)** | Travelers see only their published, non-archived trips |
| Group trips, landing page, registrations, sync | Restored — prototype | Phase 4 |
| Suppliers, bookings | Restored — prototype | Phase 4 |
| Forms & intake, documents | Restored — prototype | Phase 4 |
| Payments, invoices, commission reconciliation, payouts, commission invoices | Restored — prototype | Phase 5 |
| Tasks, calendar, scheduling | Restored — prototype | Phase 6 |
| Automations (engine, builder, simulator, demo clock), 20 workflows | Restored — prototype | Phase 6 (needs hosting cron) |
| Email inbox, Smart inbox, outbox, templates | Restored — prototype | Phase 6 (needs SMTP/IMAP) |
| Library, content hub, itinerary templates | Restored — prototype | Phases 3–6 |
| AI workspace (deterministic) | Restored — prototype | Phase 8, rule-based only |
| Softphone panel, dialer, history, voicemail, recordings, analytics, settings | Restored — prototype (simulated) | Phase 7; free WebRTC + tel: handoff; voicemail/recording unavailable at $0 |
| Analytics and report builder | Restored — prototype | Phase 8 |
| Settings, agency details, tags, staff & permissions matrix, integrations, terms, help center | Restored — prototype | Settings stored in the browser until their phase |

## Known gaps (honest)
- Bookings, payments, group trips and other prototype modules still use the prototype’s sample trips and clients until Phases 4–5.
- Prototype sample data lives in each browser’s localStorage (fictional data only). Staff sharing a browser see the same sample data; nothing is shared between computers. Database contacts are never copied into browser storage (tested).
- The live contact detail shows details, edit and delete; the richer CRM tabs exist for demo records only until their phases.
- “Staff & permissions” (matrix and role previews) is still the prototype page; actual staff management is the live “Staff accounts” page.
- Creating staff accounts is still done with the CLI or SQL tools from Phase 2 (no UI yet).
