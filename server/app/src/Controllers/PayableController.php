<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Auth, Db, FinAudit, Http, HttpError, Money, Validate};
final class PayableController {
    private static function find(array $u, int $id, bool $lock = false): array {
        [$w, $p] = TripController::scope($u);
        $r = Db::one("SELECT sp.*, s.name supplier_name, b.reference booking_ref, b.trip_id, t.name trip_name FROM supplier_payables sp JOIN suppliers s ON s.id=sp.supplier_id JOIN bookings b ON b.id=sp.booking_id JOIN trips t ON t.id=b.trip_id WHERE sp.id=? AND $w" . ($lock ? ' FOR UPDATE' : ''), array_merge([$id], $p));
        if (!$r) throw new HttpError(404, 'Payable not found'); return $r;
    }
    private static function out(array $r, bool $full = false): array {
        $o = ['id' => (int)$r['id'], 'bookingId' => (int)$r['booking_id'], 'bookingRef' => $r['booking_ref'], 'tripId' => (int)$r['trip_id'], 'tripName' => $r['trip_name'], 'supplierId' => (int)$r['supplier_id'], 'supplierName' => $r['supplier_name'],
            'currency' => $r['currency'], 'amount' => Money::show($r['amount'], $r['currency']), 'paidTotal' => Money::show($r['paid_total'], $r['currency']), 'balance' => Money::show($r['balance'], $r['currency']), 'supplierInvoiceRef' => $r['supplier_invoice_ref'], 'dueDate' => $r['due_date'],
            'status' => $r['status'] !== 'paid' && $r['due_date'] && $r['due_date'] < gmdate('Y-m-d') && (float)$r['balance'] > 0 ? 'overdue' : $r['status'], 'notes' => $r['notes'], 'version' => (int)$r['version']];
        if ($full) $o['payments'] = array_map(function ($x) use ($r) { return ['id' => (int)$x['id'], 'kind' => $x['kind'], 'amount' => Money::show($x['amount'], $r['currency']), 'paidOn' => $x['paid_on'], 'method' => $x['method'], 'reference' => $x['reference'], 'status' => $x['status'], 'reversalReason' => $x['reversal_reason']]; },
            Db::all('SELECT * FROM supplier_payments WHERE payable_id=? ORDER BY paid_on, id', [$r['id']]));
        return $o;
    }
    private static function refresh(int $id): array {
        $sp = Db::one('SELECT * FROM supplier_payables WHERE id=? FOR UPDATE', [$id]); $m = Money::minorUnits($sp['currency']); $paid = 0;
        foreach (Db::all("SELECT amount FROM supplier_payments WHERE payable_id=? AND status='recorded'", [$id]) as $r) $paid += Money::parse($r['amount'], $m);
        $bal = Money::parse($sp['amount'], $m) - $paid; $st = $sp['status'] === 'void' ? 'void' : ($bal <= 0 ? 'paid' : ($paid > 0 ? 'partially_paid' : 'open'));
        Db::exec('UPDATE supplier_payables SET paid_total=?, balance=?, status=?, updated_at=UTC_TIMESTAMP() WHERE id=?', [Money::fmt($paid, $m), Money::fmt($bal, $m), $st, $id]);
        return Db::one('SELECT * FROM supplier_payables WHERE id=?', [$id]);
    }
    public static function list(): array {
        $u = Auth::require('finance.view'); [$w, $p] = TripController::scope($u); $where = [$w];
        foreach (['supplierId' => 'sp.supplier_id', 'bookingId' => 'sp.booking_id', 'tripId' => 'b.trip_id'] as $k => $c) if (($v = (int)($_GET[$k] ?? 0)) > 0) { $where[] = "$c=?"; $p[] = $v; }
        if (($s = (string)($_GET['status'] ?? '')) !== '') { Validate::enum(['s' => $s], 's', ['open', 'partially_paid', 'paid', 'overdue']); if ($s === 'overdue') { $where[] = "sp.status IN ('open','partially_paid') AND sp.due_date < ?"; $p[] = gmdate('Y-m-d'); } else { $where[] = 'sp.status=?'; $p[] = $s; } }
        if (($c = (string)($_GET['currency'] ?? '')) !== '') { $where[] = 'sp.currency=?'; $p[] = Money::currency($c); }
        $rows = Db::all('SELECT sp.*, s.name supplier_name, b.reference booking_ref, b.trip_id, t.name trip_name FROM supplier_payables sp JOIN suppliers s ON s.id=sp.supplier_id JOIN bookings b ON b.id=sp.booking_id JOIN trips t ON t.id=b.trip_id WHERE ' . implode(' AND ', $where) . ' ORDER BY (sp.due_date IS NULL), sp.due_date, sp.id LIMIT 500', $p);
        return ['items' => array_map([self::class, 'out'], $rows)];
    }
    public static function get(array $pa): array { $u = Auth::require('finance.view'); return ['payable' => self::out(self::find($u, (int)$pa['id']), true)]; }
    public static function create(): array {
        $u = Auth::require('finance.record'); $d = Http::body();
        $id = Db::tx(function () use ($u, $d) {
            $b = Db::one('SELECT * FROM bookings WHERE id=? FOR UPDATE', [(int)($d['bookingId'] ?? 0)]); if (!$b) throw new HttpError(422, Validate::MSG, ['bookingId' => 'Unknown booking']);
            TripController::findRow($u, (int)$b['trip_id']); if (!$b['supplier_id']) throw new HttpError(422, 'Assign a supplier to the booking first');
            $ccy = Money::currency($d['currency'] ?? $b['currency']); $m = Money::minorUnits($ccy);
            $raw = $d['amount'] ?? $b['supplier_cost']; if ($raw === null || $raw === '') throw new HttpError(422, Validate::MSG, ['amount' => 'Enter the supplier cost']); $amt = Money::parse($raw, $m); if ($amt <= 0) throw new HttpError(422, Validate::MSG, ['amount' => 'Must be greater than zero']);
            $ref = Validate::str($d, 'supplierInvoiceRef', 100) ?? '';
            if (Db::one('SELECT id FROM supplier_payables WHERE booking_id=? AND supplier_invoice_ref=?', [$b['id'], $ref])) throw new HttpError(409, 'A payable with this supplier invoice reference already exists for the booking');
            $id = Db::insert("INSERT INTO supplier_payables (booking_id, supplier_id, currency, amount, supplier_invoice_ref, due_date, status, notes, paid_total, balance, created_by, created_at, updated_at, version) VALUES (?,?,?,?,?,?,'open',?,0,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)",
                [$b['id'], $b['supplier_id'], $ccy, Money::fmt($amt, $m), $ref, Validate::date($d, 'dueDate'), Validate::str($d, 'notes', 2000), Money::fmt($amt, $m), (int)$u['id']]);
            FinAudit::log((int)$u['id'], 'payable.created', 'payable', $id, null, ['bookingId' => (int)$b['id'], 'amount' => Money::fmt($amt, $m), 'currency' => $ccy]); return $id; });
        return self::get(['id' => $id]);
    }
    public static function update(array $pa): array {
        $u = Auth::require('finance.record'); $d = Http::body();
        Db::tx(function () use ($u, $pa, $d) { $sp = self::find($u, (int)$pa['id'], true); if (isset($d['version']) && (int)$d['version'] !== (int)$sp['version']) throw new HttpError(409, 'This payable was changed by someone else. Reload and try again.');
            $m = Money::minorUnits($sp['currency']); $amt = array_key_exists('amount', $d) ? Money::parse($d['amount'], $m) : Money::parse($sp['amount'], $m); $reason = Validate::str($d, 'reason', 255);
            if ($amt !== Money::parse($sp['amount'], $m)) { if (!$reason) throw new HttpError(422, Validate::MSG, ['reason' => 'Give a reason for changing the amount']); if ($amt < Money::parse($sp['paid_total'], $m)) throw new HttpError(422, 'The amount cannot be lower than what has already been paid'); }
            $ref = array_key_exists('supplierInvoiceRef', $d) ? (Validate::str($d, 'supplierInvoiceRef', 100) ?? '') : $sp['supplier_invoice_ref'];
            if ($ref !== $sp['supplier_invoice_ref'] && Db::one('SELECT id FROM supplier_payables WHERE booking_id=? AND supplier_invoice_ref=? AND id<>?', [$sp['booking_id'], $ref, $sp['id']])) throw new HttpError(409, 'Duplicate supplier invoice reference for this booking');
            Db::exec('UPDATE supplier_payables SET amount=?, supplier_invoice_ref=?, due_date=?, notes=?, version=version+1 WHERE id=?', [Money::fmt($amt, $m), $ref, array_key_exists('dueDate', $d) ? Validate::date($d, 'dueDate') : $sp['due_date'], array_key_exists('notes', $d) ? Validate::str($d, 'notes', 2000) : $sp['notes'], $sp['id']]);
            $after = self::refresh((int)$sp['id']); FinAudit::log((int)$u['id'], 'payable.updated', 'payable', (int)$sp['id'], ['amount' => $sp['amount'], 'ref' => $sp['supplier_invoice_ref']], ['amount' => $after['amount'], 'ref' => $ref], $reason); });
        return self::get($pa);
    }
    public static function pay(array $pa): array {
        $u = Auth::require('finance.record'); $d = Http::body(); $idem = (string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '') ?: null;
        Db::tx(function () use ($u, $pa, $d, $idem) { $sp = self::find($u, (int)$pa['id'], true); if ($sp['status'] === 'void') throw new HttpError(409, 'This payable is void');
            $m = Money::minorUnits($sp['currency']); $amt = Money::parse($d['amount'] ?? '', $m); if ($amt <= 0) throw new HttpError(422, Validate::MSG, ['amount' => 'Must be greater than zero']);
            if ($amt > Money::parse($sp['balance'], $m, 'b', true)) throw new HttpError(422, 'The amount exceeds the supplier balance', ['amount' => 'Exceeds the balance']);
            $kind = Validate::enum($d, 'kind', ['payment', 'credit'], 'payment');
            $pid = Db::insert("INSERT INTO supplier_payments (payable_id, kind, amount, paid_on, method, reference, notes, status, idempotency_key, recorded_by, created_at) VALUES (?,?,?,?,?,?,?,'recorded',?,?,UTC_TIMESTAMP())",
                [$sp['id'], $kind, Money::fmt($amt, $m), Validate::date($d, 'paidOn') ?: gmdate('Y-m-d'), Validate::enum($d, 'method', PaymentController::METHODS, 'bank_transfer'), Validate::str($d, 'reference', 100), Validate::str($d, 'notes', 255), $idem, (int)$u['id']]);
            $after = self::refresh((int)$sp['id']); FinAudit::log((int)$u['id'], 'payable.' . $kind . '_recorded', 'payable', (int)$sp['id'], ['balance' => $sp['balance']], ['supplierPaymentId' => $pid, 'amount' => Money::fmt($amt, $m), 'balance' => $after['balance']]); });
        return self::get($pa);
    }
    public static function reversePayment(array $pa): array {
        $u = Auth::require('finance.void'); $d = Http::body(); $reason = Validate::str($d, 'reason', 255, true);
        $pid = Db::tx(function () use ($u, $pa, $reason) { $x = Db::one('SELECT * FROM supplier_payments WHERE id=? FOR UPDATE', [(int)$pa['id']]); if (!$x) throw new HttpError(404, 'Supplier payment not found');
            self::find($u, (int)$x['payable_id'], true); if ($x['status'] === 'reversed') throw new HttpError(409, 'Already reversed');
            Db::exec("UPDATE supplier_payments SET status='reversed', reversed_at=UTC_TIMESTAMP(), reversal_reason=? WHERE id=?", [$reason, $x['id']]);
            $after = self::refresh((int)$x['payable_id']); FinAudit::log((int)$u['id'], 'payable.payment_reversed', 'payable', (int)$x['payable_id'], ['supplierPaymentId' => (int)$x['id'], 'amount' => $x['amount']], ['balance' => $after['balance']], $reason); return (int)$x['payable_id']; });
        return self::get(['id' => $pid]);
    }
}
