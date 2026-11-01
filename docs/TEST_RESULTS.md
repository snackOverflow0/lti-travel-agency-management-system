# Test report — Phase 6, increment 1 (operations backend)

One run of `run-tests.sh` (logs in `test-output/`) plus the Phase 6 suite against a real local SMTP server (aiosmtpd, test only) and the real CLI worker. Ubuntu 24.04, PHP 8.3.6, MariaDB 10.11.14. Not run on XAMPP (Windows, PHP 8.2), MySQL, or Network Solutions.

**Phase 6 is not complete.** Tasks, email, templates, workflows, follow-ups, the job queue, notifications and the activity timeline are implemented in PHP/MySQL and pass every applicable test. The restored Tasks, Emails, Automations, Workflows and Notifications screens still show labelled prototype data (increment 2). Unattended scheduling on Network Solutions is **BLOCKED** pending account verification. **Prerequisite:** Phase 5 is also unfinished (financial screens not connected) and unapproved.

| Suite | Result |
|---|---|
| React production build | PASS |
| PHP syntax check | PASS — no errors |
| Phase 6 API (real SMTP, real worker) | 28/28 |
| Phase 2 / 3 / 4 / 5 API regression | 20/20, 15/15, 29/29, 26/26 |
| Phase 4 browser regression | 7/7 (×3), 9/9 |
| Phase 3 browser / restoration regression | 11/11, 12/12 |

## Mandatory test cases
| TC | Requirement | API / backend | Restored screens |
|---|---|---|---|
| TC01 | Create task | PASS | NOT RUN — restored screens not yet connected |
| TC02 | Assign task | PASS | NOT RUN — restored screens not yet connected |
| TC03 | Edit task | PASS | NOT RUN — restored screens not yet connected |
| TC04 | Complete and reopen | PASS | NOT RUN — restored screens not yet connected |
| TC05 | Overdue filtering | PASS | NOT RUN — restored screens not yet connected |
| TC06 | Unauthorized task access | PASS | NOT RUN — restored screens not yet connected |
| TC07 | Create email template | PASS | NOT RUN — restored screens not yet connected |
| TC08 | Personalised variables | PASS | NOT RUN — restored screens not yet connected |
| TC09 | Reject invalid variables | PASS | NOT RUN — restored screens not yet connected |
| TC10 | Save email draft | PASS | NOT RUN — restored screens not yet connected |
| TC11 | Queue email | PASS | NOT RUN — restored screens not yet connected |
| TC12 | SMTP result recorded accurately | PASS | NOT RUN — restored screens not yet connected |
| TC13 | No duplicate sends on retry | PASS | NOT RUN — restored screens not yet connected |
| TC14 | Create workflow | PASS | NOT RUN — restored screens not yet connected |
| TC15 | Enable/disable workflow | PASS | NOT RUN — restored screens not yet connected |
| TC16 | Trigger from real event | PASS | NOT RUN — restored screens not yet connected |
| TC17 | Conditions evaluated | PASS | NOT RUN — restored screens not yet connected |
| TC18 | Task created by workflow | PASS | NOT RUN — restored screens not yet connected |
| TC19 | Notification from workflow | PASS | NOT RUN — restored screens not yet connected |
| TC20 | Delayed follow-up | PASS | NOT RUN — restored screens not yet connected |
| TC21 | Job persistence after restart | PASS | NOT RUN — restored screens not yet connected |
| TC22 | Concurrency-safe claiming | PASS | NOT RUN — restored screens not yet connected |
| TC23 | Retry recoverable job | PASS | NOT RUN — restored screens not yet connected |
| TC24 | Stop at retry limit | PASS | NOT RUN — restored screens not yet connected |
| TC25 | Recover abandoned job | PASS | NOT RUN — restored screens not yet connected |
| TC26 | Notification persists | PASS | NOT RUN — restored screens not yet connected |
| TC27 | Read/unread | PASS | NOT RUN — restored screens not yet connected |
| TC28 | Timeline from real events | PASS | NOT RUN — restored screens not yet connected |
| TC29 | Execution history | PASS | NOT RUN — restored screens not yet connected |
| TC30 | Unauthorized workflow editing | PASS | NOT RUN — restored screens not yet connected |
| TC31 | Actions persist across refresh | PASS (re-read in new sessions and after a server restart) | NOT RUN — restored screens not yet connected |
| TC32 | Local scheduler executes jobs | PASS (CLI worker, 3 parallel workers, token-protected HTTP endpoint) | — |
| TC33 | Production scheduler verified | **BLOCKED** — no access to the Network Solutions account; cron availability unconfirmed | — |
| TC34 | Regression of Contacts, Trips, Bookings, Finance | PASS (API 20/20, 15/15, 29/29, 26/26) | PASS (browser 7/7 ×3, 9/9, 11/11, 12/12) |
| TC35 | React production build | — | PASS |
| TC36 | PHP syntax and backend tests | PASS (lint clean; 118/118 API tests) | — |
| TC37 | Automations do not alter financial records | PASS | — |
| TC38 | No credentials in logs or responses | PASS | — |

## Phase 6 tests
| # | Test | Result | Evidence |
|---|---|---|---|
| 1 | TC01 Create task; MySQL record | PASS | todo/high/1/2/2026-11-04 |
| 2 | TC02 Assign task to a staff member; history and notification recorded | PASS | reassigned to advisor2; history + notification |
| 3 | TC03 Edit task; persists; stale version 409 | PASS | saved |
| 4 | TC04 Complete and reopen task | PASS | completed (event recorded), reopened, second reopen 409 |
| 5 | TC05 Overdue and upcoming filters use the agency time zone | PASS | overdue and upcoming lists correct |
| 6 | TC06 Unauthorized task access refused; tasks never reveal hidden CRM records | PASS | other advisor 404; linked contact shown as Restricted record; linking hidden contact 404; traveler 403 |
| 7 | TC07 Create email template | PASS | template 1 with 5 variables |
| 8 | TC08 Personalised variables render from linked records | PASS | Your Amalfi Coast Anniversary is confirmed |
| 9 | TC09 Invalid or unsafe variables rejected; advisors cannot manage templates | PASS | unknown variable, expression, header injection and script refused (422); advisor 403 |
| 10 | TC10 Save email draft from a template linked to contact and trip | PASS | draft 1 to shah@example.com |
| 11 | TC11–TC12 Queue email; real SMTP acceptance and failure recorded accurately | PASS | accepted by local SMTP (message received, rendered); closed port: 3 failed attempts then Failed; no transport: Failed with explanation |
| 12 | TC13 Duplicate sends prevented: crash mid-send becomes Unknown (never resent); parallel workers send once | PASS | abandoned send -> Unknown, flagged, not resent, retry refused; 3 parallel workers -> exactly 1 SMTP message; manual resolution recorded |
| 13 | TC14 Create workflow definition (validated on the server) | PASS | workflow 1 (draft) with 5 actions |
| 14 | TC30 Unauthorized users cannot edit workflows; unsafe definitions refused | PASS | {"advisor create":403,"finance enable":403,"advisor edit":403,"sql action":422,"financial status":422,"bad field":422} |
| 15 | TC15 Enable and disable workflow | PASS | disabled then active |
| 16 | TC16–TC19 Real trip status change triggers the workflow: conditions, task, notification, email queued | PASS | Planning -> skipped (condition); Booked -> execution 2 completed: task for trip owner, notification, email accepted |
| 17 | TC20 Delayed follow-ups stored with UTC time from the configured time zone | PASS | workflow follow-up 2026-11-03 14:00:00 UTC (09:00 New York); manual follow-up 08:30 Manila = 2026-11-02 00:30:00 UTC; jobs pending |
| 18 | TC21 Pending jobs persist across a server restart | PASS | 2 pending jobs before and after restart; visible in /jobs/status |
| 19 | TC22 Concurrency-safe claiming: 3 parallel workers run each of 20 jobs exactly once | PASS | 20 tasks, no job run twice, 3 workers shared the load |
| 20 | TC23–TC24 Recoverable failure retried with back-off then succeeds; repeated failure stops at the limit | PASS | retry scheduled later, then completed on attempt 2; permanent failure stopped after 3 attempts and flagged for review |
| 21 | TC25 Abandoned worker job (expired lease) is recovered | PASS | lease expired -> logged as abandoned -> re-run once -> completed |
| 22 | TC26–TC27 Notifications persist; read, unread and dismiss | PASS | new session sees 24 unread; read/unread/dismiss work; another user 404 |
| 23 | TC28 Activity timeline built from real events | PASS | trip: followup.executed, followup.scheduled, workflow.executed, workflow.note, trip.status_changed, task.completed; contact includes email events; hidden from advisor2 |
| 24 | TC29 Workflow execution history | PASS | completed, skipped |
| 25 | Date trigger: departure approaching (agency time zone) | PASS | execution for Amalfi (32 days before departure) |
| 26 | TC32 Local scheduler: CLI worker and token-protected HTTP endpoint | PASS | 26 CLI runs recorded; HTTP run with token 200; wrong/missing token 403; scheduler healthy |
| 27 | TC37 Automations never alter financial records | PASS | 2 finance-triggered workflows ran; invoice/payment/commission totals unchanged (200.00/150.00/50.00/1/50.00/0/partially_paid) |
| 28 | TC38 Credentials never appear in logs, errors or API responses | PASS | SMTP AUTH attempt recorded (failed); password absent from DB logs, API responses and server log; job payloads not exposed |

## Defects found and fixed
- Migration failed: a new constraint name (`fk_tc_user`) duplicated one from `trip_collaborators`. Renamed; all constraint names checked unique across migrations.
- Test harness: process-kill commands matched their own shell (fixed by separating kill and launch). No application defect.
- Housekeeping: the local test configuration had been tracked by git since an early phase; it is now untracked (it was always excluded from ZIPs).
