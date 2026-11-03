# Phase 8.1 module-by-module database connectivity

Status after batches 1–3. **36 of 57 restored page modules use MySQL** (16 at Phase 8). Each live page below was tested through the restored UI with MySQL checks, refresh, re-login, role restrictions and a 390 px viewport (see test tables).

| Module | Pages | Status | Evidence |
|---|---|---|---|
| Dashboard | dashboard | **Live** — contacts, trips, departures, bookings, my tasks, callbacks, notifications, recent activity; finance KPIs for finance roles | KPIs equal `/api/dashboard` and SQL counts (owner and scoped advisor) |
| Quotes | payments › Quotes | **Live** — create (lines, discount, tax rate), edit, mark sent, decision, convert, duplicate, archive | 2 × 1350.00 − 10% = 2430.00 stored by server |
| Invoices | payments › Invoices, invoices | **Live** — create, issue, record payment, adjustment, void, print | Issued INV number; balance 1430.00 after 1000.00 |
| Client payments, refunds | payments › Client payments, Refunds | **Live** — record, verify (staff), reverse, refund; labelled not processor-verified | Verify then reverse restores 2430.00 |
| Supplier payables | payments › Supplier payables | **Live** — create, pay, reverse supplier payment | 1500.00 payable, 500.00 paid |
| Commission | commission | **Live** — overview, records (earn, receipt, payout, adjust, reverse), rules, financial log + integrity check | Rule stored; log verified |
| Commission invoices | cominv | Honest "not implemented" page (no backend) | — |
| Reports, Analytics | reporting (Reports tab), reporting2 | **Live** — 9 server reports, filters, server CSV | Rows equal API |
| Report builder, AI assist | reporting tabs | Prototype, labelled in-page | — |
| Tasks | tasks | **Live** — create, assign, edit, complete, reopen, comment, archive, filters, history | All steps in MySQL; Restricted record for hidden contacts |
| Calendar | calendar | **Live** — tasks, trip departures, callbacks | Correct days |
| Notifications + bell | notifications, top bar | **Live** — badge = server unread; read/unread/dismiss persist | MySQL read_at/dismissed_at |
| Emails | emails | Inbox: honest "inbound not connected"; Campaigns/Segments: honest "not implemented" | — |
| Outbox | outbox | **Live** — compose, template, draft, queue, attempts, cancel, resolve unknown | Real SMTP accepted; "Accepted by mail server" |
| Email templates | emt | **Live** — create, edit, duplicate, archive, preview, allowlisted variables | Unknown variable refused |
| Automations, builder, editor, workflows | automations, autobuild, autoed, workflows | **Live** — server-validated builder, enable/disable/duplicate/archive, test preview, execution history, retry | UI-built workflow ran from a real event |
| Follow-ups, scheduler status | automations › Upcoming runs | **Live** | 09:30 Manila → 01:30 UTC |
| Workflow library | libwf | Honest "not implemented" | — |
| Earlier phases (contacts, staff accounts, trips, itineraries, portal, groups, forms, documents, suppliers, bookings, library, calls) | 17 pages | Live since Phases 2–7 | Regression suites |
| **Still prototype (labelled)** | agency, settings, roles, team, integ, libd, libt, libta, rpc, rpcl, rpq, rpview, ai, scheduling, client, trav, preview, comm, help, hsup, terms | Batch 4 | — |
