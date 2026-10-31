<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Auth, Audit, Db, Http, HttpError, Validate};
/** Traveler portal: a traveler user is linked to exactly one contact (contacts.portal_user_id).
 *  Travelers only see PUBLISHED, non-archived trips on which their contact is a traveler, and only the published snapshot. */
final class PortalController {
    private static function me(): array {
        $u = Auth::require('portal.access');
        $c = Db::one('SELECT id, first_name, last_name FROM contacts WHERE portal_user_id=? AND deleted_at IS NULL', [$u['id']]);
        if (!$c) throw new HttpError(403, 'No traveler profile is linked to this account. Please contact your advisor.');
        return [$u, $c];
    }
    private static function membership(int $tid, int $cid): array {
        $r = Db::one("SELECT t.*, tt.permission, o.full_name owner_name FROM trips t JOIN trip_travelers tt ON tt.trip_id=t.id AND tt.contact_id=? LEFT JOIN users o ON o.id=t.owner_id
                      WHERE t.id=? AND t.is_published=1 AND t.archived_at IS NULL AND t.deleted_at IS NULL AND t.published_version_id IS NOT NULL", [$cid, $tid]);
        if (!$r) throw new HttpError(404, 'Trip not found');   // same answer for other clients' trips and unpublished trips
        return $r;
    }
    public static function thread(int $tid): array {
        return array_map(function ($r) { return ['id' => (int)$r['id'], 'action' => $r['action'], 'message' => $r['message'], 'by' => $r['author_name'], 'optionId' => $r['option_id'] ? 'o' . $r['option_id'] : null, 'at' => $r['created_at']]; },
            Db::all('SELECT id, action, message, author_name, option_id, created_at FROM trip_approvals WHERE trip_id=? ORDER BY id', [$tid]));
    }
    public static function list(): array {
        [, $c] = self::me();
        $rows = Db::all("SELECT t.id, t.name, t.destination, t.start_date, t.end_date, t.status, tt.permission FROM trips t JOIN trip_travelers tt ON tt.trip_id=t.id AND tt.contact_id=?
                         WHERE t.is_published=1 AND t.archived_at IS NULL AND t.deleted_at IS NULL AND t.published_version_id IS NOT NULL ORDER BY t.start_date", [$c['id']]);
        return ['contact' => ['id' => (int)$c['id'], 'firstName' => $c['first_name'], 'lastName' => $c['last_name']], 'items' => array_map(function ($r) { return ['id' => (int)$r['id'], 'name' => $r['name'], 'destination' => $r['destination'], 'startDate' => $r['start_date'], 'endDate' => $r['end_date'], 'status' => $r['status'], 'permission' => $r['permission']]; }, $rows)];
    }
    public static function get(array $pa): array {
        [, $c] = self::me(); $t = self::membership((int)$pa['id'], (int)$c['id']);
        $snap = json_decode(Db::one('SELECT snapshot_json FROM itinerary_versions WHERE id=?', [$t['published_version_id']])['snapshot_json'], true);
        if ($t['permission'] === 'limited') foreach ($snap as &$o) foreach ($o['days'] as &$d) foreach ($d['acts'] as &$a) { unset($a['price'], $a['conf']); }
        unset($o, $d, $a);
        $travelers = array_map(function ($r) { return ['contactId' => (int)$r['contact_id'], 'firstName' => $r['first_name'], 'lastName' => $r['last_name'], 'permission' => $r['permission']]; },
            Db::all('SELECT tt.contact_id, tt.permission, c.first_name, c.last_name FROM trip_travelers tt JOIN contacts c ON c.id=tt.contact_id AND c.deleted_at IS NULL WHERE tt.trip_id=?', [$t['id']]));
        $meta = $t['itinerary_meta_json'] ? json_decode($t['itinerary_meta_json'], true) : [];
        return ['trip' => ['id' => (int)$t['id'], 'name' => $t['name'], 'destination' => $t['destination'], 'startDate' => $t['start_date'], 'endDate' => $t['end_date'], 'status' => $t['status'], 'ownerName' => $t['owner_name'],
            'permission' => $t['permission'], 'me' => (int)$c['id'], 'travelers' => $travelers, 'options' => $snap, 'selectedOptionId' => $t['selected_option_id'] ? 'o' . $t['selected_option_id'] : null,
            'packing' => $meta['pack'] ?? [], 'guide' => $meta['guide'] ?? '', 'activity' => self::thread((int)$t['id'])]];
    }
    private static function optionId(array $t, $raw): int {
        $snap = json_decode(Db::one('SELECT snapshot_json FROM itinerary_versions WHERE id=?', [$t['published_version_id']])['snapshot_json'], true);
        $ids = array_column($snap, 'id');
        if (!in_array((string)$raw, $ids, true) || !preg_match('/^o(\d+)$/', (string)$raw, $m)) throw new HttpError(422, 'Choose one of the published itinerary options');
        if (!Db::one('SELECT id FROM itinerary_options WHERE id=? AND trip_id=?', [(int)$m[1], $t['id']])) throw new HttpError(409, 'This option is no longer available. Please refresh.');
        return (int)$m[1];
    }
    private static function act(array $pa, string $action, array $allowed): array {
        [$u, $c] = self::me(); $t = self::membership((int)$pa['id'], (int)$c['id']);
        if (!in_array($t['permission'], $allowed, true)) throw new HttpError(403, 'Your traveler access does not allow this action');
        $d = Http::body(); $name = trim($c['first_name'] . ' ' . $c['last_name']); $oid = null; $msg = null;
        if ($action === 'approved' || $action === 'option_selected') $oid = self::optionId($t, $d['optionId'] ?? '');
        if ($action === 'changes_requested' || $action === 'comment') $msg = Validate::str($d, 'message', 2000, true);
        Db::tx(function () use ($t, $c, $u, $action, $oid, $msg, $name) {
            Db::exec('INSERT INTO trip_approvals (trip_id, contact_id, option_id, action, message, user_id, author_name, created_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())', [$t['id'], $c['id'], $oid, $action, $msg, $u['id'], $name]);
            if ($oid) Db::exec('UPDATE trips SET selected_option_id=?, updated_at=UTC_TIMESTAMP() WHERE id=?', [$oid, $t['id']]);
            if ($action === 'approved' && in_array($t['status'], ['Inquiry', 'Planning', 'Proposal'], true)) Db::exec("UPDATE trips SET status='Approved', version=version+1, updated_at=UTC_TIMESTAMP() WHERE id=?", [$t['id']]);
            Audit::log((int)$u['id'], 'portal.' . $action, 'trip', $t['id']);
        });
        return self::get($pa);
    }
    public static function select(array $pa): array { return self::act($pa, 'option_selected', ['primary']); }
    public static function approve(array $pa): array { return self::act($pa, 'approved', ['primary']); }
    public static function requestChanges(array $pa): array { return self::act($pa, 'changes_requested', ['primary', 'full']); }
    public static function comment(array $pa): array { return self::act($pa, 'comment', ['primary', 'full', 'limited']); }
    /** Staff: create or re-issue a traveler portal login for a contact (returns a one-time password link). */
    public static function createAccount(array $pa): array {
        $admin = Auth::require('staff.manage'); $c = ContactController::find($admin, (int)$pa['id']);
        if (!$c['email']) throw new HttpError(422, 'Add an email address to the contact first');
        $uid = $c['portal_user_id'] ? (int)$c['portal_user_id'] : 0;
        if (!$uid) {
            if (Db::one('SELECT id FROM users WHERE email=?', [$c['email']])) throw new HttpError(409, 'A user with this email already exists');
            $uid = Db::insert("INSERT INTO users (email, full_name, password_hash, role_id, is_active, created_at, updated_at) SELECT ?,?,?,id,1,UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM roles WHERE code='traveler'", [$c['email'], trim($c['first_name'] . ' ' . $c['last_name']), password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);
            Db::exec('UPDATE contacts SET portal_user_id=? WHERE id=?', [$uid, $c['id']]);
            Audit::log((int)$admin['id'], 'portal.account_created', 'contact', $c['id']);
        }
        return AuthController::issueResetLink((int)$admin['id'], $uid);
    }
}
