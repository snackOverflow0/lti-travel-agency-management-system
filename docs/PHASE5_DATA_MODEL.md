# Phase 5 entity relationships (migration `006_phase5.sql`, additive)

```
currencies (code, minor_units) <- every money record's currency
exchange_rates (from, to, rate DECIMAL(20,8), effective_date, source_note)   app_settings (reporting_currency)   tax_rates
trips 1-* quotations 1-* document_lines *-1 bookings / suppliers
quotations 1-1 quote_acceptances (decision, immutable snapshot JSON + SHA-256)
quotations 1-0..1 invoices (UNIQUE quote_id) ; invoices *-1 trips / contacts / bookings
invoices 1-* document_lines ; invoices 1-* invoice_adjustments ; invoice_sequences (per-year counter)
invoices 1-* payment_schedules (installments) 1-* payment_allocations *-1 payments
invoices 1-* payments 1-* refunds
bookings 1-* supplier_payables (UNIQUE booking_id + supplier_invoice_ref) 1-* supplier_payments ; supplier_payables *-1 suppliers
commission_rules (supplier / category / default; percent or flat; advisor share %)
bookings 1-0..1 commissions (UNIQUE booking_id) *-1 users (advisor)
commissions 1-* commission_receipts (agency income) ; 1-* advisor_payouts (staff compensation) ; 1-* commission_adjustments
financial_audit_events (append-only, hash chain) ; idempotency_keys (UNIQUE user + key)
```
Existing tables extended rather than duplicated: `quotations`, `invoices`, `document_lines`, `payment_schedules`, `payments`, `commissions`, `bookings` (currency). Legacy status values are mapped before the ENUMs change (declined→rejected, sent→issued, matched/partially_received→received, paid_out→paid, cancelled→reversed). All amounts are DECIMAL; all tables InnoDB/utf8mb4 with foreign keys.
