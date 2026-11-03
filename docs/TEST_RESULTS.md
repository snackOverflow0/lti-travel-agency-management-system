# Test report — Phase 8 (final QA)

One complete run of `run-tests.sh` on the final code (logs in `test-output/`), plus the backup/restore and performance runs. Environment: Ubuntu 24.04, PHP 8.3.6, MariaDB 10.11.14, Chromium (Playwright 1.56), fresh synthetic test database per suite. **Not run** on XAMPP/Windows (PHP 8.2), MySQL, or Network Solutions.

| Suite | Tests | Result |
|---|---|---|
| Unit (money, phone, templates, workflow conditions, redaction) | 21 | 21 PASS |
| API: Phase 2 CRM / Phase 3 trips / Phase 4 operations | 20 / 15 / 29 | all PASS |
| API: Phase 5 finance / Phase 6 tasks-email-workflows / Phase 7 calls | 26 / 28 / 21 | all PASS |
| Security (OWASP ASVS-informed) | 14 | 14 PASS |
| System flow (cross-module chain) | 11 | 11 PASS |
| Browser: restoration / Phase 3 / Phase 4 (×3) / Phase 4b / Phase 7 / Phase 8 | 12 / 11 / 7×3 / 9 / 7 / 9 | all PASS |
| React production build, PHP syntax check | — | PASS |
| Backup and restore verification | 1 | VERIFIED |
| Total automated tests in the final run | 254 | all PASS |

Scope limits (not PASS): finance and operations **screens** (Phases 5–6) are not connected, so their screen-level tests are NOT RUN; hosting, SMTP delivery and the production scheduler are BLOCKED (no access); the device phone-app handoff needs a manual check; PSTN/WebRTC NOT APPLICABLE.

## Unit tests
- PASS Money: parse decimal strings to minor units
- PASS Money: rejects too many decimals, negatives and junk
- PASS Money: negative allowed only when requested
- PASS Money: format minor units
- PASS Money: mulDiv half-up rounding
- PASS Money: percentage of amount (scaled x10000)
- PASS Money: no float drift on large values
- PASS Phone: US national and +1 formats
- PASS Phone: invalid North American numbers rejected
- PASS Phone: international, 00 and 011 prefixes
- PASS Phone: national formats by country rule
- PASS Phone: unknown codes, lengths and characters rejected
- PASS Phone: validation level is reported honestly
- PASS Template: allowlisted variables pass, unknown fail
- PASS Template: expressions, code and script refused
- PASS Template: render fills values, reports missing, never evaluates
- PASS Template: HTML output is escaped
- PASS Workflow: equality, contains, in, empty
- PASS Workflow: exact decimal comparisons (no floats)
- PASS Workflow: event payload fields and all-conditions-must-match
- PASS Jobs: secrets redacted from messages

## Security tests
| # | Test | Result | Evidence |
|---|---|---|---|
| 1 | SEC-T01 Every API route refuses unauthenticated requests | PASS | 210 protected routes all returned 401/403 without a session |
| 2 | SEC-T02 CSRF: state-changing requests without or with a wrong token are refused | PASS | {"contact create, no token":403,"task, no token":403,"call, no token":403,"wrong token":403} |
| 3 | SEC-T03 Sessions: forged cookie and logged-out session refused; cookie flags set | PASS | forged 401; after logout 401; Set-Cookie HttpOnly + SameSite=Lax (Secure is added automatically under HTTPS) |
| 4 | SEC-T04 SQL injection payloads in search and filters cause no errors or data leaks | PASS | 35 payloads across 7 search endpoints: no error, no leak, no delay; payload stored as plain text |
| 5 | SEC-T00 Fixture: real supplier, booking, internal and client-visible documents created through the API | PASS | {"booking":1,"doc_internal":1,"doc_client":2} |
| 6 | SEC-T05 Staff access control: another advisor cannot reach real records across modules (owner positive control) | PASS | owner 200 on all 7; advisor2: {"\/contacts\/2":404,"\/trips\/1":404,"\/bookings\/1":404,"\/documents\/1":404,"\/documents\/1\/download":404,"\/activity?entityType=contact&entityId=2":404,"\/contacts\/ |
| 7 | SEC-T06 Client isolation: another traveler cannot reach a client’s trips or documents (owner positive control); no staff endpoints | PASS | traveler1 downloads own client document (200) but not the internal one (404); {"trv2 -> trip":404,"trv2 -> client doc":404,"trv2 approve":404,"trv \/contacts":403,"trv \/trips":403,"trv \/invoices":40 |
| 8 | SEC-T07 Privilege escalation refused | PASS | {"advisor PATCH own role":403,"finance staff list":403,"advisor reset link":403,"advisor workflows":403,"advisor void":403} |
| 9 | SEC-T08 Unsafe uploads refused; stored files are outside the web root | PASS | {"shell.pdf":415,"shell.phtml":415,"shell.php.pdf":422,"x.svg":415,".htaccess":415,"x.exe":415}; storage outside public/ |
| 10 | SEC-T09 Sign-in rate limiting | PASS | locked after 6 attempts; correct password also refused while locked |
| 11 | SEC-T09b A locked account does not lock out colleagues on the same network; spraying many accounts from one IP is still stopped | PASS | colleague signs in while operations@ is locked (200); IP blocked after 31 failures across different accounts |
| 12 | SEC-T10 Password reset links are single-use and expire | PASS | first use 200; reuse 400; expired 400 |
| 13 | SEC-T11 Errors disclose no internals; security headers on API and app | PASS | 354 responses scanned: no stack traces, SQL errors or paths; headers present; no X-Powered-By |
| 14 | SEC-T12 HTTPS-only sign-in option refuses plain HTTP | PASS | 403 “Sign-in requires a secure (HTTPS) connection.” |

## System flow (contact → trip → itinerary → booking → quote → invoice → payment → call → workflow → client portal)
| # | Step | Result | Evidence |
|---|---|---|---|
| 1 | F01 Staff creates a CRM contact | PASS | contact 5 with normalised phone |
| 2 | F02 Contact to trip | PASS | trip 3 |
| 3 | F03 Trip to itinerary (days, activities, internal note) | PASS | 2 days, 2 activities in MySQL |
| 4 | F04 Trip to booking | PASS | booking 1 linked to trip |
| 5 | F05 Booking to quote: server total ignores client value; accepted; converted once (idempotent) | PASS | quote total 2700.00 (client sent 1.00); accepted; one invoice 1 |
| 6 | F06 Invoice issued; payment recorded; retry not duplicated; overpayment refused; manual label | PASS | INV-2026-00001; balance 1700.00 after 1000.00; retry replayed (1 payment row); 2000.00 overpayment 422; payment status 'unverified' |
| 7 | F07 Financial audit chain verifies after the flow | PASS | hash chain intact (7 events) |
| 8 | F08 Call to CRM follow-up: missed call, callback, task | PASS | missed call -> callback -> task linked to the trip |
| 9 | F09 Workflow event to notification and task via the real worker | PASS | notification and task for the trip owner |
| 10 | F10 Client sees the published itinerary without internal notes; another client cannot; no invoices in the portal | PASS | client 200 with itinerary, internal note absent; other client 404; invoice 403 |
| 11 | F11 Everything survives logout and a new login | PASS | contact, trip, booking, quote, invoice re-read in a new session |

## Phase 8 browser QA
| # | Test | Result | Evidence |
|---|---|---|---|
| 1 | XSS: stored script payloads in contact, call notes and task titles are displayed as text and never execute | PASS | payloads rendered as text on contacts, contact page, call history, call details; window.__xss undefined; no injected elements |
| 2 | Responsive 360px: every page (owner) has no horizontal page overflow; dialog and softphone fit | PASS | 73 pages without horizontal overflow; dialog and softphone within 360px |
| 3 | Responsive 390px: every page (owner) has no horizontal page overflow; dialog and softphone fit | PASS | 73 pages without horizontal overflow; dialog and softphone within 390px |
| 4 | Responsive 768px: every page (owner) has no horizontal page overflow; dialog and softphone fit | PASS | 73 pages without horizontal overflow; dialog and softphone within 768px |
| 5 | Responsive 1024px: every page (owner) has no horizontal page overflow; dialog and softphone fit | PASS | 73 pages without horizontal overflow; dialog and softphone within 1024px |
| 6 | Responsive 1440px: every page (owner) has no horizontal page overflow; dialog and softphone fit | PASS | 73 pages without horizontal overflow; dialog and softphone within 1440px |
| 7 | Client portal 360px: itinerary readable, no overflow | PASS | portal shows trip; overflow 0px |
| 8 | Client portal 390px: itinerary readable, no overflow | PASS | portal shows trip; overflow 0px |
| 9 | Keyboard: focus is visible on interactive controls | PASS | {"tag":"BUTTON","outline":"solid 2px","shadow":"none"} |

Earlier-phase suite details: see the Phase 2–7 result files in `server/tests/*_results.json` and `tests/e2e/*_results.json`.
