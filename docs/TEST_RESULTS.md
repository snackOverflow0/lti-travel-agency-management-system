# Test report — Phase 4 (complete checkpoint, awaiting approval)

All results come from one run of `run-tests.sh` in this session (logs in `test-output/`): Ubuntu 24.04, PHP 8.3.6 built-in server on 127.0.0.1:8000, MariaDB 10.11, Chromium/Playwright 1.56, a freshly migrated and seeded **test** database per suite. Not run on your XAMPP (Windows, PHP 8.2) or on Network Solutions.

| Suite | Result |
|---|---|
| TC31 React production build (TypeScript strict + Vite) | PASS |
| TC32 PHP syntax check, every PHP file | PASS — no errors |
| Phase 2 API regression | 20/20 |
| Phase 3 API regression | 15/15 |
| Phase 4 API | 29/29 |
| Phase 4 browser — Suppliers, Bookings, Documents (run 3 times) | 7/7, 7/7, 7/7 |
| Phase 4 browser — Group trips, Forms, Content library | 9/9 |
| Phase 3 browser regression | 11/11 |
| Restoration browser regression | 12/12 |

## Mandatory test cases
| TC | Requirement | Backend/API | Restored screens |
|---|---|---|---|
| TC01 | Create group trip | PASS | PASS |
| TC02 | Edit group trip | PASS | PASS |
| TC03 | Register existing traveler | PASS | PASS |
| TC04 | Register new traveler, create contact | PASS | PASS |
| TC05 | Prevent duplicate registration | PASS | PASS |
| TC06 | Enforce capacity rules | PASS | PASS |
| TC07 | Create sub-trip in group | PASS | PASS |
| TC08 | Sync group itinerary into sub-trip | PASS | PASS |
| TC09 | Preserve custom sub-trip activities | PASS | PASS |
| TC10 | Create supplier | PASS | PASS |
| TC11 | Edit supplier | PASS | PASS |
| TC12 | Archive supplier, bookings intact | PASS | PASS |
| TC13 | Booking linked to trip and supplier | PASS | PASS |
| TC14 | Add traveler to booking | PASS | PASS |
| TC15 | Edit and cancel booking | PASS | PASS |
| TC16 | Reject invalid booking relationships | PASS | N/A (screen only offers valid choices) |
| TC17 | Create custom form | PASS | PASS |
| TC18 | Add, edit, reorder fields | PASS | PASS |
| TC19 | Publish form, submit response | PASS | PASS (public link, no sign-in) |
| TC20 | Submissions in MySQL | PASS | PASS |
| TC21 | Upload document, metadata in MySQL | PASS | PASS |
| TC22 | Download authorized document | PASS | PASS |
| TC23 | Reject unauthorized document access | PASS | PASS |
| TC24 | Rename and archive document | PASS | PASS |
| TC25 | Content library folder | PASS | PASS |
| TC26 | Create, edit, duplicate template | PASS | PASS |
| TC27 | Survives browser refresh | PASS | PASS (all Phase 4 screens) |
| TC28 | Persists after logout/login | PASS | PASS (all Phase 4 screens) |
| TC29 | Staff roles and restricted access | PASS | PASS |
| TC30 | Regression: Contacts, Trips, Itineraries | PASS (20/20, 15/15) | PASS (11/11, 12/12) |
| TC31 | React production build | — | PASS |
| TC32 | PHP syntax and backend tests | PASS (lint clean, 64/64) | — |

## Phase 4 browser tests — Group trips, Forms, Content library
| # | Test | Result | Evidence |
|---|---|---|---|
| 1 | TC01–TC02 Create a group trip, edit details, landing page and room categories in the restored Group trips screen | PASS | group 3: details, landing headline and room category saved |
| 2 | TC03–TC06 Register existing and new travelers; duplicate and capacity rules; administrator override; cancellation | PASS | existing + new traveler registered; duplicate and capacity refused; override recorded; cancellation released places |
| 3 | TC07–TC09 Create a sub-trip; sync the group itinerary; custom sub-trip activity preserved | PASS | preview showed 1 to add; after sync: Vienna markets walk>Private dinner for Amelia |
| 4 | TC17–TC18 Create a form; rename; add, edit, require and reorder fields in the restored builder | PASS | form 1: First name:1,Cabin preference:1,Email:1 |
| 5 | TC19–TC20 Publish; public link submission without sign-in; response stored, linked to a trip, exported; duplicate and archive | PASS | public submission 1 stored with answers and contact; linked to trip; CSV; duplicate; archive |
| 6 | TC25–TC26 Content library: folders, activity templates (create, edit, duplicate, move), use on a trip; packing list | PASS | folder created/renamed; template created in folder, edited and moved, duplicated, added to Amalfi day 1; packing list item saved |
| 7 | TC27–TC28 Groups, forms and library survive refresh and sign-out/sign-in; nothing written to browser storage | PASS | present after refresh and re-login |
| 8 | TC29 Roles: sales cannot create groups; another advisor cannot see the group; finance has no forms access | PASS | sales blocked; advisor2 does not see the group; finance forms 403 |
| 9 | Mobile 390px: Group trips, Forms and Content library without horizontal overflow | PASS | 390/390/390 |

## Phase 4 browser tests — Suppliers, Bookings, Documents (final run)
| # | Test | Result | Evidence |
|---|---|---|---|
| 1 | TC10–TC11 Create a supplier in the restored Suppliers screen and edit it; MySQL updated | PASS | supplier 1; email now groups@sirenuse.example |
| 2 | TC13–TC15 Create a booking for a trip and supplier with a traveler; confirm, edit and cancel in the restored Bookings screen | PASS | B-00001: created with traveler, confirmed, edited, cancelled/Client moved dates |
| 3 | TC12 Archive the supplier from its profile; the booking keeps its supplier | PASS | archived; booking still linked |
| 4 | TC21–TC24 Upload, preview, download, refuse other advisor, rename and archive a document in the restored Documents screen | PASS | doc 1: stored privately, preview, download, other advisor 404, renamed, archived |
| 5 | TC27–TC28 Records survive refresh and sign-out/sign-in; none are written to browser storage | PASS | present after refresh and re-login (1/11) |
| 6 | TC29 Role restrictions in the restored screens (backend enforced) | PASS | advisor: New supplier disabled; finance: booking refused in UI; API 403 for finance booking and upload |
| 7 | Mobile 390px: Suppliers, Bookings and Documents usable without horizontal overflow | PASS | 390/390/390 |

## Phase 4 API tests
| # | Test | Result | Evidence |
|---|---|---|---|
| 1 | TC01 Create group trip; MySQL record has group fields | PASS | group 3: group/3/2027-11-20/4/Open |
| 2 | TC02 Edit group; persists; stale version 409; deadline after departure 422 | PASS | saved; partial update kept dates; 2 rooms |
| 3 | Group itinerary editable through the itinerary API | PASS | 3 group activities |
| 4 | TC03 Register an existing contact: registration, sub-trip, travelers and synced itinerary | PASS | registration 1, sub-trip 4 with 3 synced activities |
| 5 | TC04 Register a new traveler; contact is created and linked | PASS | contact 5 created |
| 6 | TC05 Duplicate active registration is rejected (409) | PASS | Already registered for this group: Amelia Hartwell |
| 7 | TC06 Capacity: over-capacity refused; advisor cannot override; administrator override recorded | PASS | refused 409; advisor override 409; admin override flagged; cancellation frees places |
| 8 | Registration update: change room and add a traveler with checks | PASS | duplicate traveler 409; update 200 |
| 9 | TC07 Create a sub-trip manually and associate it with the group | PASS | sub-trip 7 with custom dates |
| 10 | TC08–TC09 Sync group itinerary into sub-trips; custom and edited sub-trip activities are preserved | PASS | preview {"subTripId":4,"daysAdded":1,"daysRemoved":0,"added":1,"updated":1,"removed":0,"customKept":2}; applied: AmaMagna embarkation > Welcome reception and briefing > P |
| 11 | TC10 Create supplier; MySQL record | PASS | supplier 1; duplicate 409; bad category 422 |
| 12 | TC11 Edit supplier; persists; stale version 409 | PASS | saved |
| 13 | TC13 Create booking linked to an existing trip and supplier | PASS | B-00001/3/1/cruise/pending/3750.00 |
| 14 | TC14 Add traveler to booking; travelers not on the trip are rejected | PASS | sub-trip booking with traveler; outsider 422 |
| 15 | TC15 Edit and cancel booking; cancelled booking is read-only; history recorded | PASS | confirmed, commission recalculated, cancelled, history booking.created > booking.confirmed |
| 16 | TC16 Invalid booking relationships are rejected | PASS | missing trip 404, missing supplier 422, other advisor trip 404, bad category 422, no trip 422 |
| 17 | TC12 Archive supplier: existing bookings intact; cannot be newly assigned | PASS | archived; booking keeps supplier; new assignment 422 |
| 18 | TC17–TC18 Create form; add, edit and reorder fields | PASS | order email,first,who,kids,dest |
| 19 | TC19 Publish form; public submission with required and conditional validation | PASS | draft hidden; conditional required enforced; 2 submissions |
| 20 | TC20 Submissions and answers in MySQL; contact and inquiry trip created; CSV export | PASS | 2 submissions; contact 8; trip “Kenya — Nora”; CSV ok; finance 403 |
| 21 | Form duplicate and archive; archived form refuses public submissions | PASS | copy has 5 fields (draft); archived 404 |
| 22 | TC21 Upload document; metadata in MySQL; file stored outside the web root; bad types rejected | PASS | document 1 (69 bytes) stored at app/storage; .php 415; disguised file 415 |
| 23 | TC22 Authorized download returns the exact file | PASS | bytes identical; inline preview as PDF for owner |
| 24 | TC23 Unauthorized access rejected (other advisor, traveler staff route, no session, direct file URL) | PASS | other advisor 404, traveler on staff route 403, anonymous 401, traveler portal (client-visible, own trip) 200, direct URL 404, finance upload 403 |
| 25 | TC24 Rename and archive document; archived file no longer downloadable | PASS | renamed, archived, download 404, listed under archived |
| 26 | TC25 Content library folders: create, rename, move, cycle prevented, non-empty delete refused | PASS | ok; finance 403 |
| 27 | TC26 Templates: create, edit, duplicate, archive; use on a trip | PASS | activity added to Amalfi day 1; checklist added to packing; search excludes archived copy |
| 28 | TC29 Backend role enforcement across Phase 4 modules | PASS | {"finance booking":403,"sales group":403,"advisor supplier":403,"sales register":403,"advisor2 view group":404,"traveler suppliers":403,"traveler library":403,"ops forms" |
| 29 | Audit log covers Phase 4 actions | PASS | all present |

## Defects found and fixed during Phase 4
| Defect | Fix |
|---|---|
| Partial updates blanked omitted fields (groups, trips, suppliers, bookings) | Omitted fields keep current values |
| Sub-trip save marked every synced activity as customized | Comparison corrected; explicit customization flag |
| Document upload failed (storage path longer than column) | Column widened |
| Booking detail re-fetch loop | Detail marker preserved |
| Form edits never saved: field IDs were prefixed twice, so the server inserted duplicates | IDs mapped correctly |
| Supplier name kept in saved interface state | Cleared before saving |
| Intermittent: a database booking could reach browser storage (timing-dependent; not reproducible on demand) | Storage-layer guard scrubs every write of the sample-data store; Phase 4 suite then passed 3 of 3 runs |

Test-side corrections (no application change): integer vs string assertion; contacts outside an advisor’s scope; dev server serving index.html for unknown paths; disabled-button assertion; UTF-8 for the command-line MySQL client; “View” selector matching “Preview”; a step run as Sales who correctly cannot see the trip; names that also exist in the prototype’s sample data.

## Not implemented / not tested
- File-upload form fields: not offered (answers would be discarded until secure public file handling is designed).
- Destination guides, task templates and “More libraries” in the content hub remain prototype (Phase 6).
- XAMPP/Windows/PHP 8.2 and Network Solutions hosting; simultaneous registrations from two browsers (serialized by a row lock on the server, but no parallel test).
