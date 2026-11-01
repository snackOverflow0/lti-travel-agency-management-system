# Phase 5 financial module audit

## Prerequisites verified (before any Phase 5 change)
Login, roles, contacts, trips, bookings, suppliers and booking travelers verified by the Phase 2–4 suites on the unmodified code: API 20/20, 15/15, 29/29; browser 7/7 (×3), 11/11, 12/12. One Phase 4 browser test failed intermittently because of a race in the test itself (it switched forms before the app finished opening the duplicate); the test now waits, and the app behaviour was unaffected. No blockers.

## Features
| Feature | Restored screen (Revision 27) | Data source before | Tables before | Endpoint before | Implemented now | Security | Tests |
|---|---|---|---|---|---|---|---|
| Quotes | No dedicated screen (trip Proposal section is itinerary approval) | — | `quotations` (basic), `document_lines` | None | Full lifecycle API; acceptance snapshots | finance.invoice; trip/contact scope | TC01–TC07 |
| Invoices | Payments › Invoices tab | Browser sample (`X.invoices`) | `invoices` (basic) | None | Draft, issue with locked numbering, adjustments, void, printable view | finance.invoice / finance.void; scope | TC07–TC09 |
| Client payments | Payments › History, Schedule | Browser sample (`X.pay`) | `payments` (basic) | None | Record, reverse, staff confirmation, refunds | finance.record / finance.void / finance.reconcile | TC10–TC14, TC35 |
| Schedules and deposits | Payments › Schedule | Browser sample | `payment_schedules` (booking-based) | None | Installments per invoice with exact reconciliation and derived allocations | finance.record | TC15–TC16 |
| Card authorizations | Payments › Authorizations | Browser sample | — | — | **Not implemented by design**: no card data or processor (zero-cost, no credentials stored) | — | — |
| Refunds | Payments › Refunds | Browser sample | — | — | Refund records against payments | finance.void | TC16 |
| Supplier costs and payables | Bookings, Commission | Booking `supplier_cost` only | — | — | Payables, partial payments, credits, reversals | finance.record / finance.void | TC17–TC19 |
| Commissions | Commission (7 tabs), Commission invoices | Browser sample (`X.comm`, `X.payouts`, `X.stmts`) | `commissions` (basic) | None | Rules, calculation, receipts, advisor payouts, adjustments, reversal | finance.reconcile / finance.payout; advisors see own | TC20–TC24 |
| Currency | — | Assumed USD | `trips.currency` | — | Currencies, minor units, manual dated rates, reporting currency | finance.reconcile for rates | TC25 |
| Dashboard | Back office dashboard | Browser sample | — | — | `/finance/dashboard` with defined metrics | finance.view; scope | TC26 |
| Reports | Reporting | Browser sample | — | — | 9 reports, JSON and CSV | finance.view; scope | TC27 |
| Supplier statement import and matching | Commission › Statements & reconciliation | Browser sample | — | — | **Not implemented** (receipts are recorded per commission) | — | — |

## Still to do (increment 2)
Connect the restored Payments, Commission, Commission invoices, Reporting and dashboard screens to these APIs; add a quote screen within the existing financial area; screen-level tests for TC01–TC30.
