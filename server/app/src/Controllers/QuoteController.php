<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Auth, Audit, Db, FinAudit, FinDoc, Http, HttpError, Money, Validate};
final class QuoteController {
    private static function find(array $u, int $id, bool $lock = false): array {
        [$w, $p] = FinDoc::scopeSql($u, 'q');
        $r = Db::one("SELECT q.*, t.name trip_name, CONCAT_WS(' ', c.first_name, c.last_name) contact_name FROM quotations q LEFT JOIN trips t ON t.id=q.trip_id LEFT JOIN contacts c ON c.id=q.contact_id WHERE q.id=? AND $w" . ($lock ? ' FOR UPDATE' : ''), array_merge([$id], $p));
        if (!$r) throw new HttpError(404, 'Quote not found'); return $r;
    }
    private static function status(array $r): string { return $r['status'] === 'sent' && $r['valid_until'] && $r['valid_until'] < gmdate('Y-m-d') ? 'expired' : $r['status']; }
    private static function out(array $r, bool $full = false): array {
        $o = ['id' => (int)$r['id'], 'number' => $r['number'], 'title' => $r['title'], 'status' => self::status($r), 'tripId' => $r['trip_id'] === null ? null : (int)$r['trip_id'], 'tripName' => $r['trip_name'] ?? null,
            'contactId' => $r['contact_id'] === null ? null : (int)$r['contact_id'], 'contactName' => $r['contact_name'] ?? null, 'currency' => $r['currency'], 'subtotal' => Money::show($r['subtotal'], $r['currency']), 'discountType' => $r['discount_type'], 'discountValue' => $r['discount_value'],
            'discountTotal' => Money::show($r['discount_total'], $r['currency']), 'taxName' => $r['tax_name'], 'taxRate' => $r['tax_rate'], 'taxTotal' => Money::show($r['tax_total'], $r['currency']), 'total' => Money::show($r['total'], $r['currency']), 'validUntil' => $r['valid_until'], 'terms' => $r['terms'], 'notes' => $r['notes'],
            'sentAt' => $r['sent_at'], 'decidedAt' => $r['decided_at'], 'version' => (int)$r['version'], 'createdAt' => $r['created_at']];
        if ($full) { $o['lines'] = FinDoc::lines('quotation_id', (int)$r['id'], $r['currency']); $a = Db::one('SELECT * FROM quote_acceptances WHERE quote_id=?', [$r['id']]);
            $o['decision'] = $a ? ['decision' => $a['decision'], 'method' => 'Recorded by staff (ordinary electronic confirmation, not a digital signature)', 'clientName' => $a['client_name'], 'note' => $a['note'], 'at' => $a['created_at'], 'snapshot' => $a['snapshot_json'] ? json_decode($a['snapshot_json'], true) : null, 'sha256' => $a['snapshot_sha256']] : null;
            $inv = Db::one('SELECT id, number, status FROM invoices WHERE quote_id=?', [$r['id']]); $o['invoice'] = $inv ? ['id' => (int)$inv['id'], 'number' => $inv['number'], 'status' => $inv['status']] : null; }
        return $o;
    }
    public static function list(): array {
        $u = Auth::require('finance.view'); [$w, $p] = FinDoc::scopeSql($u, 'q'); $where = [$w];
        if (($s = (string)($_GET['status'] ?? '')) !== '') { Validate::enum(['s' => $s], 's', ['draft', 'sent', 'accepted', 'rejected', 'expired', 'archived']);
            if ($s === 'expired') { $where[] = "q.status='sent' AND q.valid_until < ?"; $p[] = gmdate('Y-m-d'); } else { $where[] = 'q.status=?'; $p[] = $s; } } else $where[] = "q.status<>'archived'";
        foreach (['tripId' => 'q.trip_id', 'contactId' => 'q.contact_id'] as $k => $c) if (($v = (int)($_GET[$k] ?? 0)) > 0) { $where[] = "$c=?"; $p[] = $v; }
        if (($q = trim((string)($_GET['q'] ?? ''))) !== '') { $l = '%' . addcslashes($q, '%_\\') . '%'; $where[] = "(q.number LIKE ? OR q.title LIKE ? OR t.name LIKE ? OR CONCAT_WS(' ', c.first_name, c.last_name) LIKE ?)"; array_push($p, $l, $l, $l, $l); }
        $page = max(1, (int)($_GET['page'] ?? 1)); $per = min(200, max(1, (int)($_GET['perPage'] ?? 50))); $from = 'FROM quotations q LEFT JOIN trips t ON t.id=q.trip_id LEFT JOIN contacts c ON c.id=q.contact_id WHERE ' . implode(' AND ', $where);
        return ['total' => (int)Db::one("SELECT COUNT(*) n $from", $p)['n'], 'items' => array_map([self::class, 'out'], Db::all("SELECT q.*, t.name trip_name, CONCAT_WS(' ', c.first_name, c.last_name) contact_name $from ORDER BY q.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p))];
    }
    public static function get(array $pa): array { $u = Auth::require('finance.view'); return ['quote' => self::out(self::find($u, (int)$pa['id']), true)]; }
    private static function cols(array $u, array $d, ?array $cur): array {
        if ($cur) $d = array_merge(['tripId' => $cur['trip_id'], 'contactId' => $cur['contact_id'], 'currency' => $cur['currency'], 'title' => $cur['title'], 'validUntil' => $cur['valid_until'], 'terms' => $cur['terms'], 'notes' => $cur['notes'],
            'discountType' => $cur['discount_type'], 'discountValue' => $cur['discount_value'], 'taxRateId' => $cur['tax_rate_id'], 'lines' => FinDoc::lines('quotation_id', (int)$cur['id'])], $d);
        [$trip, $contact, $tr] = FinDoc::checkLinks($u, $d); $ccy = Money::currency($d['currency'] ?? ($tr['currency'] ?? 'USD'));
        if ($d['discountType'] ?? null) { if (($d['discountType'] === 'fixed') && $cur && isset($d['discountValue']) && strpos((string)$d['discountValue'], '.') !== false) $d['discountValue'] = rtrim(rtrim((string)$d['discountValue'], '0'), '.'); }
        $c = FinDoc::compute($d, $ccy);
        return [$c, ['trip_id' => $trip, 'contact_id' => $contact, 'currency' => $ccy, 'title' => Validate::str($d, 'title', 190), 'valid_until' => Validate::date($d, 'validUntil'), 'terms' => Validate::str($d, 'terms', 10000), 'notes' => Validate::str($d, 'notes', 5000),
            'subtotal' => Money::fmt($c['subtotal'], $c['minor']), 'discount_type' => $c['discountType'], 'discount_value' => $c['discountStored'], 'discount_total' => Money::fmt($c['discount'], $c['minor']), 'tax_rate_id' => $c['taxRateId'], 'tax_name' => $c['taxName'], 'tax_rate' => $c['taxRate'],
            'tax_total' => Money::fmt($c['tax'], $c['minor']), 'total' => Money::fmt($c['total'], $c['minor'])]];
    }
    public static function create(): array {
        $u = Auth::require('finance.invoice'); [$c, $f] = self::cols($u, Http::body(), null);
        $id = Db::tx(function () use ($c, $f, $u) { $cols = array_keys($f);
            $id = Db::insert('INSERT INTO quotations (' . implode(',', $cols) . ", number, status, created_by, created_at, updated_at, version) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ",?,'draft',?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)", array_merge(array_values($f), ['TMP-' . bin2hex(random_bytes(8)), (int)$u['id']]));
            Db::exec('UPDATE quotations SET number=? WHERE id=?', ['Q-' . gmdate('Y') . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT), $id]); FinDoc::saveLines('quotation_id', $id, $c);
            FinAudit::log((int)$u['id'], 'quote.created', 'quote', $id, null, ['total' => $f['total'], 'currency' => $f['currency']]); return $id; });
        return ['quote' => self::out(self::find($u, $id), true)];
    }
    public static function update(array $pa): array {
        $u = Auth::require('finance.invoice'); $d = Http::body();
        $id = Db::tx(function () use ($u, $pa, $d) { $cur = self::find($u, (int)$pa['id'], true);
            if ($cur['status'] !== 'draft') throw new HttpError(409, 'Only draft quotes can be edited. Duplicate the quote to make changes.');
            if (isset($d['version']) && (int)$d['version'] !== (int)$cur['version']) throw new HttpError(409, 'This quote was changed by someone else. Reload and try again.');
            [$c, $f] = self::cols($u, $d, $cur); $sets = implode(',', array_map(function ($k) { return "$k=?"; }, array_keys($f)));
            Db::exec("UPDATE quotations SET $sets, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?", array_merge(array_values($f), [$cur['id']])); FinDoc::saveLines('quotation_id', (int)$cur['id'], $c);
            FinAudit::log((int)$u['id'], 'quote.updated', 'quote', (int)$cur['id'], ['total' => $cur['total']], ['total' => $f['total']]); return (int)$cur['id']; });
        return ['quote' => self::out(self::find($u, $id), true)];
    }
    public static function duplicate(array $pa): array {
        $u = Auth::require('finance.invoice'); $cur = self::find($u, (int)$pa['id']); $lines = FinDoc::lines('quotation_id', (int)$cur['id']);
        $d = ['tripId' => $cur['trip_id'], 'contactId' => $cur['contact_id'], 'currency' => $cur['currency'], 'title' => $cur['title'], 'validUntil' => null, 'terms' => $cur['terms'], 'notes' => $cur['notes'],
            'discountType' => $cur['discount_type'], 'discountValue' => $cur['discount_type'] === 'fixed' ? $cur['discount_total'] : $cur['discount_value'], 'taxRateId' => $cur['tax_rate_id'], 'lines' => $lines];
        [$c, $f] = self::cols($u, $d, null);
        $id = Db::tx(function () use ($c, $f, $u, $cur) { $cols = array_keys($f);
            $id = Db::insert('INSERT INTO quotations (' . implode(',', $cols) . ", number, status, duplicated_from, created_by, created_at, updated_at, version) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ",?,'draft',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)", array_merge(array_values($f), ['TMP-' . bin2hex(random_bytes(8)), $cur['id'], (int)$u['id']]));
            Db::exec('UPDATE quotations SET number=? WHERE id=?', ['Q-' . gmdate('Y') . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT), $id]); FinDoc::saveLines('quotation_id', $id, $c);
            FinAudit::log((int)$u['id'], 'quote.duplicated', 'quote', $id, null, ['from' => (int)$cur['id']]); return $id; });
        return ['quote' => self::out(self::find($u, $id), true)];
    }
    public static function send(array $pa): array {
        $u = Auth::require('finance.invoice'); $d = Http::body();
        Db::tx(function () use ($u, $pa, $d) { $q = self::find($u, (int)$pa['id'], true); if ($q['status'] !== 'draft') throw new HttpError(409, 'Only draft quotes can be marked as sent');
            if (!Db::one('SELECT id FROM document_lines WHERE quotation_id=? LIMIT 1', [$q['id']])) throw new HttpError(422, 'Add at least one line item before sending');
            if (!$q['valid_until']) throw new HttpError(422, Validate::MSG, ['validUntil' => 'Set an expiration date before sending']);
            Db::exec("UPDATE quotations SET status='sent', sent_at=UTC_TIMESTAMP(), version=version+1, updated_at=UTC_TIMESTAMP() WHERE id=?", [$q['id']]);
            FinAudit::log((int)$u['id'], 'quote.marked_sent', 'quote', (int)$q['id'], ['status' => 'draft'], ['status' => 'sent', 'channel' => Validate::str($d, 'channel', 100) ?: 'recorded manually']); });
        return self::get($pa);
    }
    /** Staff record the client's decision. Accepting freezes an immutable snapshot of lines, totals and terms. */
    public static function decide(array $pa): array {
        $u = Auth::require('finance.invoice'); $d = Http::body(); $dec = Validate::enum($d, 'decision', ['accepted', 'rejected']); if (!$dec) throw new HttpError(422, Validate::MSG, ['decision' => 'Required']);
        Db::tx(function () use ($u, $pa, $d, $dec) { $q = self::find($u, (int)$pa['id'], true);
            if ($q['status'] !== 'sent') throw new HttpError(409, 'Only sent quotes can be accepted or rejected');
            if (self::status($q) === 'expired') throw new HttpError(409, 'This quote has expired. Duplicate it to issue a new one.');
            $snap = null; $sha = null;
            if ($dec === 'accepted') { $s = self::out($q, false); unset($s['status'], $s['version'], $s['sentAt'], $s['decidedAt']); $s['lines'] = FinDoc::lines('quotation_id', (int)$q['id'], $q['currency']); $snap = json_encode($s, JSON_UNESCAPED_UNICODE); $sha = hash('sha256', $snap); }
            Db::insert('INSERT INTO quote_acceptances (quote_id, decision, client_name, note, snapshot_json, snapshot_sha256, recorded_by, created_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())', [$q['id'], $dec, Validate::str($d, 'clientName', 160), Validate::str($d, 'note', 500), $snap, $sha, (int)$u['id']]);
            Db::exec('UPDATE quotations SET status=?, decided_at=UTC_TIMESTAMP(), version=version+1, updated_at=UTC_TIMESTAMP() WHERE id=?', [$dec, $q['id']]);
            FinAudit::log((int)$u['id'], 'quote.' . $dec, 'quote', (int)$q['id'], ['status' => 'sent'], ['status' => $dec, 'snapshotSha256' => $sha], Validate::str($d, 'note', 255)); });
        return self::get($pa);
    }
    /** Convert an accepted quote into a draft invoice using the accepted snapshot, never the live quote. One invoice per quote (unique key). */
    public static function convert(array $pa): array {
        $u = Auth::require('finance.invoice');
        $invId = Db::tx(function () use ($u, $pa) { $q = self::find($u, (int)$pa['id'], true); if ($q['status'] !== 'accepted') throw new HttpError(409, 'Only accepted quotes can be converted');
            if ($ex = Db::one('SELECT id, number FROM invoices WHERE quote_id=?', [$q['id']])) throw new HttpError(409, 'This quote was already converted to invoice ' . $ex['number']);
            $a = Db::one("SELECT snapshot_json FROM quote_acceptances WHERE quote_id=? AND decision='accepted'", [$q['id']]); $s = json_decode($a['snapshot_json'], true);
            $id = InvoiceController::insertDraft($u, ['tripId' => $s['tripId'], 'contactId' => $s['contactId'], 'currency' => $s['currency'], 'quoteId' => (int)$q['id'], 'terms' => $s['terms'],
                'discountType' => $s['discountType'], 'discountValue' => $s['discountType'] === 'fixed' ? $s['discountTotal'] : $s['discountValue'], 'lines' => $s['lines']], ['name' => $s['taxName'], 'rate' => $s['taxRate']]);
            $inv = Db::one('SELECT total, currency FROM invoices WHERE id=?', [$id]); $mm = Money::minorUnits($inv['currency']); if (Money::parse($inv['total'], $mm) !== Money::parse($s['total'], $mm)) throw new HttpError(500, 'Converted total does not match the accepted quote');
            FinAudit::log((int)$u['id'], 'quote.converted', 'quote', (int)$q['id'], null, ['invoiceId' => $id]); return $id; });
        return InvoiceController::get(['id' => $invId]);
    }
    public static function archive(array $pa): array {
        $u = Auth::require('finance.invoice');
        Db::tx(function () use ($u, $pa) { $q = self::find($u, (int)$pa['id'], true); if ($q['status'] === 'archived') throw new HttpError(409, 'Already archived');
            Db::exec("UPDATE quotations SET status='archived', archived_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?", [$q['id']]); FinAudit::log((int)$u['id'], 'quote.archived', 'quote', (int)$q['id'], ['status' => $q['status']], ['status' => 'archived']); });
        return self::get($pa);
    }
}
