# Test report — Phase 1 frontend restoration

Environment: Ubuntu 24.04, PHP 8.3.6 built-in server on **127.0.0.1:8000**, MariaDB 10.11, Chromium via Playwright 1.56. Each run uses a freshly migrated and seeded test database. Your machine uses PHP 8.2; the code avoids 8.3-only features but was not executed on 8.2 here.

## Restoration end-to-end tests — 12/12 passed
| # | Test | Result | Evidence |
|---|---|---|---|
| 1 | Opening http://127.0.0.1:8000/ shows sign-in; after login the full Revision 27 interface appears | PASS | 11 sections: Back office, Trips, Tasks, Library, Automate, Emails, Contacts, Calls, Commission, Reporting, Help |
| 2 | Every restored page renders without errors (owner); prototype pages labelled, live pages not | PASS | 73 pages rendered and correctly labelled |
| 3 | Trip sections, tabbed pages and views render (prototype level) | PASS | ok |
| 4 | LIVE: create a database contact in the restored Contacts screen; it is in MySQL, survives reload, and is retrievable after a new sign-in | PASS | contact 5: DB row, reload, sign-out/in, search, no localStorage copy |
| 5 | LIVE: edit and delete contacts from the restored screens (server validation shown) | PASS | edit + delete persisted |
| 6 | LIVE Staff accounts: administrator deactivates a user; that user cannot sign in | PASS | live staff management works |
| 7 | Real roles drive the interface: Finance has no Automate/Library/Calls, no create; demo role switching is disabled | PASS | nav filtered; role switch refused; API 403 |
| 8 | Advisor sees only assigned database contacts; another advisor’s contact by URL is refused | PASS | scoped + direct URL refused |
| 9 | Traveler account sees only the traveler portal and can sign out | PASS | portal only |
| 10 | Prototype modules stay interactive: create a trip, run the automation clock, open the softphone | PASS | prototype interactions intact |
| 11 | Mobile 390px: restored interface usable (drawer, dashboard, live contacts) with no horizontal overflow | PASS | ok |
| 12 | No emojis anywhere in the restored interface | PASS | clean |

## API integration tests (unchanged from Phase 2, re-run) — 20/20 passed
| # | Test | Result | Evidence |
|---|---|---|---|
| 1 | Unauthenticated requests are rejected (401) | PASS | 401 |
| 2 | Wrong password is rejected without revealing which field (401) | PASS | Email or password is incorrect |
| 3 | Owner signs in; /auth/me returns role and permissions | PASS | 27 permissions |
| 4 | State-changing request without CSRF token is rejected (403) | PASS | 403 |
| 5 | Validation errors return field messages (422) | PASS | {"firstName":"Required"} |
| 6 | Create contact persists to MySQL (201) and is retrievable | PASS | id 5 |
| 7 | Duplicate email is detected (409) | PASS | 409 |
| 8 | Search and pagination query MySQL | PASS | total 1 |
| 9 | SQL injection in search is treated as text | PASS | no rows |
| 10 | Optimistic concurrency: stale version is rejected (409) | PASS | second writer blocked |
| 11 | Advisor sees only assigned contacts; other records return 404 by ID | PASS | sees Okafor; other -> 404 |
| 12 | Advisor cannot assign a contact to another staff member (403) | PASS | 403 |
| 13 | Finance staff can view but not create or delete contacts (403) | PASS | view 200, create 403, delete 403 |
| 14 | Deactivation blocks sign-in and ends an existing session | PASS | session 401, login 403 |
| 15 | Admin-assisted password reset link works once | PASS | weak 422, reset 200, reuse 400, login 200 |
| 16 | Non-admin cannot create reset links or change staff (403) | PASS | 403/403 |
| 17 | Login rate limit: 6th failure in window returns 429 | PASS | blocked even with correct password |
| 18 | Soft delete hides the contact; audit log records every change | PASS | contact.created > contact.updated > contact.deleted |
| 19 | Logout ends the session (401 afterwards) | PASS | 401 |
| 20 | Session cookie is HttpOnly and SameSite=Lax | PASS | HttpOnly; SameSite=Lax (Secure is added automatically under HTTPS) |

## Build checks
TypeScript strict type check and Vite production build pass. The restored interface script is `server/public/prototype/lti-prototype.js` (882 KB, unchanged Revision 27 code).

## Defects found and fixed during this phase
- **Search race:** an older, unfiltered contacts request could finish after a newer search request and overwrite its results. Stale responses are now ignored.
- **Refresh lost the open database contact:** the contact ID is now kept in the URL (`#lcontact-<id>`).

## Not run in this session
- The 63-test Revision 27 prototype suite (it targets the standalone HTML file; the prototype code is unchanged). The restoration suite above re-checks all 73 pages, trip sections, tabs and key prototype interactions inside the integrated app.
- Anything on Network Solutions hosting (Phase 0 still pending).

## Simulated behaviour (by design until later phases)
All modules labelled “Prototype module” — including the softphone, automations, payments and AI — are simulations on sample data.
