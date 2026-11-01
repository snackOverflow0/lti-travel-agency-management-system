<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Auth, Audit, Db, Http, HttpError, Template, Validate};
final class EmailTemplateController {
    private static function find(int $id): array { $r = Db::one('SELECT * FROM email_templates WHERE id=?', [$id]); if (!$r) throw new HttpError(404, 'Template not found'); return $r; }
    private static function out(array $r): array { return ['id' => (int)$r['id'], 'name' => $r['name'], 'category' => $r['category'], 'subject' => $r['subject'], 'body' => $r['body'], 'status' => $r['status'], 'version' => (int)$r['version'], 'updatedAt' => $r['updated_at'],
        'variables' => array_values(array_unique(array_merge(Template::tokens($r['subject']), Template::tokens($r['body']))))]; }
    public static function variables(): array { Auth::require('email.send'); return ['variables' => array_map(function ($k, $v) { return ['key' => $k, 'label' => $v, 'token' => '{{' . $k . '}}']; }, array_keys(Template::VARS), Template::VARS)]; }
    public static function list(): array { Auth::require('tasks.manage'); $w = []; $p = []; $st = (string)($_GET['status'] ?? 'active'); if ($st !== 'all') { Validate::enum(['s' => $st], 's', ['active', 'archived']); $w[] = 'status=?'; $p[] = $st; }
        if (($c = (string)($_GET['category'] ?? '')) !== '') { $w[] = 'category=?'; $p[] = $c; } if (($q = trim((string)($_GET['q'] ?? ''))) !== '') { $l = '%' . addcslashes($q, '%_\\') . '%'; $w[] = '(name LIKE ? OR subject LIKE ?)'; array_push($p, $l, $l); }
        return ['items' => array_map([self::class, 'out'], Db::all('SELECT * FROM email_templates ' . ($w ? 'WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY category, name', $p))]; }
    public static function get(array $pa): array { Auth::require('tasks.manage'); return ['template' => self::out(self::find((int)$pa['id']))]; }
    private static function fields(array $d, ?array $cur): array {
        $f = ['name' => Validate::str($d, 'name', 190, $cur === null) ?? $cur['name'], 'category' => array_key_exists('category', $d) ? Validate::str($d, 'category', 60) : ($cur['category'] ?? null),
            'subject' => Validate::str($d, 'subject', 255, $cur === null) ?? $cur['subject'], 'body' => Validate::str($d, 'body', 20000, $cur === null) ?? $cur['body']];
        if (preg_match('/[\r\n]/', $f['subject'])) throw new HttpError(422, Validate::MSG, ['subject' => 'The subject must be a single line']);
        $bad = array_values(array_unique(array_merge(Template::validate($f['subject']), Template::validate($f['body']))));
        if ($bad) throw new HttpError(422, 'Unknown or unsafe placeholders: ' . implode(', ', $bad), ['body' => 'Use only the listed variables']);
        return $f;
    }
    public static function create(): array { $u = Auth::require('email.templates'); $f = self::fields(Http::body(), null);
        $id = Db::insert("INSERT INTO email_templates (name, category, subject, body, is_published, status, created_by, updated_by, created_at, updated_at, version) VALUES (?,?,?,?,1,'active',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)", [$f['name'], $f['category'], $f['subject'], $f['body'], (int)$u['id'], (int)$u['id']]);
        Audit::log((int)$u['id'], 'email_template.created', 'email_template', $id); return ['template' => self::out(self::find($id))]; }
    public static function update(array $pa): array { $u = Auth::require('email.templates'); $cur = self::find((int)$pa['id']); $d = Http::body();
        if ($cur['status'] === 'archived') throw new HttpError(409, 'Archived templates cannot be edited'); if (isset($d['version']) && (int)$d['version'] !== (int)$cur['version']) throw new HttpError(409, 'This template was changed by someone else. Reload and try again.');
        $f = self::fields($d, $cur); Db::exec('UPDATE email_templates SET name=?, category=?, subject=?, body=?, updated_by=?, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?', [$f['name'], $f['category'], $f['subject'], $f['body'], (int)$u['id'], $cur['id']]);
        Audit::log((int)$u['id'], 'email_template.updated', 'email_template', $cur['id']); return ['template' => self::out(self::find((int)$cur['id']))]; }
    public static function duplicate(array $pa): array { $u = Auth::require('email.templates'); $c = self::find((int)$pa['id']);
        $id = Db::insert("INSERT INTO email_templates (name, category, subject, body, is_published, status, created_by, updated_by, duplicated_from, created_at, updated_at, version) VALUES (?,?,?,?,1,'active',?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)", [mb_substr($c['name'] . ' (copy)', 0, 190), $c['category'], $c['subject'], $c['body'], (int)$u['id'], (int)$u['id'], $c['id']]);
        Audit::log((int)$u['id'], 'email_template.duplicated', 'email_template', $id); return ['template' => self::out(self::find($id))]; }
    public static function archive(array $pa): array { $u = Auth::require('email.templates'); $c = self::find((int)$pa['id']); Db::exec("UPDATE email_templates SET status='archived', updated_by=?, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?", [(int)$u['id'], $c['id']]);
        Audit::log((int)$u['id'], 'email_template.archived', 'email_template', $c['id']); return ['template' => self::out(self::find((int)$c['id']))]; }
    public static function preview(array $pa): array { $u = Auth::require('email.send'); $t = self::find((int)$pa['id']); $d = Http::body(); $ids = EmailController::linkIds($u, $d);
        $ctx = Template::context($ids['contact_id'], $ids['trip_id'], $ids['booking_id'], $ids['invoice_id']); [$s, $m1] = Template::render($t['subject'], $ctx); [$b, $m2] = Template::render($t['body'], $ctx);
        return ['subject' => $s, 'body' => $b, 'missing' => array_values(array_unique(array_merge($m1, $m2))), 'note' => 'Preview only. Missing values must be filled before the message can be queued.']; }
}
