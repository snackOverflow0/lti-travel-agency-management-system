<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Auth, Audit, Db, Http, HttpError, Validate};
/** Group trips are trips rows with trip_type='group'. Each registration owns a sub-trip (trip_type='sub', parent_trip_id = group). */
final class GroupController {
    const STATUSES = ['Planning', 'Open', 'Closed', 'Booked', 'Traveling', 'Traveled', 'Cancelled', 'Archived'];
    public static function find(array $u, int $id, bool $lock = false): array {
        [$w, $p] = TripController::scope($u);
        $r = Db::one("SELECT t.*, o.full_name owner_name, co.full_name coordinator_name FROM trips t LEFT JOIN users o ON o.id=t.owner_id LEFT JOIN users co ON co.id=t.coordinator_id WHERE t.id=? AND t.trip_type='group' AND t.deleted_at IS NULL AND $w" . ($lock ? ' FOR UPDATE' : ''), array_merge([$id], $p));
        if (!$r) throw new HttpError(404, 'Group trip not found');
        return $r;
    }
    private static function activeCount(int $gid): int { return (int)Db::one("SELECT COUNT(*) n FROM group_registration_travelers grt JOIN group_registrations gr ON gr.id=grt.registration_id WHERE gr.group_trip_id=? AND gr.status='active'", [$gid])['n']; }
    private static function out(array $r): array {
        return ['id' => (int)$r['id'], 'name' => $r['name'], 'status' => $r['status'], 'destination' => $r['destination'], 'startDate' => $r['start_date'], 'endDate' => $r['end_date'],
            'ownerId' => $r['owner_id'] === null ? null : (int)$r['owner_id'], 'ownerName' => $r['owner_name'] ?? null, 'coordinatorId' => $r['coordinator_id'] === null ? null : (int)$r['coordinator_id'], 'coordinatorName' => $r['coordinator_name'] ?? null,
            'capacity' => $r['group_capacity'] === null ? null : (int)$r['group_capacity'], 'registrationDeadline' => $r['registration_deadline'], 'details' => $r['group_details_json'] ? json_decode($r['group_details_json'], true) : (object)[],
            'isPublished' => (bool)$r['is_published'], 'archivedAt' => $r['archived_at'], 'version' => (int)$r['version'], 'registered' => self::activeCount((int)$r['id'])];
    }
    private static function detail(array $u, int $id): array {
        $g = self::out(self::find($u, $id));
        $g['rooms'] = array_map(function ($r) use ($id) { return ['id' => (int)$r['id'], 'name' => $r['name'], 'pricePerPerson' => (float)$r['price_per_person'], 'capacity' => (int)$r['capacity'],
            'taken' => (int)Db::one("SELECT COUNT(*) n FROM group_registration_travelers grt JOIN group_registrations gr ON gr.id=grt.registration_id WHERE gr.room_id=? AND gr.status='active'", [$r['id']])['n']]; }, Db::all('SELECT * FROM group_rooms WHERE trip_id=? ORDER BY id', [$id]));
        $regs = Db::all('SELECT gr.*, s.name sub_name, s.status sub_status, s.start_date sub_start, s.end_date sub_end FROM group_registrations gr JOIN trips s ON s.id=gr.sub_trip_id WHERE gr.group_trip_id=? ORDER BY gr.id', [$id]);
        $g['registrations'] = array_map(function ($r) { return ['id' => (int)$r['id'], 'status' => $r['status'], 'subTripId' => (int)$r['sub_trip_id'], 'subTripName' => $r['sub_name'], 'subTripStatus' => $r['sub_status'], 'subTripStart' => $r['sub_start'], 'subTripEnd' => $r['sub_end'],
            'roomId' => $r['room_id'] === null ? null : (int)$r['room_id'], 'roomPreference' => $r['room_preference'], 'specialRequests' => $r['special_requests'], 'registeredAt' => $r['created_at'], 'cancelledAt' => $r['cancelled_at'], 'overCapacity' => $r['over_capacity_by'] !== null,
            'primaryContactId' => $r['contact_id'] === null ? null : (int)$r['contact_id'],
            'travelers' => array_map(function ($t) { return ['contactId' => (int)$t['contact_id'], 'firstName' => $t['first_name'], 'lastName' => $t['last_name'], 'email' => $t['email']]; }, Db::all('SELECT grt.contact_id, c.first_name, c.last_name, c.email FROM group_registration_travelers grt JOIN contacts c ON c.id=grt.contact_id WHERE grt.registration_id=?', [$r['id']]))]; }, $regs);
        $g['subTrips'] = array_map(function ($r) { return ['id' => (int)$r['id'], 'name' => $r['name'], 'status' => $r['status'], 'startDate' => $r['start_date'], 'endDate' => $r['end_date'], 'custom' => (int)$r['custom_acts']]; },
            Db::all('SELECT s.id, s.name, s.status, s.start_date, s.end_date, (SELECT COUNT(*) FROM itinerary_activities a JOIN itinerary_days d ON d.id=a.day_id JOIN itinerary_options o ON o.id=d.option_id WHERE o.trip_id=s.id AND (a.origin_activity_id IS NULL OR a.is_customized=1)) custom_acts FROM trips s WHERE s.parent_trip_id=? AND s.trip_type=\'sub\' AND s.deleted_at IS NULL ORDER BY s.id', [$id]));
        return $g;
    }
    public static function list(): array {
        $u = Auth::require('trips.view'); [$w, $p] = TripController::scope($u);
        $where = ["t.trip_type='group'", 't.deleted_at IS NULL', $w];
        $arch = (string)($_GET['archived'] ?? '0'); if ($arch === '0') $where[] = 't.archived_at IS NULL'; elseif ($arch === '1') $where[] = 't.archived_at IS NOT NULL';
        if (($q = trim((string)($_GET['q'] ?? ''))) !== '') { $like = '%' . addcslashes($q, '%_\\') . '%'; $where[] = '(t.name LIKE ? OR t.destination LIKE ?)'; array_push($p, $like, $like); }
        if (($st = (string)($_GET['status'] ?? '')) !== '') { Validate::enum(['s' => $st], 's', self::STATUSES); $where[] = 't.status=?'; $p[] = $st; }
        $rows = Db::all('SELECT t.*, o.full_name owner_name, co.full_name coordinator_name FROM trips t LEFT JOIN users o ON o.id=t.owner_id LEFT JOIN users co ON co.id=t.coordinator_id WHERE ' . implode(' AND ', $where) . ' ORDER BY (t.start_date IS NULL), t.start_date, t.id LIMIT 200', $p);
        return ['items' => array_map([self::class, 'out'], $rows)];
    }
    public static function get(array $pa): array { $u = Auth::require('trips.view'); return ['group' => self::detail($u, (int)$pa['id'])]; }
    private static function fields(array $d, ?array $cur): array {
        if ($cur) $d = array_merge(['name' => $cur['name'], 'destination' => $cur['destination'], 'startDate' => $cur['start_date'], 'endDate' => $cur['end_date'], 'status' => $cur['status'], 'registrationDeadline' => $cur['registration_deadline']], $d);
        $f = ['name' => Validate::str($d, 'name', 190, $cur === null), 'destination' => Validate::str($d, 'destination', 190), 'start_date' => Validate::date($d, 'startDate'), 'end_date' => Validate::date($d, 'endDate'),
            'status' => Validate::enum($d, 'status', self::STATUSES, $cur ? $cur['status'] : 'Planning'), 'registration_deadline' => Validate::date($d, 'registrationDeadline')];
        if ($cur !== null && $f['name'] === null) $f['name'] = $cur['name'];
        if ($f['start_date'] && $f['end_date'] && $f['end_date'] < $f['start_date']) throw new HttpError(422, Validate::MSG, ['endDate' => 'End date is before the start date']);
        if ($f['registration_deadline'] && $f['start_date'] && $f['registration_deadline'] > $f['start_date']) throw new HttpError(422, Validate::MSG, ['registrationDeadline' => 'Deadline must be before departure']);
        if (array_key_exists('capacity', $d)) { $c = $d['capacity'] === null || $d['capacity'] === '' ? null : (int)$d['capacity']; if ($c !== null && ($c < 1 || $c > 5000)) throw new HttpError(422, Validate::MSG, ['capacity' => 'Between 1 and 5000']); $f['group_capacity'] = $c; }
        foreach (['ownerId' => 'owner_id', 'coordinatorId' => 'coordinator_id'] as $k => $col) if (array_key_exists($k, $d)) {
            $id = $d[$k] === null || $d[$k] === '' ? null : (int)$d[$k];
            if ($id !== null && !Db::one('SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.is_active=1 AND r.is_staff=1', [$id])) throw new HttpError(422, Validate::MSG, [$k => 'Unknown staff member']);
            $f[$col] = $id;
        }
        if (isset($d['details']) && is_array($d['details'])) $f['group_details_json'] = json_encode(['headline' => mb_substr((string)($d['details']['headline'] ?? ''), 0, 190), 'description' => mb_substr((string)($d['details']['description'] ?? ''), 0, 5000), 'depositPerPerson' => is_numeric($d['details']['depositPerPerson'] ?? null) ? round((float)$d['details']['depositPerPerson'], 2) : null], JSON_UNESCAPED_UNICODE);
        return $f;
    }
    public static function create(): array {
        $u = Auth::require('groups.manage'); $d = Http::body(); $f = self::fields($d, null);
        if (!array_key_exists('owner_id', $f)) $f['owner_id'] = (int)$u['id'];
        $id = Db::tx(function () use ($f, $u) {
            $cols = array_keys($f);
            $id = Db::insert('INSERT INTO trips (' . implode(',', $cols) . ", trip_type, created_by, created_at, updated_at, version, tags_json) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ",'group',?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1,'[\"Group\"]')", array_merge(array_values($f), [(int)$u['id']]));
            $oid = Db::insert('INSERT INTO itinerary_options (trip_id, name, sort_order) VALUES (?,?,0)', [$id, 'Group itinerary']);
            Db::exec('INSERT INTO itinerary_days (option_id, title, sort_order) VALUES (?,?,0)', [$oid, 'Day 1']);
            Audit::log((int)$u['id'], 'group.created', 'trip', $id, ['name' => $f['name']]);
            return $id;
        });
        return ['group' => self::detail($u, $id)];
    }
    public static function update(array $pa): array {
        $u = Auth::require('groups.manage'); $cur = self::find($u, (int)$pa['id']); $d = Http::body();
        if (isset($d['version']) && (int)$d['version'] !== (int)$cur['version']) throw new HttpError(409, 'This group was changed by someone else. Reload and try again.');
        $f = self::fields($d, $cur);
        if (array_key_exists('group_capacity', $f) && $f['group_capacity'] !== null && $f['group_capacity'] < self::activeCount((int)$cur['id']) && !Auth::can('groups.override_capacity')) throw new HttpError(409, 'Capacity cannot be lower than the number of registered travelers');
        $f['archived_at'] = $f['status'] === 'Archived' ? ($cur['archived_at'] ?: gmdate('Y-m-d H:i:s')) : null;
        $sets = implode(',', array_map(function ($c) { return "$c=?"; }, array_keys($f)));
        Db::exec("UPDATE trips SET $sets, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?", array_merge(array_values($f), [(int)$cur['id']]));
        Audit::log((int)$u['id'], 'group.updated', 'trip', $cur['id'], ['fields' => array_keys($f)]);
        return ['group' => self::detail($u, (int)$cur['id'])];
    }
    public static function archive(array $pa, bool $restore = false): array {
        $u = Auth::require('groups.manage'); $cur = self::find($u, (int)$pa['id']);
        Db::exec($restore ? "UPDATE trips SET status='Planning', archived_at=NULL, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?" : "UPDATE trips SET status='Archived', archived_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?", [$cur['id']]);
        Audit::log((int)$u['id'], $restore ? 'group.restored' : 'group.archived', 'trip', $cur['id']);
        return ['group' => self::detail($u, (int)$cur['id'])];
    }
    public static function setRooms(array $pa): array {
        $u = Auth::require('groups.manage'); $g = self::find($u, (int)$pa['id']); $d = Http::body(); $list = is_array($d['rooms'] ?? null) ? $d['rooms'] : null;
        if ($list === null) throw new HttpError(422, Validate::MSG, ['rooms' => 'Required']);
        Db::tx(function () use ($g, $list, $u) {
            $own = array_map('intval', array_column(Db::all('SELECT id FROM group_rooms WHERE trip_id=?', [$g['id']]), 'id')); $keep = [];
            foreach ($list as $r) {
                $name = mb_substr(trim((string)($r['name'] ?? '')), 0, 120); if ($name === '') throw new HttpError(422, Validate::MSG, ['rooms' => 'Every room needs a name']);
                $price = is_numeric($r['pricePerPerson'] ?? null) ? round((float)$r['pricePerPerson'], 2) : 0; $cap = max(1, min(1000, (int)($r['capacity'] ?? 1)));
                $id = (int)($r['id'] ?? 0);
                if ($id && in_array($id, $own, true)) { Db::exec('UPDATE group_rooms SET name=?, price_per_person=?, capacity=? WHERE id=?', [$name, $price, $cap, $id]); $keep[] = $id; }
                else $keep[] = Db::insert('INSERT INTO group_rooms (trip_id, name, price_per_person, capacity) VALUES (?,?,?,?)', [$g['id'], $name, $price, $cap]);
            }
            foreach (array_diff($own, $keep) as $gone) {
                if (Db::one("SELECT id FROM group_registrations WHERE room_id=? AND status='active' LIMIT 1", [$gone])) throw new HttpError(409, 'A room category with active registrations cannot be removed');
                Db::exec('DELETE FROM group_rooms WHERE id=?', [$gone]);
            }
            Audit::log((int)$u['id'], 'group.rooms_updated', 'trip', $g['id']);
        });
        return ['group' => self::detail($u, (int)$g['id'])];
    }
    /** Resolve travelers: existing contacts (must be visible) or new contacts (needs contacts.create). */
    private static function resolveTravelers(array $u, array $list): array {
        $ids = [];
        foreach ($list as $t) {
            if (!empty($t['contactId'])) { $c = ContactController::find($u, (int)$t['contactId']); $ids[] = (int)$c['id']; continue; }
            $first = Validate::str($t, 'firstName', 100, true); $email = Validate::email($t, 'email');
            if (!Auth::can('contacts.create')) throw new HttpError(403, 'Your role cannot create contacts');
            if ($email && ($ex = Db::one('SELECT id FROM contacts WHERE email=? AND deleted_at IS NULL', [$email]))) { ContactController::find($u, (int)$ex['id']); $ids[] = (int)$ex['id']; continue; }
            $ids[] = Db::insert("INSERT INTO contacts (first_name,last_name,email,phone,contact_type,source,assigned_to,created_by,created_at,updated_at) VALUES (?,?,?,?,'traveler','Group registration',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                [$first, Validate::str($t, 'lastName', 100), $email, Validate::str($t, 'phone', 40), (int)$u['id'], (int)$u['id']]);
            Audit::log((int)$u['id'], 'contact.created', 'contact', end($ids), ['via' => 'group registration']);
        }
        if (count($ids) !== count(array_unique($ids))) throw new HttpError(422, 'The same traveler is listed twice');
        if (!$ids) throw new HttpError(422, Validate::MSG, ['travelers' => 'Add at least one traveler']);
        return $ids;
    }
    private static function checkRules(array $g, array $ids, ?int $roomId, bool $override, int $exceptReg = 0): ?int {
        $dups = Db::all("SELECT c.first_name, c.last_name FROM group_registration_travelers grt JOIN group_registrations gr ON gr.id=grt.registration_id JOIN contacts c ON c.id=grt.contact_id WHERE gr.group_trip_id=? AND gr.status='active' AND gr.id<>? AND grt.contact_id IN (" . implode(',', array_map('intval', $ids)) . ')', [$g['id'], $exceptReg]);
        if ($dups) throw new HttpError(409, 'Already registered for this group: ' . implode(', ', array_map(function ($r) { return trim($r['first_name'] . ' ' . $r['last_name']); }, $dups)));
        if (in_array($g['status'], ['Cancelled', 'Archived', 'Traveled'], true)) throw new HttpError(409, 'This group is not accepting registrations');
        $canOverride = $override && Auth::can('groups.override_capacity'); $over = false;
        if ($g['registration_deadline'] && $g['registration_deadline'] < gmdate('Y-m-d')) { if (!$canOverride) throw new HttpError(409, 'The registration deadline has passed. An administrator can authorize a late registration.'); $over = true; }
        $used = (int)Db::one("SELECT COUNT(*) n FROM group_registration_travelers grt JOIN group_registrations gr ON gr.id=grt.registration_id WHERE gr.group_trip_id=? AND gr.status='active' AND gr.id<>?", [$g['id'], $exceptReg])['n'];
        if ($g['group_capacity'] !== null && $used + count($ids) > (int)$g['group_capacity']) {
            if (!$canOverride) throw new HttpError(409, sprintf('Group capacity reached (%d of %d places taken). An administrator can authorize registration beyond capacity.', $used, (int)$g['group_capacity']));
            $over = true;
        }
        if ($roomId) {
            $room = Db::one('SELECT id, capacity FROM group_rooms WHERE id=? AND trip_id=?', [$roomId, $g['id']]); if (!$room) throw new HttpError(422, Validate::MSG, ['roomId' => 'Unknown room category']);
            $taken = (int)Db::one("SELECT COUNT(*) n FROM group_registration_travelers grt JOIN group_registrations gr ON gr.id=grt.registration_id WHERE gr.room_id=? AND gr.status='active' AND gr.id<>?", [$roomId, $exceptReg])['n'];
            if ($taken + count($ids) > (int)$room['capacity']) { if (!$canOverride) throw new HttpError(409, 'Not enough space left in that room category'); $over = true; }
        }
        return $over ? (int)Auth::user()['id'] : null;
    }
    public static function register(array $pa): array {
        $u = Auth::require('groups.manage'); $d = Http::body();
        $result = Db::tx(function () use ($u, $pa, $d) {
            $g = self::find($u, (int)$pa['id'], true);   // row lock serialises concurrent registrations
            $ids = self::resolveTravelers($u, is_array($d['travelers'] ?? null) ? $d['travelers'] : []);
            $roomId = empty($d['roomId']) ? null : (int)$d['roomId'];
            $overBy = self::checkRules($g, $ids, $roomId, !empty($d['overrideCapacity']));
            $primary = $ids[0]; $pc = Db::one('SELECT first_name, last_name FROM contacts WHERE id=?', [$primary]);
            $sub = self::createSub($g, $u, $g['name'] . ' — ' . trim(($pc['last_name'] ?: $pc['first_name'])), null, null, $ids);
            $rid = Db::insert("INSERT INTO group_registrations (group_trip_id, sub_trip_id, room_id, contact_id, status, room_preference, special_requests, over_capacity_by, created_by, created_at) VALUES (?,?,?,?, 'active', ?,?,?,?,UTC_TIMESTAMP())",
                [$g['id'], $sub, $roomId, $primary, Validate::str($d, 'roomPreference', 190), Validate::str($d, 'specialRequests', 2000), $overBy, (int)$u['id']]);
            foreach ($ids as $cid) Db::exec('INSERT INTO group_registration_travelers (registration_id, contact_id) VALUES (?,?)', [$rid, $cid]);
            Audit::log((int)$u['id'], 'group.registration_created', 'trip', $g['id'], ['registration' => $rid, 'travelers' => count($ids), 'overCapacity' => $overBy !== null]);
            return ['registrationId' => $rid, 'subTripId' => $sub, 'groupId' => (int)$g['id']];
        });
        return array_merge($result, ['group' => self::detail($u, $result['groupId'])]);
    }
    public static function updateRegistration(array $pa): array {
        $u = Auth::require('groups.manage'); $d = Http::body();
        Db::tx(function () use ($u, $pa, $d) {
            $g = self::find($u, (int)$pa['id'], true);
            $r = Db::one("SELECT * FROM group_registrations WHERE id=? AND group_trip_id=? FOR UPDATE", [(int)$pa['rid'], $g['id']]); if (!$r) throw new HttpError(404, 'Registration not found');
            if ($r['status'] !== 'active') throw new HttpError(409, 'Cancelled registrations cannot be changed');
            $ids = isset($d['travelers']) ? self::resolveTravelers($u, $d['travelers']) : array_map('intval', array_column(Db::all('SELECT contact_id FROM group_registration_travelers WHERE registration_id=?', [$r['id']]), 'contact_id'));
            $roomId = array_key_exists('roomId', $d) ? (empty($d['roomId']) ? null : (int)$d['roomId']) : ($r['room_id'] === null ? null : (int)$r['room_id']);
            $overBy = self::checkRules($g, $ids, $roomId, !empty($d['overrideCapacity']), (int)$r['id']);
            Db::exec('UPDATE group_registrations SET room_id=?, contact_id=?, room_preference=?, special_requests=?, over_capacity_by=COALESCE(?, over_capacity_by) WHERE id=?',
                [$roomId, $ids[0], array_key_exists('roomPreference', $d) ? Validate::str($d, 'roomPreference', 190) : $r['room_preference'], array_key_exists('specialRequests', $d) ? Validate::str($d, 'specialRequests', 2000) : $r['special_requests'], $overBy, $r['id']]);
            Db::exec('DELETE FROM group_registration_travelers WHERE registration_id=?', [$r['id']]);
            foreach ($ids as $cid) Db::exec('INSERT INTO group_registration_travelers (registration_id, contact_id) VALUES (?,?)', [$r['id'], $cid]);
            Db::exec('DELETE FROM trip_travelers WHERE trip_id=?', [$r['sub_trip_id']]);
            foreach ($ids as $i => $cid) Db::exec('INSERT INTO trip_travelers (trip_id, contact_id, permission) VALUES (?,?,?)', [$r['sub_trip_id'], $cid, $i === 0 ? 'primary' : 'full']);
            Db::exec('UPDATE trips SET primary_contact_id=?, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?', [$ids[0], $r['sub_trip_id']]);
            Audit::log((int)$u['id'], 'group.registration_updated', 'trip', $g['id'], ['registration' => (int)$r['id']]);
        });
        return ['group' => self::detail($u, (int)$pa['id'])];
    }
    public static function cancelRegistration(array $pa): array {
        $u = Auth::require('groups.manage'); $g = self::find($u, (int)$pa['id']); $d = Http::body();
        $r = Db::one('SELECT * FROM group_registrations WHERE id=? AND group_trip_id=?', [(int)$pa['rid'], $g['id']]); if (!$r) throw new HttpError(404, 'Registration not found');
        if ($r['status'] === 'cancelled') throw new HttpError(409, 'Already cancelled');
        Db::tx(function () use ($r, $d, $u, $g) {
            Db::exec("UPDATE group_registrations SET status='cancelled', cancelled_at=UTC_TIMESTAMP(), cancel_reason=? WHERE id=?", [Validate::str($d, 'reason', 255), $r['id']]);
            Db::exec("UPDATE trips SET status='Cancelled', updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?", [$r['sub_trip_id']]);
            Audit::log((int)$u['id'], 'group.registration_cancelled', 'trip', $g['id'], ['registration' => (int)$r['id']]);
        });
        return ['group' => self::detail($u, (int)$g['id'])];
    }
    private static function createSub(array $g, array $u, string $name, ?string $sd, ?string $ed, array $contactIds): int {
        $sub = Db::insert("INSERT INTO trips (name,status,trip_type,parent_trip_id,destination,start_date,end_date,owner_id,coordinator_id,created_by,primary_contact_id,tags_json,is_published,created_at,updated_at,version) VALUES (?, 'Booked', 'sub', ?,?,?,?,?,?,?,?,'[\"Group\"]',0,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)",
            [mb_substr($name, 0, 190), $g['id'], $g['destination'], $sd ?: $g['start_date'], $ed ?: $g['end_date'], $g['owner_id'], $g['coordinator_id'], (int)$u['id'], $contactIds[0] ?? null]);
        foreach ($contactIds as $i => $cid) Db::exec('INSERT INTO trip_travelers (trip_id, contact_id, permission) VALUES (?,?,?)', [$sub, $cid, $i === 0 ? 'primary' : 'full']);
        Db::insert('INSERT INTO itinerary_options (trip_id, name, sort_order) VALUES (?,?,0)', [$sub, 'Itinerary']);
        self::sync((int)$g['id'], $sub, true);
        if ((int)$g['is_published'] === 1) ItineraryController::publishInternal($sub, (int)$u['id'], 'Published with group');
        return $sub;
    }
    public static function createSubTrip(array $pa): array {
        $u = Auth::require('groups.manage'); $d = Http::body();
        $sub = Db::tx(function () use ($u, $pa, $d) {
            $g = self::find($u, (int)$pa['id'], true);
            $sd = Validate::date($d, 'startDate'); $ed = Validate::date($d, 'endDate'); if ($sd && $ed && $ed < $sd) throw new HttpError(422, Validate::MSG, ['endDate' => 'End date is before the start date']);
            $ids = []; foreach (is_array($d['contactIds'] ?? null) ? $d['contactIds'] : [] as $cid) { $c = ContactController::find($u, (int)$cid); $ids[] = (int)$c['id']; }
            $sub = self::createSub($g, $u, Validate::str($d, 'name', 190, true), $sd, $ed, array_values(array_unique($ids)));
            Audit::log((int)$u['id'], 'group.subtrip_created', 'trip', $g['id'], ['subTrip' => $sub]);
            return $sub;
        });
        return ['subTripId' => $sub, 'group' => self::detail($u, (int)$pa['id'])];
    }
    const SYNC_COLS = ['activity_type', 'title', 'description', 'start_time', 'location', 'price', 'confirmation_number', 'internal_note', 'client_note', 'details_json'];
    /** Push the group's first itinerary option into a sub-trip. Group-origin activities are added/updated/removed;
     *  activities created or edited inside the sub-trip (origin_activity_id NULL) are never touched. */
    public static function sync(int $gid, int $sid, bool $apply): array {
        $plan = ['daysAdded' => 0, 'daysRemoved' => 0, 'added' => 0, 'updated' => 0, 'removed' => 0, 'customKept' => 0];
        $gOpt = Db::one('SELECT id FROM itinerary_options WHERE trip_id=? ORDER BY sort_order, id LIMIT 1', [$gid]); if (!$gOpt) return $plan;
        $sOpt = Db::one('SELECT id FROM itinerary_options WHERE trip_id=? ORDER BY sort_order, id LIMIT 1', [$sid]);
        if (!$sOpt) { if (!$apply) return $plan; $sOpt = ['id' => Db::insert('INSERT INTO itinerary_options (trip_id,name,sort_order) VALUES (?,?,0)', [$sid, 'Itinerary'])]; }
        $gDays = Db::all('SELECT id, title FROM itinerary_days WHERE option_id=? ORDER BY sort_order, id', [$gOpt['id']]);
        $sDays = Db::all('SELECT id, title, origin_day_id FROM itinerary_days WHERE option_id=? ORDER BY sort_order, id', [$sOpt['id']]);
        $gActs = []; foreach ($gDays as $gd) foreach (Db::all('SELECT * FROM itinerary_activities WHERE day_id=? ORDER BY sort_order, id', [$gd['id']]) as $a) $gActs[(int)$a['id']] = $a + ['_gday' => (int)$gd['id']];
        $sActs = Db::all('SELECT a.* FROM itinerary_activities a JOIN itinerary_days d ON d.id=a.day_id WHERE d.option_id=?', [$sOpt['id']]);
        $byOrigin = []; foreach ($sActs as $a) { if ($a['origin_activity_id'] === null || (int)$a['is_customized'] === 1) $plan['customKept']++; if ($a['origin_activity_id'] !== null) $byOrigin[(int)$a['origin_activity_id']] = $a; }
        $dayMap = []; foreach ($sDays as $sd) if ($sd['origin_day_id'] !== null) $dayMap[(int)$sd['origin_day_id']] = (int)$sd['id'];
        foreach ($gDays as $i => $gd) {
            if (!isset($dayMap[(int)$gd['id']])) { $plan['daysAdded']++; if ($apply) $dayMap[(int)$gd['id']] = Db::insert('INSERT INTO itinerary_days (option_id,title,sort_order,origin_day_id) VALUES (?,?,?,?)', [$sOpt['id'], $gd['title'], $i, $gd['id']]); }
            elseif ($apply) Db::exec('UPDATE itinerary_days SET title=?, sort_order=? WHERE id=?', [$gd['title'], $i, $dayMap[(int)$gd['id']]]);
        }
        foreach ($gActs as $gaid => $ga) {
            $target = $dayMap[$ga['_gday']] ?? 0; $vals = array_map(function ($c) use ($ga) { return $ga[$c]; }, self::SYNC_COLS);
            if (!isset($byOrigin[$gaid])) { $plan['added']++; if ($apply && $target) Db::exec('INSERT INTO itinerary_activities (day_id,' . implode(',', self::SYNC_COLS) . ',sort_order,origin_activity_id) VALUES (?,' . implode(',', array_fill(0, count(self::SYNC_COLS), '?')) . ',?,?)', array_merge([$target], $vals, [(int)$ga['sort_order'], $gaid])); continue; }
            $sa = $byOrigin[$gaid]; if ((int)$sa['is_customized'] === 1) continue; $diff = (int)$sa['day_id'] !== $target || (int)$sa['sort_order'] !== (int)$ga['sort_order'];
            foreach (self::SYNC_COLS as $c) if ((string)$sa[$c] !== (string)$ga[$c]) $diff = true;
            if ($diff) { $plan['updated']++; if ($apply) Db::exec('UPDATE itinerary_activities SET day_id=?,' . implode('=?,', self::SYNC_COLS) . '=?, sort_order=? WHERE id=?', array_merge([$target], $vals, [(int)$ga['sort_order'], $sa['id']])); }
        }
        foreach ($byOrigin as $origin => $sa) if (!isset($gActs[$origin])) { if ((int)$sa['is_customized'] === 1) { if ($apply) Db::exec('UPDATE itinerary_activities SET origin_activity_id=NULL WHERE id=?', [$sa['id']]); continue; } $plan['removed']++; if ($apply) Db::exec('DELETE FROM itinerary_activities WHERE id=?', [$sa['id']]); }
        foreach ($sDays as $sd) if ($sd['origin_day_id'] !== null && !in_array((int)$sd['origin_day_id'], array_map('intval', array_column($gDays, 'id')), true)) {
            $hasCustom = Db::one('SELECT id FROM itinerary_activities WHERE day_id=? LIMIT 1', [$sd['id']]);
            if ($apply) { if ($hasCustom) Db::exec('UPDATE itinerary_days SET origin_day_id=NULL WHERE id=?', [$sd['id']]); else { Db::exec('DELETE FROM itinerary_days WHERE id=?', [$sd['id']]); } }
            if (!$hasCustom) $plan['daysRemoved']++;
        }
        if ($apply) Db::exec('UPDATE trips SET itinerary_rev=itinerary_rev+1, updated_at=UTC_TIMESTAMP() WHERE id=?', [$sid]);
        return $plan;
    }
    public static function syncSubTrips(array $pa): array {
        $u = Auth::require('groups.manage'); $g = self::find($u, (int)$pa['id']); $d = Http::body(); $apply = !empty($d['apply']);
        $subs = array_map('intval', array_column(Db::all("SELECT id FROM trips WHERE parent_trip_id=? AND trip_type='sub' AND deleted_at IS NULL AND status<>'Cancelled'", [$g['id']]), 'id'));
        if (isset($d['subTripIds']) && is_array($d['subTripIds'])) $subs = array_values(array_intersect($subs, array_map('intval', $d['subTripIds'])));
        $out = [];
        foreach ($subs as $sid) $out[] = ['subTripId' => $sid] + ($apply ? Db::tx(function () use ($g, $sid) { return self::sync((int)$g['id'], $sid, true); }) : self::sync((int)$g['id'], $sid, false));
        if ($apply) Audit::log((int)$u['id'], 'group.synced', 'trip', $g['id'], ['subTrips' => count($subs)]);
        return ['applied' => $apply, 'results' => $out];
    }
}
