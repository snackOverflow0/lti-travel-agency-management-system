<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Auth, Audit, Db, Events, Http, HttpError, Jobs, Mailer, Template, Validate};
/** Outbound email records. Status meanings: draft, queued, sending, accepted (by the SMTP server — not proof of delivery),
 *  failed, unknown (outcome unclear; needs review, never resent automatically), cancelled. There is no simulated inbox. */
final class EmailController {
    private static function scope(array $u): array { [$cw, $cp] = ContactController::scope($u); [$tw, $tp] = TripController::scope($u);
        return ["(e.created_by=? OR (e.contact_id IS NOT NULL AND EXISTS (SELECT 1 FROM contacts c WHERE c.id=e.contact_id AND $cw)) OR (e.trip_id IS NOT NULL AND EXISTS (SELECT 1 FROM trips t WHERE t.id=e.trip_id AND $tw)))", array_merge([(int)$u['id']], $cp, $tp)]; }
    private static function find(array $u, int $id): array { [$w, $p] = self::scope($u); $r = Db::one("SELECT e.*, u.full_name creator FROM emails e LEFT JOIN users u ON u.id=e.created_by WHERE e.id=? AND $w", array_merge([$id], $p)); if (!$r) throw new HttpError(404, 'Email not found'); return $r; }
    private static function out(array $r, bool $full = false): array {
        $label = ['draft' => 'Draft', 'queued' => 'Queued', 'sending' => 'Sending', 'accepted' => 'Accepted by mail server', 'failed' => 'Failed', 'unknown' => 'Outcome unknown — needs review', 'cancelled' => 'Cancelled'];
        $o = ['id' => (int)$r['id'], 'status' => $r['status'], 'statusLabel' => $label[$r['status']] ?? $r['status'], 'to' => $r['to_addr'], 'subject' => $r['subject'], 'contactId' => $r['contact_id'] === null ? null : (int)$r['contact_id'],
            'tripId' => $r['trip_id'] === null ? null : (int)$r['trip_id'], 'bookingId' => $r['booking_id'] === null ? null : (int)$r['booking_id'], 'invoiceId' => $r['invoice_id'] === null ? null : (int)$r['invoice_id'], 'templateId' => $r['template_id'] === null ? null : (int)$r['template_id'],
            'fromWorkflow' => $r['workflow_execution_id'] !== null, 'attempts' => (int)$r['attempts'], 'acceptedAt' => $r['accepted_at'], 'error' => $r['error'], 'createdBy' => $r['creator'] ?? null, 'createdAt' => $r['created_at'], 'version' => (int)$r['version'],
            'deliveryEvidence' => 'Not tracked: “accepted” means the SMTP server took the message; delivery and inbox placement are not verified.'];
        if ($full) { $o['body'] = $r['body']; $o['attemptsLog'] = array_map(function ($a) { return ['attempt' => (int)$a['attempt_no'], 'startedAt' => $a['started_at'], 'finishedAt' => $a['finished_at'], 'result' => $a['result'], 'response' => $a['response']]; }, Db::all('SELECT * FROM email_send_attempts WHERE email_id=? ORDER BY attempt_no', [$r['id']])); }
        return $o;
    }
    public static function linkIds(array $u, array $d): array {
        $o = ['contact_id' => null, 'trip_id' => null, 'booking_id' => null, 'invoice_id' => null];
        if (!empty($d['contactId'])) $o['contact_id'] = (int)ContactController::find($u, (int)$d['contactId'])['id'];
        if (!empty($d['tripId'])) $o['trip_id'] = (int)TripController::findRow($u, (int)$d['tripId'])['id'];
        if (!empty($d['bookingId'])) { $b = Db::one('SELECT id, trip_id FROM bookings WHERE id=?', [(int)$d['bookingId']]); if (!$b) throw new HttpError(422, Validate::MSG, ['bookingId' => 'Unknown booking']); TripController::findRow($u, (int)$b['trip_id']); $o['booking_id'] = (int)$b['id']; }
        if (!empty($d['invoiceId'])) { Auth::require('finance.view'); $o['invoice_id'] = (int)InvoiceController::find($u, (int)$d['invoiceId'])['id']; }
        return $o;
    }
    public static function list(): array {
        $u = Auth::require('email.send'); [$w, $p] = self::scope($u); $where = [$w];
        if (($s = (string)($_GET['status'] ?? '')) !== '') { Validate::enum(['s' => $s], 's', ['draft', 'queued', 'sending', 'accepted', 'failed', 'unknown', 'cancelled']); $where[] = 'e.status=?'; $p[] = $s; }
        foreach (['contactId' => 'e.contact_id', 'tripId' => 'e.trip_id', 'bookingId' => 'e.booking_id'] as $k => $c) if (($v = (int)($_GET[$k] ?? 0)) > 0) { $where[] = "$c=?"; $p[] = $v; }
        if (($q = trim((string)($_GET['q'] ?? ''))) !== '') { $l = '%' . addcslashes($q, '%_\\') . '%'; $where[] = '(e.subject LIKE ? OR e.to_addr LIKE ?)'; array_push($p, $l, $l); }
        $page = max(1, (int)($_GET['page'] ?? 1)); $per = min(200, max(1, (int)($_GET['perPage'] ?? 50))); $sql = implode(' AND ', $where);
        return ['transport' => Mailer::status() + ['password' => null], 'total' => (int)Db::one("SELECT COUNT(*) n FROM emails e WHERE $sql", $p)['n'],
            'items' => array_map([self::class, 'out'], Db::all("SELECT e.*, u.full_name creator FROM emails e LEFT JOIN users u ON u.id=e.created_by WHERE $sql ORDER BY e.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p))];
    }
    public static function get(array $pa): array { $u = Auth::require('email.send'); return ['email' => self::out(self::find($u, (int)$pa['id']), true)]; }
    private static function fields(array $u, array $d, ?array $cur): array {
        if ($cur) $d = array_merge(['contactId' => $cur['contact_id'], 'tripId' => $cur['trip_id'], 'bookingId' => $cur['booking_id'], 'invoiceId' => $cur['invoice_id'], 'to' => $cur['to_addr'], 'subject' => $cur['subject'], 'body' => $cur['body'], 'templateId' => $cur['template_id']], $d);
        $ids = self::linkIds($u, $d); $to = Validate::str($d, 'to', 500);
        if (!$to && $ids['contact_id']) $to = (string)(Db::one('SELECT email FROM contacts WHERE id=?', [$ids['contact_id']])['email'] ?? '');
        if ($to) foreach (explode(',', $to) as $a) if (!filter_var(trim($a), FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $a)) throw new HttpError(422, Validate::MSG, ['to' => 'Enter valid email addresses separated by commas']);
        if ($to && count(explode(',', $to)) > 10) throw new HttpError(422, Validate::MSG, ['to' => 'At most 10 recipients']);
        $tpl = null; $subject = Validate::str($d, 'subject', 255); $body = Validate::str($d, 'body', 20000);
        if (!empty($d['templateId'])) { $tpl = Db::one("SELECT * FROM email_templates WHERE id=? AND status='active'", [(int)$d['templateId']]); if (!$tpl) throw new HttpError(422, Validate::MSG, ['templateId' => 'Unknown or archived template']); $subject = $subject ?? $tpl['subject']; $body = $body ?? $tpl['body']; }
        if ($subject !== null && preg_match('/[\r\n]/', $subject)) throw new HttpError(422, Validate::MSG, ['subject' => 'The subject must be a single line']);
        foreach (['subject' => $subject, 'body' => $body] as $k => $v) if ($v !== null && ($bad = Template::validate($v))) throw new HttpError(422, 'Unknown or unsafe placeholders: ' . implode(', ', $bad), [$k => 'Use only the listed variables']);
        return $ids + ['to_addr' => $to ?: null, 'subject' => $subject, 'body' => $body, 'template_id' => $tpl ? (int)$tpl['id'] : null];
    }
    public static function create(): array { $u = Auth::require('email.send'); $f = self::fields($u, Http::body(), null);
        $id = Db::insert("INSERT INTO emails (direction, status, to_addr, subject, body, contact_id, trip_id, booking_id, invoice_id, template_id, created_by, created_at, updated_at, version) VALUES ('outbound','draft',?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)",
            [$f['to_addr'], $f['subject'], $f['body'], $f['contact_id'], $f['trip_id'], $f['booking_id'], $f['invoice_id'], $f['template_id'], (int)$u['id']]); return ['email' => self::out(self::find($u, $id), true)]; }
    public static function update(array $pa): array { $u = Auth::require('email.send'); $cur = self::find($u, (int)$pa['id']); $d = Http::body(); if ($cur['status'] !== 'draft') throw new HttpError(409, 'Only drafts can be edited');
        if (isset($d['version']) && (int)$d['version'] !== (int)$cur['version']) throw new HttpError(409, 'This draft was changed elsewhere. Reload and try again.'); $f = self::fields($u, $d, $cur);
        Db::exec('UPDATE emails SET to_addr=?, subject=?, body=?, contact_id=?, trip_id=?, booking_id=?, invoice_id=?, template_id=?, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?', [$f['to_addr'], $f['subject'], $f['body'], $f['contact_id'], $f['trip_id'], $f['booking_id'], $f['invoice_id'], $f['template_id'], $cur['id']]);
        return ['email' => self::out(self::find($u, (int)$cur['id']), true)]; }
    /** Render placeholders, freeze the final text and queue a single send job (dedupe per message). */
    public static function queue(array $pa): array {
        $u = Auth::require('email.send');
        Db::tx(function () use ($u, $pa) { $e = self::find($u, (int)$pa['id']); if ($e['status'] !== 'draft') throw new HttpError(409, 'Only drafts can be queued');
            if (!$e['to_addr']) throw new HttpError(422, Validate::MSG, ['to' => 'Add a recipient']); if (!$e['subject'] || !$e['body']) throw new HttpError(422, 'Add a subject and a message');
            $ctx = Template::context($e['contact_id'] ? (int)$e['contact_id'] : null, $e['trip_id'] ? (int)$e['trip_id'] : null, $e['booking_id'] ? (int)$e['booking_id'] : null, $e['invoice_id'] ? (int)$e['invoice_id'] : null);
            [$s, $m1] = Template::render($e['subject'], $ctx); [$b, $m2] = Template::render($e['body'], $ctx); if ($m = array_values(array_unique(array_merge($m1, $m2)))) throw new HttpError(422, 'Missing values for: ' . implode(', ', $m) . '. Link the related record or edit the text.', ['body' => 'Missing variable values']);
            if (!Db::exec("UPDATE emails SET status='queued', subject=?, body=?, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=? AND status='draft'", [str_replace(["\r", "\n"], ' ', $s), $b, $e['id']])) throw new HttpError(409, 'Already queued');
            Jobs::enqueue('email.send', ['emailId' => (int)$e['id']], null, 'email:' . $e['id'], 3);
            if ($e['contact_id']) Events::activity('contact', (int)$e['contact_id'], 'email.queued', (int)$u['id'], 'Email queued: ' . mb_substr($s, 0, 160), ['emailId' => (int)$e['id']]);
            Audit::log((int)$u['id'], 'email.queued', 'email', $e['id']); });
        return self::get($pa);
    }
    public static function cancel(array $pa): array { $u = Auth::require('email.send'); $e = self::find($u, (int)$pa['id']);
        if (!Db::exec("UPDATE emails SET status='cancelled', updated_at=UTC_TIMESTAMP() WHERE id=? AND status IN ('draft','queued')", [$e['id']])) throw new HttpError(409, 'Only drafts and queued messages can be cancelled');
        Db::exec("UPDATE scheduled_jobs SET status='cancelled', updated_at=UTC_TIMESTAMP() WHERE dedupe_key=? AND status='pending'", ['email:' . $e['id']]); Audit::log((int)$u['id'], 'email.cancelled', 'email', $e['id']); return self::get($pa); }
    /** A person who has checked the outcome of an "unknown" send records it; there is no automatic resend. */
    public static function resolve(array $pa): array { $u = Auth::require('email.send'); $e = self::find($u, (int)$pa['id']); $d = Http::body(); $o = Validate::enum($d, 'outcome', ['accepted', 'failed']); $note = Validate::str($d, 'note', 255, true);
        if ($e['status'] !== 'unknown' || !$o) throw new HttpError(409, 'Only messages with an unknown outcome can be resolved');
        Db::exec('UPDATE emails SET status=?, error=?, updated_at=UTC_TIMESTAMP() WHERE id=?', [$o, 'Resolved by ' . $u['full_name'] . ': ' . $note, $e['id']]); Audit::log((int)$u['id'], 'email.resolved', 'email', $e['id'], ['outcome' => $o]); return self::get($pa); }
}
