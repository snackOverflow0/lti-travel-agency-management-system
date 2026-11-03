# Phase 8.1 test results (batches 1–3)

Full `run-tests.sh` on the batch 3 code: unit 21/21; API Phase 2–7 139/139; security 14/14; system flow 11/11; browser Phase 3 11/11, Phase 4 7/7 ×3, Phase 4b 9/9, Phase 7 7/7, Phase 8 9/9, Phase 8.1 finance 9/9, Phase 8.1 operations 7/7; React build PASS; PHP lint clean. In that run two tests failed for test-side reasons (an outdated restoration step that clicked the removed simulated automation clock; a race reading the Outbox banner before data loaded); both tests were corrected and the two suites re-run: restoration 12/12, Phase 8.1 email/automation 7/7.

## Finance and Dashboard (9/9)
| # | Test | Result | Evidence |
|---|---|---|---|
| 1 | Dashboard: live figures equal the API and MySQL (owner and advisor); no prototype label | PASS | owner 4 contacts (= SQL), advisor 1 (scoped); finance KPIs shown to owner |
| 2 | Quote: create with lines and discount (server total), send, accept, convert to invoice — verified in MySQL | PASS | quote 1: 2700.00 − 270.00 = 2430.00 (server); sent → accepted → invoice 1 |
| 3 | Invoice: advisor issues (no payment controls); finance records payment through the screen; balance in MySQL, after refresh and re-login | PASS | INV-2026-00001 issued by advisor (no payment buttons shown to advisor); finance recorded 1000.00 → balance 1430.00 (partially paid) in MySQL, after refresh and re-login; labelled unverified |
| 4 | Finance user verifies and reverses the payment; balance restored | PASS | confirmed by staff (label updated, Verify no longer offered), then reversed: balance back to 2430.00 |
| 5 | Supplier payable: create and pay through the screen | PASS | payable 1500.00 created; 500.00 paid → 500.00/partially_paid |
| 6 | Commission: overview figures from server; rule added through the screen; audit log verifies | PASS | rule stored percent/12.0000/40.0000/cruise; financial log listed and verified intact |
| 7 | Reports: live report rows equal the API; CSV export | PASS | balances: 1 rows = API; CSV lti-balances-20261101.csv; Report builder tab still labelled prototype: true |
| 8 | Access: a role without finance access sees Restricted; no finance data in its API calls | PASS | Payments, Commission, Reporting restricted; /api/invoices 403 |
| 9 | Mobile 390px: Payments tabs and invoice dialog fit | PASS | no page overflow; dialog within 390px |

## Tasks, Calendar, Notifications (7/7)
| # | Test | Result | Evidence |
|---|---|---|---|
| 1 | Tasks: create through the screen (assigned, overdue, linked contact); Overdue tab; MySQL record and assignment history | PASS | task 1: todo/high/5/2/2026-10-31; history + notification to assignee; listed under Overdue |
| 2 | Tasks: edit, complete (checkbox), reopen, comment, archive — each verified in MySQL; persists after refresh | PASS | priority urgent → completed via dialog (event) → persisted after refresh → reopened via list checkbox → comment → archived; history: assigned_to:5,priority:urgent,status:completed,status:todo,status:archived |
| 3 | Task access: assignee sees the task with the other advisor’s client as Restricted record; unrelated role cannot see it | PASS | advisor2 sees task, contact shown as Restricted record; sales sees 0 |
| 4 | Notification bell: badge equals server unread count (no samples); clicking an item marks it read in MySQL | PASS | badge 2 = API unread; bell click persisted read_at |
| 5 | Notifications page: mark unread, dismiss, mark all read — persisted | PASS | unread, mark-all-read and dismiss persisted |
| 6 | Calendar: task due date and trip departure appear on the correct days; month navigation | PASS | task on 2026-11-02; Amalfi departure on 2026-12-03 |
| 7 | Mobile 390px: Tasks and Calendar have no page overflow | PASS | overflow [0,0,0] |

## Email and Automation (7/7, real local SMTP and real worker)
| # | Test | Result | Evidence |
|---|---|---|---|
| 1 | Email templates: create through the screen; unknown variable refused; preview fills real values | PASS | unknown {{contact.ssn}} refused (not saved); template saved; preview “Documents for Amalfi Coast Anniversary” |
| 2 | Outbox: compose with template → draft → queue → real worker + SMTP → “Accepted by mail server” (never “delivered”) | PASS | draft → queued (subject rendered) → worker sent to local SMTP (message received) → UI “Accepted by mail server”, attempt logged, no delivery claim |
| 3 | Emails page: Inbox states inbound email is not connected; Campaigns not implemented; no sample messages | PASS | honest inbox and campaigns pages |
| 4 | Workflow builder: build through the screen, save, enable, preview; real trip status change runs it via the worker; history shows the run | PASS | built in the UI (trigger, condition event.to=Booked, 2 actions) → draft in MySQL → previewed → enabled → real status change → worker → task created → completed run in history |
| 5 | Follow-ups: schedule and cancel through the screen; scheduler card reflects real worker runs | PASS | 09:30 Manila stored as 01:30 UTC; cancelled; scheduler card shows real status |
| 6 | Access: advisor cannot open Automations; finance cannot open Outbox | PASS | restricted as expected (server also refuses) |
| 7 | Mobile 390px: Outbox, Automations and builder dialog fit | PASS | no overflow [0,0,0,0]; builder within 390px |

## Defects found and fixed in this phase
| ID | Severity | Defect | Fix |
|---|---|---|---|
| P81-01 | Medium | Finance buttons shown to roles the server refuses (e.g. advisors offered Record payment) | Each button gated on the endpoint's permission (record / reconcile / void / payout) |
| P81-02 | Medium | Background notification sync redrew the whole page every 30 s, wiping text being typed (likely cause of the earlier intermittent Phase 3 failure) | Sync refreshes only the top bar |
| P81-03 | Low | Confirmed payments still displayed as unverified (wrong status value) | Uses `confirmed_by_staff`; label "Confirmed by staff (not processor-verified)" |
| P81-04 | Low | Report requests used the wrong parameter; CSV built in the browser | `name` parameter; server CSV export |
