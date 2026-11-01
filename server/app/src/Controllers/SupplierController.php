<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Auth, Audit, Db, Http, HttpError, Validate};
final class SupplierController {
    const CATEGORIES = ['hotel', 'airline', 'cruise', 'ground_transport', 'tour_operator', 'restaurant', 'dmc', 'activity', 'insurance', 'other'];
    private static function out(array $r): array {
        return ['id' => (int)$r['id'], 'name' => $r['name'], 'category' => $r['category'], 'status' => $r['status'], 'isPreferred' => (bool)$r['is_preferred'], 'commissionRate' => $r['commission_rate'] === null ? null : (float)$r['commission_rate'],
            'email' => $r['email'], 'phone' => $r['phone'], 'website' => $r['website'], 'address' => $r['address'], 'destination' => $r['destination'], 'contractReference' => $r['contract_reference'], 'contractNotes' => $r['contract_notes'],
            'notes' => $r['notes'], 'archivedAt' => $r['archived_at'], 'version' => (int)$r['version'], 'bookingCount' => isset($r['booking_count']) ? (int)$r['booking_count'] : null];
    }
    public static function find(int $id): array { $r = Db::one('SELECT * FROM suppliers WHERE id=?', [$id]); if (!$r) throw new HttpError(404, 'Supplier not found'); return $r; }
    public static function list(): array {
        Auth::require('trips.view'); $w = []; $p = [];
        $st = (string)($_GET['status'] ?? 'active'); if ($st !== 'all') { Validate::enum(['s' => $st], 's', ['active', 'archived']); $w[] = 's.status=?'; $p[] = $st; }
        if (($c = (string)($_GET['category'] ?? '')) !== '') { Validate::enum(['c' => $c], 'c', self::CATEGORIES); $w[] = 's.category=?'; $p[] = $c; }
        if (!empty($_GET['preferred'])) $w[] = 's.is_preferred=1';
        if (($q = trim((string)($_GET['q'] ?? ''))) !== '') { $like = '%' . addcslashes($q, '%_\\') . '%'; $w[] = '(s.name LIKE ? OR s.destination LIKE ? OR s.email LIKE ? OR s.contract_reference LIKE ?)'; array_push($p, $like, $like, $like, $like); }
        $sqlW = $w ? 'WHERE ' . implode(' AND ', $w) : ''; $page = max(1, (int)($_GET['page'] ?? 1)); $per = min(200, max(1, (int)($_GET['perPage'] ?? 100)));
        $total = (int)Db::one("SELECT COUNT(*) n FROM suppliers s $sqlW", $p)['n'];
        $rows = Db::all("SELECT s.*, (SELECT COUNT(*) FROM bookings b WHERE b.supplier_id=s.id) booking_count FROM suppliers s $sqlW ORDER BY s.name LIMIT $per OFFSET " . (($page - 1) * $per), $p);
        return ['items' => array_map([self::class, 'out'], $rows), 'total' => $total, 'page' => $page, 'perPage' => $per];
    }
    public static function get(array $pa): array {
        Auth::require('trips.view'); $s = self::out(self::find((int)$pa['id']) + ['booking_count' => 0]);
        $s['contacts'] = array_map(function ($r) { return ['id' => (int)$r['id'], 'name' => $r['name'], 'role' => $r['role'], 'email' => $r['email'], 'phone' => $r['phone'], 'isPrimary' => (bool)$r['is_primary']]; }, Db::all('SELECT * FROM supplier_contacts WHERE supplier_id=? ORDER BY is_primary DESC, name', [$s['id']]));
        $s['bookings'] = array_map(function ($r) { return ['id' => (int)$r['id'], 'reference' => $r['reference'], 'tripName' => $r['trip_name'], 'status' => $r['status'], 'startDate' => $r['travel_date'], 'amount' => (float)$r['amount']]; },
            Db::all('SELECT b.id, b.reference, b.status, b.travel_date, b.amount, t.name trip_name FROM bookings b LEFT JOIN trips t ON t.id=b.trip_id WHERE b.supplier_id=? ORDER BY b.travel_date DESC LIMIT 50', [$s['id']]));
        $s['bookingCount'] = count($s['bookings']);
        return ['supplier' => $s];
    }
    private static function fields(array $d, ?array $cur): array {
        if ($cur) $d = array_merge(['name' => $cur['name'], 'category' => $cur['category'], 'email' => $cur['email'], 'phone' => $cur['phone'], 'website' => $cur['website'], 'address' => $cur['address'], 'destination' => $cur['destination'],
            'contractReference' => $cur['contract_reference'], 'contractNotes' => $cur['contract_notes'], 'notes' => $cur['notes'], 'isPreferred' => (bool)$cur['is_preferred'], 'commissionRate' => $cur['commission_rate']], $d);
        $f = ['name' => Validate::str($d, 'name', 190, $cur === null), 'category' => Validate::enum($d, 'category', self::CATEGORIES, $cur ? $cur['category'] : null), 'email' => Validate::email($d, 'email'), 'phone' => Validate::str($d, 'phone', 40),
            'website' => Validate::str($d, 'website', 255), 'address' => Validate::str($d, 'address', 255), 'destination' => Validate::str($d, 'destination', 190), 'contract_reference' => Validate::str($d, 'contractReference', 100),
            'contract_notes' => Validate::str($d, 'contractNotes', 5000), 'notes' => Validate::str($d, 'notes', 5000), 'is_preferred' => !empty($d['isPreferred']) ? 1 : 0];
        if ($cur !== null && $f['name'] === null) $f['name'] = $cur['name'];
        if ($f['category'] === null) throw new HttpError(422, Validate::MSG, ['category' => 'Required']);
        if (isset($d['commissionRate']) && $d['commissionRate'] !== '' && $d['commissionRate'] !== null) { if (!is_numeric($d['commissionRate']) || $d['commissionRate'] < 0 || $d['commissionRate'] > 100) throw new HttpError(422, Validate::MSG, ['commissionRate' => '0 to 100']); $f['commission_rate'] = round((float)$d['commissionRate'], 2); } else $f['commission_rate'] = null;
        if ($f['website'] && !preg_match('#^https?://#i', $f['website'])) throw new HttpError(422, Validate::MSG, ['website' => 'Start with http:// or https://']);
        $dup = Db::one('SELECT id FROM suppliers WHERE name=? AND id<>?', [$f['name'], $cur ? (int)$cur['id'] : 0]); if ($dup) throw new HttpError(409, 'A supplier with this name already exists', ['name' => 'Duplicate name']);
        return $f;
    }
    public static function create(): array {
        $u = Auth::require('suppliers.manage'); $f = self::fields(Http::body(), null); $cols = array_keys($f);
        $id = Db::insert('INSERT INTO suppliers (' . implode(',', $cols) . ', status, created_by, created_at, updated_at, version) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ",'active',?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)", array_merge(array_values($f), [(int)$u['id']]));
        Audit::log((int)$u['id'], 'supplier.created', 'supplier', $id, ['name' => $f['name']]);
        return self::get(['id' => $id]);
    }
    public static function update(array $pa): array {
        $u = Auth::require('suppliers.manage'); $cur = self::find((int)$pa['id']); $d = Http::body();
        if (isset($d['version']) && (int)$d['version'] !== (int)$cur['version']) throw new HttpError(409, 'This supplier was changed by someone else. Reload and try again.');
        $f = self::fields($d, $cur); $sets = implode(',', array_map(function ($c) { return "$c=?"; }, array_keys($f)));
        Db::exec("UPDATE suppliers SET $sets, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?", array_merge(array_values($f), [(int)$cur['id']]));
        Audit::log((int)$u['id'], 'supplier.updated', 'supplier', $cur['id']);
        return self::get(['id' => (int)$cur['id']]);
    }
    public static function archive(array $pa, bool $restore = false): array {
        $u = Auth::require('suppliers.manage'); $cur = self::find((int)$pa['id']);
        Db::exec($restore ? "UPDATE suppliers SET status='active', archived_at=NULL, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?" : "UPDATE suppliers SET status='archived', archived_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?", [$cur['id']]);
        Audit::log((int)$u['id'], $restore ? 'supplier.restored' : 'supplier.archived', 'supplier', $cur['id']);   // bookings keep their supplier link
        return self::get(['id' => (int)$cur['id']]);
    }
    public static function setContacts(array $pa): array {
        $u = Auth::require('suppliers.manage'); $s = self::find((int)$pa['id']); $d = Http::body(); $list = is_array($d['contacts'] ?? null) ? $d['contacts'] : [];
        Db::tx(function () use ($s, $list, $u) {
            Db::exec('DELETE FROM supplier_contacts WHERE supplier_id=?', [$s['id']]);
            foreach ($list as $c) { $name = Validate::str($c, 'name', 160, true);
                Db::exec('INSERT INTO supplier_contacts (supplier_id,name,role,email,phone,is_primary,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())', [$s['id'], $name, Validate::str($c, 'role', 100), Validate::email($c, 'email'), Validate::str($c, 'phone', 40), !empty($c['isPrimary']) ? 1 : 0]); }
            Audit::log((int)$u['id'], 'supplier.contacts_updated', 'supplier', $s['id']);
        });
        return self::get(['id' => (int)$s['id']]);
    }
}
