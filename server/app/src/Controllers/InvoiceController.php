<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Auth, Db, FinAudit, FinDoc, Http, HttpError, Money, Validate, Events};
final class InvoiceController {
    public static function find(array $u, int $id, bool $lock = false): array {
        [$w, $p] = FinDoc::scopeSql($u, 'i');
        $r = Db::one("SELECT i.*, t.name trip_name, CONCAT_WS(' ', c.first_name, c.last_name) contact_name FROM invoices i LEFT JOIN trips t ON t.id=i.trip_id LEFT JOIN contacts c ON c.id=i.contact_id WHERE i.id=? AND $w" . ($lock ? ' FOR UPDATE' : ''), array_merge([$id], $p));
        if (!$r) throw new HttpError(404, 'Invoice not found'); return $r;
    }
    public static function displayStatus(array $r): string { return in_array($r['status'], ['issued', 'partially_paid'], true) && $r['due_date'] && $r['due_date'] < gmdate('Y-m-d') && (float)$r['balance'] > 0 ? 'overdue' : $r['status']; }
    private static function out(array $r, bool $full = false): array {
        $o = ['id' => (int)$r['id'], 'number' => $r['number'], 'status' => self::displayStatus($r), 'storedStatus' => $r['status'], 'tripId' => $r['trip_id'] === null ? null : (int)$r['trip_id'], 'tripName' => $r['trip_name'] ?? null,
            'contactId' => $r['contact_id'] === null ? null : (int)$r['contact_id'], 'contactName' => $r['contact_name'] ?? null, 'bookingId' => $r['booking_id'] === null ? null : (int)$r['booking_id'], 'quoteId' => $r['quote_id'] === null ? null : (int)$r['quote_id'],
            'currency' => $r['currency'], 'subtotal' => Money::show($r['subtotal'], $r['currency']), 'discountType' => $r['discount_type'], 'discountValue' => $r['discount_value'], 'discountTotal' => Money::show($r['discount_total'], $r['currency']), 'taxName' => $r['tax_name'], 'taxRate' => $r['tax_rate'], 'taxTotal' => Money::show($r['tax_total'], $r['currency']),
            'total' => Money::show($r['total'], $r['currency']), 'adjustmentsTotal' => Money::show($r['adjustments_total'], $r['currency']), 'amountPaid' => Money::show($r['amount_paid'], $r['currency']), 'balance' => Money::show($r['balance'], $r['currency']), 'issueDate' => $r['issue_date'], 'dueDate' => $r['due_date'], 'paymentTerms' => $r['payment_terms'], 'terms' => $r['terms'], 'notes' => $r['notes'],
            'issuedAt' => $r['issued_at'], 'voidedAt' => $r['voided_at'], 'voidReason' => $r['void_reason'], 'version' => (int)$r['version']];
        if ($full) { $id = (int)$r['id']; $o['lines'] = FinDoc::lines('invoice_id', $id, $r['currency']); $ccy = $r['currency'];
            $o['adjustments'] = array_map(function ($a) use ($ccy) { return ['id' => (int)$a['id'], 'amount' => Money::show($a['amount'], $ccy), 'reason' => $a['reason'], 'by' => $a['full_name'], 'at' => $a['created_at']]; }, Db::all('SELECT a.*, u.full_name FROM invoice_adjustments a LEFT JOIN users u ON u.id=a.created_by WHERE a.invoice_id=? ORDER BY a.id', [$id]));
            $o['payments'] = array_map([PaymentController::class, 'out'], Db::all('SELECT p.*, u.full_name recorder FROM payments p LEFT JOIN users u ON u.id=p.recorded_by WHERE p.invoice_id=? ORDER BY p.received_on, p.id', [$id]));
            $o['installments'] = array_map(function ($s) use ($ccy) { return ['id' => (int)$s['id'], 'label' => $s['label'], 'dueDate' => $s['due_date'], 'amount' => Money::show($s['amount'], $ccy), 'isDeposit' => (bool)$s['is_deposit'], 'paid' => Money::show($s['paid'] ?? '0', $ccy),
                'status' => $s['status'] !== 'paid' && $s['due_date'] < gmdate('Y-m-d') ? 'overdue' : $s['status']]; }, Db::all('SELECT s.*, (SELECT COALESCE(SUM(a.amount),0) FROM payment_allocations a WHERE a.installment_id=s.id) paid FROM payment_schedules s WHERE s.invoice_id=? ORDER BY s.due_date, s.sort_order, s.id', [$id])); }
        return $o;
    }
    public static function list(): array {
        $u = Auth::require('finance.view'); [$w, $p] = FinDoc::scopeSql($u, 'i'); $where = [$w];
        if (($s = (string)($_GET['status'] ?? '')) !== '') { Validate::enum(['s' => $s], 's', ['draft', 'issued', 'partially_paid', 'paid', 'overdue', 'void']);
            if ($s === 'overdue') { $where[] = "i.status IN ('issued','partially_paid') AND i.due_date < ? AND i.balance > 0"; $p[] = gmdate('Y-m-d'); } else { $where[] = 'i.status=?'; $p[] = $s; } }
        foreach (['tripId' => 'i.trip_id', 'contactId' => 'i.contact_id'] as $k => $c) if (($v = (int)($_GET[$k] ?? 0)) > 0) { $where[] = "$c=?"; $p[] = $v; }
        if (($c = (string)($_GET['currency'] ?? '')) !== '') { $where[] = 'i.currency=?'; $p[] = Money::currency($c); }
        if (($q = trim((string)($_GET['q'] ?? ''))) !== '') { $l = '%' . addcslashes($q, '%_\\') . '%'; $where[] = "(i.number LIKE ? OR t.name LIKE ? OR CONCAT_WS(' ', c.first_name, c.last_name) LIKE ?)"; array_push($p, $l, $l, $l); }
        $page = max(1, (int)($_GET['page'] ?? 1)); $per = min(200, max(1, (int)($_GET['perPage'] ?? 50))); $from = 'FROM invoices i LEFT JOIN trips t ON t.id=i.trip_id LEFT JOIN contacts c ON c.id=i.contact_id WHERE ' . implode(' AND ', $where);
        return ['total' => (int)Db::one("SELECT COUNT(*) n $from", $p)['n'], 'items' => array_map([self::class, 'out'], Db::all("SELECT i.*, t.name trip_name, CONCAT_WS(' ', c.first_name, c.last_name) contact_name $from ORDER BY i.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p))];
    }
    public static function get(array $pa): array { $u = Auth::require('finance.view'); return ['invoice' => self::out(self::find($u, (int)$pa['id']), true)]; }
    public static function insertDraft(array $u, array $d, ?array $taxSnap = null): int {
        [$trip, $contact, $tr] = FinDoc::checkLinks($u, $d); $ccy = Money::currency($d['currency'] ?? ($tr['currency'] ?? 'USD'));
        $bk = !empty($d['bookingId']) ? (int)$d['bookingId'] : null; if ($bk && !Db::one('SELECT id FROM bookings WHERE id=? AND (? IS NULL OR trip_id=?)', [$bk, $trip, $trip])) throw new HttpError(422, Validate::MSG, ['bookingId' => 'Booking not found on this trip']);
        $c = FinDoc::compute($d, $ccy);
        if ($taxSnap && $taxSnap['name']) { $c['taxName'] = $taxSnap['name']; $c['taxRate'] = $taxSnap['rate']; $tax = Money::pct(array_sum(array_map(function ($l) { return $l['taxable'] ? $l['total'] : 0; }, $c['lines'])) - ($c['subtotal'] > 0 ? Money::mulDiv($c['discount'], array_sum(array_map(function ($l) { return $l['taxable'] ? $l['total'] : 0; }, $c['lines'])), $c['subtotal']) : 0), Money::scaled($taxSnap['rate'], 4, 'taxRate')); $c['tax'] = $tax; $c['total'] = $c['subtotal'] - $c['discount'] + $tax; }
        $m = $c['minor'];
        $id = Db::insert("INSERT INTO invoices (number, trip_id, contact_id, booking_id, quote_id, currency, status, subtotal, discount_type, discount_value, discount_total, tax_name, tax_rate, tax_total, total, balance, due_date, payment_terms, terms, notes, created_by, created_at, updated_at, version)
            VALUES (?,?,?,?,?,?,'draft',?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)", ['DRAFT-' . bin2hex(random_bytes(8)), $trip, $contact, $bk, $d['quoteId'] ?? null, $ccy, Money::fmt($c['subtotal'], $m), $c['discountType'], $c['discountStored'], Money::fmt($c['discount'], $m), $c['taxName'], $c['taxRate'], Money::fmt($c['tax'], $m), Money::fmt($c['total'], $m), Money::fmt($c['total'], $m),
            Validate::date($d, 'dueDate'), Validate::str($d, 'paymentTerms', 190), Validate::str($d, 'terms', 10000), Validate::str($d, 'notes', 5000), (int)$u['id']]);
        Db::exec('UPDATE invoices SET number=? WHERE id=?', ['DRAFT-' . $id, $id]); FinDoc::saveLines('invoice_id', $id, $c);
        FinAudit::log((int)$u['id'], 'invoice.created', 'invoice', $id, null, ['total' => Money::fmt($c['total'], $m), 'currency' => $ccy, 'quoteId' => $d['quoteId'] ?? null]);
        return $id;
    }
    public static function create(): array { $u = Auth::require('finance.invoice'); $d = Http::body(); $id = Db::tx(function () use ($u, $d) { return self::insertDraft($u, $d); }); return ['invoice' => self::out(self::find($u, $id), true)]; }
    public static function update(array $pa): array {
        $u = Auth::require('finance.invoice'); $d = Http::body();
        Db::tx(function () use ($u, $pa, $d) { $cur = self::find($u, (int)$pa['id'], true);
            if ($cur['status'] !== 'draft') throw new HttpError(409, 'Issued invoices cannot be edited. Record an adjustment or void the invoice.');
            if ($cur['quote_id']) throw new HttpError(409, 'Invoices created from an accepted quote keep the accepted pricing. Record an adjustment after issuing instead.');
            if (isset($d['version']) && (int)$d['version'] !== (int)$cur['version']) throw new HttpError(409, 'This invoice was changed by someone else. Reload and try again.');
            $d = array_merge(['tripId' => $cur['trip_id'], 'contactId' => $cur['contact_id'], 'currency' => $cur['currency'], 'dueDate' => $cur['due_date'], 'paymentTerms' => $cur['payment_terms'], 'terms' => $cur['terms'], 'notes' => $cur['notes'], 'lines' => FinDoc::lines('invoice_id', (int)$cur['id'], $cur['currency'])], $d);
            [$trip, $contact, $tr] = FinDoc::checkLinks($u, $d); $ccy = Money::currency($d['currency']); $c = FinDoc::compute($d, $ccy); $m = $c['minor'];
            Db::exec('UPDATE invoices SET trip_id=?, contact_id=?, currency=?, subtotal=?, discount_type=?, discount_value=?, discount_total=?, tax_name=?, tax_rate=?, tax_total=?, total=?, balance=?, due_date=?, payment_terms=?, terms=?, notes=?, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?',
                [$trip, $contact, $ccy, Money::fmt($c['subtotal'], $m), $c['discountType'], $c['discountStored'], Money::fmt($c['discount'], $m), $c['taxName'], $c['taxRate'], Money::fmt($c['tax'], $m), Money::fmt($c['total'], $m), Money::fmt($c['total'], $m),
                 Validate::date($d, 'dueDate'), Validate::str($d, 'paymentTerms', 190), Validate::str($d, 'terms', 10000), Validate::str($d, 'notes', 5000), $cur['id']]);
            FinDoc::saveLines('invoice_id', (int)$cur['id'], $c); FinAudit::log((int)$u['id'], 'invoice.updated', 'invoice', (int)$cur['id'], ['total' => $cur['total']], ['total' => Money::fmt($c['total'], $m)]); });
        return self::get($pa);
    }
    /** Issue: assign the next number from a locked per-year sequence inside the transaction (no duplicates, even concurrently). */
    public static function issue(array $pa): array {
        $u = Auth::require('finance.invoice'); $d = Http::body();
        Db::tx(function () use ($u, $pa, $d) { $inv = self::find($u, (int)$pa['id'], true); if ($inv['status'] !== 'draft') throw new HttpError(409, 'This invoice has already been issued');
            if (!Db::one('SELECT id FROM document_lines WHERE invoice_id=? LIMIT 1', [$inv['id']])) throw new HttpError(422, 'Add at least one line item before issuing');
            $issue = Validate::date($d, 'issueDate') ?: gmdate('Y-m-d'); $due = Validate::date($d, 'dueDate') ?: $inv['due_date']; if (!$due) throw new HttpError(422, Validate::MSG, ['dueDate' => 'Set a due date']); if ($due < $issue) throw new HttpError(422, Validate::MSG, ['dueDate' => 'Due date is before the issue date']);
            $year = (int)substr($issue, 0, 4); Db::exec('INSERT IGNORE INTO invoice_sequences (seq_year, next_value) VALUES (?, 1)', [$year]);
            $seq = (int)Db::one('SELECT next_value FROM invoice_sequences WHERE seq_year=? FOR UPDATE', [$year])['next_value']; Db::exec('UPDATE invoice_sequences SET next_value=next_value+1 WHERE seq_year=?', [$year]);
            $number = sprintf('INV-%d-%05d', $year, $seq);
            Db::exec("UPDATE invoices SET number=?, status='issued', issue_date=?, due_date=?, issued_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?", [$number, $issue, $due, $inv['id']]);
            FinDoc::refreshInvoice((int)$inv['id']); Events::emit('invoice.issued', 'invoice', (int)$inv['id'], (int)$u['id'], 'Invoice ' . $number . ' issued');
            if ($inv['contact_id']) Events::activity('contact', (int)$inv['contact_id'], 'invoice.issued', (int)$u['id'], 'Invoice ' . $number . ' issued'); FinAudit::log((int)$u['id'], 'invoice.issued', 'invoice', (int)$inv['id'], ['status' => 'draft'], ['status' => 'issued', 'number' => $number, 'total' => $inv['total']]); });
        return self::get($pa);
    }
    public static function adjust(array $pa): array {
        $u = Auth::require('finance.record'); $d = Http::body(); $reason = Validate::str($d, 'reason', 255, true);
        Db::tx(function () use ($u, $pa, $d, $reason) { $inv = self::find($u, (int)$pa['id'], true); if (in_array($inv['status'], ['draft', 'void'], true)) throw new HttpError(409, 'Adjustments apply to issued invoices only');
            $m = Money::minorUnits($inv['currency']); $amt = Money::parse($d['amount'] ?? '', $m, 'amount', true); if ($amt === 0) throw new HttpError(422, Validate::MSG, ['amount' => 'Enter a non-zero amount']);
            $newDue = Money::parse($inv['total'], $m) + Money::parse($inv['adjustments_total'], $m, 'a', true) + $amt; if ($newDue < Money::parse($inv['amount_paid'], $m, 'p', true)) throw new HttpError(422, 'The adjustment would reduce the amount due below what has already been paid');
            if ($newDue < 0) throw new HttpError(422, 'The amount due cannot be negative');
            $aid = Db::insert('INSERT INTO invoice_adjustments (invoice_id, amount, reason, created_by, created_at) VALUES (?,?,?,?,UTC_TIMESTAMP())', [$inv['id'], Money::fmt($amt, $m), $reason, (int)$u['id']]);
            $after = FinDoc::refreshInvoice((int)$inv['id']); FinAudit::log((int)$u['id'], 'invoice.adjusted', 'invoice', (int)$inv['id'], ['balance' => $inv['balance']], ['adjustment' => Money::fmt($amt, $m), 'balance' => $after['balance'], 'adjustmentId' => $aid], $reason); });
        return self::get($pa);
    }
    public static function void(array $pa): array {
        $u = Auth::require('finance.void'); $d = Http::body(); $reason = Validate::str($d, 'reason', 255, true);
        Db::tx(function () use ($u, $pa, $reason) { $inv = self::find($u, (int)$pa['id'], true); if ($inv['status'] === 'void') throw new HttpError(409, 'Already voided');
            if (Db::one("SELECT id FROM payments WHERE invoice_id=? AND status='recorded' LIMIT 1", [$inv['id']])) throw new HttpError(409, 'Reverse or refund recorded payments before voiding this invoice');
            Db::exec("UPDATE invoices SET status='void', voided_at=UTC_TIMESTAMP(), void_reason=?, balance=0, version=version+1 WHERE id=?", [$reason, $inv['id']]);
            FinAudit::log((int)$u['id'], 'invoice.voided', 'invoice', (int)$inv['id'], ['status' => $inv['status'], 'balance' => $inv['balance']], ['status' => 'void'], $reason); });
        return self::get($pa);
    }
    /** Replace the installment schedule. Installments must add up exactly to the current amount due; allocations are re-derived. */
    public static function setSchedule(array $pa): array {
        $u = Auth::require('finance.record'); $d = Http::body(); $list = is_array($d['installments'] ?? null) ? $d['installments'] : null; if ($list === null || !$list) throw new HttpError(422, Validate::MSG, ['installments' => 'Add at least one installment']);
        Db::tx(function () use ($u, $pa, $list, $d) { $inv = self::find($u, (int)$pa['id'], true); if ($inv['status'] === 'void') throw new HttpError(409, 'Voided invoices have no schedule');
            $m = Money::minorUnits($inv['currency']); $sum = 0; $rows = []; $deposits = 0;
            foreach (array_values($list) as $i => $l) { $amt = Money::parse($l['amount'] ?? '', $m, "installments.$i.amount"); if ($amt <= 0) throw new HttpError(422, Validate::MSG, ["installments.$i.amount" => 'Must be greater than zero']);
                $due = Validate::date($l, 'dueDate'); if (!$due) throw new HttpError(422, Validate::MSG, ["installments.$i.dueDate" => 'Required']); $dep = !empty($l['isDeposit']); $deposits += $dep ? 1 : 0;
                $rows[] = [Validate::str($l, 'label', 80) ?: ($dep ? 'Deposit' : 'Installment ' . ($i + 1)), $amt, $due, $dep]; $sum += $amt; }
            if ($deposits > 1) throw new HttpError(422, 'Only one installment can be the deposit');
            $dueTotal = Money::parse($inv['total'], $m) + Money::parse($inv['adjustments_total'], $m, 'a', true);
            if ($sum !== $dueTotal) throw new HttpError(422, sprintf('Installments total %s but the invoice amount due is %s %s. They must match exactly.', Money::fmt($sum, $m), $inv['currency'], Money::fmt($dueTotal, $m)));
            $before = Db::all('SELECT label, amount, due_date FROM payment_schedules WHERE invoice_id=?', [$inv['id']]);
            Db::exec('DELETE a FROM payment_allocations a JOIN payment_schedules s ON s.id=a.installment_id WHERE s.invoice_id=?', [$inv['id']]); Db::exec('DELETE FROM payment_schedules WHERE invoice_id=?', [$inv['id']]);
            foreach ($rows as $i => [$label, $amt, $due, $dep]) Db::exec("INSERT INTO payment_schedules (invoice_id, trip_id, label, amount, due_date, status, is_deposit, sort_order, created_by) VALUES (?,?,?,?,?,'scheduled',?,?,?)", [$inv['id'], $inv['trip_id'], $label, Money::fmt($amt, $m), $due, $dep ? 1 : 0, $i, (int)$u['id']]);
            FinDoc::refreshInvoice((int)$inv['id']); FinAudit::log((int)$u['id'], 'invoice.schedule_set', 'invoice', (int)$inv['id'], $before, Db::all('SELECT label, amount, due_date FROM payment_schedules WHERE invoice_id=?', [$inv['id']]), Validate::str($d, 'reason', 255)); });
        return self::get($pa);
    }
    /** Printable HTML (use the browser's Print / Save as PDF). Output is escaped. */
    public static function printView(array $pa): void {
        $u = Auth::require('finance.view'); $i = self::out(self::find($u, (int)$pa['id']), true); $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store'); header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
        $rows = implode('', array_map(function ($l) use ($e) { return '<tr><td>' . $e($l['description']) . '</td><td class="n">' . $e($l['quantity']) . '</td><td class="n">' . $e($l['unitPrice']) . '</td><td class="n">' . $e($l['lineTotal']) . '</td></tr>'; }, $i['lines']));
        $adj = implode('', array_map(function ($a) use ($e) { return '<tr><td colspan="3">Adjustment: ' . $e($a['reason']) . '</td><td class="n">' . $e($a['amount']) . '</td></tr>'; }, $i['adjustments']));
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $e($i['number']) . '</title><style>body{font-family:Georgia,serif;color:#1d2433;max-width:760px;margin:24px auto;padding:0 16px}h1{font-size:22px;margin:0}table{width:100%;border-collapse:collapse;margin-top:16px}td,th{padding:6px;border-bottom:1px solid #ddd;text-align:left}.n{text-align:right}.mu{color:#667}.void{color:#a00;font-weight:bold}@media print{.np{display:none}}</style></head><body>'
            . '<p class="np"><button onclick="window.print()">Print or save as PDF</button></p><h1>Luxury Travel Institute</h1><p class="mu">Invoice ' . $e($i['number']) . ($i['status'] === 'void' ? ' <span class="void">VOID — ' . $e($i['voidReason']) . '</span>' : '') . '</p>'
            . '<p>Bill to: ' . $e($i['contactName'] ?: '—') . '<br>Trip: ' . $e($i['tripName'] ?: '—') . '<br>Issued: ' . $e($i['issueDate'] ?: 'Draft — not issued') . ' · Due: ' . $e($i['dueDate'] ?: '—') . '<br>Currency: ' . $e($i['currency']) . '</p>'
            . '<table><thead><tr><th>Description</th><th class="n">Qty</th><th class="n">Unit</th><th class="n">Amount</th></tr></thead><tbody>' . $rows . '<tr><td colspan="3">Subtotal</td><td class="n">' . $e($i['subtotal']) . '</td></tr>'
            . ((float)$i['discountTotal'] ? '<tr><td colspan="3">Discount</td><td class="n">-' . $e($i['discountTotal']) . '</td></tr>' : '') . ((float)$i['taxTotal'] ? '<tr><td colspan="3">' . $e($i['taxName']) . ' (' . $e($i['taxRate']) . '%)</td><td class="n">' . $e($i['taxTotal']) . '</td></tr>' : '')
            . '<tr><td colspan="3"><b>Total</b></td><td class="n"><b>' . $e($i['total']) . '</b></td></tr>' . $adj . '<tr><td colspan="3">Payments recorded (net of refunds)</td><td class="n">' . $e($i['amountPaid']) . '</td></tr><tr><td colspan="3"><b>Balance due</b></td><td class="n"><b>' . $e($i['balance']) . '</b></td></tr></tbody></table>'
            . ($i['paymentTerms'] ? '<p>Payment terms: ' . $e($i['paymentTerms']) . '</p>' : '') . ($i['terms'] ? '<p class="mu" style="white-space:pre-wrap">' . $e($i['terms']) . '</p>' : '') . '<p class="mu">Payments shown are recorded by LTI staff; recorded payments are not proof of external settlement.</p></body></html>';
        exit;
    }
}
