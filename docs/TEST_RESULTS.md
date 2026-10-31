# Test results — full-stack vertical slice

Environment: Ubuntu 24.04, PHP 8.3.6 (built-in server), MariaDB 10.11.14, Node 22, Chromium (Playwright 1.56). Fresh migrated + seeded test database for each run.

## Passed automated tests
### API integration (PHP, against MySQL) — 20/20
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

### Browser end-to-end (React build → PHP → MySQL) — 8/8
| # | Test | Result | Evidence |
|---|---|---|---|
| 1 | Sign-in rejects a wrong password with a clear message | PASS | Email or password is incorrect |
| 2 | VERTICAL SLICE: staff login → create client → saved in MySQL → reload → retrieve (also after a new session) | PASS | contact 5 saved, reloaded, retrieved after re-login; audit row present |
| 3 | Edit saves to MySQL and survives reload; server field errors are shown | PASS | edit persisted |
| 4 | Finance role: no create button, no Staff menu, API refuses create | PASS | UI hides create; direct API call 403 |
| 5 | Advisor cannot open another advisor’s client by changing the URL | PASS | list scoped; direct URL -> Contact not found |
| 6 | Administrator deactivates a staff account; that person can no longer sign in | PASS | blocked with message |
| 7 | Mobile 390px: contacts usable, no horizontal overflow, menu drawer works | PASS | ok |
| 8 | No emojis rendered; no customer data in browser storage | PASS | clean |

### Build checks
- TypeScript `strict` type check: pass. Vite production build: pass (JS 166 KB, 54 KB gzipped).
- `php -l` on every PHP file: pass. Migrations 001–003 apply cleanly (48 tables, 72 foreign keys).

## Manually verified
- Login page, contacts list, detail and dialogs render with the Revision 27 CSS and icons (reviewed during test debugging).

## Simulated behaviour
- None in the new application. (The Revision 27 HTML prototype is still a simulation; its 63-test Playwright suite is retained unchanged in the prototype package and was **not re-run** in this session.)

## Hosting-dependent — not tested
- Real Network Solutions PHP version, MySQL version and limits, Apache `.htaccess` routing (locally emulated by `server/dev-router.php`), HTTPS and the `Secure` cookie flag, cron, SMTP, IMAP, storage quotas.
- PHP 7.4 compatibility: code avoids 8.x-only syntax but was only executed on PHP 8.3.

## Unsupported at $0 (not tested, will not be simulated)
- PSTN calling inside LTI, voicemail capture, call recording, TURN relay for strict networks.

## Issues found during this work
- Test-environment only: background servers launched in the same shell kept the tool session open; now started fully detached.
- Test-side: navigating to an identical `#/login` URL does not trigger a load in Playwright; helper adjusted. No application defects found in the slice.
