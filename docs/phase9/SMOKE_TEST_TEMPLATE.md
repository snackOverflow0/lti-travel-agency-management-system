# Post-deployment smoke test report (template)

Release: ______ Environment URL: ______ Tester: ______ Date/time: ______

Use clearly named synthetic records (e.g. contact "Smoke Test Client"); void or archive them afterwards. Never alter real balances.

| # | Check | Expected | Actual | PASS/FAIL |
|---|---|---|---|---|
| 1 | Site loads over HTTPS | Sign-in page, no certificate warning | | |
| 2 | Owner signs in; wrong password refused | Dashboard with real (possibly zero) figures | | |
| 3 | Dashboard values | Match the records you can see | | |
| 4 | Create, edit, archive a test contact | Saved; survives refresh | | |
| 5 | Create a test trip, add an itinerary day, publish | Saved; version created | | |
| 6 | Create a test booking | Saved | | |
| 7 | Open an invoice view (create a draft test invoice, then void) | Totals shown; void recorded | | |
| 8 | Create and complete a task | Saved; notification shown to assignee | | |
| 9 | Client portal: test traveler sees only their trip | Other trips not visible | | |
| 10 | Upload a PDF; open `../lti_app/storage` URL guess | Upload works; direct URL 403/404 | | |
| 11 | Log a call and schedule a callback | Call in history; callback task created | | |
| 12 | Trigger an error (e.g. bad URL `/lti/api/nope`) | Generic message, no paths or SQL | | |
| 13 | `/lti/api/jobs/status` (owner) | Scheduler status reported honestly | | |

Decision: GO / ROLLBACK (see ROLLBACK_PLAN.md). Signed: ______
