# Database integration verification matrix

Migrations 001–009 apply in order on an empty database (every suite starts from one). 87 tables, 187 foreign keys (counted in the restored copy). Constraint names verified unique across migrations. Persistence verified by re-reading through the API in new sessions and by direct SQL in the tests.

| Module | Create via | API verified | MySQL verified | Refresh / re-login | Edit | Archive / cancel | Audit |
|---|---|---|---|---|---|---|---|
| Contacts | Screen | Yes | Yes | Yes (browser) | Yes | Yes | audit_log, activity |
| Trips, itinerary | Screen | Yes | Yes | Yes (browser) | Yes | Yes (archive) | audit_log, versions |
| Bookings, suppliers | Screen | Yes | Yes | Yes (browser ×3) | Yes | Yes (cancel) | audit_log |
| Groups, forms, library | Screen | Yes | Yes | Yes (browser) | Yes | Yes | audit_log |
| Documents | Screen | Yes | Yes (sha256) | Yes | Yes | Yes | audit_log |
| Quotes, invoices, payments | **API only** | Yes | Yes | Yes (F11) | Yes | Void/reverse | financial hash chain (F07) |
| Payables, commissions | **API only** | Yes | Yes | Yes (Phase 5) | Yes | Yes | financial hash chain |
| Tasks, email, workflows, follow-ups, notifications | **API only** | Yes | Yes | Yes (Phase 6) | Yes | Yes | task_history, audit_log |
| Calls, callbacks, dispositions | Screen | Yes | Yes | Yes (browser + re-login) | Yes | Yes | call_events, audit_log |

Screen-level persistence for the API-only rows is not verified because those screens are not connected.
