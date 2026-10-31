<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Auth, Audit, Db, Http, HttpError, Validate};
final class TripController {
    const STATUSES = ['Inquiry', 'Planning', 'Proposal', 'Approved', 'Booked', 'Traveling', 'Traveled', 'Cancelled', 'Archived'];
    const PERMS = ['primary', 'full', 'limited'];
    /** Row scope: trips.view_all sees all trips; others see trips they own, created or collaborate on. */
    public static function scope(array $u): array {
        if (in_array('trips.view_all', $u['permissions'], true)) return ['1=1', []];
        $id = (int)$u['id'];
        return ['(t.owner_id = ? OR t.created_by = ? OR EXISTS (SELECT 1 FROM trip_collaborators tc WHERE tc.trip_id = t.id AND tc.user_id = ?))', [$id, $id, $id]];
    }
    public static function findRow(array $u, int $id): array {
        [$w, $p] = self::scope($u);
        $r = Db::one("SELECT t.*, o.full_name owner_name, CONCAT_WS(' ', c.first_name, c.last_name) contact_name FROM trips t LEFT JOIN users o ON o.id=t.owner_id LEFT JOIN contacts c ON c.id=t.primary_contact_id AND c.deleted_at IS NULL WHERE t.id=? AND t.deleted_at IS NULL AND t.trip_type='standard' AND $w", array_merge([$id], $p));
        if (!$r) throw new HttpError(404, 'Trip not found');
        return $r;
    }
    public static function out(array $r): array {
        return ['id' => (int)$r['id'], 'name' => $r['name'], 'status' => $r['status'], 'destination' => $r['destination'], 'startDate' => $r['start_date'], 'endDate' => $r['end_date'],
            'ownerId' => $r['owner_id'] === null ? null : (int)$r['owner_id'], 'ownerName' => $r['owner_name'] ?? null, 'primaryContactId' => $r['primary_contact_id'] === null ? null : (int)$r['primary_contact_id'],
            'primaryContactName' => $r['contact_name'] ?? null, 'notes' => $r['notes'], 'tags' => $r['tags_json'] ? json_decode($r['tags_json'], true) : [],
            'isPublished' => (bool)$r['is_published'], 'archivedAt' => $r['archived_at'], 'version' => (int)$r['version'], 'createdAt' => $r['created_at'], 'updatedAt' => $r['updated_at']];
    }
    private static function detail(int $id, array $u): array {
        $t = self::out(self::findRow($u, $id));
        $t['travelers'] = array_map(function ($r) { return ['contactId' => (int)$r['contact_id'], 'permission' => $r['permission'], 'firstName' => $r['first_name'], 'lastName' => $r['last_name'], 'email' => $r['email'], 'phone' => $r['phone']]; },
            Db::all('SELECT tt.contact_id, tt.permission, c.first_name, c.last_name, c.email, c.phone FROM trip_travelers tt JOIN contacts c ON c.id=tt.contact_id AND c.deleted_at IS NULL WHERE tt.trip_id=? ORDER BY FIELD(tt.permission,\'primary\',\'full\',\'limited\'), c.last_name', [$id]));
        $t['collaborators'] = array_map(function ($r) { return ['id' => (int)$r['id'], 'fullName' => $r['full_name']]; }, Db::all('SELECT u.id, u.full_name FROM trip_collaborators tc JOIN users u ON u.id=tc.user_id WHERE tc.trip_id=? ORDER BY u.full_name', [$id]));
        $t['activity'] = PortalController::thread($id);
        return $t;
    }
    public static function list(): array {
        $u = Auth::require('trips.view'); [$w, $p] = self::scope($u);
        $where = ['t.deleted_at IS NULL', "t.trip_type='standard'", $w];
        $arch = (string)($_GET['archived'] ?? '0');
        if ($arch === '0') $where[] = 't.archived_at IS NULL'; elseif ($arch === '1') $where[] = 't.archived_at IS NOT NULL';
        if (($st = (string)($_GET['status'] ?? '')) !== '') { Validate::enum(['s' => $st], 's', self::STATUSES); $where[] = 't.status=?'; $p[] = $st; }
        if (($cid = (int)($_GET['contactId'] ?? 0)) > 0) { $where[] = '(t.primary_contact_id=? OR EXISTS (SELECT 1 FROM trip_travelers x WHERE x.trip_id=t.id AND x.contact_id=?))'; array_push($p, $cid, $cid); }
        if (($q = trim((string)($_GET['q'] ?? ''))) !== '') { $like = '%' . addcslashes($q, '%_\\') . '%'; $where[] = "(t.name LIKE ? OR t.destination LIKE ? OR CONCAT_WS(' ', c.first_name, c.last_name) LIKE ?)"; array_push($p, $like, $like, $like); }
        $page = max(1, (int)($_GET['page'] ?? 1)); $per = min(200, max(1, (int)($_GET['perPage'] ?? 100)));
        $sqlW = implode(' AND ', $where);
        $from = "FROM trips t LEFT JOIN users o ON o.id=t.owner_id LEFT JOIN contacts c ON c.id=t.primary_contact_id AND c.deleted_at IS NULL WHERE $sqlW";
        $total = (int)Db::one("SELECT COUNT(*) n $from", $p)['n'];
        $rows = Db::all("SELECT t.*, o.full_name owner_name, CONCAT_WS(' ', c.first_name, c.last_name) contact_name $from ORDER BY (t.start_date IS NULL), t.start_date, t.id LIMIT $per OFFSET " . (($page - 1) * $per), $p);
        $items = array_map([self::class, 'out'], $rows);
        if ($items) {   // travelers and collaborators for all listed trips in two queries (no N+1)
            $ids = implode(',', array_map(function ($i) { return (int)$i['id']; }, $items)); $tv = []; $co = [];
            foreach (Db::all("SELECT tt.trip_id, tt.contact_id, tt.permission, c.first_name, c.last_name, c.email, c.phone FROM trip_travelers tt JOIN contacts c ON c.id=tt.contact_id AND c.deleted_at IS NULL WHERE tt.trip_id IN ($ids) ORDER BY FIELD(tt.permission,'primary','full','limited'), c.last_name") as $r)
                $tv[(int)$r['trip_id']][] = ['contactId' => (int)$r['contact_id'], 'permission' => $r['permission'], 'firstName' => $r['first_name'], 'lastName' => $r['last_name'], 'email' => $r['email'], 'phone' => $r['phone']];
            foreach (Db::all("SELECT tc.trip_id, u.id, u.full_name FROM trip_collaborators tc JOIN users u ON u.id=tc.user_id WHERE tc.trip_id IN ($ids)") as $r) $co[(int)$r['trip_id']][] = ['id' => (int)$r['id'], 'fullName' => $r['full_name']];
            foreach ($items as &$i) { $i['travelers'] = $tv[$i['id']] ?? []; $i['collaborators'] = $co[$i['id']] ?? []; }
            unset($i);
        }
        return ['items' => $items, 'total' => $total, 'page' => $page, 'perPage' => $per];
    }
    public static function get(array $pa): array { $u = Auth::require('trips.view'); return ['trip' => self::detail((int)$pa['id'], $u)]; }
    private static function fields(array $d, array $u, ?array $cur): array {
        $f = ['name' => Validate::str($d, 'name', 190, $cur === null), 'destination' => Validate::str($d, 'destination', 190), 'start_date' => Validate::date($d, 'startDate'), 'end_date' => Validate::date($d, 'endDate'),
            'status' => Validate::enum($d, 'status', self::STATUSES, $cur ? $cur['status'] : 'Inquiry'), 'notes' => Validate::str($d, 'notes', 5000)];
        if ($cur !== null && $f['name'] === null) $f['name'] = $cur['name'];
        if ($f['start_date'] && $f['end_date'] && $f['end_date'] < $f['start_date']) throw new HttpError(422, Validate::MSG, ['endDate' => 'End date is before the start date']);
        $tags = isset($d['tags']) && is_array($d['tags']) ? array_values(array_unique(array_filter(array_map(function ($x) { return mb_substr(trim((string)$x), 0, 40); }, $d['tags'])))) : ($cur && $cur['tags_json'] ? json_decode($cur['tags_json'], true) : []);
        $f['tags_json'] = json_encode(array_slice($tags, 0, 20), JSON_UNESCAPED_UNICODE);
        if (array_key_exists('ownerId', $d)) {
            $oid = $d['ownerId'] === null || $d['ownerId'] === '' ? null : (int)$d['ownerId'];
            if ($oid !== null && !Db::one("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.is_active=1 AND r.is_staff=1", [$oid])) throw new HttpError(422, Validate::MSG, ['ownerId' => 'Unknown staff member']);
            $f['owner_id'] = $oid;
        } elseif ($cur === null) $f['owner_id'] = (int)$u['id'];
        if (array_key_exists('primaryContactId', $d)) {
            $cid = $d['primaryContactId'] === null || $d['primaryContactId'] === '' ? null : (int)$d['primaryContactId'];
            if ($cid !== null) ContactController::find($u, $cid);   // must be a contact this user may see
            $f['primary_contact_id'] = $cid;
        }
        return $f;
    }
    public static function create(): array {
        $u = Auth::require('trips.edit'); $d = Http::body(); $f = self::fields($d, $u, null);
        if ($f['status'] === 'Archived') throw new HttpError(422, Validate::MSG, ['status' => 'Create the trip first, then archive it']);
        $id = Db::tx(function () use ($f, $u) {
            $cols = array_keys($f);
            $id = Db::insert('INSERT INTO trips (' . implode(',', $cols) . ', trip_type, created_by, created_at, updated_at, version) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ",'standard',?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)", array_merge(array_values($f), [(int)$u['id']]));
            if (!empty($f['primary_contact_id'])) Db::exec("INSERT INTO trip_travelers (trip_id, contact_id, permission) VALUES (?,?,'primary')", [$id, $f['primary_contact_id']]);
            $oid = Db::insert('INSERT INTO itinerary_options (trip_id, name, sort_order) VALUES (?,?,0)', [$id, 'Itinerary']);
            Db::exec('INSERT INTO itinerary_days (option_id, title, sort_order) VALUES (?,?,0)', [$oid, 'Day 1']);
            Audit::log((int)$u['id'], 'trip.created', 'trip', $id, ['name' => $f['name']]);
            return $id;
        });
        return ['trip' => self::detail($id, $u)];
    }
    public static function update(array $pa): array {
        $u = Auth::require('trips.edit'); $cur = self::findRow($u, (int)$pa['id']); $d = Http::body();
        if (isset($d['version']) && (int)$d['version'] !== (int)$cur['version']) throw new HttpError(409, 'This trip was changed by someone else. Reload and try again.');
        $f = self::fields($d, $u, $cur);
        if ($f['status'] !== $cur['status'] && in_array($f['status'], ['Archived', 'Cancelled'], true) && !Auth::can('trips.delete')) throw new HttpError(403, 'Your role cannot archive or cancel trips');
        $f['archived_at'] = $f['status'] === 'Archived' ? ($cur['archived_at'] ?: gmdate('Y-m-d H:i:s')) : null;
        $sets = implode(',', array_map(function ($c) { return "$c=?"; }, array_keys($f)));
        Db::tx(function () use ($sets, $f, $cur, $d, $u) {
            Db::exec("UPDATE trips SET $sets, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?", array_merge(array_values($f), [(int)$cur['id']]));
            if (array_key_exists('primary_contact_id', $f) && $f['primary_contact_id'] && (int)$f['primary_contact_id'] !== (int)$cur['primary_contact_id']) {
                Db::exec("UPDATE trip_travelers SET permission='full' WHERE trip_id=? AND permission='primary'", [$cur['id']]);
                Db::exec("INSERT INTO trip_travelers (trip_id, contact_id, permission) VALUES (?,?,'primary') ON DUPLICATE KEY UPDATE permission='primary'", [$cur['id'], $f['primary_contact_id']]);
            }
            if (isset($d['collaboratorIds']) && is_array($d['collaboratorIds'])) {
                Db::exec('DELETE FROM trip_collaborators WHERE trip_id=?', [$cur['id']]);
                foreach (array_unique(array_map('intval', $d['collaboratorIds'])) as $cid) if ($cid > 0 && Db::one('SELECT id FROM users WHERE id=? AND is_active=1', [$cid])) Db::exec('INSERT INTO trip_collaborators (trip_id, user_id) VALUES (?,?)', [$cur['id'], $cid]);
            }
            $changed = array_keys(array_filter($f, function ($v, $k) use ($cur) { return (string)$v !== (string)($cur[$k] ?? ''); }, ARRAY_FILTER_USE_BOTH));
            Audit::log((int)$u['id'], $f['status'] !== $cur['status'] ? 'trip.status_changed' : 'trip.updated', 'trip', $cur['id'], ['fields' => $changed, 'from' => $cur['status'], 'to' => $f['status']]);
        });
        return ['trip' => self::detail((int)$cur['id'], $u)];
    }
    public static function archive(array $pa, bool $restore = false): array {
        $u = Auth::require('trips.delete'); $cur = self::findRow($u, (int)$pa['id']);
        if ($restore) Db::exec("UPDATE trips SET status=IF(status='Archived','Traveled',status), archived_at=NULL, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?", [$cur['id']]);
        else Db::exec("UPDATE trips SET status='Archived', archived_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?", [$cur['id']]);
        Audit::log((int)$u['id'], $restore ? 'trip.restored' : 'trip.archived', 'trip', $cur['id']);
        return ['trip' => self::detail((int)$cur['id'], $u)];
    }
    public static function setTravelers(array $pa): array {
        $u = Auth::require('trips.edit'); $cur = self::findRow($u, (int)$pa['id']); $d = Http::body();
        $list = isset($d['travelers']) && is_array($d['travelers']) ? $d['travelers'] : null;
        if ($list === null) throw new HttpError(422, Validate::MSG, ['travelers' => 'Required']);
        $clean = []; $primary = null;
        foreach ($list as $t) {
            $cid = (int)($t['contactId'] ?? 0); $perm = (string)($t['permission'] ?? 'full');
            if (!in_array($perm, self::PERMS, true)) throw new HttpError(422, Validate::MSG, ['travelers' => 'Invalid permission']);
            ContactController::find($u, $cid);
            if ($perm === 'primary') { if ($primary !== null) throw new HttpError(422, Validate::MSG, ['travelers' => 'Only one primary traveler is allowed']); $primary = $cid; }
            $clean[$cid] = $perm;
        }
        Db::tx(function () use ($cur, $clean, $primary, $u) {
            Db::exec('DELETE FROM trip_travelers WHERE trip_id=?', [$cur['id']]);
            foreach ($clean as $cid => $perm) Db::exec('INSERT INTO trip_travelers (trip_id, contact_id, permission) VALUES (?,?,?)', [$cur['id'], $cid, $perm]);
            Db::exec('UPDATE trips SET primary_contact_id=?, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?', [$primary, $cur['id']]);
            Audit::log((int)$u['id'], 'trip.travelers_updated', 'trip', $cur['id'], ['count' => count($clean)]);
        });
        return ['trip' => self::detail((int)$cur['id'], $u)];
    }
}
