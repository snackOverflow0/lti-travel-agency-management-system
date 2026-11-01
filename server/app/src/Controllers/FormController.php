<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Auth, Audit, Db, Http, HttpError, Validate};
final class FormController {
    const TYPES = ['text', 'textarea', 'email', 'phone', 'date', 'number', 'select', 'radio', 'checkbox', 'file'];
    private static function find(int $id): array { $r = Db::one('SELECT * FROM forms WHERE id=?', [$id]); if (!$r) throw new HttpError(404, 'Form not found'); return $r; }
    private static function fieldsOf(int $fid): array {
        return array_map(function ($r) { return ['id' => 'f' . $r['id'], 'key' => $r['field_key'], 'label' => $r['label'], 'type' => $r['field_type'], 'required' => (bool)$r['is_required'], 'options' => $r['options_json'] ? json_decode($r['options_json'], true) : [], 'showIf' => (string)$r['show_if'], 'step' => (int)$r['step']]; },
            Db::all('SELECT * FROM form_fields WHERE form_id=? ORDER BY sort_order, id', [$fid]));
    }
    private static function out(array $r, bool $withFields = true): array {
        $o = ['id' => (int)$r['id'], 'name' => $r['name'], 'category' => $r['category'], 'status' => $r['status'], 'token' => $r['public_token'], 'steps' => $r['steps_json'] ? json_decode($r['steps_json'], true) : ['Step 1'],
            'settings' => $r['settings_json'] ? json_decode($r['settings_json'], true) : (object)[], 'version' => (int)$r['version'], 'publishedAt' => $r['published_at'], 'updatedAt' => $r['updated_at'],
            'submissionCount' => (int)Db::one('SELECT COUNT(*) n FROM form_submissions WHERE form_id=?', [$r['id']])['n']];
        if ($withFields) $o['fields'] = self::fieldsOf((int)$r['id']);
        return $o;
    }
    public static function list(): array { Auth::require('forms.manage'); $st = (string)($_GET['status'] ?? ''); $p = []; $w = "status<>'archived'"; if ($st !== '') { Validate::enum(['s' => $st], 's', ['draft', 'published', 'archived']); $w = 'status=?'; $p[] = $st; }
        return ['items' => array_map(function ($r) { return self::out($r, true); }, Db::all("SELECT * FROM forms WHERE $w ORDER BY name", $p))]; }
    public static function get(array $pa): array { Auth::require('forms.manage'); return ['form' => self::out(self::find((int)$pa['id']))]; }
    private static function saveFields(int $formId, array $fields): void {
        $own = array_map('intval', array_column(Db::all('SELECT id FROM form_fields WHERE form_id=?', [$formId]), 'id')); $keep = []; $keys = [];
        if (count($fields) > 100) throw new HttpError(422, Validate::MSG, ['fields' => 'Maximum 100 fields']);
        foreach (array_values($fields) as $i => $f) {
            $label = Validate::str($f, 'label', 190, true); $type = in_array($f['type'] ?? '', self::TYPES, true) ? $f['type'] : null;
            if ($type === null) throw new HttpError(422, Validate::MSG, ['fields' => "Field “{$label}” has an unsupported type"]);
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($f['key'] ?? ''))) ?: 'field_' . ($i + 1); if (isset($keys[$key])) $key .= '_' . ($i + 1); $keys[$key] = 1;
            $opts = in_array($type, ['select', 'radio', 'checkbox'], true) ? array_values(array_filter(array_map(function ($x) { return mb_substr(trim((string)$x), 0, 120); }, is_array($f['options'] ?? null) ? $f['options'] : explode(',', (string)($f['options'] ?? ''))))) : [];
            if (in_array($type, ['select', 'radio'], true) && !$opts) throw new HttpError(422, Validate::MSG, ['fields' => "Add options to “{$label}”"]);
            $vals = [$key, $label, $type, !empty($f['required']) ? 1 : 0, json_encode($opts, JSON_UNESCAPED_UNICODE), mb_substr((string)($f['showIf'] ?? ''), 0, 190), max(1, min(20, (int)($f['step'] ?? 1))), $i];
            $id = preg_match('/^f(\d+)$/', (string)($f['id'] ?? ''), $m) && in_array((int)$m[1], $own, true) ? (int)$m[1] : 0;
            if ($id) Db::exec('UPDATE form_fields SET field_key=?, label=?, field_type=?, is_required=?, options_json=?, show_if=?, step=?, sort_order=? WHERE id=?', array_merge($vals, [$id]));
            else $id = Db::insert('INSERT INTO form_fields (field_key,label,field_type,is_required,options_json,show_if,step,sort_order,form_id) VALUES (?,?,?,?,?,?,?,?,?)', array_merge($vals, [$formId]));
            $keep[] = $id;
        }
        $gone = array_diff($own, $keep); if ($gone) Db::exec('DELETE FROM form_fields WHERE id IN (' . implode(',', $gone) . ')');
    }
    private static function meta(array $d, ?array $cur): array {
        $steps = is_array($d['steps'] ?? null) ? array_slice(array_values(array_map(function ($s) { return mb_substr(trim((string)$s), 0, 80) ?: 'Step'; }, $d['steps'])), 0, 20) : ($cur && $cur['steps_json'] ? json_decode($cur['steps_json'], true) : ['Step 1']);
        $st = is_array($d['settings'] ?? null) ? ['createContact' => !empty($d['settings']['createContact']), 'createTrip' => !empty($d['settings']['createTrip'])] : ($cur && $cur['settings_json'] ? json_decode($cur['settings_json'], true) : ['createContact' => false, 'createTrip' => false]);
        return ['name' => Validate::str($d, 'name', 190, $cur === null) ?? $cur['name'], 'category' => Validate::str($d, 'category', 60), 'steps_json' => json_encode($steps ?: ['Step 1'], JSON_UNESCAPED_UNICODE), 'settings_json' => json_encode($st)];
    }
    public static function create(): array {
        $u = Auth::require('forms.manage'); $d = Http::body(); $m = self::meta($d, null);
        $id = Db::tx(function () use ($m, $d, $u) { $id = Db::insert("INSERT INTO forms (name,category,steps_json,settings_json,definition_json,public_token,status,created_by,created_at,updated_at,version) VALUES (?,?,?,?,NULL,?,'draft',?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)", [$m['name'], $m['category'], $m['steps_json'], $m['settings_json'], bin2hex(random_bytes(16)), (int)$u['id']]);
            self::saveFields($id, is_array($d['fields'] ?? null) ? $d['fields'] : []); Audit::log((int)$u['id'], 'form.created', 'form', $id); return $id; });
        return ['form' => self::out(self::find($id))];
    }
    public static function update(array $pa): array {
        $u = Auth::require('forms.manage'); $cur = self::find((int)$pa['id']); $d = Http::body();
        if ($cur['status'] === 'archived') throw new HttpError(409, 'Archived forms cannot be edited');
        if (isset($d['version']) && (int)$d['version'] !== (int)$cur['version']) throw new HttpError(409, 'This form was changed by someone else. Reload and try again.');
        $m = self::meta($d, $cur);
        Db::tx(function () use ($m, $d, $cur, $u) { Db::exec('UPDATE forms SET name=?, category=?, steps_json=?, settings_json=?, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?', [$m['name'], $m['category'], $m['steps_json'], $m['settings_json'], $cur['id']]);
            if (isset($d['fields']) && is_array($d['fields'])) self::saveFields((int)$cur['id'], $d['fields']); Audit::log((int)$u['id'], 'form.updated', 'form', $cur['id']); });
        return ['form' => self::out(self::find((int)$cur['id']))];
    }
    public static function setStatus(array $pa, string $status): array {
        $u = Auth::require('forms.manage'); $cur = self::find((int)$pa['id']);
        if ($status === 'published' && !Db::one('SELECT id FROM form_fields WHERE form_id=? LIMIT 1', [$cur['id']])) throw new HttpError(422, 'Add at least one field before publishing');
        Db::exec('UPDATE forms SET status=?, published_at=' . ($status === 'published' ? 'UTC_TIMESTAMP()' : 'published_at') . ', updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?', [$status, $cur['id']]);
        Audit::log((int)$u['id'], 'form.' . $status, 'form', $cur['id']);
        return ['form' => self::out(self::find((int)$cur['id']))];
    }
    public static function duplicate(array $pa): array {
        $u = Auth::require('forms.manage'); $cur = self::find((int)$pa['id']); $f = self::fieldsOf((int)$cur['id']); foreach ($f as &$x) unset($x['id']); unset($x);
        $id = Db::tx(function () use ($cur, $f, $u) { $id = Db::insert("INSERT INTO forms (name,category,steps_json,settings_json,public_token,status,created_by,created_at,updated_at,version) VALUES (?,?,?,?,?,'draft',?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)", [mb_substr($cur['name'] . ' (copy)', 0, 190), $cur['category'], $cur['steps_json'], $cur['settings_json'], bin2hex(random_bytes(16)), (int)$u['id']]);
            self::saveFields($id, $f); Audit::log((int)$u['id'], 'form.duplicated', 'form', $id, ['from' => (int)$cur['id']]); return $id; });
        return ['form' => self::out(self::find($id))];
    }
    /** Validate answers against field definitions, honouring conditional fields (show_if "key=value"). */
    private static function validateAnswers(array $fields, array $data): array {
        $clean = []; $errors = [];
        $visible = function ($f) use ($data) { if (!$f['showIf']) return true; [$k, $v] = array_pad(explode('=', $f['showIf'], 2), 2, ''); return (string)($data[$k] ?? '') === $v; };
        foreach ($fields as $f) {
            if (!$visible($f)) continue; if ($f['type'] === 'file') continue;   // file answers are not accepted in Phase 4 (see report)
            $v = $data[$f['key']] ?? null; if (is_array($v)) $v = implode(', ', array_map('strval', $v)); $v = $v === null ? '' : trim((string)$v);
            if ($v === '' || ($f['type'] === 'checkbox' && !$f['options'] && $v === 'false')) { if ($f['required']) $errors[$f['key']] = 'Required'; continue; }
            if (mb_strlen($v) > 5000) { $errors[$f['key']] = 'Too long'; continue; }
            if ($f['type'] === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) $errors[$f['key']] = 'Enter a valid email';
            if ($f['type'] === 'number' && !is_numeric($v)) $errors[$f['key']] = 'Enter a number';
            if ($f['type'] === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) $errors[$f['key']] = 'Use YYYY-MM-DD';
            if ($f['type'] === 'phone' && !preg_match('/^[0-9+().\-\s]{5,40}$/', $v)) $errors[$f['key']] = 'Enter a valid phone number';
            if (in_array($f['type'], ['select', 'radio'], true) && !in_array($v, $f['options'], true)) $errors[$f['key']] = 'Choose one of the options';
            $clean[$f['key']] = ['id' => (int)substr($f['id'], 1), 'value' => $v];
        }
        if ($errors) throw new HttpError(422, 'Please correct the highlighted fields', $errors);
        return $clean;
    }
    private static function store(array $form, array $clean, string $source, ?int $uid, ?int $contactId, ?int $tripId): int {
        return Db::tx(function () use ($form, $clean, $source, $uid, $contactId, $tripId) {
            $st = $form['settings_json'] ? json_decode($form['settings_json'], true) : [];
            $v = function ($k) use ($clean) { return isset($clean[$k]) ? $clean[$k]['value'] : null; };
            if (!$contactId && !empty($st['createContact'])) {
                $email = null; foreach ($clean as $k => $a) if (filter_var($a['value'], FILTER_VALIDATE_EMAIL)) { $email = mb_strtolower($a['value']); break; }
                $ex = $email ? Db::one('SELECT id FROM contacts WHERE email=? AND deleted_at IS NULL', [$email]) : null;
                $contactId = $ex ? (int)$ex['id'] : Db::insert("INSERT INTO contacts (first_name,last_name,email,contact_type,lead_status,source,created_by,created_at,updated_at) VALUES (?,?,?,'lead','new',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                    [mb_substr($v('first') ?: $v('name') ?: 'New lead', 0, 100), mb_substr((string)$v('last'), 0, 100) ?: null, $email, mb_substr('Form: ' . $form['name'], 0, 100), $uid]);
                if (!$tripId && !empty($st['createTrip'])) { $tripId = Db::insert("INSERT INTO trips (name,status,trip_type,destination,primary_contact_id,created_by,tags_json,created_at,updated_at,version) VALUES (?, 'Inquiry','standard',?,?,?,'[]',UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)", [mb_substr(($v('dest') ?: 'New inquiry') . ' — ' . ($v('first') ?: 'Lead'), 0, 190), mb_substr((string)$v('dest'), 0, 190) ?: null, $contactId, $uid]);
                    Db::exec("INSERT INTO trip_travelers (trip_id,contact_id,permission) VALUES (?,?,'primary')", [$tripId, $contactId]); $oid = Db::insert('INSERT INTO itinerary_options (trip_id,name,sort_order) VALUES (?,?,0)', [$tripId, 'Itinerary']); Db::exec('INSERT INTO itinerary_days (option_id,title,sort_order) VALUES (?,?,0)', [$oid, 'Day 1']); }
            }
            $sid = Db::insert('INSERT INTO form_submissions (form_id,data_json,contact_id,trip_id,status,submitted_by,ip,source,created_at) VALUES (?,?,?,?,\'new\',?,?,?,UTC_TIMESTAMP())', [$form['id'], json_encode(array_map(function ($a) { return $a['value']; }, $clean), JSON_UNESCAPED_UNICODE), $contactId, $tripId, $uid, Http::ip(), $source]);
            foreach ($clean as $k => $a) Db::exec('INSERT INTO form_answers (submission_id, field_id, field_key, value) VALUES (?,?,?,?)', [$sid, $a['id'], $k, $a['value']]);
            Audit::log($uid, 'form.submitted', 'form', $form['id'], ['submission' => $sid, 'source' => $source]);
            return $sid;
        });
    }
    public static function staffSubmit(array $pa): array {
        $u = Auth::require('forms.manage'); $f = self::find((int)$pa['id']); if ($f['status'] === 'archived') throw new HttpError(409, 'Archived forms do not accept responses');
        $d = Http::body(); $contact = !empty($d['contactId']) ? (int)ContactController::find($u, (int)$d['contactId'])['id'] : null; $trip = !empty($d['tripId']) ? (int)TripController::findRow($u, (int)$d['tripId'])['id'] : null;
        $sid = self::store($f, self::validateAnswers(self::fieldsOf((int)$f['id']), is_array($d['answers'] ?? null) ? $d['answers'] : []), 'staff', (int)$u['id'], $contact, $trip);
        return ['submissionId' => $sid];
    }
    public static function publicGet(array $pa): array {
        $f = Db::one("SELECT * FROM forms WHERE public_token=? AND status='published'", [$pa['token']]); if (!$f) throw new HttpError(404, 'This form is not available');
        return ['form' => ['name' => $f['name'], 'steps' => json_decode($f['steps_json'] ?: '["Step 1"]', true), 'fields' => array_values(array_filter(self::fieldsOf((int)$f['id']), function ($x) { return $x['type'] !== 'file'; }))]];
    }
    public static function publicSubmit(array $pa): array {
        $f = Db::one("SELECT * FROM forms WHERE public_token=? AND status='published'", [$pa['token']]); if (!$f) throw new HttpError(404, 'This form is not available');
        $n = (int)Db::one('SELECT COUNT(*) n FROM form_submissions WHERE ip=? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR', [Http::ip()])['n'];
        if ($n >= 20) throw new HttpError(429, 'Too many submissions from this network. Please try again later.');
        $d = Http::body(); if (!empty($d['website'])) return ['ok' => true];   // honeypot: silently drop bots
        $sid = self::store($f, self::validateAnswers(self::fieldsOf((int)$f['id']), is_array($d['answers'] ?? null) ? $d['answers'] : []), 'public', null, null, null);
        return ['ok' => true, 'submissionId' => $sid];
    }
    public static function submissions(array $pa): array {
        Auth::require('forms.manage'); $f = self::find((int)$pa['id']); $page = max(1, (int)($_GET['page'] ?? 1)); $per = min(200, max(1, (int)($_GET['perPage'] ?? 50)));
        $rows = Db::all("SELECT s.*, CONCAT_WS(' ', c.first_name, c.last_name) contact_name, t.name trip_name FROM form_submissions s LEFT JOIN contacts c ON c.id=s.contact_id LEFT JOIN trips t ON t.id=s.trip_id WHERE s.form_id=? ORDER BY s.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), [$f['id']]);
        return ['total' => (int)Db::one('SELECT COUNT(*) n FROM form_submissions WHERE form_id=?', [$f['id']])['n'], 'items' => array_map(function ($r) { return ['id' => (int)$r['id'], 'createdAt' => $r['created_at'], 'status' => $r['status'], 'source' => $r['source'],
            'contactId' => $r['contact_id'] === null ? null : (int)$r['contact_id'], 'contactName' => $r['contact_name'] ?: null, 'tripId' => $r['trip_id'] === null ? null : (int)$r['trip_id'], 'tripName' => $r['trip_name'], 'answers' => json_decode($r['data_json'], true)]; }, $rows)];
    }
    public static function updateSubmission(array $pa): array {
        $u = Auth::require('forms.manage'); $s = Db::one('SELECT * FROM form_submissions WHERE id=?', [(int)$pa['id']]); if (!$s) throw new HttpError(404, 'Submission not found'); $d = Http::body();
        $contact = array_key_exists('contactId', $d) ? ($d['contactId'] ? (int)ContactController::find($u, (int)$d['contactId'])['id'] : null) : $s['contact_id'];
        $trip = array_key_exists('tripId', $d) ? ($d['tripId'] ? (int)TripController::findRow($u, (int)$d['tripId'])['id'] : null) : $s['trip_id'];
        Db::exec('UPDATE form_submissions SET contact_id=?, trip_id=?, status=? WHERE id=?', [$contact, $trip, Validate::enum($d, 'status', ['new', 'reviewed'], $s['status']), $s['id']]);
        Audit::log((int)$u['id'], 'form.submission_updated', 'form', $s['form_id'], ['submission' => (int)$s['id']]);
        return ['ok' => true];
    }
    public static function exportCsv(array $pa): void {
        Auth::require('forms.manage'); $f = self::find((int)$pa['id']); $fields = self::fieldsOf((int)$f['id']);
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="form-' . (int)$f['id'] . '-submissions.csv"'); header('Cache-Control: no-store');
        $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
        $cell = function ($v) { $v = (string)$v; return preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v; };   // spreadsheet formula injection guard
        fputcsv($out, array_merge(['Submitted (UTC)', 'Source', 'Contact', 'Trip'], array_column($fields, 'label')));
        foreach (Db::all("SELECT s.*, CONCAT_WS(' ', c.first_name, c.last_name) cn, t.name tn FROM form_submissions s LEFT JOIN contacts c ON c.id=s.contact_id LEFT JOIN trips t ON t.id=s.trip_id WHERE s.form_id=? ORDER BY s.id", [$f['id']]) as $r) {
            $a = json_decode($r['data_json'], true) ?: []; fputcsv($out, array_map($cell, array_merge([$r['created_at'], $r['source'], $r['cn'], $r['tn']], array_map(function ($x) use ($a) { return $a[$x['key']] ?? ''; }, $fields))));
        }
        fclose($out); Audit::log((int)Auth::user()['id'], 'form.exported', 'form', $f['id']); exit;
    }
}
