<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Auth, Audit, Db, Http, HttpError, Validate, Events};
final class ContactController {
    const TYPES = ['client', 'lead', 'traveler', 'supplier_contact'];
    const LEAD = ['new', 'contacted', 'qualified', 'quoted', 'won', 'lost'];
    /** Row-level scope: contacts.view_all sees everything; others only contacts assigned to or created by them. */
    public static function scope(array $u): array {
        if (in_array('contacts.view_all', $u['permissions'], true)) return ['1=1', []];
        return ['(c.assigned_to = ? OR c.created_by = ?)', [(int)$u['id'], (int)$u['id']]];
    }
    public static function out(array $r): array {
        return ['id' => (int)$r['id'], 'firstName' => $r['first_name'], 'lastName' => $r['last_name'], 'email' => $r['email'], 'phone' => $r['phone'],
            'type' => $r['contact_type'], 'leadStatus' => $r['lead_status'], 'source' => $r['source'], 'birthday' => $r['birthday'], 'anniversary' => $r['anniversary'],
            'passportExpiry' => $r['passport_expiry'], 'notes' => $r['notes'], 'assignedTo' => $r['assigned_to'] === null ? null : (int)$r['assigned_to'],
            'assignedName' => $r['assigned_name'] ?? null, 'createdAt' => $r['created_at'], 'updatedAt' => $r['updated_at'], 'version' => (int)$r['version']];
    }
    public static function find(array $u, int $id): array {
        [$w, $p] = self::scope($u);
        $r = Db::one("SELECT c.*, a.full_name assigned_name FROM contacts c LEFT JOIN users a ON a.id=c.assigned_to WHERE c.id=? AND c.deleted_at IS NULL AND $w", array_merge([$id], $p));
        if (!$r) throw new HttpError(404, 'Contact not found');   // identical response for missing and out-of-scope records
        return $r;
    }
    public static function list(): array {
        $u = Auth::require('contacts.view');
        [$w, $p] = self::scope($u);
        $q = trim((string)($_GET['q'] ?? '')); $type = (string)($_GET['type'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1)); $per = min(100, max(1, (int)($_GET['perPage'] ?? 25)));
        $where = ['c.deleted_at IS NULL', $w];
        if ($q !== '') { $where[] = "(c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR CONCAT(c.first_name,' ',COALESCE(c.last_name,'')) LIKE ?)"; $like = '%' . addcslashes($q, '%_\\') . '%'; array_push($p, $like, $like, $like, $like, $like); }
        if ($type !== '') { Validate::enum(['t' => $type], 't', self::TYPES); $where[] = 'c.contact_type = ?'; $p[] = $type; }
        $sqlW = implode(' AND ', $where);
        $total = (int)Db::one("SELECT COUNT(*) n FROM contacts c WHERE $sqlW", $p)['n'];
        $rows = Db::all("SELECT c.*, a.full_name assigned_name FROM contacts c LEFT JOIN users a ON a.id=c.assigned_to WHERE $sqlW ORDER BY c.last_name, c.first_name, c.id LIMIT $per OFFSET " . (($page - 1) * $per), $p);
        return ['items' => array_map([self::class, 'out'], $rows), 'total' => $total, 'page' => $page, 'perPage' => $per];
    }
    public static function get(array $pa): array { $u = Auth::require('contacts.view'); return ['contact' => self::out(self::find($u, (int)$pa['id']))]; }
    private static function fields(array $d): array {
        $f = ['first_name' => Validate::str($d, 'firstName', 100, true), 'last_name' => Validate::str($d, 'lastName', 100),
            'email' => Validate::email($d, 'email'), 'phone' => Validate::str($d, 'phone', 40),
            'contact_type' => Validate::enum($d, 'type', self::TYPES, 'client'), 'lead_status' => Validate::enum($d, 'leadStatus', self::LEAD),
            'source' => Validate::str($d, 'source', 100), 'birthday' => Validate::date($d, 'birthday'), 'anniversary' => Validate::date($d, 'anniversary'),
            'passport_expiry' => Validate::date($d, 'passportExpiry'), 'notes' => Validate::str($d, 'notes', 5000)];
        if ($f['phone'] !== null && !preg_match('/^[0-9+().\-\s]{5,40}$/', $f['phone'])) throw new HttpError(422, Validate::MSG, ['phone' => 'Use digits, spaces and + ( ) - only']);
        return $f;
    }
    private static function assignee(array $u, array $d): ?int {
        if (!array_key_exists('assignedTo', $d) || $d['assignedTo'] === null || $d['assignedTo'] === '') return null;
        $id = (int)$d['assignedTo'];
        if (!Db::one('SELECT id FROM users WHERE id=? AND is_active=1', [$id])) throw new HttpError(422, Validate::MSG, ['assignedTo' => 'Unknown staff member']);
        if ($id !== (int)$u['id'] && !Auth::can('contacts.assign')) throw new HttpError(403, 'Your role cannot assign contacts to other staff');
        return $id;
    }
    public static function create(): array {
        $u = Auth::require('contacts.create'); $d = Http::body(); $f = self::fields($d);
        $assigned = self::assignee($u, $d) ?? (int)$u['id'];
        if ($f['email'] && empty($d['allowDuplicate']) && Db::one('SELECT id FROM contacts WHERE email=? AND deleted_at IS NULL', [$f['email']]))
            throw new HttpError(409, 'A contact with this email already exists', ['email' => 'Duplicate email']);
        $id = Db::tx(function () use ($f, $u, $assigned) {
            $cols = array_keys($f);
            $id = Db::insert('INSERT INTO contacts (' . implode(',', $cols) . ', assigned_to, created_by, created_at, updated_at, version) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)', array_merge(array_values($f), [$assigned, (int)$u['id']]));
            Audit::log((int)$u['id'], 'contact.created', 'contact', $id, ['name' => trim($f['first_name'] . ' ' . ($f['last_name'] ?? ''))]);
            Events::emit('contact.created', 'contact', $id, (int)$u['id'], 'Contact created');
            return $id;
        });
        return ['contact' => self::out(self::find($u, $id))];
    }
    public static function update(array $pa): array {
        $u = Auth::require('contacts.edit'); $cur = self::find($u, (int)$pa['id']); $d = Http::body(); $f = self::fields($d);
        if (isset($d['version']) && (int)$d['version'] !== (int)$cur['version']) throw new HttpError(409, 'This contact was changed by someone else. Reload and try again.');
        $assigned = array_key_exists('assignedTo', $d) ? self::assignee($u, $d) : ($cur['assigned_to'] === null ? null : (int)$cur['assigned_to']);
        $sets = implode(',', array_map(function ($c) { return "$c=?"; }, array_keys($f)));
        Db::exec("UPDATE contacts SET $sets, assigned_to=?, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?", array_merge(array_values($f), [$assigned, (int)$cur['id']]));
        $changed = array_keys(array_filter($f, function ($v, $k) use ($cur) { return (string)$v !== (string)$cur[$k]; }, ARRAY_FILTER_USE_BOTH));
        Audit::log((int)$u['id'], 'contact.updated', 'contact', $cur['id'], ['fields' => $changed]);
        if ($changed) Events::emit('contact.updated', 'contact', (int)$cur['id'], (int)$u['id'], 'Contact updated: ' . implode(', ', $changed), ['fields' => $changed]);
        return ['contact' => self::out(self::find($u, (int)$cur['id']))];
    }
    public static function delete(array $pa): array {
        $u = Auth::require('contacts.delete'); $cur = self::find($u, (int)$pa['id']);
        Db::exec('UPDATE contacts SET deleted_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?', [$cur['id']]);
        Audit::log((int)$u['id'], 'contact.deleted', 'contact', $cur['id']);
        return ['ok' => true];
    }
    public static function staffOptions(): array { Auth::require('contacts.view'); return ['items' => array_map(function ($r) { return ['id' => (int)$r['id'], 'fullName' => $r['full_name']]; }, Db::all('SELECT id, full_name FROM users WHERE is_active=1 AND role_id <> (SELECT id FROM roles WHERE code=\'traveler\') ORDER BY full_name'))]; }
}
