# Financial calculation and reconciliation rules

**Precision.** Amounts are converted to integers in the currency's minor unit and multiplied/divided with decimal-string long arithmetic (`Money::mulDiv`), rounding half up. Binary floating point is never used for stored values. Amounts with more decimals than the currency allows are rejected, not rounded. Supported: 0- and 2-decimal currencies; 3-decimal currencies are inactive (columns hold two decimals).

**Quote and invoice totals (server-side only; client totals are ignored).**
1. line total = round(unit price × quantity), quantity to 2 decimals
2. subtotal = sum of line totals
3. discount = round(subtotal × percent) or a fixed amount no larger than the subtotal
4. discount on taxable lines = round(discount × taxable subtotal ÷ subtotal)
5. tax = round((taxable subtotal − discount on taxable lines) × configured rate)
6. total = subtotal − discount + tax

**Accepted quotes.** Accepting stores a JSON snapshot of lines, totals and terms with its SHA-256; the quote can no longer be edited, and conversion copies the snapshot (not the live quote) into one invoice (unique `quote_id`). Acceptance is recorded by staff: an ordinary electronic confirmation, not a digital signature.

**Invoice balance.** amount due = total + adjustments; paid = recorded payments − their refunds (reversed payments excluded); balance = amount due − paid. Status: paid when balance ≤ 0, partially paid when paid > 0, otherwise issued; overdue is shown when issued/partially paid, past due date and balance > 0. Payments above the balance are rejected (no automatic credit). Issued invoices change only through adjustments or a void (void requires no active payments and a reason).

**Installments.** The installments must add up exactly to the amount due. Allocations are re-derived first-in-first-out from active payments (net of refunds) after every change, so a payment is never counted twice.

**Invoice numbers.** `INV-YYYY-NNNNN` from a per-year counter locked inside the issuing transaction; parallel issue attempts yield one success and one refusal.

**Supplier payables.** balance = amount − recorded payments and credits; payments above the balance are rejected; amount changes need a reason and cannot go below what was paid.

**Commissions.** Rule priority: supplier + category, supplier, category, default; flat rules apply only to bookings in the same currency. expected = percent × booking amount or the flat amount; advisor share = share % × expected. Receipts (agency income) and advisor payouts (compensation) are separate records. Status: reversed; paid (agency fully received and advisor fully paid); payable (money received and advisor share outstanding); received; earned (marked earned, or confirmed booking whose travel date has passed); expected. Payouts require a receipt first; cancelling a booking reverses its commission when nothing has been received.

**Currency.** Totals are always grouped per currency. Conversion to the reporting currency uses the latest manual rate on or before the period end (direct or inverse); currencies without a rate are listed, never guessed.
