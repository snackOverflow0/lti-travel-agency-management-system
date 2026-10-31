# Test report — Phase 3 (trips, travelers, itineraries, client portal)

All results below come from one run of `run-tests.sh` in this session (logs in `test-output/`). Environment: Ubuntu 24.04, PHP 8.3.6 built-in server on 127.0.0.1:8000, MariaDB 10.11, Chromium/Playwright 1.56; each suite starts from a freshly migrated and seeded **test** database. Your XAMPP setup (PHP 8.2 + MySQL on Windows) was **not** available here; the code avoids PHP 8.3-only features but has not been executed on 8.2.

| Suite | Result |
|---|---|
| TC15 Frontend production build (`tsc --noEmit && vite build`) | PASS (`test-output/build.log`) |
| Phase 2 API regression (auth, staff, contacts) | 20/20 |
| Phase 3 API (trips, travelers, itineraries, portal) | 15/15 |
| Phase 3 browser tests (TC01–TC14 through the restored screens) | 11/11 |
| Phase 1 restoration regression (browser) | 12/12 |

## Mandatory test cases
| TC | Requirement | Covered by | Result |
|---|---|---|---|
| TC01 | Create a trip, verify in MySQL | E2E TC01; API “Create trip” | PASS |
| TC02 | Refresh, trip remains | E2E TC02 | PASS |
| TC03 | Edit trip, DB changes | E2E TC03; API “Update trip” | PASS |
| TC04 | Link a real CRM contact | E2E TC04; API “Travelers” | PASS |
| TC05 | Itinerary associated with the trip | E2E TC05–TC07 (option row checked) | PASS |
| TC06 | Add day and activity | E2E TC05–TC07 | PASS |
| TC07 | Reorder activities, saved order | E2E TC05–TC07 (Move up, verified after reload); API “Itinerary save” | PASS |
| TC08 | Publish, published version | E2E TC08–TC09; API “Publish” | PASS |
| TC09 | Draft edit does not change published version | E2E TC08–TC09; API “Editing the draft” | PASS |
| TC10 | Permitted traveler opens published itinerary | E2E TC10 (also approves; no internal notes) | PASS |
| TC11 | Traveler cannot access another client’s trip | E2E TC11; API “Traveler cannot open…” | PASS |
| TC12 | Archive without corrupting related records | E2E TC12; API “Archive keeps…” | PASS |
| TC13 | Contacts and Staff still work | E2E TC13; Phase 2 API suite; restoration suite | PASS |
| TC14 | Dashboard and prototype modules accessible | E2E TC14; restoration suite | PASS |
| TC15 | Production build and PHP tests | build log; both PHP suites | PASS |

## Phase 3 browser tests
| # | Test | Result | Evidence |
|---|---|---|---|
| 1 | TC01 Create a trip in the restored Trips screen; record is in MySQL | PASS | trip 3: Inquiry/Lisbon, Portugal/2027-05-10/2027-05-18/1 |
| 2 | TC02 Refresh: the trip remains (loaded from MySQL) | PASS | present after reload; sample trips not shown |
| 3 | TC03 Edit the trip (status, destination, internal notes); MySQL changes | PASS | Planning/Lisbon & Porto, Portugal/Prefers boutique hotels |
| 4 | TC04 Link a real CRM contact as a traveler from the Travelers section | PASS | 1:primary,3:full |
| 5 | TC05–TC07 Itinerary: add a day and activities in the editor, reorder, saved order in MySQL | PASS | Belcanto dinner>Alfama walk>Pena Palace |
| 6 | TC08–TC09 Publish creates a version; editing the draft leaves the published version unchanged | PASS | version 3 unchanged by draft edit; indicator shown |
| 7 | TC10 Permitted traveler signs in and opens the published itinerary (no internal notes); approval saved | PASS | portal shows published itinerary; Approved/1 |
| 8 | TC11 A traveler cannot access another client’s trip (UI list and direct API) | PASS | sees Kenya Family Safari, Douro Discovery 2027; other trip 404; staff API 403 |
| 9 | TC12 Archive a trip from the trip Actions menu; related records intact | PASS | travelers/activities/versions 2/3/1 unchanged |
| 10 | TC13 Contacts and Staff still work (live) | PASS | ok |
| 11 | TC14 Dashboard and prototype modules remain accessible and labelled | PASS | 73 pages render; prototype pages labelled |

## Phase 3 API tests
| # | Test | Result | Evidence |
|---|---|---|---|
| 1 | Create trip (201) is stored in MySQL with primary traveler and default itinerary | PASS | trip 3 |
| 2 | Validation: end before start (422); archived on create refused (422) | PASS | 422/422 |
| 3 | Update trip with version; stale version returns 409 | PASS | saved; stale blocked |
| 4 | Travelers: link a second contact; two primaries rejected (422) | PASS | 2 travelers |
| 5 | Scoping: another advisor cannot read or list this trip (404 / hidden); finance cannot create (403) | PASS | advisor2 sees Kenya Family Safari |
| 6 | Itinerary save: new option/day/activities get ids; order persisted; stale rev 409 | PASS | reordered: Belcanto dinner > Alfama walk |
| 7 | Publish creates a version; internal notes are not in the client snapshot | PASS | version 3 |
| 8 | Editing the draft does not change the published version | PASS | published snapshot unchanged; draft flagged |
| 9 | Traveler sees only own published trips; snapshot excludes internal notes | PASS | ok |
| 10 | Traveler cannot open another client’s trip or staff endpoints | PASS | portal 404, staff APIs 403 |
| 11 | Traveler approves: approval recorded, trip status becomes Approved, staff sees it | PASS | approved; bad option 422 |
| 12 | Limited-access traveler cannot approve and sees no prices | PASS | limited: view only, no prices |
| 13 | Archive keeps travelers, itinerary and versions; trip leaves the portal | PASS | related rows intact: {"tv":2,"op":1,"ve":1} |
| 14 | Staff creates a traveler portal login for a contact (one-time link), traveler signs in | PASS | traveler account works; advisor cannot create (403) |
| 15 | Audit log records trip, itinerary and portal actions | PASS | itinerary.published, trip.created, trip.status_changed, trip.travelers_updated, itinerary.saved, portal.approved, trip.archived |

## Phase 2 API regression
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

## Restoration regression
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

## Failures seen during development (honest log)
- API test expected traveler2 to see exactly one trip; a previous test had legitimately added that contact (limited access) to another published trip. Assertion corrected to “must not see Amalfi”. Access codes were correct throughout.
- E2E TC01 browser-storage check matched the prototype’s own sample trip, which has the same name (“Lisbon & Douro Valley”); the test now uses a unique name and still asserts no database trip is written to browser storage.
- E2E TC12 used a lazily created filter object; test fixed. No application defects were found by these failures.

## Not tested
- XAMPP / Windows / PHP 8.2 and Network Solutions hosting (Phase 0 still pending).
- Two staff members editing the same itinerary at once beyond the stale-revision API test (the UI reloads the latest draft on conflict; not covered by a browser test).
- Creating a traveler login from the user interface (only the API endpoint exists and is tested).
