<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Auth, Audit, Db, Http, HttpError, Validate};
/** Itinerary drafts are stored relationally; publishing freezes a sanitized client snapshot in itinerary_versions.
 *  The portal only ever reads published snapshots, so editing a draft cannot change what travelers see. */
final class ItineraryController {
    const TYPES = ['flight', 'hotel', 'cruise', 'train', 'transfer', 'tour', 'excursion', 'dining', 'free', 'insurance', 'custom'];
    /** Draft in the shape used by the Revision 27 itinerary editor. */
    public static function draft(int $tid): array {
        $opts = Db::all('SELECT id, name FROM itinerary_options WHERE trip_id=? ORDER BY sort_order, id', [$tid]);
        $out = [];
        foreach ($opts as $o) {
            $days = [];
            foreach (Db::all('SELECT id, title FROM itinerary_days WHERE option_id=? ORDER BY sort_order, id', [$o['id']]) as $dy) {
                $acts = [];
                foreach (Db::all('SELECT * FROM itinerary_activities WHERE day_id=? ORDER BY sort_order, id', [$dy['id']]) as $a) {
                    $x = $a['details_json'] ? (json_decode($a['details_json'], true) ?: []) : [];
                    $acts[] = ['id' => 'a' . $a['id'], 'type' => $a['activity_type'], 'title' => $a['title'], 'desc' => (string)$a['description'], 'time' => $a['start_time'] ? substr($a['start_time'], 0, 5) : '',
                        'loc' => (string)$a['location'], 'price' => $a['price'] === null ? 0 : (float)$a['price'], 'conf' => (string)$a['confirmation_number'], 'inote' => (string)$a['internal_note'], 'cnote' => (string)$a['client_note'],
                        'supplier' => (string)($x['supplier'] ?? ''), 'bk' => (string)($x['bk'] ?? ''), 'trv' => array_values($x['trv'] ?? []), 'att' => array_values($x['att'] ?? []), 'f' => (object)($x['f'] ?? []), 'img' => (string)($x['img'] ?? '')];
                }
                $days[] = ['id' => 'dy' . $dy['id'], 't' => $dy['title'], 'acts' => $acts];
            }
            $out[] = ['id' => 'o' . $o['id'], 'name' => $o['name'], 'days' => $days];
        }
        return $out;
    }
    /** Client snapshot: internal notes and internal booking references removed. */
    public static function sanitize(array $opts): array {
        foreach ($opts as &$o) foreach ($o['days'] as &$d) foreach ($d['acts'] as &$a) { unset($a['inote'], $a['bk']); } 
        return $opts;
    }
    public static function hash(array $opts): string {
        $strip = function ($opts) { $r = []; foreach ($opts as $o) { $dd = []; foreach ($o['days'] as $d) { $aa = []; foreach ($d['acts'] as $a) { $c = $a; unset($c['id']); $aa[] = $c; } $dd[] = [$d['t'], $aa]; } $r[] = [$o['name'], $dd]; } return $r; };
        return hash('sha256', json_encode($strip($opts), JSON_UNESCAPED_UNICODE));
    }
    public static function payload(int $tid, array $extra = []): array {
        $t = Db::one('SELECT itinerary_rev, itinerary_meta_json, published_version_id, is_published, selected_option_id FROM trips WHERE id=?', [$tid]);
        $draft = self::draft($tid); $pub = null;
        if ($t['published_version_id']) {
            $v = Db::one('SELECT id, snapshot_json, draft_hash, created_at FROM itinerary_versions WHERE id=?', [$t['published_version_id']]);
            if ($v) $pub = ['versionId' => (int)$v['id'], 'publishedAt' => $v['created_at'], 'snapshot' => json_decode($v['snapshot_json'], true), 'hasUnpublishedChanges' => $v['draft_hash'] !== self::hash($draft)];
        }
        $versions = array_map(function ($r) { return ['id' => (int)$r['id'], 'label' => $r['label'], 'createdAt' => $r['created_at']]; }, Db::all('SELECT id, label, created_at FROM itinerary_versions WHERE trip_id=? ORDER BY id DESC LIMIT 50', [$tid]));
        return array_merge(['tripId' => $tid, 'rev' => (int)$t['itinerary_rev'], 'options' => $draft, 'meta' => $t['itinerary_meta_json'] ? json_decode($t['itinerary_meta_json'], true) : (object)[],
            'selectedOptionId' => $t['selected_option_id'] ? 'o' . $t['selected_option_id'] : null, 'isPublished' => (bool)$t['is_published'], 'published' => $pub, 'versions' => $versions], $extra);
    }
    public static function get(array $pa): array { $u = Auth::require('trips.view'); $t = TripController::findRow($u, (int)$pa['id']); return self::payload((int)$t['id']); }
    private static function str($v, int $max): string { $s = trim((string)($v ?? '')); return mb_substr($s, 0, $max); }
    /** Replace the whole draft in one transaction: existing ids are updated, new ones inserted (id map returned), missing ones deleted. */
    public static function save(array $pa): array {
        $u = Auth::require('trips.edit'); $t = TripController::findRow($u, (int)$pa['id']); $tid = (int)$t['id']; $d = Http::body();
        if (!isset($d['rev']) || (int)$d['rev'] !== (int)$t['itinerary_rev']) throw new HttpError(409, 'The itinerary was changed in another window. Reloading the latest version.');
        $opts = $d['options'] ?? null; if (!is_array($opts) || !$opts) throw new HttpError(422, Validate::MSG, ['options' => 'At least one itinerary option is required']);
        if (count($opts) > 10) throw new HttpError(422, Validate::MSG, ['options' => 'Maximum 10 options']);
        $map = [];
        Db::tx(function () use ($tid, $opts, $d, &$map, $u) {
            $ownOpt = array_map('intval', array_column(Db::all('SELECT id FROM itinerary_options WHERE trip_id=?', [$tid]), 'id'));
            $ownDay = array_map('intval', array_column(Db::all('SELECT d.id FROM itinerary_days d JOIN itinerary_options o ON o.id=d.option_id WHERE o.trip_id=?', [$tid]), 'id'));
            $ownAct = array_map('intval', array_column(Db::all('SELECT a.id FROM itinerary_activities a JOIN itinerary_days d ON d.id=a.day_id JOIN itinerary_options o ON o.id=d.option_id WHERE o.trip_id=?', [$tid]), 'id'));
            $keepO = []; $keepD = []; $keepA = []; $nActs = 0;
            $orig = []; foreach (Db::all('SELECT a.id, a.origin_activity_id, a.activity_type, a.title, a.description, a.start_time, a.location, a.price, a.client_note FROM itinerary_activities a JOIN itinerary_days d ON d.id=a.day_id JOIN itinerary_options o ON o.id=d.option_id WHERE o.trip_id=? AND a.origin_activity_id IS NOT NULL AND a.is_customized=0', [$tid]) as $r) $orig[(int)$r['id']] = $r;
            foreach (array_values($opts) as $oi => $o) {
                $name = self::str($o['name'] ?? '', 120) ?: 'Option ' . ($oi + 1);
                $oid = preg_match('/^o(\d+)$/', (string)($o['id'] ?? ''), $m) && in_array((int)$m[1], $ownOpt, true) ? (int)$m[1] : 0;
                if ($oid) Db::exec('UPDATE itinerary_options SET name=?, sort_order=? WHERE id=?', [$name, $oi, $oid]);
                else { $oid = Db::insert('INSERT INTO itinerary_options (trip_id, name, sort_order) VALUES (?,?,?)', [$tid, $name, $oi]); $map[(string)($o['id'] ?? '')] = 'o' . $oid; }
                $keepO[] = $oid;
                foreach (array_values($o['days'] ?? []) as $di => $dy) {
                    if (count($keepD) >= 120) throw new HttpError(422, Validate::MSG, ['days' => 'Maximum 120 days']);
                    $title = self::str($dy['t'] ?? '', 190) ?: 'Day ' . ($di + 1);
                    $did = preg_match('/^dy(\d+)$/', (string)($dy['id'] ?? ''), $m) && in_array((int)$m[1], $ownDay, true) && !in_array((int)$m[1], $keepD, true) ? (int)$m[1] : 0;
                    if ($did) Db::exec('UPDATE itinerary_days SET option_id=?, title=?, sort_order=? WHERE id=?', [$oid, $title, $di, $did]);
                    else { $did = Db::insert('INSERT INTO itinerary_days (option_id, title, sort_order) VALUES (?,?,?)', [$oid, $title, $di]); $map[(string)($dy['id'] ?? '')] = 'dy' . $did; }
                    $keepD[] = $did;
                    foreach (array_values($dy['acts'] ?? []) as $ai => $a) {
                        if (++$nActs > 1000) throw new HttpError(422, Validate::MSG, ['activities' => 'Maximum 1000 activities']);
                        $type = in_array($a['type'] ?? '', self::TYPES, true) ? $a['type'] : 'custom';
                        $time = preg_match('/^\d{2}:\d{2}$/', (string)($a['time'] ?? '')) ? $a['time'] . ':00' : null;
                        $price = isset($a['price']) && is_numeric($a['price']) ? round((float)$a['price'], 2) : null;
                        $img = (string)($a['img'] ?? ''); if (strlen($img) > 400000) $img = '';  // large images belong in Documents (Phase 4)
                        $det = json_encode(['supplier' => self::str($a['supplier'] ?? '', 190), 'bk' => self::str($a['bk'] ?? '', 40), 'trv' => array_values(array_map('strval', is_array($a['trv'] ?? null) ? $a['trv'] : [])),
                            'att' => array_values(array_map(function ($x) { return mb_substr((string)$x, 0, 190); }, is_array($a['att'] ?? null) ? $a['att'] : [])), 'f' => is_array($a['f'] ?? null) ? array_map(function ($x) { return mb_substr((string)$x, 0, 190); }, $a['f']) : [], 'img' => $img], JSON_UNESCAPED_UNICODE);
                        $vals = [$did, $type, self::str($a['title'] ?? '', 190) ?: 'Activity', self::str($a['desc'] ?? '', 5000), $time, self::str($a['loc'] ?? '', 190), $price, self::str($a['conf'] ?? '', 60), self::str($a['inote'] ?? '', 5000), self::str($a['cnote'] ?? '', 5000), $det, $ai];
                        $aid = preg_match('/^a(\d+)$/', (string)($a['id'] ?? ''), $m) && in_array((int)$m[1], $ownAct, true) && !in_array((int)$m[1], $keepA, true) ? (int)$m[1] : 0;
                        if ($aid) {
                            Db::exec('UPDATE itinerary_activities SET day_id=?, activity_type=?, title=?, description=?, start_time=?, location=?, price=?, confirmation_number=?, internal_note=?, client_note=?, details_json=?, sort_order=? WHERE id=?', array_merge($vals, [$aid]));
                            if (isset($orig[$aid])) { $o0 = $orig[$aid]; $changed = $o0['activity_type'] !== $vals[1] || $o0['title'] !== $vals[2] || (string)$o0['description'] !== $vals[3] || (string)$o0['start_time'] !== (string)$vals[4] || (string)$o0['location'] !== $vals[5] || abs((float)($o0['price'] ?? 0) - (float)($vals[6] ?? 0)) > 0.001 || (string)$o0['client_note'] !== $vals[9];
                                if ($changed) Db::exec('UPDATE itinerary_activities SET is_customized=1 WHERE id=?', [$aid]); }
                        }
                        else { $aid = Db::insert('INSERT INTO itinerary_activities (day_id, activity_type, title, description, start_time, location, price, confirmation_number, internal_note, client_note, details_json, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', $vals); $map[(string)($a['id'] ?? '')] = 'a' . $aid; }
                        $keepA[] = $aid;
                    }
                }
            }
            $del = function (string $table, array $own, array $keep) { $gone = array_diff($own, $keep); if ($gone) Db::exec("DELETE FROM $table WHERE id IN (" . implode(',', array_map('intval', $gone)) . ')'); };
            $del('itinerary_activities', $ownAct, $keepA); $del('itinerary_days', $ownDay, $keepD); $del('itinerary_options', $ownOpt, $keepO);
            $meta = is_array($d['meta'] ?? null) ? ['pack' => array_slice(array_values(array_map(function ($p) { return ['t' => mb_substr((string)($p['t'] ?? ''), 0, 190), 'x' => !empty($p['x']) ? 1 : 0]; }, is_array($d['meta']['pack'] ?? null) ? $d['meta']['pack'] : [])), 0, 200), 'guide' => mb_substr((string)($d['meta']['guide'] ?? ''), 0, 40), 'prefs' => mb_substr((string)($d['meta']['prefs'] ?? ''), 0, 5000)] : null;
            Db::exec('UPDATE trips SET itinerary_rev=itinerary_rev+1' . ($meta !== null ? ', itinerary_meta_json=?' : '') . ', updated_at=UTC_TIMESTAMP() WHERE id=?', $meta !== null ? [json_encode($meta, JSON_UNESCAPED_UNICODE), $tid] : [$tid]);
            Audit::log((int)$u['id'], 'itinerary.saved', 'trip', $tid, ['activities' => count($keepA), 'days' => count($keepD), 'options' => count($keepO)]);
        });
        return self::payload($tid, ['idMap' => (object)$map]);
    }
    public static function publishInternal(int $tid, int $userId, string $label): int {
        $draft = self::draft($tid);
        return Db::tx(function () use ($tid, $userId, $label, $draft) {
            $vid = Db::insert('INSERT INTO itinerary_versions (trip_id, label, snapshot_json, draft_hash, created_by, created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())', [$tid, $label, json_encode(self::sanitize($draft), JSON_UNESCAPED_UNICODE), self::hash($draft), $userId]);
            Db::exec('UPDATE trips SET published_version_id=?, is_published=1, updated_at=UTC_TIMESTAMP() WHERE id=?', [$vid, $tid]);
            Audit::log($userId, 'itinerary.published', 'trip', $tid, ['version' => $vid]);
            return $vid;
        });
    }
    public static function publish(array $pa): array { $u = Auth::require('trips.publish'); $t = TripController::findRow($u, (int)$pa['id']); if ($t['archived_at']) throw new HttpError(422, 'Archived trips cannot be published'); self::publishInternal((int)$t['id'], (int)$u['id'], 'Published by ' . $u['full_name']); return self::payload((int)$t['id']); }
    public static function unpublish(array $pa): array { $u = Auth::require('trips.publish'); $t = TripController::findRow($u, (int)$pa['id']); Db::exec('UPDATE trips SET is_published=0, updated_at=UTC_TIMESTAMP() WHERE id=?', [$t['id']]); Audit::log((int)$u['id'], 'itinerary.unpublished', 'trip', $t['id']); return self::payload((int)$t['id']); }
    public static function version(array $pa): array {
        $u = Auth::require('trips.view'); $t = TripController::findRow($u, (int)$pa['id']);
        $v = Db::one('SELECT id, label, snapshot_json, created_at FROM itinerary_versions WHERE id=? AND trip_id=?', [(int)$pa['vid'], $t['id']]);
        if (!$v) throw new HttpError(404, 'Version not found');
        return ['version' => ['id' => (int)$v['id'], 'label' => $v['label'], 'createdAt' => $v['created_at'], 'snapshot' => json_decode($v['snapshot_json'], true)]];
    }
    public static function staffComment(array $pa): array {
        $u = Auth::require('trips.edit'); $t = TripController::findRow($u, (int)$pa['id']); $d = Http::body(); $m = Validate::str($d, 'message', 2000, true);
        Db::exec("INSERT INTO trip_approvals (trip_id, action, message, user_id, author_name, created_at) VALUES (?, 'comment', ?, ?, ?, UTC_TIMESTAMP())", [$t['id'], $m, $u['id'], $u['full_name'] . ' (advisor)']);
        Audit::log((int)$u['id'], 'trip.comment', 'trip', $t['id']);
        return ['activity' => PortalController::thread((int)$t['id'])];
    }
}
