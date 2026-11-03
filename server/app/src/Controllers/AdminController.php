<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Audit, Auth, Db, Events, Http, HttpError, Validate};
/** Tags, roles and permissions, travelers list, support requests, and the Revision 27 settings import. */
final class AdminController {
    /* ---- agency tags ---- */
    private static function tagRows(): array { $trips = Db::all("SELECT tags_json FROM trips WHERE tags_json IS NOT NULL AND tags_json<>'' AND deleted_at IS NULL"); $tripUse = [];
        foreach ($trips as $t) foreach ((json_decode((string)$t['tags_json'], true) ?: []) as $n) $tripUse[mb_strtolower((string)$n)] = ($tripUse[mb_strtolower((string)$n)] ?? 0) + 1;
        return array_map(function ($r) use ($tripUse) { return ['id' => (int)$r['id'], 'name' => $r['name'], 'origin' => $r['origin'], 'contacts' => (int)$r['n'], 'trips' => $tripUse[mb_strtolower($r['name'])] ?? 0]; }, Db::all('SELECT t.*, (SELECT COUNT(*) FROM contact_tags ct WHERE ct.tag_id=t.id) n FROM tags t ORDER BY t.name')); }
    public static function tags(): array { Auth::require('contacts.view'); return ['items' => self::tagRows()]; }
    public static function createTag(): array { $u = Auth::require('staff.manage'); $n = trim((string)Validate::str(Http::body(), 'name', 60, true)); if (Db::one('SELECT id FROM tags WHERE name=?', [$n])) throw new HttpError(409, 'That tag already exists');
        $id = Db::insert('INSERT INTO tags (name, created_at) VALUES (?,UTC_TIMESTAMP())', [$n]); Audit::log((int)$u['id'], 'tag.created', 'tag', $id, ['name' => $n]); return ['items' => self::tagRows()]; }
    public static function renameTag(array $pa): array { $u = Auth::require('staff.manage'); $t = Db::one('SELECT * FROM tags WHERE id=?', [(int)$pa['id']]); if (!$t) throw new HttpError(404, 'Tag not found'); $n = trim((string)Validate::str(Http::body(), 'name', 60, true));
        if (Db::one('SELECT id FROM tags WHERE name=? AND id<>?', [$n, $t['id']])) throw new HttpError(409, 'That tag already exists'); Db::exec('UPDATE tags SET name=? WHERE id=?', [$n, $t['id']]); Audit::log((int)$u['id'], 'tag.renamed', 'tag', $t['id'], ['from' => $t['name'], 'to' => $n]); return ['items' => self::tagRows()]; }
    public static function deleteTag(array $pa): array { $u = Auth::require('staff.manage'); $row = null; foreach (self::tagRows() as $r) if ($r['id'] === (int)$pa['id']) $row = $r; if (!$row) throw new HttpError(404, 'Tag not found');
        if ($row['contacts'] || $row['trips']) throw new HttpError(409, "This tag is in use ({$row['contacts']} contacts, {$row['trips']} trips); remove it from them first"); Db::exec('DELETE FROM tags WHERE id=?', [$row['id']]); Audit::log((int)$u['id'], 'tag.deleted', 'tag', $row['id'], ['name' => $row['name']]); return ['items' => self::tagRows()]; }
    /* ---- roles and permissions ---- */
    const PORTAL_ONLY = ['portal.access'];
    public static function roles(): array { Auth::require('staff.manage'); $perms = Db::all('SELECT code, description FROM permissions ORDER BY code');
        $roles = array_map(function ($r) { return ['code' => $r['code'], 'name' => $r['name'], 'isStaff' => (bool)$r['is_staff'], 'users' => (int)$r['n'], 'locked' => $r['code'] === 'owner', 'permissions' => array_column(Db::all('SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=? ORDER BY p.code', [$r['id']]), 'code')]; },
            Db::all('SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id=r.id AND u.is_active=1) n FROM roles r ORDER BY r.is_staff DESC, r.id'));
        return ['roles' => $roles, 'permissions' => $perms, 'canEdit' => (Auth::user()['role'] ?? '') === 'owner']; }
    /** Only owners may change permissions. The owner role is locked; the traveler role may only hold portal permissions; at least one owner must keep staff.manage. */
    public static function setRolePermissions(array $pa): array { $u = Auth::require('staff.manage'); if (($u['role'] ?? '') !== 'owner') throw new HttpError(403, 'Only an owner can change role permissions');
        $role = Db::one('SELECT * FROM roles WHERE code=?', [(string)$pa['code']]); if (!$role) throw new HttpError(404, 'Role not found'); if ($role['code'] === 'owner') throw new HttpError(409, 'The owner role cannot be changed');
        $want = array_values(array_unique(array_map('strval', is_array(Http::body()['permissions'] ?? null) ? Http::body()['permissions'] : [])));
        $known = array_column(Db::all('SELECT id, code FROM permissions'), 'id', 'code'); foreach ($want as $c) if (!isset($known[$c])) throw new HttpError(422, Validate::MSG, ['permissions' => 'Unknown permission ' . $c]);
        if (!$role['is_staff'] && array_diff($want, self::PORTAL_ONLY)) throw new HttpError(422, 'Client accounts can only have portal permissions');
        if ($role['is_staff'] && in_array('portal.access', $want, true)) throw new HttpError(422, 'Staff roles cannot use the traveler portal permission');
        $before = array_column(Db::all('SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=?', [$role['id']]), 'code');
        Db::tx(function () use ($role, $want, $known) { Db::exec('DELETE FROM role_permissions WHERE role_id=?', [$role['id']]); foreach ($want as $c) Db::exec('INSERT INTO role_permissions (role_id, permission_id) VALUES (?,?)', [$role['id'], $known[$c]]); });
        Audit::log((int)$u['id'], 'role.permissions_changed', 'role', (int)$role['id'], ['role' => $role['code'], 'added' => array_values(array_diff($want, $before)), 'removed' => array_values(array_diff($before, $want))]); return self::roles(); }
    /* ---- travelers across visible trips ---- */
    public static function travelers(): array { $u = Auth::require('contacts.view'); [$tw, $tp] = TripController::scope($u); $q = trim((string)($_GET['q'] ?? '')); $w = ''; $p = $tp;
        if ($q !== '') { $l = '%' . addcslashes($q, '%_\\') . '%'; $w = " AND CONCAT_WS(' ', c.first_name, c.last_name, c.email) LIKE ?"; $p[] = $l; }
        $rows = Db::all("SELECT c.id, c.first_name, c.last_name, c.email, c.phone, c.passport_country, c.passport_expiry, GROUP_CONCAT(DISTINCT CONCAT(t.id,':',REPLACE(t.name,'|',' '),':',tt.permission) SEPARATOR '|') trips
            FROM trip_travelers tt JOIN trips t ON t.id=tt.trip_id AND t.deleted_at IS NULL JOIN contacts c ON c.id=tt.contact_id AND c.deleted_at IS NULL WHERE $tw$w GROUP BY c.id ORDER BY c.last_name, c.first_name LIMIT 500", $p);
        $soon = gmdate('Y-m-d', strtotime('+6 months'));
        return ['items' => array_map(function ($r) use ($soon) { return ['contactId' => (int)$r['id'], 'name' => trim($r['first_name'] . ' ' . $r['last_name']), 'email' => $r['email'], 'phone' => $r['phone'],
            'passportCountry' => $r['passport_country'], 'passportExpiry' => $r['passport_expiry'], 'passportExpiresSoon' => $r['passport_expiry'] && $r['passport_expiry'] < $soon,
            'trips' => array_map(function ($x) { [$id, $n, $perm] = array_pad(explode(':', $x, 3), 3, ''); return ['id' => (int)$id, 'name' => $n, 'permission' => $perm]; }, array_filter(explode('|', (string)$r['trips'])))]; }, $rows)]; }
    /* ---- support requests become real tasks for an administrator ---- */
    public static function supportRequest(): array { $u = Auth::require('tasks.manage'); $d = Http::body(); $cat = Validate::enum($d, 'category', ['Platform question', 'Bug report', 'Access / permissions', 'Integration request', 'Other'], 'Other'); $sub = Validate::str($d, 'subject', 200, true); $det = Validate::str($d, 'details', 5000);
        $to = Db::one("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code IN ('owner','admin') AND u.is_active=1 AND u.id<>? ORDER BY r.code='admin' DESC, u.id LIMIT 1", [(int)$u['id']]) ?: Db::one("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='owner' AND u.is_active=1 ORDER BY u.id LIMIT 1");
        $id = Db::insert("INSERT INTO tasks (title, description, priority, status, assigned_to, source, created_by, created_at, updated_at, version) VALUES (?,?,?,'todo',?,'Support request',?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)", [mb_substr("[$cat] $sub", 0, 255), trim(($det ?? '') . "\n\nFrom: " . $u['full_name']), $cat === 'Bug report' ? 'high' : 'normal', $to ? (int)$to['id'] : null, (int)$u['id']]);
        if ($to) Events::notify((int)$to['id'], 'task_assignment', 'Support request from ' . $u['full_name'] . ': ' . mb_substr($sub, 0, 150), 'task', $id, 'event'); Audit::log((int)$u['id'], 'support.requested', 'task', $id); return ['taskId' => $id, 'assignedTo' => $to ? (int)$to['id'] : null]; }
    /* ---- Revision 27 settings import: configuration only; fictional contact details and sample legal text are not imported ---- */
    public static function importSettings(): array { $u = Auth::require('staff.manage'); $dry = !empty(Http::body()['dryRun']); $snap = LTI_APP . '/data/revision27_settings.json'; if (!is_file($snap)) throw new HttpError(404, 'Settings snapshot not found');
        $s = json_decode((string)file_get_contents($snap), true)['data']; $out = ['tags' => ['imported' => 0, 'skipped' => 0], 'settings' => ['imported' => [], 'kept existing' => []], 'notImported' => ['agency email, phone and email signature (fictional placeholders)', 'terms and conditions (sample legal text)']];
        foreach ($s['tripTags'] as $t) { if (Db::one('SELECT id FROM tags WHERE name=?', [$t])) { $out['tags']['skipped']++; continue; } $out['tags']['imported']++; if (!$dry) Db::exec("INSERT INTO tags (name, origin, created_at) VALUES (?,'revision27',UTC_TIMESTAMP())", [$t]); }
        $defaults = ['brand_color' => '#c19a3b', 'date_format' => 'MMM D, YYYY', 'week_start' => 'Sun', 'agency_timezone' => 'America/New_York'];
        foreach (['brand_color' => $s['set']['brand'] ?? null, 'date_format' => $s['set']['datefmt'] ?? null, 'week_start' => $s['set']['weekStart'] ?? null, 'agency_timezone' => $s['set']['tz'] ?? null, 'agency_name' => $s['set']['agency'] ?? null] as $k => $v) {
            if ($v === null) continue; $cur = SettingsController::get_($k); if ($cur !== '' && $cur !== ($defaults[$k] ?? null) && $cur !== $v) { $out['settings']['kept existing'][] = $k; continue; }
            $out['settings']['imported'][] = $k; if (!$dry) Db::exec('INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at) VALUES (?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by=VALUES(updated_by), updated_at=VALUES(updated_at)', [$k, (string)$v, (int)$u['id']]); }
        if (!$dry) Audit::log((int)$u['id'], 'settings.imported', 'settings', null, ['source' => 'revision27']); return ['dryRun' => $dry] + $out; }
}
