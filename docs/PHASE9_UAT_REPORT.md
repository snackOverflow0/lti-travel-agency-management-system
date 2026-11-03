# Phase 9 — UAT report (automated evidence; staff UAT pending)

Run date 2026-11-02. Environment: isolated test database with synthetic records (Ubuntu 24.04, PHP 8.3.6, MariaDB 10.11.14, Chromium via Playwright). **Not** run on XAMPP/Windows, MySQL or Network Solutions. Tester: automated suites run by Claude; **agency staff UAT has not been performed** and is required for sign-off.

**Final full run: 308 of 309 automated tests passed.** The one failure (workflow-builder browser test, click timeout) passed 7/7 on two immediate reruns; it is recorded as open defect P9-05 (intermittent, cause undetermined).

## Module results
Columns: UI = driven through the restored screen; API = PHP response checked; DB = MySQL verified; Perm = role/permission checks; Persist = refresh and re-login; Mobile = 390 px.

| Module | Status | UI | API | DB | Perm | Persist | Mobile | Evidence |
|---|---|---|---|---|---|---|---|---|
| Authentication, sessions, reset | PASS | Yes | Yes | Yes | Yes | Yes | Yes | Phase 2 API SUMMARY 20/20; Security SECURITY SUMMARY 14/14 |
| Contacts and staff accounts | PASS | Yes | Yes | Yes | Yes | Yes | Yes | Phase 2; Restoration E2E SUMMARY 12/12 |
| Trips and itineraries (Scenario A) | PASS | Yes | Yes | Yes | Yes | Yes | Yes | Phase 3 API PHASE3 API SUMMARY 15/15; browser PHASE3 E2E SUMMARY 11/11; System flow F02–F03 |
| Client portal (Scenario A 11–14) | PASS | Yes | Yes | Yes | Yes | Yes | Yes | Phase 3; SEC-T06; F10 |
| Bookings and suppliers (Scenario B) | PASS | Yes | Yes | Yes | Yes | Yes | Yes | Phase 4 API PHASE4 API SUMMARY 29/29; browser PHASE4 E2E SUMMARY 7/7 ×3 runs |
| Group travel, forms | PASS | Yes | Yes | Yes | Yes | Yes | — | Phase 4b PHASE4B E2E SUMMARY 9/9 |
| Documents | PASS | Yes | Yes | Yes | Yes | Yes | — | Phase 4; SEC-T08 (unsafe uploads refused) |
| Content library (incl. migration) | PASS | Yes | Yes | Yes | Yes | Yes | Yes | Import LIBRARY IMPORT SUMMARY 8/8; browser LIBRARY E2E SUMMARY 7/7 |
| Quotes, invoices, payments (Scenario C) | PASS | Yes | Yes | Yes | Yes | Yes | Yes | Phase 5 PHASE5 API SUMMARY 26/26; Finance UI PHASE81 E2E SUMMARY 9/9; F05–F07 |
| Commissions, payables, finance reports | PASS | Yes | Yes | Yes | Yes | Yes | Yes | Phase 5; Finance UI |
| Tasks and calendar | PASS | Yes | Yes | Yes | Yes | Yes | Yes | Phase 6 PHASE6 API SUMMARY 28/28; Ops UI PHASE81 OPS E2E SUMMARY 7/7 |
| Email and automations (Scenario D) | PASS locally / BLOCKED on host | Yes | Yes | Yes | Yes | Yes | Yes | Comms UI PHASE81 COMMS E2E SUMMARY 6/7 (1 intermittent failure, P9-05); real local SMTP; host SMTP and cron unverified |
| Notifications | PASS | Yes | Yes | Yes | Yes | Yes | Yes | Ops UI; P9-04 focus fix |
| Softphone and calls (Scenario E) | PASS (logging, handoff) / NOT APPLICABLE (PSTN) | Yes | Yes | Yes | Yes | Yes | Yes | Phase 7 API PHASE7 API SUMMARY 21/21; browser PHASE7 E2E SUMMARY 7/7 |
| Dashboard and reports | PASS | Yes | Yes | Yes | Yes | Yes | Yes | Finance UI (dashboard equals SQL) |
| Settings and administration | PASS | Yes | Yes | Yes | Yes | Yes | Yes | Admin API ADMIN SUMMARY 8/8; Phase 9 UI PHASE9 E2E SUMMARY 9/9 |
| Security (negative tests) | PASS | — | Yes | Yes | Yes | — | — | SECURITY SUMMARY 14/14; XSS and CSP sweep PHASE8 E2E SUMMARY 9/9 |

## Scenario coverage
A client to trip — PASS (Phase 3, F01–F03, F10). B booking and supplier — PASS (Phase 4; no real external reservation is made). C financial — PASS (quote → invoice → partial payment → balance → reversal/verify → payable → commission → reports; synthetic data only). D communications — PASS locally; real delivery and unattended scheduling BLOCKED until host SMTP/cron verified. E calls — PASS for lookup, handoff, logs, notes, dispositions, callbacks, timeline; **browser PSTN calling unavailable**.

## Known gaps in this evidence
- Phase 9 browser check of booking-control enforcement found no booking in that database (server enforcement proven by API A02).
- The device phone-app handoff (`tel:`) needs a one-off manual check on a real device.
- No test has run on the production host.
