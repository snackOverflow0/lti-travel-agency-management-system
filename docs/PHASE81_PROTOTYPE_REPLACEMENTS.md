# Prototype data sources removed or replaced (batches 1–3)

| Prototype source | Used by | Classification | Replacement |
|---|---|---|---|
| `S.trips`, `S.cm`, `S.tasks`, `X.bookings`, `X.pay`, `cmStats()` | Dashboard figures | Fabricated business data | `GET /api/dashboard`, `GET /api/finance/dashboard` |
| `X.pay`, `X.invoices` | Payments, Invoices | Fabricated financial records | `/api/quotes`, `/api/invoices`, `/api/payments`, `/api/payables` |
| `X.comm`, `X.cominv` | Commission, Commission invoices | Fabricated financial records | `/api/commissions`, `/api/commission-rules`, `/api/finance/audit`; cominv → not-implemented page |
| `rpData()` mock report rows | Reporting, Analytics | Fabricated report data | `/api/finance/reports?name=…` |
| `S.tasks`, `calEvents()` | Tasks, Calendar | Fabricated tasks/events | `/api/tasks`, `/api/trips`, `/api/callbacks` |
| `X.notifs`, `notify()` | Notifications, top-bar bell | Simulated notifications | `/api/notifications` (bell synced; `notify()` disabled) |
| `X.outbox`, `X.mail`, `X.etpl` | Emails, Outbox, Templates | Simulated inbox/sends | `/api/emails`, `/api/email-templates`; inbox → honest unavailable page |
| `X.autos`, `X.wfs`, `X.runs`, demo clock (`X.today`) | Automations, builder, workflows | Simulated automation engine | `/api/workflows` (+ meta, test, executions), `/api/followups`, `/api/jobs/status`; demo clock removed |
| Browser storage | All of the above | Not used for business records (storage guard since Phase 1) | — |
| UI preferences (`S.tab`, filters, view modes) | All pages | Legitimate UI state (memory only) | Kept |

The original Revision 27 script is unchanged on disk; replaced page renderers are overridden at runtime, so the approved assets remain until every module is replaced and tested.
