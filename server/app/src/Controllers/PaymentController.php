<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Auth, Db, FinAudit, FinDoc, Http, HttpError, Money, Validate, Events};
/** Client payments are records entered by staff. They are never proof of external settlement; "confirmed by staff" means
 *  a staff member checked it against a bank statement or receipt, nothing more. No card or bank credentials are accepted. */
final class PaymentController {
    const METHODS = ['bank_transfer', 'cash', 'check', 'card_external', 'other'];
    public static function out(array $r): array {
        $ref = Db::all('SELECT id, amount, refunded_on, method, reference, reason, created_at FROM refunds WHERE payment_id=? ORDER BY id', [$r['id']]);
        return ['id' => (int)$r['id'], 'invoiceId' => $r['invoice_id'] === null ? null : (int)$r['invoice_id'], 'invoiceNumber' => $r['invoice_number'] ?? null, 'amount' => Money::show($r['amount'], $r['currency']), 'currency' => $r['currency'], 'receivedOn' => $r['received_on'],
            'method' => $r['method'], 'reference' => $r['reference'], 'notes' => $r['notes'], 'status' => $r['status'], 'verification' => $r['verification'], 'verificationNote' => $r['verification_note'],
            'reversedAt' => $r['reversed_at'], 'reversalReason' => $r['reversal_reason'], 'recordedBy' => $r['recorder'] ?? null, 'createdAt' => $r['created_at'],
            'refunds' => array_map(function ($x) use ($r) { return ['id' => (int)$x['id'], 'amount' => Money::show($x['amount'], $r['currency']), 'refundedOn' => $x['refunded_on'], 'method' => $x['method'], 'reference' => $x['reference'], 'reason' => $x['reason']]; }, $ref)];
    }
    private static function find(array $u, int $id, bool $lock = false): array {
        [$w, $p] = FinDoc::scopeSql($u, 'i');
        $r = Db::one("SELECT p.*, i.number invoice_number FROM payments p JOIN invoices i ON i.id=p.invoice_id WHERE p.id=? AND $w" . ($lock ? ' FOR UPDATE' : ''), array_merge([$id], $p));
        if (!$r) throw new HttpError(404, 'Payment not found'); return $r;
    }
    public static function list(): array {
        $u = Auth::require('finance.view'); [$w, $p] = FinDoc::scopeSql($u, 'i'); $where = [$w];
        foreach (['invoiceId' => 'p.invoice_id', 'contactId' => 'p.contact_id', 'tripId' => 'p.trip_id'] as $k => $c) if (($v = (int)($_GET[$k] ?? 0)) > 0) { $where[] = "$c=?"; $p[] = $v; }
        if (($s = (string)($_GET['status'] ?? '')) !== '') { Validate::enum(['s' => $s], 's', ['recorded', 'reversed']); $where[] = 'p.status=?'; $p[] = $s; }
        if (($f = Validate::date($_GET, 'from'))) { $where[] = 'p.received_on>=?'; $p[] = $f; } if (($t = Validate::date($_GET, 'to'))) { $where[] = 'p.received_on<=?'; $p[] = $t; }
        $rows = Db::all('SELECT p.*, i.number invoice_number, u.full_name recorder FROM payments p JOIN invoices i ON i.id=p.invoice_id LEFT JOIN users u ON u.id=p.recorded_by WHERE ' . implode(' AND ', $where) . ' ORDER BY p.received_on DESC, p.id DESC LIMIT 500', $p);
        return ['items' => array_map([self::class, 'out'], $rows)];
    }
    public static function create(): array {
        $u = Auth::require('finance.record'); $d = Http::body(); $idem = (string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '') ?: null;
        $id = Db::tx(function () use ($u, $d, $idem) {
            $inv = InvoiceController::find($u, (int)($d['invoiceId'] ?? 0), true);
            if ($inv['status'] === 'draft') throw new HttpError(409, 'Issue the invoice before recording payments'); if ($inv['status'] === 'void') throw new HttpError(409, 'This invoice is void');
            $ccy = Money::currency($d['currency'] ?? $inv['currency']); if ($ccy !== $inv['currency']) throw new HttpError(422, Validate::MSG, ['currency' => "Payments must be recorded in the invoice currency ($inv[currency]). Record the converted amount and note the original in the reference."]);
            $m = Money::minorUnits($ccy); $amt = Money::parse($d['amount'] ?? '', $m); if ($amt <= 0) throw new HttpError(422, Validate::MSG, ['amount' => 'Must be greater than zero']);
            $bal = Money::parse($inv['balance'], $m, 'b', true); if ($amt > $bal) throw new HttpError(422, sprintf('The payment of %s exceeds the outstanding balance of %s %s. Overpayments and credits are not recorded automatically.', Money::fmt($amt, $m), $ccy, Money::fmt($bal, $m)), ['amount' => 'Exceeds the balance']);
            $method = Validate::enum($d, 'method', self::METHODS, null); if (!$method) throw new HttpError(422, Validate::MSG, ['method' => 'Choose how the payment was made']);
            $on = Validate::date($d, 'receivedOn') ?: gmdate('Y-m-d'); if ($on > gmdate('Y-m-d', time() + 86400)) throw new HttpError(422, Validate::MSG, ['receivedOn' => 'Cannot be in the future']);
            $refNo = Validate::str($d, 'reference', 100); if ($refNo && preg_match('/\d{13,19}/', preg_replace('/[\s-]/', '', $refNo))) throw new HttpError(422, Validate::MSG, ['reference' => 'Do not enter card or account numbers. Use the last four digits or a receipt number.']);
            $id = Db::insert("INSERT INTO payments (invoice_id, contact_id, trip_id, amount, currency, method, reference, received_on, notes, status, verification, idempotency_key, recorded_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,'recorded','unverified',?,?,UTC_TIMESTAMP())",
                [$inv['id'], $inv['contact_id'], $inv['trip_id'], Money::fmt($amt, $m), $ccy, $method, $refNo, $on, Validate::str($d, 'notes', 255), $idem, (int)$u['id']]);
            $after = FinDoc::refreshInvoice((int)$inv['id']);
            Events::emit('payment.recorded', 'payment', $id, (int)$u['id'], 'Payment recorded against ' . $inv['number'], ['invoiceId' => (int)$inv['id']]); if ($inv['contact_id']) Events::activity('contact', (int)$inv['contact_id'], 'payment.recorded', (int)$u['id'], 'Payment recorded against ' . $inv['number']);
            FinAudit::log((int)$u['id'], 'payment.recorded', 'payment', $id, ['invoiceBalance' => $inv['balance']], ['amount' => Money::fmt($amt, $m), 'currency' => $ccy, 'invoiceId' => (int)$inv['id'], 'invoiceBalance' => $after['balance'], 'invoiceStatus' => $after['status']]);
            return $id; });
        return ['payment' => self::out(self::find($u, $id)), 'invoice' => InvoiceController::get(['id' => (int)$d['invoiceId']])['invoice']];
    }
    public static function reverse(array $pa): array {
        $u = Auth::require('finance.void'); $d = Http::body(); $reason = Validate::str($d, 'reason', 255, true);
        $p = Db::tx(function () use ($u, $pa, $reason) { $p = self::find($u, (int)$pa['id'], true); if ($p['status'] === 'reversed') throw new HttpError(409, 'Already reversed');
            if (Db::one('SELECT id FROM refunds WHERE payment_id=? LIMIT 1', [$p['id']])) throw new HttpError(409, 'This payment has refunds recorded; it cannot also be reversed');
            InvoiceController::find($u, (int)$p['invoice_id'], true);
            Db::exec("UPDATE payments SET status='reversed', reversed_at=UTC_TIMESTAMP(), reversed_by=?, reversal_reason=? WHERE id=?", [(int)$u['id'], $reason, $p['id']]);
            $after = FinDoc::refreshInvoice((int)$p['invoice_id']); FinAudit::log((int)$u['id'], 'payment.reversed', 'payment', (int)$p['id'], ['status' => 'recorded', 'amount' => $p['amount']], ['status' => 'reversed', 'invoiceBalance' => $after['balance']], $reason); return $p; });
        return ['payment' => self::out(self::find($u, (int)$p['id'])), 'invoice' => InvoiceController::get(['id' => (int)$p['invoice_id']])['invoice']];
    }
    public static function verify(array $pa): array {
        $u = Auth::require('finance.reconcile'); $d = Http::body(); $note = Validate::str($d, 'note', 255, true);
        Db::tx(function () use ($u, $pa, $note) { $p = self::find($u, (int)$pa['id'], true); if ($p['status'] !== 'recorded') throw new HttpError(409, 'Only active payments can be confirmed');
            Db::exec("UPDATE payments SET verification='confirmed_by_staff', verification_note=?, verified_by=?, verified_at=UTC_TIMESTAMP() WHERE id=?", [$note, (int)$u['id'], $p['id']]);
            FinAudit::log((int)$u['id'], 'payment.confirmed_by_staff', 'payment', (int)$p['id'], ['verification' => $p['verification']], ['verification' => 'confirmed_by_staff'], $note); });
        return ['payment' => self::out(self::find($u, (int)$pa['id']))];
    }
    public static function refund(array $pa): array {
        $u = Auth::require('finance.void'); $d = Http::body(); $reason = Validate::str($d, 'reason', 255, true); $idem = (string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '') ?: null;
        $p = Db::tx(function () use ($u, $pa, $d, $reason, $idem) { $p = self::find($u, (int)$pa['id'], true); if ($p['status'] !== 'recorded') throw new HttpError(409, 'Reversed payments cannot be refunded');
            InvoiceController::find($u, (int)$p['invoice_id'], true); $m = Money::minorUnits($p['currency']); $amt = Money::parse($d['amount'] ?? '', $m); if ($amt <= 0) throw new HttpError(422, Validate::MSG, ['amount' => 'Must be greater than zero']);
            $done = 0; foreach (Db::all('SELECT amount FROM refunds WHERE payment_id=?', [$p['id']]) as $r) $done += Money::parse($r['amount'], $m);
            if ($amt > Money::parse($p['amount'], $m) - $done) throw new HttpError(422, 'Refunds cannot exceed the payment amount', ['amount' => 'Exceeds the refundable amount']);
            $method = Validate::enum($d, 'method', self::METHODS, $p['method']);
            $rid = Db::insert('INSERT INTO refunds (payment_id, amount, refunded_on, method, reference, reason, idempotency_key, recorded_by, created_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP())', [$p['id'], Money::fmt($amt, $m), Validate::date($d, 'refundedOn') ?: gmdate('Y-m-d'), $method, Validate::str($d, 'reference', 100), $reason, $idem, (int)$u['id']]);
            $after = FinDoc::refreshInvoice((int)$p['invoice_id']); FinAudit::log((int)$u['id'], 'payment.refunded', 'payment', (int)$p['id'], null, ['refundId' => $rid, 'amount' => Money::fmt($amt, $m), 'invoiceBalance' => $after['balance']], $reason); return $p; });
        return ['payment' => self::out(self::find($u, (int)$p['id'])), 'invoice' => InvoiceController::get(['id' => (int)$p['invoice_id']])['invoice']];
    }
}
