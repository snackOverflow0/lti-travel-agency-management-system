<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Auth, Db, FinAudit, FinDoc, Http, HttpError, Money, Validate};
/** Dashboard metrics, operational reports, exchange rates, tax rates, settings and the financial audit trail.
 *  Figures are operational totals from recorded data, not audited accounting statements. */
final class FinanceController {
    const DEFINITIONS = [
        'invoiced' => 'Total of issued, partially paid and paid invoices (total plus adjustments) with an issue date in the period.',
        'paymentsRecorded' => 'Client payments recorded with a received date in the period, minus refunds dated in the period. Reversed payments are excluded. Recorded payments are not proof of settlement.',
        'receivables' => 'Current outstanding balance of issued and partially paid invoices (as of today, not limited by the period).',
        'overdue' => 'Outstanding balance of invoices past their due date (as of today).',
        'payables' => 'Current unpaid balance owed to suppliers on open and partially paid payables (as of today).',
        'commissionExpected' => 'Agency commission expected from suppliers (rule-based amount plus adjustments) on non-reversed commissions for bookings travelling in the period.',
        'commissionReceived' => 'Supplier commission receipts recorded with a received date in the period (agency income).',
        'advisorPayable' => 'Advisor commission share still owed on commissions where the agency has received money (staff compensation, not agency income).',
        'bookedSales' => 'Gross value of non-cancelled bookings travelling in the period. This is booking value, not recognised revenue.'];
    private static function filters(array $u): array {
        $from = Validate::date($_GET, 'from') ?: gmdate('Y') . '-01-01'; $to = Validate::date($_GET, 'to') ?: gmdate('Y-m-d');
        $ccy = ($c = (string)($_GET['currency'] ?? '')) !== '' ? Money::currency($c) : null;
        return ['from' => $from, 'to' => $to, 'trip' => (int)($_GET['tripId'] ?? 0) ?: null, 'advisor' => (int)($_GET['advisorId'] ?? 0) ?: null, 'supplier' => (int)($_GET['supplierId'] ?? 0) ?: null, 'ccy' => $ccy];
    }
    private static function grouped(string $sql, array $p): array { $o = []; foreach (Db::all($sql, $p) as $r) $o[$r['currency']] = Money::show($r['v'] ?? '0', $r['currency']); return $o; }
    private static function rateFor(string $from, string $to, string $date): ?array {
        if ($from === $to) return ['mul' => '1', 'div' => '1'];
        $mf = Money::minorUnits($from); $mt = Money::minorUnits($to);
        $r = Db::one('SELECT rate FROM exchange_rates WHERE from_currency=? AND to_currency=? AND effective_date<=? ORDER BY effective_date DESC LIMIT 1', [$from, $to, $date]);
        if ($r) return ['mul' => Money::scaled($r['rate'], 8, 'rate') . str_repeat('0', $mt), 'div' => '100000000' . str_repeat('0', $mf)];
        $r = Db::one('SELECT rate FROM exchange_rates WHERE from_currency=? AND to_currency=? AND effective_date<=? ORDER BY effective_date DESC LIMIT 1', [$to, $from, $date]);
        if ($r) return ['mul' => '100000000' . str_repeat('0', $mt), 'div' => Money::scaled($r['rate'], 8, 'rate') . str_repeat('0', $mf)];
        return null;
    }
    private static function convert(array $byCcy, string $to, string $date): array {
        $sum = 0; $missing = [];
        foreach ($byCcy as $ccy => $v) { $m = Money::minorUnits($ccy); $amt = Money::parse($v, $m, 'v', true); if ($amt === 0) continue; $r = self::rateFor($ccy, $to, $date); if (!$r) { $missing[] = $ccy; continue; } $sum += Money::mulDiv($amt, $r['mul'], $r['div']); }
        return ['total' => Money::fmt($sum, Money::minorUnits($to)), 'currency' => $to, 'missingRates' => $missing];
    }
    public static function dashboard(): array {
        $u = Auth::require('finance.view'); $f = self::filters($u); [$iw, $ip] = FinDoc::scopeSql($u, 'i'); [$tw, $tp] = TripController::scope($u);
        $cmw = Auth::can('finance.reconcile') ? '1=1' : 'cm.advisor_id=' . (int)$u['id'];
        $invF = function (array &$p) use ($f, $iw, $ip) { $w = [$iw]; $p = array_merge($p, $ip); if ($f['trip']) { $w[] = 'i.trip_id=?'; $p[] = $f['trip']; } if ($f['advisor']) { $w[] = 't.owner_id=?'; $p[] = $f['advisor']; } if ($f['ccy']) { $w[] = 'i.currency=?'; $p[] = $f['ccy']; } return implode(' AND ', $w); };
        $J = 'FROM invoices i LEFT JOIN trips t ON t.id=i.trip_id LEFT JOIN contacts c ON c.id=i.contact_id';
        $p = []; $w = $invF($p); $invoiced = self::grouped("SELECT i.currency, SUM(i.total + i.adjustments_total) v $J WHERE $w AND i.status IN ('issued','partially_paid','paid') AND i.issue_date BETWEEN ? AND ? GROUP BY i.currency", array_merge($p, [$f['from'], $f['to']]));
        $p = []; $w = $invF($p); $pay = self::grouped("SELECT p.currency, SUM(p.amount) v FROM payments p JOIN invoices i ON i.id=p.invoice_id LEFT JOIN trips t ON t.id=i.trip_id LEFT JOIN contacts c ON c.id=i.contact_id WHERE $w AND p.status='recorded' AND p.received_on BETWEEN ? AND ? GROUP BY p.currency", array_merge($p, [$f['from'], $f['to']]));
        $p = []; $w = $invF($p); $refunds = self::grouped("SELECT p.currency, SUM(r.amount) v FROM refunds r JOIN payments p ON p.id=r.payment_id JOIN invoices i ON i.id=p.invoice_id LEFT JOIN trips t ON t.id=i.trip_id LEFT JOIN contacts c ON c.id=i.contact_id WHERE $w AND p.status='recorded' AND r.refunded_on BETWEEN ? AND ? GROUP BY p.currency", array_merge($p, [$f['from'], $f['to']]));
        foreach ($refunds as $cc => $v) { $m = Money::minorUnits($cc); $pay[$cc] = Money::fmt(Money::parse($pay[$cc] ?? '0', $m) - Money::parse($v, $m), $m); }
        $p = []; $w = $invF($p); $recv = self::grouped("SELECT i.currency, SUM(i.balance) v $J WHERE $w AND i.status IN ('issued','partially_paid') GROUP BY i.currency", $p);
        $p = []; $w = $invF($p); $over = self::grouped("SELECT i.currency, SUM(i.balance) v $J WHERE $w AND i.status IN ('issued','partially_paid') AND i.due_date < ? AND i.balance > 0 GROUP BY i.currency", array_merge($p, [gmdate('Y-m-d')]));
        $p = []; $w = $invF($p); $overN = (int)Db::one("SELECT COUNT(*) n $J WHERE $w AND i.status IN ('issued','partially_paid') AND i.due_date < ? AND i.balance > 0", array_merge($p, [gmdate('Y-m-d')]))['n'];
        $bw = [$tw]; $bp = $tp; if ($f['trip']) { $bw[] = 't.id=?'; $bp[] = $f['trip']; } if ($f['advisor']) { $bw[] = 't.owner_id=?'; $bp[] = $f['advisor']; } if ($f['supplier']) { $bw[] = 'b.supplier_id=?'; $bp[] = $f['supplier']; } $bws = implode(' AND ', $bw);
        $payables = self::grouped("SELECT sp.currency, SUM(sp.balance) v FROM supplier_payables sp JOIN bookings b ON b.id=sp.booking_id JOIN trips t ON t.id=b.trip_id WHERE $bws AND sp.status IN ('open','partially_paid')" . ($f['ccy'] ? ' AND sp.currency=?' : '') . ' GROUP BY sp.currency', $f['ccy'] ? array_merge($bp, [$f['ccy']]) : $bp);
        $sales = self::grouped("SELECT b.currency, SUM(b.amount) v FROM bookings b JOIN trips t ON t.id=b.trip_id WHERE $bws AND b.status<>'cancelled' AND b.travel_date BETWEEN ? AND ?" . ($f['ccy'] ? ' AND b.currency=?' : '') . ' GROUP BY b.currency', array_merge($bp, [$f['from'], $f['to']], $f['ccy'] ? [$f['ccy']] : []));
        $cw = [$cmw]; $cp = []; if ($f['advisor']) { $cw[] = 'cm.advisor_id=?'; $cp[] = $f['advisor']; } if ($f['supplier']) { $cw[] = 'b.supplier_id=?'; $cp[] = $f['supplier']; } if ($f['trip']) { $cw[] = 'b.trip_id=?'; $cp[] = $f['trip']; } if ($f['ccy']) { $cw[] = 'cm.currency=?'; $cp[] = $f['ccy']; } $cws = implode(' AND ', $cw);
        $cExp = self::grouped("SELECT cm.currency, SUM(cm.expected + cm.adjustments_total) v FROM commissions cm JOIN bookings b ON b.id=cm.booking_id WHERE $cws AND cm.reversed_at IS NULL AND b.travel_date BETWEEN ? AND ? GROUP BY cm.currency", array_merge($cp, [$f['from'], $f['to']]));
        $cRec = self::grouped("SELECT cm.currency, SUM(r.amount) v FROM commission_receipts r JOIN commissions cm ON cm.id=r.commission_id JOIN bookings b ON b.id=cm.booking_id WHERE $cws AND r.status='recorded' AND r.received_on BETWEEN ? AND ? GROUP BY cm.currency", array_merge($cp, [$f['from'], $f['to']]));
        $cAdv = self::grouped("SELECT cm.currency, SUM(GREATEST(cm.advisor_amount + cm.advisor_adjustments - cm.advisor_paid, 0)) v FROM commissions cm JOIN bookings b ON b.id=cm.booking_id WHERE $cws AND cm.reversed_at IS NULL AND cm.received > 0 GROUP BY cm.currency", $cp);
        $p = []; $w = $invF($p); $activity = Db::all("SELECT DATE_FORMAT(p.received_on, '%Y-%m') month, p.currency, SUM(p.amount) amount FROM payments p JOIN invoices i ON i.id=p.invoice_id LEFT JOIN trips t ON t.id=i.trip_id LEFT JOIN contacts c ON c.id=i.contact_id WHERE $w AND p.status='recorded' AND p.received_on BETWEEN ? AND ? GROUP BY month, p.currency ORDER BY month", array_merge($p, [$f['from'], $f['to']]));
        $rep = Db::one("SELECT setting_value v FROM app_settings WHERE setting_key='reporting_currency'")['v'] ?? 'USD';
        $metrics = ['invoiced' => $invoiced, 'paymentsRecorded' => $pay, 'receivables' => $recv, 'overdue' => $over, 'payables' => $payables, 'commissionExpected' => $cExp, 'commissionReceived' => $cRec, 'advisorPayable' => $cAdv, 'bookedSales' => $sales];
        $out = []; foreach ($metrics as $k => $v) { ksort($v); $out[$k] = ['byCurrency' => (object)$v, 'converted' => self::convert($v, $rep, $f['to']), 'definition' => self::DEFINITIONS[$k]]; }
        return ['filters' => $f, 'reportingCurrency' => $rep, 'overdueCount' => $overN, 'metrics' => $out, 'paymentsByMonth' => $activity, 'note' => 'Operational figures from recorded data; not audited accounting statements.'];
    }
    /* ---------------- reports ---------------- */
    const REPORTS = ['aging', 'balances', 'payments', 'payables', 'commissions', 'advisors', 'trips', 'bookings', 'quotes'];
    public static function report(array $pa): void {
        $u = Auth::require('finance.view'); $name = (string)($_GET['name'] ?? ''); if (!in_array($name, self::REPORTS, true)) throw new HttpError(404, 'Unknown report');
        $f = self::filters($u); [$iw, $ip] = FinDoc::scopeSql($u, 'i'); [$tw, $tp] = TripController::scope($u); $cmw = Auth::can('finance.reconcile') ? '1=1' : 'cm.advisor_id=' . (int)$u['id']; $today = gmdate('Y-m-d');
        $q = trim((string)($_GET['q'] ?? '')); $like = '%' . addcslashes($q, '%_\\') . '%';
        $IJ = "FROM invoices i LEFT JOIN trips t ON t.id=i.trip_id LEFT JOIN contacts c ON c.id=i.contact_id";
        switch ($name) {
            case 'aging': $rows = Db::all("SELECT i.number Invoice, CONCAT_WS(' ', c.first_name, c.last_name) Client, t.name Trip, i.currency Currency, i.issue_date Issued, i.due_date Due, GREATEST(DATEDIFF(?, i.due_date), 0) DaysOverdue,
                CASE WHEN i.due_date >= ? THEN 'Current' WHEN DATEDIFF(?, i.due_date) <= 30 THEN '1-30' WHEN DATEDIFF(?, i.due_date) <= 60 THEN '31-60' WHEN DATEDIFF(?, i.due_date) <= 90 THEN '61-90' ELSE '90+' END Bucket, i.balance Balance
                $IJ WHERE $iw AND i.status IN ('issued','partially_paid') AND i.balance > 0" . ($q !== '' ? " AND (i.number LIKE ? OR CONCAT_WS(' ', c.first_name, c.last_name) LIKE ?)" : '') . ' ORDER BY i.due_date', array_merge([$today, $today, $today, $today, $today], $ip, $q !== '' ? [$like, $like] : [])); break;
            case 'balances': $rows = Db::all("SELECT CONCAT_WS(' ', c.first_name, c.last_name) Client, i.currency Currency, COUNT(*) OpenInvoices, SUM(i.total + i.adjustments_total) Invoiced, SUM(i.amount_paid) Paid, SUM(i.balance) Balance $IJ WHERE $iw AND i.status IN ('issued','partially_paid') GROUP BY c.id, i.currency ORDER BY Balance DESC", $ip); break;
            case 'payments': $rows = Db::all("SELECT p.received_on Received, i.number Invoice, CONCAT_WS(' ', c.first_name, c.last_name) Client, p.currency Currency, p.amount Amount, (SELECT COALESCE(SUM(r.amount),0) FROM refunds r WHERE r.payment_id=p.id) Refunded, p.method Method, p.reference Reference, p.status Status, p.verification Verification
                FROM payments p JOIN invoices i ON i.id=p.invoice_id LEFT JOIN trips t ON t.id=i.trip_id LEFT JOIN contacts c ON c.id=i.contact_id WHERE $iw AND p.received_on BETWEEN ? AND ? ORDER BY p.received_on DESC", array_merge($ip, [$f['from'], $f['to']])); break;
            case 'payables': $rows = Db::all("SELECT s.name Supplier, b.reference Booking, t.name Trip, sp.supplier_invoice_ref SupplierInvoice, sp.currency Currency, sp.amount Amount, sp.paid_total Paid, sp.balance Balance, sp.due_date Due, sp.status Status
                FROM supplier_payables sp JOIN suppliers s ON s.id=sp.supplier_id JOIN bookings b ON b.id=sp.booking_id JOIN trips t ON t.id=b.trip_id WHERE $tw" . ($f['supplier'] ? ' AND sp.supplier_id=' . $f['supplier'] : '') . ' ORDER BY sp.due_date', $tp); break;
            case 'commissions': $rows = Db::all("SELECT b.reference Booking, t.name Trip, s.name Supplier, a.full_name Advisor, b.travel_date Travel, cm.currency Currency, cm.expected + cm.adjustments_total AgencyExpected, cm.received AgencyReceived, cm.advisor_amount + cm.advisor_adjustments AdvisorShare, cm.advisor_paid AdvisorPaid, cm.status Status
                FROM commissions cm JOIN bookings b ON b.id=cm.booking_id JOIN trips t ON t.id=b.trip_id LEFT JOIN suppliers s ON s.id=b.supplier_id LEFT JOIN users a ON a.id=cm.advisor_id WHERE $cmw" . ($f['advisor'] ? ' AND cm.advisor_id=' . $f['advisor'] : '') . ($f['supplier'] ? ' AND b.supplier_id=' . $f['supplier'] : '') . ' AND (b.travel_date IS NULL OR b.travel_date BETWEEN ? AND ?) ORDER BY b.travel_date', [$f['from'], $f['to']]); break;
            case 'advisors': $rows = Db::all("SELECT a.full_name Advisor, cm.currency Currency, COUNT(*) Bookings, SUM(b.amount) BookedSales, SUM(cm.expected + cm.adjustments_total) AgencyExpected, SUM(cm.received) AgencyReceived, SUM(cm.advisor_amount + cm.advisor_adjustments) AdvisorShare, SUM(cm.advisor_paid) AdvisorPaid,
                SUM(CASE WHEN cm.received > 0 THEN GREATEST(cm.advisor_amount + cm.advisor_adjustments - cm.advisor_paid, 0) ELSE 0 END) AdvisorPayable FROM commissions cm JOIN bookings b ON b.id=cm.booking_id LEFT JOIN users a ON a.id=cm.advisor_id WHERE $cmw AND cm.reversed_at IS NULL GROUP BY cm.advisor_id, cm.currency ORDER BY Advisor", []); break;
            case 'trips': $rows = Db::all("SELECT t.name Trip, t.currency Currency, (SELECT COALESCE(SUM(i.total + i.adjustments_total),0) FROM invoices i WHERE i.trip_id=t.id AND i.status IN ('issued','partially_paid','paid')) Invoiced,
                (SELECT COALESCE(SUM(i.amount_paid),0) FROM invoices i WHERE i.trip_id=t.id AND i.status<>'void') Paid, (SELECT COALESCE(SUM(i.balance),0) FROM invoices i WHERE i.trip_id=t.id AND i.status IN ('issued','partially_paid')) Balance,
                (SELECT COALESCE(SUM(sp.amount),0) FROM supplier_payables sp JOIN bookings b ON b.id=sp.booking_id WHERE b.trip_id=t.id) SupplierCosts, (SELECT COALESCE(SUM(sp.balance),0) FROM supplier_payables sp JOIN bookings b ON b.id=sp.booking_id WHERE b.trip_id=t.id) SupplierUnpaid,
                (SELECT COALESCE(SUM(cm.expected + cm.adjustments_total),0) FROM commissions cm JOIN bookings b ON b.id=cm.booking_id WHERE b.trip_id=t.id AND cm.reversed_at IS NULL) CommissionExpected
                FROM trips t WHERE $tw AND t.deleted_at IS NULL" . ($q !== '' ? ' AND t.name LIKE ?' : '') . ' ORDER BY t.start_date', array_merge($tp, $q !== '' ? [$like] : [])); break;
            case 'bookings': $rows = Db::all("SELECT b.reference Booking, t.name Trip, s.name Supplier, b.booking_type Category, b.status Status, b.currency Currency, b.amount Amount, b.supplier_cost SupplierCost, COALESCE(cm.expected + cm.adjustments_total, 0) CommissionExpected,
                (SELECT COALESCE(SUM(sp.balance),0) FROM supplier_payables sp WHERE sp.booking_id=b.id) SupplierUnpaid FROM bookings b JOIN trips t ON t.id=b.trip_id LEFT JOIN suppliers s ON s.id=b.supplier_id LEFT JOIN commissions cm ON cm.booking_id=b.id WHERE $tw" . ($f['supplier'] ? ' AND b.supplier_id=' . $f['supplier'] : '') . ' ORDER BY b.travel_date', $tp); break;
            default: [$qw, $qp] = FinDoc::scopeSql($u, 'q'); $rows = Db::all("SELECT q.currency Currency, COUNT(*) Quotes, SUM(q.status='draft') Draft, SUM(q.status='sent' AND (q.valid_until IS NULL OR q.valid_until >= ?)) Sent, SUM(q.status='accepted') Accepted, SUM(q.status='rejected') Rejected, SUM(q.status='sent' AND q.valid_until < ?) Expired,
                SUM(EXISTS (SELECT 1 FROM invoices i WHERE i.quote_id=q.id)) Converted, SUM(CASE WHEN q.status='accepted' THEN q.total ELSE 0 END) AcceptedValue FROM quotations q LEFT JOIN trips t ON t.id=q.trip_id LEFT JOIN contacts c ON c.id=q.contact_id WHERE $qw AND q.status<>'archived' AND DATE(q.created_at) BETWEEN ? AND ? GROUP BY q.currency", array_merge([$today, $today], $qp, [$f['from'], $f['to']]));
                foreach ($rows as &$r) { $decided = (int)$r['Accepted'] + (int)$r['Rejected'] + (int)$r['Expired']; $r['AcceptanceRate'] = $decided ? round(100 * (int)$r['Accepted'] / $decided, 1) . '%' : '—'; } unset($r);
        }
        if (($_GET['format'] ?? '') === 'csv') {
            header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="lti-' . $name . '-' . gmdate('Ymd') . '.csv"'); header('Cache-Control: no-store');
            $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); $cell = function ($v) { $v = (string)$v; return preg_match('/^[=+\-@]/', $v) && !is_numeric($v) ? "'" . $v : $v; };
            fputcsv($o, ['Operational report generated ' . gmdate('Y-m-d H:i') . ' UTC — not an audited accounting statement']); if ($rows) fputcsv($o, array_keys($rows[0])); foreach ($rows as $r) fputcsv($o, array_map($cell, $r)); fclose($o);
            \LTI\Audit::log((int)$u['id'], 'finance.report_exported', 'report', null, ['report' => $name]); exit;
        }
        \LTI\Http::json(['report' => $name, 'columns' => $rows ? array_keys($rows[0]) : [], 'rows' => $rows, 'filters' => $f, 'note' => 'Operational report from recorded data; not an audited accounting statement.']); exit;
    }
    /* ---------------- rates, settings, tax ---------------- */
    public static function rates(): array { Auth::require('finance.view'); return ['items' => Db::all('SELECT id, from_currency fromCurrency, to_currency toCurrency, rate, effective_date effectiveDate, source_note sourceNote, created_at createdAt FROM exchange_rates ORDER BY effective_date DESC, id DESC LIMIT 500')]; }
    public static function addRate(): array {
        $u = Auth::require('finance.reconcile'); $d = Http::body(); $from = Money::currency($d['fromCurrency'] ?? ''); $to = Money::currency($d['toCurrency'] ?? ''); if ($from === $to) throw new HttpError(422, 'Choose two different currencies');
        $rate = Money::scaled((string)($d['rate'] ?? ''), 8, 'rate'); if ($rate === '0') throw new HttpError(422, Validate::MSG, ['rate' => 'Must be greater than zero']); $date = Validate::date($d, 'effectiveDate') ?: gmdate('Y-m-d'); $src = Validate::str($d, 'sourceNote', 190, true);
        $id = Db::tx(function () use ($from, $to, $rate, $date, $src, $u) { if (Db::one('SELECT id FROM exchange_rates WHERE from_currency=? AND to_currency=? AND effective_date=?', [$from, $to, $date])) throw new HttpError(409, 'A rate for this pair and date already exists');
            $id = Db::insert('INSERT INTO exchange_rates (from_currency, to_currency, rate, effective_date, source_note, created_by, created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())', [$from, $to, Money::fmt((int)$rate, 8), $date, $src, (int)$u['id']]);
            FinAudit::log((int)$u['id'], 'fx_rate.added', 'exchange_rate', $id, null, ['pair' => "$from/$to", 'rate' => Money::fmt((int)$rate, 8), 'date' => $date, 'source' => $src]); return $id; });
        return ['rate' => Db::one('SELECT * FROM exchange_rates WHERE id=?', [$id])];
    }
    public static function settings(): array { Auth::require('finance.view');
        return ['reportingCurrency' => Db::one("SELECT setting_value v FROM app_settings WHERE setting_key='reporting_currency'")['v'] ?? 'USD', 'currencies' => Db::all('SELECT code, name, minor_units minorUnits FROM currencies WHERE is_active=1 ORDER BY code'),
            'taxRates' => Db::all('SELECT id, name, rate, is_active isActive FROM tax_rates ORDER BY name')]; }
    public static function updateSettings(): array { $u = Auth::require('finance.reconcile'); $d = Http::body(); $c = Money::currency($d['reportingCurrency'] ?? '');
        Db::tx(function () use ($c, $u) { $old = Db::one("SELECT setting_value v FROM app_settings WHERE setting_key='reporting_currency'")['v'] ?? null; Db::exec("REPLACE INTO app_settings (setting_key, setting_value, updated_by, updated_at) VALUES ('reporting_currency', ?, ?, UTC_TIMESTAMP())", [$c, (int)$u['id']]);
            FinAudit::log((int)$u['id'], 'settings.reporting_currency', 'setting', 0, ['reportingCurrency' => $old], ['reportingCurrency' => $c]); });
        return self::settings(); }
    public static function addTaxRate(): array { $u = Auth::require('finance.reconcile'); $d = Http::body(); $rate = Money::scaled((string)($d['rate'] ?? ''), 4, 'rate'); if ((int)$rate > 1000000) throw new HttpError(422, Validate::MSG, ['rate' => 'At most 100%']);
        $id = Db::tx(function () use ($d, $rate, $u) { $id = Db::insert('INSERT INTO tax_rates (name, rate, is_active, created_at) VALUES (?,?,1,UTC_TIMESTAMP())', [Validate::str($d, 'name', 80, true), Money::fmt((int)$rate, 4)]); FinAudit::log((int)$u['id'], 'tax_rate.added', 'tax_rate', $id, null, ['rate' => Money::fmt((int)$rate, 4)]); return $id; });
        return ['taxRate' => Db::one('SELECT id, name, rate, is_active isActive FROM tax_rates WHERE id=?', [$id])]; }
    public static function setTaxRateActive(array $pa): array { $u = Auth::require('finance.reconcile'); $d = Http::body(); $t = Db::one('SELECT * FROM tax_rates WHERE id=?', [(int)$pa['id']]); if (!$t) throw new HttpError(404, 'Tax rate not found');
        Db::tx(function () use ($t, $d, $u) { Db::exec('UPDATE tax_rates SET is_active=? WHERE id=?', [!empty($d['isActive']) ? 1 : 0, $t['id']]); FinAudit::log((int)$u['id'], 'tax_rate.status', 'tax_rate', (int)$t['id'], ['active' => (int)$t['is_active']], ['active' => !empty($d['isActive']) ? 1 : 0]); });
        return ['ok' => true]; }
    /* ---------------- audit ---------------- */
    public static function audit(): array { Auth::require('finance.reconcile'); $w = []; $p = [];
        if (($t = (string)($_GET['entityType'] ?? '')) !== '') { $w[] = 'e.entity_type=?'; $p[] = $t; } if (($i = (int)($_GET['entityId'] ?? 0)) > 0) { $w[] = 'e.entity_id=?'; $p[] = $i; }
        return ['items' => array_map(function ($r) { return ['id' => (int)$r['id'], 'action' => $r['action'], 'entityType' => $r['entity_type'], 'entityId' => (int)$r['entity_id'], 'by' => $r['full_name'], 'at' => $r['created_at'], 'reason' => $r['reason'], 'before' => json_decode((string)$r['before_json'], true), 'after' => json_decode((string)$r['after_json'], true)]; },
            Db::all('SELECT e.*, u.full_name FROM financial_audit_events e LEFT JOIN users u ON u.id=e.actor_id ' . ($w ? 'WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY e.id DESC LIMIT 500', $p))]; }
    public static function verifyAudit(): array { Auth::require('audit.view'); return FinAudit::verify(); }
}
