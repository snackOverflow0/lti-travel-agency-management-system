<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Auth, Audit, Db, Http, HttpError, Validate, Events};
/** Bookings record supplier reservations for a trip. Amounts are stored for Phase 5; no payment processing happens here. */
final class BookingController {
    const CATEGORIES = ['accommodation', 'flight', 'cruise', 'transfer', 'activity', 'tour', 'dining', 'insurance', 'other'];
    const STATUSES = ['pending', 'confirmed', 'cancelled'];
    private static function find(array $u, int $id): array {
        [$w, $p] = TripController::scope($u);
        $r = Db::one("SELECT b.*, t.name trip_name, t.trip_type, s.name supplier_name, s.status supplier_status, a.full_name agent_name FROM bookings b JOIN trips t ON t.id=b.trip_id LEFT JOIN suppliers s ON s.id=b.supplier_id LEFT JOIN users a ON a.id=b.agent_id WHERE b.id=? AND $w", array_merge([$id], $p));
        if (!$r) throw new HttpError(404, 'Booking not found');
        return $r;
    }
    private static function out(array $r): array {
        return ['id' => (int)$r['id'], 'reference' => $r['reference'], 'tripId' => (int)$r['trip_id'], 'tripName' => $r['trip_name'], 'tripType' => $r['trip_type'] ?? null, 'supplierId' => $r['supplier_id'] === null ? null : (int)$r['supplier_id'],
            'supplierName' => $r['supplier_name'], 'supplierArchived' => ($r['supplier_status'] ?? '') === 'archived', 'category' => $r['booking_type'], 'status' => $r['status'], 'confirmationNumber' => $r['confirmation_number'], 'confirmationDetails' => $r['confirmation_details'],
            'startDate' => $r['travel_date'], 'endDate' => $r['end_date'], 'amount' => (float)$r['amount'], 'supplierCost' => $r['supplier_cost'] === null ? null : (float)$r['supplier_cost'], 'commissionRate' => $r['commission_rate'] === null ? null : (float)$r['commission_rate'],
            'commissionExpected' => (float)$r['commission_expected'], 'agentId' => $r['agent_id'] === null ? null : (int)$r['agent_id'], 'agentName' => $r['agent_name'] ?? null, 'notes' => $r['notes'], 'cancelledAt' => $r['cancelled_at'], 'cancelReason' => $r['cancel_reason'],
            'version' => (int)$r['version'], 'createdAt' => $r['created_at'], 'updatedAt' => $r['updated_at']];
    }
    private static function detail(array $u, int $id): array {
        $b = self::out(self::find($u, $id));
        $b['travelers'] = array_map(function ($r) { return ['contactId' => (int)$r['contact_id'], 'firstName' => $r['first_name'], 'lastName' => $r['last_name']]; }, Db::all('SELECT bt.contact_id, c.first_name, c.last_name FROM booking_travelers bt JOIN contacts c ON c.id=bt.contact_id WHERE bt.booking_id=?', [$id]));
        $b['history'] = array_map(function ($r) { return ['action' => $r['action'], 'by' => $r['full_name'], 'at' => $r['created_at'], 'detail' => $r['detail'] ? json_decode($r['detail'], true) : null]; }, Db::all("SELECT a.action, a.detail, a.created_at, u.full_name FROM audit_log a LEFT JOIN users u ON u.id=a.user_id WHERE a.entity_type='booking' AND a.entity_id=? ORDER BY a.id", [(string)$id]));
        return $b;
    }
    public static function list(): array {
        $u = Auth::require('trips.view'); [$w, $p] = TripController::scope($u); $where = [$w];
        foreach (['status' => [self::STATUSES, 'b.status'], 'category' => [self::CATEGORIES, 'b.booking_type']] as $k => [$allowed, $col]) if (($v = (string)($_GET[$k] ?? '')) !== '') { Validate::enum([$k => $v], $k, $allowed); $where[] = "$col=?"; $p[] = $v; }
        foreach (['supplierId' => 'b.supplier_id', 'tripId' => 'b.trip_id'] as $k => $col) if (($v = (int)($_GET[$k] ?? 0)) > 0) { $where[] = "$col=?"; $p[] = $v; }
        if (($g = (int)($_GET['groupId'] ?? 0)) > 0) { $where[] = '(b.trip_id=? OR t.parent_trip_id=?)'; array_push($p, $g, $g); }
        if (($fr = (string)($_GET['from'] ?? '')) !== '') { Validate::date(['f' => $fr], 'f'); $where[] = 'b.travel_date>=?'; $p[] = $fr; }
        if (($to = (string)($_GET['to'] ?? '')) !== '') { Validate::date(['t' => $to], 't'); $where[] = 'b.travel_date<=?'; $p[] = $to; }
        if (($q = trim((string)($_GET['q'] ?? ''))) !== '') { $like = '%' . addcslashes($q, '%_\\') . '%'; $where[] = '(b.reference LIKE ? OR b.confirmation_number LIKE ? OR s.name LIKE ? OR t.name LIKE ?)'; array_push($p, $like, $like, $like, $like); }
        $from = 'FROM bookings b JOIN trips t ON t.id=b.trip_id LEFT JOIN suppliers s ON s.id=b.supplier_id LEFT JOIN users a ON a.id=b.agent_id WHERE ' . implode(' AND ', $where);
        $page = max(1, (int)($_GET['page'] ?? 1)); $per = min(200, max(1, (int)($_GET['perPage'] ?? 100)));
        $total = (int)Db::one("SELECT COUNT(*) n $from", $p)['n'];
        $rows = Db::all("SELECT b.*, t.name trip_name, t.trip_type, s.name supplier_name, s.status supplier_status, a.full_name agent_name $from ORDER BY (b.travel_date IS NULL), b.travel_date, b.id LIMIT $per OFFSET " . (($page - 1) * $per), $p);
        $items = array_map([self::class, 'out'], $rows);
        if ($items) { $ids = implode(',', array_column($items, 'id')); $tv = []; foreach (Db::all("SELECT booking_id, contact_id FROM booking_travelers WHERE booking_id IN ($ids)") as $r) $tv[(int)$r['booking_id']][] = (int)$r['contact_id']; foreach ($items as &$i) $i['travelerIds'] = $tv[$i['id']] ?? []; unset($i); }
        return ['items' => $items, 'total' => $total, 'page' => $page, 'perPage' => $per];
    }
    public static function get(array $pa): array { $u = Auth::require('trips.view'); return ['booking' => self::detail($u, (int)$pa['id'])]; }
    private static function fields(array $u, array $d, ?array $cur): array {
        if ($cur) $d = array_merge(['category' => $cur['booking_type'], 'confirmationNumber' => $cur['confirmation_number'], 'confirmationDetails' => $cur['confirmation_details'], 'startDate' => $cur['travel_date'], 'endDate' => $cur['end_date'], 'notes' => $cur['notes']], $d);
        $tripId = array_key_exists('tripId', $d) ? (int)$d['tripId'] : ($cur ? (int)$cur['trip_id'] : 0);
        if ($tripId <= 0) throw new HttpError(422, Validate::MSG, ['tripId' => 'Choose a trip']);
        $trip = TripController::findRow($u, $tripId);   // 404 for missing or out-of-scope trips
        $supId = array_key_exists('supplierId', $d) ? ($d['supplierId'] === null || $d['supplierId'] === '' ? null : (int)$d['supplierId']) : ($cur && $cur['supplier_id'] !== null ? (int)$cur['supplier_id'] : null);
        if ($supId !== null) { $s = Db::one('SELECT id, status FROM suppliers WHERE id=?', [$supId]); if (!$s) throw new HttpError(422, Validate::MSG, ['supplierId' => 'Unknown supplier']);
            if ($s['status'] === 'archived' && (!$cur || (int)$cur['supplier_id'] !== $supId)) throw new HttpError(422, Validate::MSG, ['supplierId' => 'This supplier is archived']); }
        $f = ['trip_id' => (int)$trip['id'], 'currency' => $cur ? $cur['currency'] : ($trip['currency'] ?? 'USD'), 'supplier_id' => $supId, 'booking_type' => Validate::enum($d, 'category', self::CATEGORIES, $cur ? $cur['booking_type'] : null),
            'status' => Validate::enum($d, 'status', ['pending', 'confirmed'], $cur ? ($cur['status'] === 'cancelled' ? 'cancelled' : $cur['status']) : 'pending'),
            'confirmation_number' => Validate::str($d, 'confirmationNumber', 60), 'confirmation_details' => Validate::str($d, 'confirmationDetails', 5000), 'travel_date' => Validate::date($d, 'startDate'), 'end_date' => Validate::date($d, 'endDate'),
            'notes' => Validate::str($d, 'notes', 5000)];
        if ($f['booking_type'] === null) throw new HttpError(422, Validate::MSG, ['category' => 'Required']);
        if ($f['travel_date'] && $f['end_date'] && $f['end_date'] < $f['travel_date']) throw new HttpError(422, Validate::MSG, ['endDate' => 'End date is before the start date']);
        foreach (['amount' => 'amount', 'supplierCost' => 'supplier_cost', 'commissionRate' => 'commission_rate'] as $k => $col) if (array_key_exists($k, $d)) {
            $v = $d[$k]; if ($v === null || $v === '') { $f[$col] = $col === 'amount' ? 0 : null; continue; }
            if (!is_numeric($v) || $v < 0 || ($col === 'commission_rate' && $v > 100)) throw new HttpError(422, Validate::MSG, [$k => 'Enter a valid non-negative number']);
            $f[$col] = round((float)$v, 2);
        }
        $amt = $f['amount'] ?? ($cur ? (float)$cur['amount'] : 0); $rate = array_key_exists('commission_rate', $f) ? $f['commission_rate'] : ($cur ? $cur['commission_rate'] : null);
        $f['commission_expected'] = $rate === null ? 0 : round($amt * (float)$rate / 100, 2);
        if (array_key_exists('agentId', $d)) { $a = $d['agentId'] === null || $d['agentId'] === '' ? null : (int)$d['agentId']; if ($a !== null && !Db::one('SELECT id FROM users WHERE id=? AND is_active=1', [$a])) throw new HttpError(422, Validate::MSG, ['agentId' => 'Unknown staff member']); $f['agent_id'] = $a; }
        return $f;
    }
    private static function setTravelersFor(int $bid, int $tripId, array $ids): void {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids) { $on = array_map('intval', array_column(Db::all('SELECT contact_id FROM trip_travelers WHERE trip_id=? AND contact_id IN (' . implode(',', $ids) . ')', [$tripId]), 'contact_id'));
            if (count($on) !== count($ids)) throw new HttpError(422, Validate::MSG, ['travelers' => 'Travelers must already be on the booking’s trip']); }
        Db::exec('DELETE FROM booking_travelers WHERE booking_id=?', [$bid]);
        foreach ($ids as $c) Db::exec('INSERT INTO booking_travelers (booking_id, contact_id) VALUES (?,?)', [$bid, $c]);
    }
    /** Agency booking controls (Settings): required fields are enforced when a booking is confirmed. */
    private static function enforceRequired(array $f, array $d, ?array $cur): void { $req = SettingsController::bookingRequired(); if (!$req || ($f['status'] ?? ($cur['status'] ?? '')) !== 'confirmed') return; $miss = [];
        if (in_array('confirmationNumber', $req, true) && trim((string)($f['confirmation_number'] ?? '')) === '') $miss['confirmationNumber'] = 'Required by agency booking controls';
        if (in_array('supplier', $req, true) && empty($f['supplier_id'])) $miss['supplierId'] = 'Required by agency booking controls';
        if (in_array('amount', $req, true) && (string)($f['amount'] ?? '') === '') $miss['amount'] = 'Required by agency booking controls';
        if (in_array('travelers', $req, true)) { $ids = isset($d['travelerIds']) && is_array($d['travelerIds']) ? $d['travelerIds'] : ($cur ? array_column(Db::all('SELECT contact_id FROM booking_travelers WHERE booking_id=?', [$cur['id']]), 'contact_id') : []); if (!$ids) $miss['travelerIds'] = 'Required by agency booking controls'; }
        if ($miss) throw new HttpError(422, 'Agency booking controls require more details before confirming', $miss); }
    public static function create(): array {
        $u = Auth::require('bookings.manage'); $d = Http::body(); $f = self::fields($u, $d, null); if (!isset($f['agent_id'])) $f['agent_id'] = (int)$u['id']; self::enforceRequired($f, $d, null);
        $id = Db::tx(function () use ($f, $d, $u) {
            $cols = array_keys($f);
            $id = Db::insert('INSERT INTO bookings (' . implode(',', $cols) . ', reference, created_by, created_at, updated_at, version) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)', array_merge(array_values($f), ['TMP-' . bin2hex(random_bytes(8)), (int)$u['id']]));
            Db::exec('UPDATE bookings SET reference=? WHERE id=?', ['B-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT), $id]);
            if (isset($d['travelerIds']) && is_array($d['travelerIds'])) self::setTravelersFor($id, $f['trip_id'], $d['travelerIds']);
            Audit::log((int)$u['id'], 'booking.created', 'booking', $id, ['trip' => $f['trip_id'], 'supplier' => $f['supplier_id']]);
            Events::emit('booking.created', 'booking', $id, (int)$u['id'], 'Booking created'); Events::activity('trip', (int)$f['trip_id'], 'booking.created', (int)$u['id'], 'Booking created');
            return $id;
        });
        return ['booking' => self::detail($u, $id)];
    }
    public static function update(array $pa): array {
        $u = Auth::require('bookings.manage'); $cur = self::find($u, (int)$pa['id']); $d = Http::body();
        if ($cur['status'] === 'cancelled') throw new HttpError(409, 'Cancelled bookings cannot be edited');
        if (isset($d['version']) && (int)$d['version'] !== (int)$cur['version']) throw new HttpError(409, 'This booking was changed by someone else. Reload and try again.');
        $f = self::fields($u, $d, $cur); self::enforceRequired($f, $d, $cur); $sets = implode(',', array_map(function ($c) { return "$c=?"; }, array_keys($f)));
        Db::tx(function () use ($sets, $f, $cur, $d, $u) {
            Db::exec("UPDATE bookings SET $sets, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?", array_merge(array_values($f), [(int)$cur['id']]));
            if (isset($d['travelerIds']) && is_array($d['travelerIds'])) self::setTravelersFor((int)$cur['id'], $f['trip_id'], $d['travelerIds']);
            elseif ($f['trip_id'] !== (int)$cur['trip_id']) Db::exec('DELETE FROM booking_travelers WHERE booking_id=?', [$cur['id']]);
            $changed = array_keys(array_filter($f, function ($v, $k) use ($cur) { return (string)$v !== (string)($cur[$k] ?? ''); }, ARRAY_FILTER_USE_BOTH));
            Audit::log((int)$u['id'], $f['status'] === 'confirmed' && $cur['status'] !== 'confirmed' ? 'booking.confirmed' : 'booking.updated', 'booking', $cur['id'], ['fields' => $changed]);
            if ($f['status'] !== $cur['status']) Events::emit('booking.status_changed', 'booking', (int)$cur['id'], (int)$u['id'], 'Booking ' . $cur['reference'] . ' status changed from ' . $cur['status'] . ' to ' . $f['status'], ['from' => $cur['status'], 'to' => $f['status']]);
        });
        return ['booking' => self::detail($u, (int)$cur['id'])];
    }
    public static function setTravelers(array $pa): array {
        $u = Auth::require('bookings.manage'); $cur = self::find($u, (int)$pa['id']); $d = Http::body();
        self::setTravelersFor((int)$cur['id'], (int)$cur['trip_id'], is_array($d['travelerIds'] ?? null) ? $d['travelerIds'] : []);
        Audit::log((int)$u['id'], 'booking.travelers_updated', 'booking', $cur['id']);
        return ['booking' => self::detail($u, (int)$cur['id'])];
    }
    public static function cancel(array $pa): array {
        $u = Auth::require('bookings.manage'); $cur = self::find($u, (int)$pa['id']); if ($cur['status'] === 'cancelled') throw new HttpError(409, 'Already cancelled');
        $d = Http::body(); Db::tx(function () use ($d, $cur, $u) { Db::exec("UPDATE bookings SET status='cancelled', cancelled_at=UTC_TIMESTAMP(), cancel_reason=?, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?", [Validate::str($d, 'reason', 255), $cur['id']]);
            CommissionController::onBookingCancelled((int)$cur['id'], (int)$u['id']);
            Events::emit('booking.status_changed', 'booking', (int)$cur['id'], (int)$u['id'], 'Booking ' . $cur['reference'] . ' cancelled', ['from' => $cur['status'], 'to' => 'cancelled']); });
        Audit::log((int)$u['id'], 'booking.cancelled', 'booking', $cur['id'], ['reason' => $d['reason'] ?? null]);
        return ['booking' => self::detail($u, (int)$cur['id'])];
    }
}
