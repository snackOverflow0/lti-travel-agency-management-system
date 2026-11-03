# Readiness scorecard — Phase 9 (evidence-based)

| Category | Max | 8.1 (batches 1–3) | Now | Evidence and deductions |
|---|---:|---:|---:|---|
| Frontend and UX | 10 | 8 | 9 | Every route live, routed or accurately labelled; false demo/browser-only claims removed; keyboard-focus defect fixed. −1: several features intentionally unavailable |
| Database persistence | 20 | 18 | 20 | Settings, roles, tags, terms, travelers, support and library persist and reload; verified in MySQL |
| Backend API integration | 15 | 14 | 15 | Settings/admin APIs added and tested; every screen uses the API |
| Core business workflows | 15 | 13 | 13 | All core workflows usable in UI. −2: unattended scheduler and SMTP unverified on host |
| Security and access control | 15 | 12 | 13 | Server-enforced booking controls; owner-only role editing with safeguards; router hardened. −2: HTTPS unverified on host; CSP needs 'unsafe-inline' |
| Financial integrity | 10 | 9 | 9 | Unchanged; −1 no external accounting reconciliation |
| Performance and reliability | 5 | 4 | 4 | −1: N+1 list pattern; intermittent test P9-05 |
| Hosting and deployment readiness | 5 | 1 | 2 | Verified frontend deploy and release builder with manifest. −3: host unverified |
| Testing and documentation | 5 | 5 | 5 | 309 automated tests in the final run (308 pass); UAT report, sign-off template |
| **Total** | **100** | **84** | **90** | |

## Release gate: NOT APPROVED
Blockers B1–B4 in PHASE9_BLOCKERS.md (hosting unverified; host restore untested; limitations not yet accepted in writing; staff UAT not signed). A score does not override these.
