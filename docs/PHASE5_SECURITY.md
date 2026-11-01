# Phase 5 security and authorization

| Permission | Granted to | Allows |
|---|---|---|
| finance.view | owner, admin, supervisor, advisor, finance, operations | Read quotes, invoices, payments, payables, commissions (scoped), dashboard, reports |
| finance.invoice (new) | owner, admin, supervisor, advisor, finance | Create, edit, send, record decisions, convert, issue |
| finance.record | owner, admin, operations, finance | Record payments, schedules, adjustments, payables and supplier payments |
| finance.void (new) | owner, admin, finance | Void invoices, reverse payments, refunds, reverse supplier payments |
| finance.reconcile | owner, admin, finance | Commission rules, receipts, adjustments, reversals, staff confirmation of payments, exchange and tax rates, audit trail |
| finance.payout | owner, finance | Advisor payouts |

- Row scope: financial documents follow trip scope (or contact scope when no trip); advisors see only their own commissions; out-of-scope records return 404; reports and CSV exports use the same scope.
- All writes run in transactions with row locks (`FOR UPDATE`) on the invoice, payable or commission being changed.
- Duplicate protection: `Idempotency-Key` header replays the stored response for the same user and request (200 + `Idempotent-Replay: true`), refuses reuse with a different body, and reports in-progress duplicates; unique keys protect quote conversion, invoice numbers and payable references.
- Payment records are labelled *unverified* until a finance user confirms them against a statement; nothing claims external settlement. References that look like card or account numbers are refused; no card, CVV or banking credentials are accepted.
- `financial_audit_events` records actor, action, entity, before/after values and reason for every financial write, chained by SHA-256; `/finance/audit/verify` detects any direct edit (tested by tampering). The application has no update or delete for this table.
- CSRF protection on all writes; printable invoices are escaped HTML with a restrictive Content-Security-Policy; CSV cells are protected against formula injection.
