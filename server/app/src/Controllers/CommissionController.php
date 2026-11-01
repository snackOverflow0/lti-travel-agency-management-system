<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Auth, Db, FinAudit, Http, HttpError, Money, Validate};
/** Agency commission (paid by suppliers to LTI) and the advisor's share (compensation paid by LTI to staff) are tracked
 *  separately: receipts record supplier money in, payouts record money out to advisors. */
final class CommissionController {
    private static function scope(array $u): array { return Auth::can('finance.reconcile') ? ['1=1', []] : ['cm.advisor_id=?', [(int)$u['id']]]; }
    private static function find(array $u, int $id, bool $lock = false): array {
        Auth::require('finance.view'); [$w, $p] = self::scope($u);
        $r = Db::one("SELECT cm.*, b.reference booking_ref, b.travel_date, b.status booking_status, s.name supplier_name, b.supplier_id, a.full_name advisor_name, t.name trip_name FROM commissions cm JOIN bookings b ON b.id=cm.booking_id JOIN trips t ON t.id=b.trip_id LEFT JOIN suppliers s ON s.id=b.supplier_id LEFT JOIN users a ON a.id=cm.advisor_id WHERE cm.id=? AND $w" . ($lock ? ' FOR UPDATE' : ''), array_merge([$id], $p));
        if (!$r) throw new HttpError(404, 'Commission not found'); return $r;
    }
    private static function out(array $r, bool $full = false): array {
        $o = ['id' => (int)$r['id'], 'bookingId' => (int)$r['booking_id'], 'bookingRef' => $r['booking_ref'], 'tripName' => $r['trip_name'], 'travelDate' => $r['travel_date'], 'supplierId' => $r['supplier_id'] === null ? null : (int)$r['supplier_id'], 'supplierName' => $r['supplier_name'],
            'advisorId' => $r['advisor_id'] === null ? null : (int)$r['advisor_id'], 'advisorName' => $r['advisor_name'], 'currency' => $r['currency'], 'basis' => $r['basis'], 'rateValue' => $r['rate_value'], 'baseAmount' => Money::show($r['base_amount'], $r['currency']), 'isOverride' => (bool)$r['is_override'],
            'agencyExpected' => Money::show($r['expected'], $r['currency']), 'agencyAdjustments' => Money::show($r['adjustments_total'], $r['currency']), 'agencyReceived' => Money::show($r['received'], $r['currency']), 'advisorSharePct' => $r['advisor_share_pct'], 'advisorAmount' => Money::show($r['advisor_amount'], $r['currency']), 'advisorAdjustments' => Money::show($r['advisor_adjustments'], $r['currency']),
            'advisorPaid' => Money::show($r['advisor_paid'], $r['currency']), 'status' => $r['status'], 'earnedAt' => $r['earned_at'], 'reversedAt' => $r['reversed_at'], 'reversalReason' => $r['reversal_reason'], 'version' => (int)$r['version']];
        if ($full) { $id = (int)$r['id']; $cc = $r['currency'];
            $o['receipts'] = array_map(function ($x) use ($cc) { return ['id' => (int)$x['id'], 'amount' => Money::show($x['amount'], $cc), 'receivedOn' => $x['received_on'], 'reference' => $x['reference'], 'status' => $x['status'], 'reversalReason' => $x['reversal_reason']]; }, Db::all('SELECT * FROM commission_receipts WHERE commission_id=? ORDER BY id', [$id]));
            $o['payouts'] = array_map(function ($x) use ($cc) { return ['id' => (int)$x['id'], 'amount' => Money::show($x['amount'], $cc), 'paidOn' => $x['paid_on'], 'reference' => $x['reference'], 'status' => $x['status'], 'reversalReason' => $x['reversal_reason']]; }, Db::all('SELECT * FROM advisor_payouts WHERE commission_id=? ORDER BY id', [$id]));
            $o['adjustments'] = array_map(function ($x) use ($cc) { return ['id' => (int)$x['id'], 'target' => $x['target'], 'amount' => Money::show($x['amount'], $cc), 'reason' => $x['reason'], 'at' => $x['created_at']]; }, Db::all('SELECT * FROM commission_adjustments WHERE commission_id=? ORDER BY id', [$id])); }
        return $o;
    }
    public static function refresh(int $id): array {
        $c = Db::one('SELECT cm.*, b.status booking_status, b.travel_date FROM commissions cm JOIN bookings b ON b.id=cm.booking_id WHERE cm.id=? FOR UPDATE', [$id]); $m = Money::minorUnits($c['currency']); $P = function ($v) use ($m) { return Money::parse((string)$v, $m, 'x', true); };
        $rec = 0; foreach (Db::all("SELECT amount FROM commission_receipts WHERE commission_id=? AND status='recorded'", [$id]) as $r) $rec += $P($r['amount']);
        $paid = 0; foreach (Db::all("SELECT amount FROM advisor_payouts WHERE commission_id=? AND status='recorded'", [$id]) as $r) $paid += $P($r['amount']);
        $aAdj = 0; $vAdj = 0; foreach (Db::all('SELECT target, amount FROM commission_adjustments WHERE commission_id=?', [$id]) as $r) { if ($r['target'] === 'agency') $aAdj += $P($r['amount']); else $vAdj += $P($r['amount']); }
        $agencyDue = $P($c['expected']) + $aAdj; $advDue = $P($c['advisor_amount']) + $vAdj;
        if ($c['reversed_at']) $st = 'reversed';
        elseif ($rec >= $agencyDue && $agencyDue > 0 && $advDue > 0 && $paid >= $advDue) $st = 'paid';
        elseif ($rec > 0 && $advDue - $paid > 0) $st = 'payable';
        elseif ($rec > 0) $st = 'received';
        elseif ($c['earned_at'] || ($c['booking_status'] === 'confirmed' && $c['travel_date'] && $c['travel_date'] <= gmdate('Y-m-d'))) $st = 'earned';
        else $st = 'expected';
        Db::exec('UPDATE commissions SET received=?, advisor_paid=?, adjustments_total=?, advisor_adjustments=?, status=?, updated_at=UTC_TIMESTAMP() WHERE id=?', [Money::fmt($rec, $m), Money::fmt($paid, $m), Money::fmt($aAdj, $m), Money::fmt($vAdj, $m), $st, $id]);
        return Db::one('SELECT * FROM commissions WHERE id=?', [$id]);
    }
    /* ---- rules ---- */
    private static function ruleOut(array $r): array { return ['id' => (int)$r['id'], 'name' => $r['name'], 'supplierId' => $r['supplier_id'] === null ? null : (int)$r['supplier_id'], 'bookingCategory' => $r['booking_category'], 'basis' => $r['basis'], 'value' => $r['value'], 'currency' => $r['currency'], 'advisorSharePct' => $r['advisor_share_pct'], 'isActive' => (bool)$r['is_active']]; }
    public static function rules(): array { Auth::require('finance.view'); return ['items' => array_map([self::class, 'ruleOut'], Db::all('SELECT * FROM commission_rules ORDER BY is_active DESC, name'))]; }
    private static function ruleCols(array $d, ?array $cur): array {
        if ($cur) $d = array_merge(['name' => $cur['name'], 'supplierId' => $cur['supplier_id'], 'bookingCategory' => $cur['booking_category'], 'basis' => $cur['basis'], 'value' => $cur['value'], 'currency' => $cur['currency'], 'advisorSharePct' => $cur['advisor_share_pct'], 'isActive' => (bool)$cur['is_active']], $d);
        $basis = Validate::enum($d, 'basis', ['percent', 'flat']); if (!$basis) throw new HttpError(422, Validate::MSG, ['basis' => 'Choose percent or flat']);
        $sup = !empty($d['supplierId']) ? (int)$d['supplierId'] : null; if ($sup && !Db::one('SELECT id FROM suppliers WHERE id=?', [$sup])) throw new HttpError(422, Validate::MSG, ['supplierId' => 'Unknown supplier']);
        $cat = Validate::enum($d, 'bookingCategory', BookingController::CATEGORIES, null);
        $ccy = null; if ($basis === 'percent') { $v = Money::scaled((string)($d['value'] ?? ''), 4, 'value'); if ((int)$v > 1000000) throw new HttpError(422, Validate::MSG, ['value' => 'At most 100%']); $val = Money::fmt((int)$v, 4); }
        else { $ccy = Money::currency($d['currency'] ?? ''); $val = Money::fmt(Money::parse($d['value'] ?? '', Money::minorUnits($ccy), 'value'), Money::minorUnits($ccy)); }
        $share = Money::scaled((string)($d['advisorSharePct'] ?? '0'), 4, 'advisorSharePct'); if ((int)$share > 1000000) throw new HttpError(422, Validate::MSG, ['advisorSharePct' => 'At most 100%']);
        return ['name' => Validate::str($d, 'name', 120, true), 'supplier_id' => $sup, 'booking_category' => $cat, 'basis' => $basis, 'value' => $val, 'currency' => $ccy, 'advisor_share_pct' => Money::fmt((int)$share, 4), 'is_active' => !isset($d['isActive']) || !empty($d['isActive']) ? 1 : 0];
    }
    public static function createRule(): array { $u = Auth::require('finance.reconcile'); $f = self::ruleCols(Http::body(), null); $cols = array_keys($f);
        $id = Db::tx(function () use ($f, $cols, $u) { $id = Db::insert('INSERT INTO commission_rules (' . implode(',', $cols) . ',created_by,created_at,updated_at) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())', array_merge(array_values($f), [(int)$u['id']]));
            FinAudit::log((int)$u['id'], 'commission_rule.created', 'commission_rule', $id, null, $f); return $id; });
        return ['rule' => self::ruleOut(Db::one('SELECT * FROM commission_rules WHERE id=?', [$id]))]; }
    public static function updateRule(array $pa): array { $u = Auth::require('finance.reconcile'); $cur = Db::one('SELECT * FROM commission_rules WHERE id=?', [(int)$pa['id']]); if (!$cur) throw new HttpError(404, 'Rule not found');
        $f = self::ruleCols(Http::body(), $cur); $sets = implode(',', array_map(function ($k) { return "$k=?"; }, array_keys($f)));
        Db::tx(function () use ($sets, $f, $cur, $u) { Db::exec("UPDATE commission_rules SET $sets, updated_at=UTC_TIMESTAMP() WHERE id=?", array_merge(array_values($f), [$cur['id']])); FinAudit::log((int)$u['id'], 'commission_rule.updated', 'commission_rule', (int)$cur['id'], $cur, $f); });
        return ['rule' => self::ruleOut(Db::one('SELECT * FROM commission_rules WHERE id=?', [$cur['id']]))]; }
    /** Most specific active rule wins: supplier + category, supplier, category, then default. */
    private static function resolveRule(array $b): ?array {
        return Db::one('SELECT * FROM commission_rules WHERE is_active=1 AND (supplier_id IS NULL OR supplier_id=?) AND (booking_category IS NULL OR booking_category=?) AND (basis=\'percent\' OR currency=?)
            ORDER BY (supplier_id IS NOT NULL) * 2 + (booking_category IS NOT NULL) DESC, id LIMIT 1', [$b['supplier_id'], $b['booking_type'], $b['currency']]);
    }
    /** Create or recalculate the commission for a booking from the rules (or an authorized override). */
    public static function calculate(array $pa): array {
        $u = Auth::require('bookings.manage'); $d = Http::body();
        $id = Db::tx(function () use ($u, $pa, $d) {
            $b = Db::one('SELECT * FROM bookings WHERE id=? FOR UPDATE', [(int)$pa['id']]); if (!$b) throw new HttpError(404, 'Booking not found'); TripController::findRow($u, (int)$b['trip_id']);
            if ($b['status'] === 'cancelled') throw new HttpError(409, 'Cancelled bookings earn no commission');
            $ex = Db::one('SELECT * FROM commissions WHERE booking_id=? FOR UPDATE', [$b['id']]);
            if ($ex && ($ex['reversed_at'] || Db::one("SELECT id FROM commission_receipts WHERE commission_id=? AND status='recorded' LIMIT 1", [$ex['id']]))) throw new HttpError(409, 'This commission already has receipts or is reversed; record an adjustment instead');
            $ccy = $b['currency']; $m = Money::minorUnits($ccy); $base = Money::parse($b['amount'], $m); $ov = is_array($d['override'] ?? null) ? $d['override'] : null;
            if ($ov) { Auth::require('finance.reconcile'); $rule = null; $basis = Validate::enum($ov, 'basis', ['percent', 'flat']); if (!$basis) throw new HttpError(422, Validate::MSG, ['override.basis' => 'Required']);
                $val = $basis === 'percent' ? Money::scaled((string)($ov['value'] ?? ''), 4, 'override.value') : (string)Money::parse($ov['value'] ?? '', $m, 'override.value'); $share = Money::scaled((string)($ov['advisorSharePct'] ?? '0'), 4, 'override.advisorSharePct'); }
            else { $rule = self::resolveRule($b); if (!$rule) throw new HttpError(422, 'No active commission rule matches this booking. Create a rule or use an override.');
                $basis = $rule['basis']; $val = $basis === 'percent' ? Money::scaled($rule['value'], 4, 'value') : (string)Money::parse($rule['value'], $m, 'value'); $share = Money::scaled($rule['advisor_share_pct'], 4, 'share'); }
            if ((int)$share > 1000000 || ($basis === 'percent' && (int)$val > 1000000)) throw new HttpError(422, 'Percentages cannot exceed 100');
            $expected = $basis === 'percent' ? Money::pct($base, $val) : (int)$val; $advAmt = Money::pct($expected, $share);
            $advisor = array_key_exists('advisorId', $d) && $d['advisorId'] ? (int)$d['advisorId'] : ($ex && $ex['advisor_id'] ? (int)$ex['advisor_id'] : ($b['agent_id'] ? (int)$b['agent_id'] : null));
            if ($advisor && !Db::one('SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND r.is_staff=1', [$advisor])) throw new HttpError(422, Validate::MSG, ['advisorId' => 'Unknown staff member']);
            if ($advisor && $advisor !== (int)$b['agent_id'] && !Auth::can('finance.reconcile')) throw new HttpError(403, 'Only finance can assign commission to a different advisor');
            $vals = [$rule ? (int)$rule['id'] : null, $ccy, $basis, $basis === 'percent' ? Money::fmt((int)$val, 4) : Money::fmt((int)$val, $m), Money::fmt($base, $m), $ov ? 1 : 0, Money::fmt($expected, $m), $advisor, Money::fmt((int)$share, 4), Money::fmt($advAmt, $m)];
            if ($ex) { Db::exec('UPDATE commissions SET rule_id=?, currency=?, basis=?, rate_value=?, base_amount=?, is_override=?, expected=?, advisor_id=?, advisor_share_pct=?, advisor_amount=?, version=version+1, updated_at=UTC_TIMESTAMP() WHERE id=?', array_merge($vals, [$ex['id']])); $id = (int)$ex['id']; }
            else $id = Db::insert('INSERT INTO commissions (rule_id, currency, basis, rate_value, base_amount, is_override, expected, advisor_id, advisor_share_pct, advisor_amount, booking_id, received, status, created_by, created_at, updated_at, version) VALUES (?,?,?,?,?,?,?,?,?,?,?,0,\'expected\',?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)', array_merge($vals, [$b['id'], (int)$u['id']]));
            Db::exec('UPDATE bookings SET commission_expected=? WHERE id=?', [Money::fmt($expected, $m), $b['id']]);
            $after = self::refresh($id); FinAudit::log((int)$u['id'], $ex ? 'commission.recalculated' : 'commission.created', 'commission', $id, $ex ? ['expected' => $ex['expected'], 'advisorAmount' => $ex['advisor_amount']] : null, ['expected' => $after['expected'], 'advisorAmount' => $after['advisor_amount'], 'ruleId' => $rule ? (int)$rule['id'] : null, 'override' => (bool)$ov]);
            return $id; });
        return ['commission' => self::out(self::find($u, $id), true)];
    }
    public static function list(): array {
        $u = Auth::require('finance.view'); [$w, $p] = self::scope($u); $where = [$w];
        foreach (['advisorId' => 'cm.advisor_id', 'supplierId' => 'b.supplier_id', 'bookingId' => 'cm.booking_id'] as $k => $c) if (($v = (int)($_GET[$k] ?? 0)) > 0) { $where[] = "$c=?"; $p[] = $v; }
        if (($s = (string)($_GET['status'] ?? '')) !== '') { Validate::enum(['s' => $s], 's', ['expected', 'earned', 'received', 'payable', 'paid', 'reversed']); $where[] = 'cm.status=?'; $p[] = $s; }
        if (($f = Validate::date($_GET, 'from'))) { $where[] = 'b.travel_date>=?'; $p[] = $f; } if (($t = Validate::date($_GET, 'to'))) { $where[] = 'b.travel_date<=?'; $p[] = $t; }
        $rows = Db::all('SELECT cm.*, b.reference booking_ref, b.travel_date, s.name supplier_name, b.supplier_id, a.full_name advisor_name, t.name trip_name FROM commissions cm JOIN bookings b ON b.id=cm.booking_id JOIN trips t ON t.id=b.trip_id LEFT JOIN suppliers s ON s.id=b.supplier_id LEFT JOIN users a ON a.id=cm.advisor_id WHERE ' . implode(' AND ', $where) . ' ORDER BY (b.travel_date IS NULL), b.travel_date, cm.id LIMIT 500', $p);
        return ['items' => array_map([self::class, 'out'], $rows)];
    }
    public static function get(array $pa): array { $u = Auth::require('finance.view'); return ['commission' => self::out(self::find($u, (int)$pa['id']), true)]; }
    private static function money(array $c, array $d, string $field = 'amount', bool $neg = false): int { $m = Money::minorUnits($c['currency']); $v = Money::parse($d[$field] ?? '', $m, $field, $neg); if ($v === 0 || (!$neg && $v < 0)) throw new HttpError(422, Validate::MSG, [$field => $neg ? 'Enter a non-zero amount' : 'Must be greater than zero']); return $v; }
    public static function receipt(array $pa): array {
        $u = Auth::require('finance.reconcile'); $d = Http::body(); $idem = (string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '') ?: null;
        Db::tx(function () use ($u, $pa, $d, $idem) { $c = self::find($u, (int)$pa['id'], true); if ($c['reversed_at']) throw new HttpError(409, 'This commission is reversed'); $amt = self::money($c, $d); $m = Money::minorUnits($c['currency']);
            $rid = Db::insert("INSERT INTO commission_receipts (commission_id, amount, received_on, reference, status, idempotency_key, recorded_by, created_at) VALUES (?,?,?,?,'recorded',?,?,UTC_TIMESTAMP())", [$c['id'], Money::fmt($amt, $m), Validate::date($d, 'receivedOn') ?: gmdate('Y-m-d'), Validate::str($d, 'reference', 100), $idem, (int)$u['id']]);
            $after = self::refresh((int)$c['id']); FinAudit::log((int)$u['id'], 'commission.receipt_recorded', 'commission', (int)$c['id'], ['received' => $c['received'], 'status' => $c['status']], ['receiptId' => $rid, 'amount' => Money::fmt($amt, $m), 'received' => $after['received'], 'status' => $after['status']]); });
        return self::get($pa);
    }
    public static function payout(array $pa): array {
        $u = Auth::require('finance.payout'); $d = Http::body(); $idem = (string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '') ?: null;
        Db::tx(function () use ($u, $pa, $d, $idem) { $c = self::find($u, (int)$pa['id'], true); if ($c['reversed_at']) throw new HttpError(409, 'This commission is reversed'); if (!$c['advisor_id']) throw new HttpError(422, 'No advisor is assigned to this commission');
            $m = Money::minorUnits($c['currency']); if (Money::parse($c['received'], $m) <= 0) throw new HttpError(409, 'Advisor payouts are recorded after the agency has received the supplier commission');
            $amt = self::money($c, $d); $left = Money::parse($c['advisor_amount'], $m) + Money::parse($c['advisor_adjustments'], $m, 'x', true) - Money::parse($c['advisor_paid'], $m);
            if ($amt > $left) throw new HttpError(422, sprintf('Payout exceeds the advisor amount still owed (%s %s)', $c['currency'], Money::fmt(max(0, $left), $m)), ['amount' => 'Exceeds the amount owed']);
            $pid = Db::insert("INSERT INTO advisor_payouts (commission_id, advisor_id, amount, paid_on, reference, status, idempotency_key, recorded_by, created_at) VALUES (?,?,?,?,?,'recorded',?,?,UTC_TIMESTAMP())", [$c['id'], $c['advisor_id'], Money::fmt($amt, $m), Validate::date($d, 'paidOn') ?: gmdate('Y-m-d'), Validate::str($d, 'reference', 100), $idem, (int)$u['id']]);
            $after = self::refresh((int)$c['id']); FinAudit::log((int)$u['id'], 'commission.payout_recorded', 'commission', (int)$c['id'], ['advisorPaid' => $c['advisor_paid']], ['payoutId' => $pid, 'amount' => Money::fmt($amt, $m), 'advisorPaid' => $after['advisor_paid'], 'status' => $after['status']]); });
        return self::get($pa);
    }
    public static function adjust(array $pa): array {
        $u = Auth::require('finance.reconcile'); $d = Http::body(); $reason = Validate::str($d, 'reason', 255, true); $target = Validate::enum($d, 'target', ['agency', 'advisor']); if (!$target) throw new HttpError(422, Validate::MSG, ['target' => 'Choose agency or advisor']);
        Db::tx(function () use ($u, $pa, $d, $reason, $target) { $c = self::find($u, (int)$pa['id'], true); if ($c['reversed_at']) throw new HttpError(409, 'This commission is reversed'); $amt = self::money($c, $d, 'amount', true); $m = Money::minorUnits($c['currency']);
            $base = $target === 'agency' ? Money::parse($c['expected'], $m) + Money::parse($c['adjustments_total'], $m, 'x', true) : Money::parse($c['advisor_amount'], $m) + Money::parse($c['advisor_adjustments'], $m, 'x', true);
            if ($base + $amt < 0) throw new HttpError(422, 'The adjustment would make the amount negative');
            if ($target === 'advisor' && $base + $amt < Money::parse($c['advisor_paid'], $m)) throw new HttpError(422, 'The advisor amount cannot drop below what has already been paid out');
            $aid = Db::insert('INSERT INTO commission_adjustments (commission_id, target, amount, reason, created_by, created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())', [$c['id'], $target, Money::fmt($amt, $m), $reason, (int)$u['id']]);
            $after = self::refresh((int)$c['id']); FinAudit::log((int)$u['id'], 'commission.adjusted', 'commission', (int)$c['id'], ['target' => $target, 'before' => Money::fmt($base, $m)], ['adjustmentId' => $aid, 'amount' => Money::fmt($amt, $m), 'status' => $after['status']], $reason); });
        return self::get($pa);
    }
    public static function reverse(array $pa, string $why = ''): array {
        $u = Auth::require('finance.reconcile'); $d = Http::body(); $reason = Validate::str($d, 'reason', 255, true);
        Db::tx(function () use ($u, $pa, $reason) { $c = self::find($u, (int)$pa['id'], true); if ($c['reversed_at']) throw new HttpError(409, 'Already reversed');
            if (Db::one("SELECT id FROM advisor_payouts WHERE commission_id=? AND status='recorded' LIMIT 1", [$c['id']])) throw new HttpError(409, 'Reverse the advisor payouts first');
            Db::exec('UPDATE commissions SET reversed_at=UTC_TIMESTAMP(), reversal_reason=?, version=version+1 WHERE id=?', [$reason, $c['id']]); $after = self::refresh((int)$c['id']);
            FinAudit::log((int)$u['id'], 'commission.reversed', 'commission', (int)$c['id'], ['status' => $c['status']], ['status' => $after['status']], $reason); });
        return self::get($pa);
    }
    public static function earn(array $pa): array {
        $u = Auth::require('finance.reconcile');
        Db::tx(function () use ($u, $pa) { $c = self::find($u, (int)$pa['id'], true); if ($c['reversed_at']) throw new HttpError(409, 'This commission is reversed');
            Db::exec('UPDATE commissions SET earned_at=COALESCE(earned_at, UTC_TIMESTAMP()) WHERE id=?', [$c['id']]); $a = self::refresh((int)$c['id']); FinAudit::log((int)$u['id'], 'commission.marked_earned', 'commission', (int)$c['id'], ['status' => $c['status']], ['status' => $a['status']]); });
        return self::get($pa);
    }
    public static function reverseLine(array $pa, string $table): array {
        $u = Auth::require($table === 'advisor_payouts' ? 'finance.payout' : 'finance.reconcile'); $d = Http::body(); $reason = Validate::str($d, 'reason', 255, true);
        $cid = Db::tx(function () use ($u, $pa, $table, $reason) { $x = Db::one("SELECT * FROM $table WHERE id=? FOR UPDATE", [(int)$pa['id']]); if (!$x) throw new HttpError(404, 'Record not found'); $c = self::find($u, (int)$x['commission_id'], true);
            if ($x['status'] === 'reversed') throw new HttpError(409, 'Already reversed');
            if ($table === 'commission_receipts') { $m = Money::minorUnits($c['currency']); $restRec = Money::parse($c['received'], $m) - Money::parse($x['amount'], $m); if ($restRec <= 0 && Money::parse($c['advisor_paid'], $m) > 0) throw new HttpError(409, 'Reverse the advisor payouts before reversing the last receipt'); }
            Db::exec("UPDATE $table SET status='reversed', reversed_at=UTC_TIMESTAMP(), reversal_reason=? WHERE id=?", [$reason, $x['id']]); $after = self::refresh((int)$c['id']);
            FinAudit::log((int)$u['id'], $table === 'advisor_payouts' ? 'commission.payout_reversed' : 'commission.receipt_reversed', 'commission', (int)$c['id'], ['lineId' => (int)$x['id'], 'amount' => $x['amount']], ['status' => $after['status']], $reason); return (int)$c['id']; });
        return self::get(['id' => $cid]);
    }
    /** Called when a booking is cancelled: commissions without receipts are reversed automatically; others are left for finance review. */
    public static function onBookingCancelled(int $bookingId, int $actor): void {
        $c = Db::one('SELECT * FROM commissions WHERE booking_id=?', [$bookingId]); if (!$c || $c['reversed_at']) return;
        if (Db::one("SELECT id FROM commission_receipts WHERE commission_id=? AND status='recorded' LIMIT 1", [$c['id']])) { FinAudit::log($actor, 'commission.review_needed', 'commission', (int)$c['id'], null, null, 'Booking cancelled after commission was received'); return; }
        Db::exec('UPDATE commissions SET reversed_at=UTC_TIMESTAMP(), reversal_reason=? WHERE id=?', ['Booking cancelled', $c['id']]); self::refresh((int)$c['id']);
        FinAudit::log($actor, 'commission.reversed', 'commission', (int)$c['id'], ['status' => $c['status']], ['status' => 'reversed'], 'Booking cancelled');
    }
}
