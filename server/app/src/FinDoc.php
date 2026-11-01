<?php
declare(strict_types=1);
namespace LTI;
use LTI\Controllers\{ContactController, TripController};
/** Shared rules for quotes and invoices: scope, line validation, authoritative totals, invoice refresh. */
final class FinDoc {
    /** SQL scope for documents linked to a trip (alias t) or, when no trip, a contact (alias c). */
    public static function scopeSql(array $u, string $alias): array {
        [$tw, $tp] = TripController::scope($u); [$cw, $cp] = ContactController::scope($u);
        return ["(($alias.trip_id IS NOT NULL AND $tw) OR ($alias.trip_id IS NULL AND $alias.contact_id IS NOT NULL AND $cw))", array_merge($tp, $cp)];
    }
    public static function checkLinks(array $u, array $d): array {
        $trip = !empty($d['tripId']) ? TripController::findRow($u, (int)$d['tripId']) : null;
        $contact = !empty($d['contactId']) ? ContactController::find($u, (int)$d['contactId']) : null;
        if (!$trip && !$contact) throw new HttpError(422, Validate::MSG, ['tripId' => 'Link the document to a trip or a contact']);
        if (!$contact && $trip && $trip['primary_contact_id']) $contact = Db::one('SELECT id FROM contacts WHERE id=?', [$trip['primary_contact_id']]);
        return [$trip ? (int)$trip['id'] : null, $contact ? (int)$contact['id'] : null, $trip];
    }
    /** Validate lines and compute totals in minor units. Never trusts client-side totals. */
    public static function compute(array $d, string $ccy): array {
        $minor = Money::minorUnits($ccy); $lines = is_array($d['lines'] ?? null) ? array_values($d['lines']) : [];
        if (count($lines) > 200) throw new HttpError(422, Validate::MSG, ['lines' => 'Maximum 200 lines']);
        $out = []; $sub = 0; $taxable = 0;
        foreach ($lines as $i => $l) {
            $desc = Validate::str($l, 'description', 255, true);
            $qty = Money::scaled((string)($l['quantity'] ?? '1'), 2, "lines.$i.quantity"); if ($qty === '0' || strlen($qty) > 6) throw new HttpError(422, Validate::MSG, ["lines.$i.quantity" => 'Quantity must be between 0.01 and 9999.99']);
            $unit = Money::parse($l['unitPrice'] ?? '0', $minor, "lines.$i.unitPrice");
            $total = Money::mulDiv($unit, $qty, '100'); $tx = !isset($l['taxable']) || !empty($l['taxable']);
            $bk = !empty($l['bookingId']) ? (int)$l['bookingId'] : null; if ($bk && !Db::one('SELECT id FROM bookings WHERE id=?', [$bk])) throw new HttpError(422, Validate::MSG, ["lines.$i.bookingId" => 'Unknown booking']);
            $sp = !empty($l['supplierId']) ? (int)$l['supplierId'] : null; if ($sp && !Db::one('SELECT id FROM suppliers WHERE id=?', [$sp])) throw new HttpError(422, Validate::MSG, ["lines.$i.supplierId" => 'Unknown supplier']);
            $out[] = ['description' => $desc, 'qty' => $qty, 'unit' => $unit, 'total' => $total, 'taxable' => $tx, 'bookingId' => $bk, 'supplierId' => $sp]; $sub += $total; if ($tx) $taxable += $total;
        }
        $dt = Validate::enum($d, 'discountType', ['none', 'percent', 'fixed'], 'none'); $dv = '0'; $disc = 0;
        if ($dt === 'percent') { $dv = Money::scaled((string)($d['discountValue'] ?? '0'), 4, 'discountValue'); if (strlen($dv) > 7 || (int)$dv > 1000000) throw new HttpError(422, Validate::MSG, ['discountValue' => 'At most 100%']); $disc = Money::pct($sub, $dv); }
        elseif ($dt === 'fixed') { $disc = Money::parse($d['discountValue'] ?? '0', $minor, 'discountValue'); if ($disc > $sub) throw new HttpError(422, Validate::MSG, ['discountValue' => 'Discount cannot exceed the subtotal']); $dv = (string)$disc; }
        $taxName = null; $taxRate = '0';
        if (!empty($d['taxRateId'])) { $t = Db::one('SELECT * FROM tax_rates WHERE id=? AND is_active=1', [(int)$d['taxRateId']]); if (!$t) throw new HttpError(422, Validate::MSG, ['taxRateId' => 'Unknown or inactive tax rate']); $taxName = $t['name']; $taxRate = Money::scaled($t['rate'], 4, 'taxRate'); }
        $discTaxable = $sub > 0 ? Money::mulDiv($disc, $taxable, $sub) : 0; $tax = Money::pct($taxable - $discTaxable, $taxRate);
        return ['lines' => $out, 'subtotal' => $sub, 'discountType' => $dt, 'discountStored' => $dt === 'percent' ? Money::fmt((int)$dv, 4) : ($dt === 'fixed' ? Money::fmt($disc, $minor) : '0'),
            'discount' => $disc, 'taxName' => $taxName, 'taxRate' => Money::fmt((int)$taxRate, 4), 'taxRateId' => !empty($d['taxRateId']) ? (int)$d['taxRateId'] : null, 'tax' => $tax, 'total' => $sub - $disc + $tax, 'minor' => $minor];
    }
    public static function saveLines(string $col, int $docId, array $c): void {
        Db::exec("DELETE FROM document_lines WHERE $col=?", [$docId]);
        foreach ($c['lines'] as $i => $l) Db::exec("INSERT INTO document_lines ($col, description, quantity, unit_amount, booking_id, supplier_id, is_taxable, line_total, sort_order) VALUES (?,?,?,?,?,?,?,?,?)",
            [$docId, $l['description'], Money::fmt((int)$l['qty'], 2), Money::fmt($l['unit'], $c['minor']), $l['bookingId'], $l['supplierId'], $l['taxable'] ? 1 : 0, Money::fmt($l['total'], $c['minor']), $i]);
    }
    public static function lines(string $col, int $id, ?string $ccy = null): array {
        $sh = function ($v) use ($ccy) { return $ccy ? Money::show($v, $ccy) : $v; };
        return array_map(function ($r) use ($sh) { return ['description' => $r['description'], 'quantity' => $r['quantity'], 'unitPrice' => $sh($r['unit_amount']), 'lineTotal' => $sh($r['line_total']), 'taxable' => (bool)$r['is_taxable'],
            'bookingId' => $r['booking_id'] === null ? null : (int)$r['booking_id'], 'supplierId' => $r['supplier_id'] === null ? null : (int)$r['supplier_id']]; }, Db::all("SELECT * FROM document_lines WHERE $col=? ORDER BY sort_order, id", [$id]));
    }
    /** Recompute paid amount, balance, status and installment allocations for an invoice. Call inside a transaction. */
    public static function refreshInvoice(int $id): array {
        $inv = Db::one('SELECT * FROM invoices WHERE id=? FOR UPDATE', [$id]); $m = Money::minorUnits($inv['currency']); $p = function ($v) use ($m) { return Money::parse((string)$v, $m, 'amount', true); };
        $adj = 0; foreach (Db::all('SELECT amount FROM invoice_adjustments WHERE invoice_id=?', [$id]) as $r) $adj += $p($r['amount']);
        $payments = Db::all("SELECT id, amount FROM payments WHERE invoice_id=? AND status='recorded' ORDER BY received_on, id", [$id]);
        $paid = 0; foreach ($payments as $r) $paid += $p($r['amount']);
        $ref = 0; foreach (Db::all("SELECT r.amount FROM refunds r JOIN payments x ON x.id=r.payment_id WHERE x.invoice_id=? AND x.status='recorded'", [$id]) as $r) $ref += $p($r['amount']);
        $net = $paid - $ref; $due = $p($inv['total']) + $adj; $bal = $due - $net;
        $status = $inv['status'];
        if (!in_array($status, ['draft', 'void'], true)) $status = $bal <= 0 && ($due > 0 || $net > 0) ? 'paid' : ($net > 0 ? 'partially_paid' : 'issued');
        Db::exec('UPDATE invoices SET adjustments_total=?, amount_paid=?, balance=?, status=?, updated_at=UTC_TIMESTAMP() WHERE id=?', [Money::fmt($adj, $m), Money::fmt($net, $m), Money::fmt($inv['status'] === 'void' ? 0 : $bal, $m), $status, $id]);
        // installments: re-derive allocations first-in-first-out from active payments (net of refunds), so nothing is counted twice
        $inst = Db::all('SELECT id, amount FROM payment_schedules WHERE invoice_id=? ORDER BY due_date, sort_order, id', [$id]);
        if ($inst) { Db::exec('DELETE a FROM payment_allocations a JOIN payment_schedules s ON s.id=a.installment_id WHERE s.invoice_id=?', [$id]);
            $left = []; foreach ($inst as $i) $left[(int)$i['id']] = $p($i['amount']);
            $pool = $net; foreach ($payments as $pay) { $avail = min($p($pay['amount']), max(0, $pool)); $pool -= $avail;
                foreach ($left as $iid => $rem) { if ($avail <= 0) break; if ($rem <= 0) continue; $take = min($rem, $avail); $left[$iid] -= $take; $avail -= $take;
                    Db::exec('INSERT INTO payment_allocations (payment_id, installment_id, amount) VALUES (?,?,?)', [$pay['id'], $iid, Money::fmt($take, $m)]); } }
            foreach ($inst as $i) { $iid = (int)$i['id']; $st = $left[$iid] <= 0 ? 'paid' : ($left[$iid] < $p($i['amount']) ? 'partially_paid' : 'scheduled'); Db::exec('UPDATE payment_schedules SET status=? WHERE id=?', [$st, $iid]); } }
        return Db::one('SELECT * FROM invoices WHERE id=?', [$id]);
    }
}
