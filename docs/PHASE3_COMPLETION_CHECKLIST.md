# Phase 3 completion checklist

| # | Required work | Status | Evidence / notes |
|---|---|---|---|
| 1 | Audit tables/migrations for trips, travelers, itineraries, days, activities, versions, approvals, comments | Done | Existing 003 tables reused; additive `004_phase3.sql` adds creator, notes, tags, archive time, published-version pointer, selected option, draft revision, itinerary metadata, version hash, approval author. No existing rows changed. |
| 2 | Secure PHP REST endpoints | Done | `TripController`, `ItineraryController`, `PortalController`; see `docs/API_PHASE3.md` |
| 3 | Authenticated, role-based access on every endpoint | Done, tested | trips.view/edit/delete/publish, staff.manage, portal.access; row scoping (owner, creator, collaborator, or trips.view_all); traveler scoping by linked contact |
| 4 | Frontend adapter loads real records | Done, tested | `web/src/prototype/trips.ts` feeds the unchanged Revision 27 screens |
| 5 | Create, edit, view, archive, search, filter, publishing | Done, tested | Create dialog, status/field edits, board/list/calendar filters and search, archive/restore, publish/unpublish, version history |
| 6 | Trips linked to real contacts and travelers | Done, tested | Primary client on creation; Travelers section adds database contacts with Primary/Full/Limited access; contact page lists its trips |
| 7 | Published versions connected to the authorized client portal | Done, tested | Travelers sign in and see only published, non-archived trips they are on |
| 8 | Unpublished edits never alter the published client version | Done, tested | Publish freezes a sanitized snapshot (internal notes removed); portal reads only snapshots |
| 9 | Replace sample data only where integration passed tests | Done | Trips board/list/calendar, trip detail (core sections), itinerary editor and portal use database trips only |
| 10 | Later-phase modules stay labelled and operational | Done, tested | Trip sections Bookings, Payments, Insurance, Service fees, Documents, Emails, Forms, Tasks, Automations, Workflows, Activity, Group trip show a phase label; group trips, bookings, finance, automations and other modules remain prototype |

## Known limitations (to review before approval)
- Itinerary drafts are saved as a whole document with a revision number. If two people edit the same itinerary at the same moment, the second save is refused and the screen reloads the latest version (their last few seconds of edits are lost).
- “Restore” from version history restores the client snapshot, which by design has no internal notes.
- Activity images larger than about 400 KB are not saved (documents and images are Phase 4).
- Custom trip statuses added under Settings are rejected by the server; the nine standard statuses are supported.
- Prototype automations and workflows (Phase 6) are switched off for database trips so nothing simulated is attached to real records.
- Group trips, and moving a database trip into a group, remain Phase 4.
- Staff can preview the portal but cannot approve or comment on behalf of travelers (by design).
- Traveler logins are created through `POST /api/contacts/{id}/portal-account` (tested); a button in the contact screen is not built yet.

## Not started (awaiting approval)
Phase 4 (suppliers, bookings, group trips, forms, documents) has not been started.
