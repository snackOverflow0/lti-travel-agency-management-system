<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Auth, Audit, Db, Http, HttpError, Validate};
final class LibraryController {
    const KINDS = ['itinerary', 'activity', 'checklist', 'supplier_info', 'document', 'guide', 'task', 'workflow_reference'];
    const SECTIONS = ['general', 'itinerary', 'photos', 'tasks', 'workflows'];
    private static function folder(int $id): array { $f = Db::one('SELECT * FROM content_folders WHERE id=?', [$id]); if (!$f) throw new HttpError(404, 'Folder not found'); return $f; }
    private static function tpl(int $id): array { $t = Db::one('SELECT t.*, u.full_name updater FROM content_templates t LEFT JOIN users u ON u.id=t.updated_by WHERE t.id=?', [$id]); if (!$t) throw new HttpError(404, 'Template not found'); return $t; }
    private static function out(array $t): array { return ['origin' => $t['origin'] ?? null, 'id' => (int)$t['id'], 'folderId' => $t['folder_id'] === null ? null : (int)$t['folder_id'], 'kind' => $t['kind'], 'title' => $t['title'], 'summary' => $t['summary'], 'body' => json_decode($t['body_json'], true),
        'tags' => $t['tags_json'] ? json_decode($t['tags_json'], true) : [], 'status' => $t['status'], 'version' => (int)$t['version'], 'updatedAt' => $t['updated_at'], 'updatedBy' => $t['updater'] ?? null,
        'usageCount' => (int)Db::one('SELECT COUNT(*) n FROM content_template_usage WHERE template_id=?', [$t['id']])['n']]; }
    public static function folders(): array { Auth::require('library.view'); $sec = (string)($_GET['section'] ?? ''); if ($sec !== '') Validate::enum(['s' => $sec], 's', self::SECTIONS);
        return ['items' => array_map(function ($r) { return ['id' => (int)$r['id'], 'name' => $r['name'], 'section' => $r['section'], 'origin' => $r['origin'], 'parentId' => $r['parent_id'] === null ? null : (int)$r['parent_id'], 'templateCount' => (int)$r['n']]; },
        Db::all("SELECT f.*, (SELECT COUNT(*) FROM content_templates t WHERE t.folder_id=f.id AND t.status='active') n FROM content_folders f" . ($sec !== '' ? ' WHERE f.section=' . Db::pdo()->quote($sec) : '') . " ORDER BY f.name"))]; }
    private static function parent(array $d, ?int $self): ?int {
        if (!array_key_exists('parentId', $d) || $d['parentId'] === null || $d['parentId'] === '') return null;
        $pid = (int)$d['parentId']; self::folder($pid);
        for ($x = $pid, $guard = 0; $x !== null && $guard < 50; $guard++) { if ($self !== null && $x === $self) throw new HttpError(422, 'A folder cannot be moved inside itself'); $r = Db::one('SELECT parent_id FROM content_folders WHERE id=?', [$x]); $x = $r && $r['parent_id'] !== null ? (int)$r['parent_id'] : null; }
        return $pid;
    }
    private static function uniqueName(string $name, ?int $parent, int $except, string $section = 'general'): void { if (Db::one('SELECT id FROM content_folders WHERE section=' . Db::pdo()->quote($section) . ' AND name=? AND ' . ($parent === null ? 'parent_id IS NULL' : 'parent_id=' . $parent) . ' AND id<>?', [$name, $except])) throw new HttpError(409, 'A folder with this name already exists here'); }
    public static function createFolder(): array { $u = Auth::require('library.manage'); $d = Http::body(); $name = Validate::str($d, 'name', 120, true); $p = self::parent($d, null); $sec = Validate::enum($d, 'section', self::SECTIONS, $p ? self::folder($p)['section'] : 'general'); self::uniqueName($name, $p, 0, $sec);
        $id = Db::insert('INSERT INTO content_folders (name,parent_id,section,created_by,created_at,updated_at) VALUES (?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())', [$name, $p, $sec, (int)$u['id']]); Audit::log((int)$u['id'], 'library.folder_created', 'folder', $id); return ['folder' => ['id' => $id, 'name' => $name, 'parentId' => $p]]; }
    public static function updateFolder(array $pa): array { $u = Auth::require('library.manage'); $f = self::folder((int)$pa['id']); $d = Http::body(); $name = array_key_exists('name', $d) ? Validate::str($d, 'name', 120, true) : $f['name'];
        $p = array_key_exists('parentId', $d) ? self::parent($d, (int)$f['id']) : ($f['parent_id'] === null ? null : (int)$f['parent_id']); self::uniqueName($name, $p, (int)$f['id'], $f['section']);
        Db::exec('UPDATE content_folders SET name=?, parent_id=?, updated_at=UTC_TIMESTAMP() WHERE id=?', [$name, $p, $f['id']]); Audit::log((int)$u['id'], 'library.folder_updated', 'folder', $f['id']); return ['folder' => ['id' => (int)$f['id'], 'name' => $name, 'parentId' => $p]]; }
    public static function deleteFolder(array $pa): array { $u = Auth::require('library.manage'); $f = self::folder((int)$pa['id']);
        if (Db::one('SELECT id FROM content_templates WHERE folder_id=? LIMIT 1', [$f['id']]) || Db::one('SELECT id FROM content_folders WHERE parent_id=? LIMIT 1', [$f['id']])) throw new HttpError(409, 'Move or archive the folder’s contents first');
        Db::exec('DELETE FROM content_folders WHERE id=?', [$f['id']]); Audit::log((int)$u['id'], 'library.folder_deleted', 'folder', $f['id']); return ['ok' => true]; }
    public static function list(): array {
        Auth::require('library.view'); $w = []; $p = [];
        $st = (string)($_GET['status'] ?? 'active'); if ($st !== 'all') { Validate::enum(['s' => $st], 's', ['active', 'archived']); $w[] = 't.status=?'; $p[] = $st; }
        if (($k = (string)($_GET['kind'] ?? '')) !== '') { Validate::enum(['k' => $k], 'k', self::KINDS); $w[] = 't.kind=?'; $p[] = $k; }
        if (isset($_GET['folderId']) && $_GET['folderId'] !== '') { if ($_GET['folderId'] === 'none') $w[] = 't.folder_id IS NULL'; else { $w[] = 't.folder_id=?'; $p[] = (int)$_GET['folderId']; } }
        if (($q = trim((string)($_GET['q'] ?? ''))) !== '') { $like = '%' . addcslashes($q, '%_\\') . '%'; $w[] = '(t.title LIKE ? OR t.summary LIKE ? OR t.tags_json LIKE ? OR t.body_json LIKE ?)'; array_push($p, $like, $like, $like, $like); }
        $rows = Db::all('SELECT t.*, u.full_name updater FROM content_templates t LEFT JOIN users u ON u.id=t.updated_by ' . ($w ? 'WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY t.title LIMIT 500', $p);
        return ['items' => array_map([self::class, 'out'], $rows)];
    }
    public static function get(array $pa): array { Auth::require('library.view'); return ['template' => self::out(self::tpl((int)$pa['id']))]; }
    private static function fields(array $d, ?array $cur): array {
        $body = $d['body'] ?? ($cur ? json_decode($cur['body_json'], true) : []); if (!is_array($body)) throw new HttpError(422, Validate::MSG, ['body' => 'Invalid content']);
        $json = json_encode($body, JSON_UNESCAPED_UNICODE); if (strlen($json) > 500000) throw new HttpError(413, 'Template content is too large');
        $folder = array_key_exists('folderId', $d) ? ($d['folderId'] === null || $d['folderId'] === '' ? null : (int)self::folder((int)$d['folderId'])['id']) : ($cur ? $cur['folder_id'] : null);
        $tags = isset($d['tags']) && is_array($d['tags']) ? array_slice(array_values(array_unique(array_filter(array_map(function ($x) { return mb_substr(trim((string)$x), 0, 40); }, $d['tags'])))), 0, 20) : ($cur && $cur['tags_json'] ? json_decode($cur['tags_json'], true) : []);
        return ['folder_id' => $folder, 'kind' => Validate::enum($d, 'kind', self::KINDS, $cur ? $cur['kind'] : null) ?? (function () { throw new HttpError(422, Validate::MSG, ['kind' => 'Required']); })(),
            'title' => Validate::str($d, 'title', 190, $cur === null) ?? $cur['title'], 'summary' => array_key_exists('summary', $d) ? Validate::str($d, 'summary', 500) : ($cur ? $cur['summary'] : null), 'body_json' => $json, 'tags_json' => json_encode($tags, JSON_UNESCAPED_UNICODE)];
    }
    public static function create(): array { $u = Auth::require('library.manage'); $f = self::fields(Http::body(), null); $cols = array_keys($f);
        $id = Db::insert('INSERT INTO content_templates (' . implode(',', $cols) . ',status,created_by,updated_by,created_at,updated_at,version) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ",'active',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)", array_merge(array_values($f), [(int)$u['id'], (int)$u['id']]));
        Audit::log((int)$u['id'], 'library.template_created', 'template', $id); return ['template' => self::out(self::tpl($id))]; }
    public static function update(array $pa): array { $u = Auth::require('library.manage'); $cur = self::tpl((int)$pa['id']); $d = Http::body();
        if (isset($d['version']) && (int)$d['version'] !== (int)$cur['version']) throw new HttpError(409, 'This template was changed by someone else. Reload and try again.');
        $f = self::fields($d, $cur); $sets = implode(',', array_map(function ($c) { return "$c=?"; }, array_keys($f)));
        Db::exec("UPDATE content_templates SET $sets, updated_by=?, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?", array_merge(array_values($f), [(int)$u['id'], $cur['id']]));
        Audit::log((int)$u['id'], 'library.template_updated', 'template', $cur['id']); return ['template' => self::out(self::tpl((int)$cur['id']))]; }
    public static function duplicate(array $pa): array { $u = Auth::require('library.manage'); $cur = self::tpl((int)$pa['id']);
        $id = Db::insert("INSERT INTO content_templates (folder_id,kind,title,summary,body_json,tags_json,status,created_by,updated_by,created_at,updated_at,version) VALUES (?,?,?,?,?,?,'active',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)", [$cur['folder_id'], $cur['kind'], mb_substr($cur['title'] . ' (copy)', 0, 190), $cur['summary'], $cur['body_json'], $cur['tags_json'], (int)$u['id'], (int)$u['id']]);
        Audit::log((int)$u['id'], 'library.template_duplicated', 'template', $id, ['from' => (int)$cur['id']]); return ['template' => self::out(self::tpl($id))]; }
    public static function archive(array $pa, bool $restore = false): array { $u = Auth::require('library.manage'); $cur = self::tpl((int)$pa['id']);
        Db::exec('UPDATE content_templates SET status=?, updated_by=?, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?', [$restore ? 'active' : 'archived', (int)$u['id'], $cur['id']]);
        Audit::log((int)$u['id'], $restore ? 'library.template_restored' : 'library.template_archived', 'template', $cur['id']); return ['template' => self::out(self::tpl((int)$cur['id']))]; }
    /** Use a template on a trip: activity templates are appended to a day of the trip draft; checklists add packing items; every use is recorded. */
    public static function useOnTrip(array $pa): array {
        $u = Auth::require('trips.edit'); Auth::require('library.view'); $t = self::tpl((int)$pa['id']); if ($t['status'] !== 'active') throw new HttpError(409, 'Archived templates cannot be used');
        $d = Http::body(); $trip = TripController::findRow($u, (int)($d['tripId'] ?? 0)); $body = json_decode($t['body_json'], true) ?: []; $actId = null;
        Db::tx(function () use ($t, $d, $trip, $body, $u, &$actId) {
            if ($t['kind'] === 'activity') {
                $day = Db::one('SELECT d.id FROM itinerary_days d JOIN itinerary_options o ON o.id=d.option_id WHERE d.id=? AND o.trip_id=?', [(int)($d['dayId'] ?? 0), $trip['id']]);
                if (!$day) throw new HttpError(422, Validate::MSG, ['dayId' => 'Choose a day of this trip']);
                $sort = (int)Db::one('SELECT COALESCE(MAX(sort_order),-1)+1 n FROM itinerary_activities WHERE day_id=?', [$day['id']])['n'];
                $actId = Db::insert('INSERT INTO itinerary_activities (day_id, activity_type, title, description, location, price, client_note, details_json, sort_order) VALUES (?,?,?,?,?,?,?,?,?)', [$day['id'], in_array($body['type'] ?? '', ItineraryController::TYPES, true) ? $body['type'] : 'custom', mb_substr((string)($body['title'] ?? $t['title']), 0, 190), mb_substr((string)($body['description'] ?? ''), 0, 5000), mb_substr((string)($body['location'] ?? ''), 0, 190), is_numeric($body['price'] ?? null) ? (float)$body['price'] : null, mb_substr((string)($body['clientNote'] ?? ''), 0, 5000), json_encode(['supplier' => (string)($body['supplier'] ?? '')]), $sort]);
                Db::exec('UPDATE trips SET itinerary_rev=itinerary_rev+1, updated_at=UTC_TIMESTAMP() WHERE id=?', [$trip['id']]);
            } elseif ($t['kind'] === 'checklist') {
                $meta = json_decode((string)Db::one('SELECT itinerary_meta_json m FROM trips WHERE id=?', [$trip['id']])['m'], true) ?: []; $pack = $meta['pack'] ?? []; $have = array_column($pack, 't');
                foreach ((array)($body['items'] ?? []) as $it) { $it = mb_substr((string)$it, 0, 190); if ($it !== '' && !in_array($it, $have, true)) $pack[] = ['t' => $it, 'x' => 0]; }
                $meta['pack'] = array_slice($pack, 0, 200); Db::exec('UPDATE trips SET itinerary_meta_json=?, itinerary_rev=itinerary_rev+1, updated_at=UTC_TIMESTAMP() WHERE id=?', [json_encode($meta, JSON_UNESCAPED_UNICODE), $trip['id']]);
            }
            Db::exec('INSERT INTO content_template_usage (template_id, trip_id, activity_id, used_by, created_at) VALUES (?,?,?,?,UTC_TIMESTAMP())', [$t['id'], $trip['id'], $actId, (int)$u['id']]);
            Audit::log((int)$u['id'], 'library.template_used', 'trip', $trip['id'], ['template' => (int)$t['id']]);
        });
        return ['ok' => true, 'activityId' => $actId];
    }
    /** Admin-only: import the Revision 27 library snapshot. dryRun reports what would be imported without writing. */
    public static function importRevision27(): array { $u = Auth::require('library.manage'); if (!Auth::can('staff.manage')) throw new HttpError(403, 'Only administrators can run the library import');
        return \LTI\LibraryImport::run(!empty(Http::body()['dryRun']), (int)$u['id']); }
}
