<?php
declare(strict_types=1);
namespace LTI;
/** Imports the Revision 27 prototype library snapshot (server/app/data/revision27_library.json) into MySQL.
 *  Idempotent: every record carries a unique source_key; existing keys are skipped and never overwritten (staff edits are preserved).
 *  Nothing is deleted. Records are tagged origin='revision27'. */
final class LibraryImport {
    const ORIGIN = 'revision27';
    const PRIORITY = ['High' => 'high', 'Medium' => 'normal', 'Low' => 'low', 'Urgent' => 'urgent'];
    /** Placeholders in the prototype templates with no data source in LTI: replaced by visible markers, never silently dropped. */
    const UNSUPPORTED = ['form.link' => 'form link', 'advisor.signature' => 'advisor signature', 'payment.amount' => 'payment amount', 'payment.due_date' => 'payment due date', 'trip.packing_list' => 'packing list', 'client.passport_expiration' => 'passport expiration date'];
    private array $sum = []; private bool $dry; private ?int $by; private array $folderIds = [];
    public static function file(): string { return LTI_APP . '/data/revision27_library.json'; }
    public static function run(bool $dry, ?int $by): array { $i = new self(); $i->dry = $dry; $i->by = $by; return $i->go(); }
    private function count(string $type, string $what, int $n = 1): void { $this->sum[$type][$what] = ($this->sum[$type][$what] ?? 0) + $n; }
    private function exists(string $table, string $key): ?int { $r = Db::one("SELECT id FROM $table WHERE source_key=?", [$key]); return $r ? (int)$r['id'] : null; }
    private function folder(string $section, string $path, string $key): ?int {
        $full = "r27:folder:$section:$path"; if (array_key_exists($full, $this->folderIds)) return $this->folderIds[$full];
        $parts = explode('/', $path); $name = mb_substr(array_pop($parts), 0, 120); $parent = $parts ? $this->folder($section, implode('/', $parts), $key) : null;
        if ($id = $this->exists('content_folders', $full)) { $this->count('folders', 'skipped (already imported)'); return $this->folderIds[$full] = $id; }
        $this->count('folders', 'imported'); if ($this->dry) return $this->folderIds[$full] = null;
        $id = Db::insert("INSERT INTO content_folders (name, parent_id, section, created_by, created_at, updated_at, origin, source_key) VALUES (?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),?,?)", [$name, $parent, $section, $this->by, self::ORIGIN, $full]);
        return $this->folderIds[$full] = $id;
    }
    private function template(string $type, string $key, string $kind, string $title, ?string $summary, array $body, ?int $folder = null): void {
        if ($this->exists('content_templates', $key)) { $this->count($type, 'skipped (already imported)'); return; }
        $this->count($type, 'imported'); if ($this->dry) return;
        Db::exec("INSERT INTO content_templates (folder_id, kind, title, summary, body_json, tags_json, status, created_by, updated_by, created_at, updated_at, version, origin, source_key) VALUES (?,?,?,?,?,'[]','active',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1,?,?)",
            [$folder, $kind, mb_substr($title, 0, 190), $summary === null ? null : mb_substr($summary, 0, 500), json_encode($body, JSON_UNESCAPED_UNICODE), $this->by, $this->by, self::ORIGIN, $key]);
    }
    private function go(): array {
        $f = self::file(); if (!is_file($f)) throw new HttpError(404, 'Library snapshot not found');
        $raw = file_get_contents($f); $sha = hash('sha256', $raw); $s = json_decode($raw, true); $d = $s['data'] ?? null; if (!$d) throw new HttpError(422, 'Snapshot is not valid');
        Db::tx(function () use ($d) {
            foreach (array_values(array_filter($d['itineraryLibrary'][1] ?? [], fn($r) => !empty($r['f']))) as $r) $this->folder('itinerary', $r['n'], 'itinerary');
            if (empty(array_filter($d['itineraryLibrary'][1] ?? [], fn($r) => empty($r['f'])))) $this->sum['itinerary templates']['not present in prototype (folders only)'] = 0;
            foreach (($d['photoLibrary'] ?? []) as $path => $entry) foreach ($entry[0] as $row) { $full = ($path === '' ? '' : $path . '/') . $row[0];
                if ($row[1] === null) $this->folder('photos', $full, 'photos'); else $this->count('photo files', 'not migrated (file names only, no image data in prototype)'); }
            foreach (($d['libraryLists']['libta'] ?? []) as $r) if (!empty($r['f'])) $this->folder('tasks', $r['n'], 'tasks');
            $wfFolder = null; foreach (($d['libraryLists']['libwf'] ?? []) as $r) if (!empty($r['f'])) $wfFolder = $this->folder('workflows', $r['n'], 'workflows');
            foreach ($d['activities'] as $a) $this->template('activities', 'r27:act:' . $a['id'], 'activity', $a['title'], $a['desc'] ?: null, ['type' => $a['type'], 'title' => $a['title'], 'description' => $a['desc'], 'location' => $a['loc'], 'price' => $a['price'], 'supplier' => $a['supplier'], 'time' => $a['time'], 'internalNote' => $a['inote'], 'clientNote' => $a['cnote'], 'f' => $a['f'], 'sourceId' => $a['id']]);
            foreach ($d['guides'] as $g) $this->template('destination guides', 'r27:guide:' . $g['id'], 'guide', $g['title'], $g['dest'], ['destination' => $g['dest'], 'sections' => array_map(fn($x) => ['title' => $x[0], 'text' => $x[1]], $g['sec']), 'sourceId' => $g['id']]);
            foreach ($d['packing'] as $p) $this->template('packing lists', 'r27:pack:' . $p['id'], 'checklist', $p['n'], null, ['items' => array_values($p['items']), 'sourceId' => $p['id']]);
            foreach ($d['taskTemplates'] as $t) $this->template('task templates', 'r27:task:' . $t['id'], 'task', $t['n'], null, ['dueInDays' => (int)$t['days'], 'priority' => self::PRIORITY[$t['pri']] ?? 'normal', 'sourceId' => $t['id']]);
            foreach (array_values(array_filter($d['cruiseLibrary'][1] ?? [], fn($r) => empty($r['f']))) as $r) $this->template('cruise library', 'r27:cruise:' . md5($r['n']), 'supplier_info', $r['n'], ($r['c'][1] ?? null), ['type' => $r['c'][0] ?? 'Cruise', 'supplier' => $r['c'][1] ?? null]);
            foreach ($d['workflows'] as $w) $this->template('workflow references', 'r27:wf:' . $w['id'], 'workflow_reference', $w['n'], $w['desc'] ?? null, ['referenceOnly' => true, 'kind' => 'process workflow', 'category' => $w['cat'] ?? null, 'original' => $w], $wfFolder);
            foreach ($d['automationTemplates'] as $i => $a) $this->template('automation references', 'r27:autotpl:' . $i . ':' . md5($a['name']), 'workflow_reference', $a['name'], 'Automation template (' . ($a['tplOf'] ?? 'library') . ')', ['referenceOnly' => true, 'kind' => 'automation template', 'original' => $a]);
            $names = array_map(fn($w) => mb_strtolower($w['n']), $d['workflows']);
            foreach (($d['libraryLists']['libwf'] ?? []) as $r) if (empty($r['f'])) { if (in_array(mb_strtolower($r['n']), $names, true)) { $this->count('workflow library names', 'skipped (duplicate of an imported workflow)'); continue; }
                $this->template('workflow library names', 'r27:libwf:' . $r['id'], 'workflow_reference', $r['n'], 'Name only in the prototype library', ['referenceOnly' => true, 'kind' => 'library entry (name only)', 'original' => $r], $wfFolder); }
            foreach ($d['emailTemplates'] as $e) $this->email($e);
        });
        $out = ['source' => $s['source'], 'sourceSha256' => $sha, 'dryRun' => $this->dry, 'origin' => self::ORIGIN, 'summary' => $this->sum];
        if (!$this->dry || true) Db::exec('INSERT INTO library_import_runs (source, source_sha256, dry_run, run_by, summary_json, created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())', ['revision27_library', $sha, $this->dry ? 1 : 0, $this->by, json_encode($this->sum)]);
        if (!$this->dry) Audit::log($this->by, 'library.imported', 'library', null, ['source' => 'revision27', 'sha256' => $sha]);
        return $out;
    }
    private function email(array $e): void {
        $key = 'r27:etpl:' . $e['id']; if ($this->exists('email_templates', $key)) { $this->count('email templates', 'skipped (already imported)'); return; }
        $marked = []; $conv = function (string $t) use (&$marked) { return preg_replace_callback('/\{\{\s*([^{}]+?)\s*\}\}/', function ($m) use (&$marked) { if (isset(self::UNSUPPORTED[$m[1]])) { $marked[$m[1]] = 1; return '[Fill in: ' . self::UNSUPPORTED[$m[1]] . ']'; } return $m[0]; }, $t); };
        $subj = $conv((string)$e['subj']); $body = $conv((string)$e['body']);
        if ($bad = array_merge(Template::validate($subj), Template::validate($body))) { $this->count('email templates', 'not imported (unsupported placeholder: ' . implode(', ', array_unique($bad)) . ')'); return; }
        $this->count('email templates', $marked ? 'imported with [Fill in] markers' : 'imported'); if ($this->dry) return;
        Db::exec("INSERT INTO email_templates (name, category, subject, body, is_published, status, created_by, updated_by, created_at, updated_at, version, origin, source_key) VALUES (?,?,?,?,1,'active',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1,?,?)",
            [mb_substr($e['n'], 0, 190), $e['cat'] ?? null, str_replace(["\r", "\n"], ' ', $subj), $body, $this->by, $this->by, self::ORIGIN, $key]);
    }
}
