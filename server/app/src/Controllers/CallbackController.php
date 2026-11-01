<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Auth, Audit, Db, Events, Http, HttpError, Jobs, Validate, Workflow};
/** Callbacks create a linked task and queue a reminder job; reminders are delivered only when the scheduler runs. */
final class CallbackController {
    private static function out(array $r): array { $tz = new \DateTimeZone($r['timezone']); $l = (new \DateTime($r['scheduled_at'], new \DateTimeZone('UTC')))->setTimezone($tz);
        return ['id' => (int)$r['id'], 'callId' => $r['call_id'] === null ? null : (int)$r['call_id'], 'contactId' => (int)$r['contact_id'], 'contactName' => $r['contact_name'] ?? null, 'tripId' => $r['trip_id'] === null ? null : (int)$r['trip_id'],
            'assignedTo' => (int)$r['assigned_to'], 'assigneeName' => $r['assignee'] ?? null, 'scheduledAtUtc' => $r['scheduled_at'], 'scheduledAtLocal' => $l->format('Y-m-d H:i'), 'timezone' => $r['timezone'], 'status' => $r['status'],
            'overdue' => $r['status'] === 'pending' && strtotime($r['scheduled_at'] . ' UTC') < time(), 'notes' => $r['notes'], 'taskId' => $r['task_id'] === null ? null : (int)$r['task_id'], 'rescheduleCount' => (int)$r['reschedule_count'], 'completedAt' => $r['completed_at']]; }
    private static function scope(array $u): array { if (Auth::can('calls.view_all')) return ['1=1', []]; [$cw, $cp] = ContactController::scope($u);
        return ["(cb.assigned_to=? OR cb.created_by=? OR EXISTS (SELECT 1 FROM contacts c WHERE c.id=cb.contact_id AND $cw))", array_merge([(int)$u['id'], (int)$u['id']], $cp)]; }
    private static function find(array $u, int $id): array { [$w, $p] = self::scope($u); $r = Db::one("SELECT cb.*, CONCAT_WS(' ', c2.first_name, c2.last_name) contact_name, a.full_name assignee FROM callbacks cb JOIN contacts c2 ON c2.id=cb.contact_id LEFT JOIN users a ON a.id=cb.assigned_to WHERE cb.id=? AND $w", array_merge([$id], $p)); if (!$r) throw new HttpError(404, 'Callback not found'); return $r; }
    public static function forCall(array $u, int $callId): array { [$w, $p] = self::scope($u); return array_map([self::class, 'out'], Db::all("SELECT cb.*, CONCAT_WS(' ', c2.first_name, c2.last_name) contact_name, a.full_name assignee FROM callbacks cb JOIN contacts c2 ON c2.id=cb.contact_id LEFT JOIN users a ON a.id=cb.assigned_to WHERE cb.call_id=? AND $w ORDER BY cb.id", array_merge([$callId], $p))); }
    public static function list(): array { $u = Auth::require('calls.use'); [$w, $p] = self::scope($u); $where = [$w];
        if (($s = (string)($_GET['status'] ?? '')) !== '') { if ($s === 'overdue') $where[] = "cb.status='pending' AND cb.scheduled_at < UTC_TIMESTAMP()"; else { Validate::enum(['s' => $s], 's', ['pending', 'completed', 'cancelled']); $where[] = 'cb.status=?'; $p[] = $s; } }
        if (($a = (string)($_GET['assignedTo'] ?? '')) !== '') { $where[] = 'cb.assigned_to=?'; $p[] = $a === 'me' ? (int)$u['id'] : (int)$a; }
        return ['items' => array_map([self::class, 'out'], Db::all('SELECT cb.*, CONCAT_WS(\' \', c2.first_name, c2.last_name) contact_name, a.full_name assignee FROM callbacks cb JOIN contacts c2 ON c2.id=cb.contact_id LEFT JOIN users a ON a.id=cb.assigned_to WHERE ' . implode(' AND ', $where) . ' ORDER BY cb.scheduled_at LIMIT 300', $p))]; }
    private static function when(array $d): array { try { $tz = new \DateTimeZone((string)($d['timezone'] ?? Workflow::agencyTz()->getName())); } catch (\Throwable $e) { throw new HttpError(422, Validate::MSG, ['timezone' => 'Unknown time zone']); }
        $x = \DateTime::createFromFormat('Y-m-d\TH:i', (string)($d['scheduledAt'] ?? ''), $tz); if (!$x) throw new HttpError(422, Validate::MSG, ['scheduledAt' => 'Use date and time (YYYY-MM-DDTHH:MM)']);
        return [(clone $x)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $tz->getName(), $x->format('Y-m-d')]; }
    private static function remind(int $id, string $utc, int $rev): void { Jobs::enqueue('callback.remind', ['callbackId' => $id, 'rev' => $rev], $utc, 'cb:' . $id . ':' . $rev, 3); }
    public static function create(): array {
        $u = Auth::require('calls.use'); $d = Http::body(); $call = null;
        if (!empty($d['callId'])) { $call = Db::one('SELECT * FROM calls WHERE id=?', [(int)$d['callId']]); if (!$call) throw new HttpError(422, Validate::MSG, ['callId' => 'Unknown call']); CallController::get(['id' => (int)$call['id']]); }
        $cid = (int)($d['contactId'] ?? ($call['contact_id'] ?? 0)); if (!$cid) throw new HttpError(422, Validate::MSG, ['contactId' => 'Choose a contact']); $c = ContactController::find($u, $cid);
        $tid = !empty($d['tripId']) ? (int)TripController::findRow($u, (int)$d['tripId'])['id'] : ($call && $call['trip_id'] ? (int)$call['trip_id'] : null);
        $as = !empty($d['assignedTo']) ? (int)$d['assignedTo'] : (int)$u['id']; if (!Db::one('SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.is_active=1 AND r.is_staff=1', [$as])) throw new HttpError(422, Validate::MSG, ['assignedTo' => 'Unknown staff member']);
        [$utc, $tz, $localDate] = self::when($d); $notes = Validate::str($d, 'notes', 500); $name = trim($c['first_name'] . ' ' . $c['last_name']);
        $id = Db::tx(function () use ($u, $call, $cid, $tid, $as, $utc, $tz, $localDate, $notes, $name) {
            $id = Db::insert("INSERT INTO callbacks (call_id, contact_id, trip_id, assigned_to, scheduled_at, timezone, status, notes, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,'pending',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())", [$call ? (int)$call['id'] : null, $cid, $tid, $as, $utc, $tz, $notes, (int)$u['id']]);
            $task = Db::insert("INSERT INTO tasks (title, description, due_date, priority, status, assigned_to, contact_id, trip_id, source, dedupe_key, created_by, created_at, updated_at, version) VALUES (?,?,?,'high','todo',?,?,?,'Callback',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)",
                [mb_substr('Call back ' . $name, 0, 255), $notes, $localDate, $as, $cid, $tid, 'callback:' . $id, (int)$u['id']]);
            Db::exec('INSERT INTO task_history (task_id, user_id, field, old_value, new_value, created_at) VALUES (?,?,\'assigned_to\',NULL,?,UTC_TIMESTAMP())', [$task, (int)$u['id'], (string)$as]);
            Db::exec('UPDATE callbacks SET task_id=? WHERE id=?', [$task, $id]); self::remind($id, $utc, 0);
            if ($as !== (int)$u['id']) Events::notify($as, 'task_assignment', $u['full_name'] . ' scheduled a callback for you: ' . mb_substr($name, 0, 150), 'task', $task, 'event');
            Events::activity('contact', $cid, 'callback.scheduled', (int)$u['id'], 'Callback scheduled for ' . $tz . ' time'); if ($tid) Events::activity('trip', $tid, 'callback.scheduled', (int)$u['id'], 'Callback scheduled with ' . $name);
            Audit::log((int)$u['id'], 'callback.created', 'callback', $id); return $id; });
        return ['callback' => self::out(self::find($u, $id))];
    }
    public static function reschedule(array $pa): array { $u = Auth::require('calls.use'); $d = Http::body();
        Db::tx(function () use ($u, $pa, $d) { $cb = self::find($u, (int)$pa['id']); if ($cb['status'] !== 'pending') throw new HttpError(409, 'Only pending callbacks can be rescheduled'); [$utc, $tz, $localDate] = self::when($d);
            Db::exec("UPDATE scheduled_jobs SET status='cancelled', updated_at=UTC_TIMESTAMP() WHERE dedupe_key=? AND status='pending'", ['cb:' . $cb['id'] . ':' . $cb['reschedule_count']]);
            Db::exec('UPDATE callbacks SET scheduled_at=?, timezone=?, reschedule_count=reschedule_count+1, updated_at=UTC_TIMESTAMP() WHERE id=?', [$utc, $tz, $cb['id']]);
            if ($cb['task_id']) { Db::exec('UPDATE tasks SET due_date=?, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?', [$localDate, $cb['task_id']]); Db::exec("INSERT INTO task_history (task_id, user_id, field, old_value, new_value, created_at) VALUES (?,?,'due_date',?,?,UTC_TIMESTAMP())", [$cb['task_id'], (int)$u['id'], null, $localDate]); }
            self::remind((int)$cb['id'], $utc, (int)$cb['reschedule_count'] + 1); Events::activity('contact', (int)$cb['contact_id'], 'callback.rescheduled', (int)$u['id'], 'Callback rescheduled' . (($r = Validate::str($d, 'reason', 200)) ? ': ' . $r : ''));
            Audit::log((int)$u['id'], 'callback.rescheduled', 'callback', $cb['id'], ['from' => $cb['scheduled_at'], 'to' => $utc]); });
        return ['callback' => self::out(self::find($u, (int)$pa['id']))]; }
    public static function setStatus(array $pa, string $to): array { $u = Auth::require('calls.use');
        Db::tx(function () use ($u, $pa, $to) { $cb = self::find($u, (int)$pa['id']); if ($cb['status'] !== 'pending') throw new HttpError(409, 'Callback is already ' . $cb['status']);
            Db::exec('UPDATE callbacks SET status=?, completed_at=' . ($to === 'completed' ? 'UTC_TIMESTAMP()' : 'NULL') . ', updated_at=UTC_TIMESTAMP() WHERE id=?', [$to, $cb['id']]);
            Db::exec("UPDATE scheduled_jobs SET status='cancelled', updated_at=UTC_TIMESTAMP() WHERE dedupe_key=? AND status='pending'", ['cb:' . $cb['id'] . ':' . $cb['reschedule_count']]);
            if ($cb['task_id'] && $to === 'completed') { Db::exec("UPDATE tasks SET status='completed', completed_at=UTC_TIMESTAMP(), completed_by=?, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=? AND status NOT IN ('completed','archived')", [(int)$u['id'], $cb['task_id']]); Db::exec("INSERT INTO task_history (task_id, user_id, field, old_value, new_value, created_at) VALUES (?,?,'status','todo','completed',UTC_TIMESTAMP())", [$cb['task_id'], (int)$u['id']]); }
            if ($cb['task_id'] && $to === 'cancelled') Db::exec("UPDATE tasks SET status='archived', archived_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE id=? AND status NOT IN ('completed','archived')", [$cb['task_id']]);
            Events::activity('contact', (int)$cb['contact_id'], 'callback.' . $to, (int)$u['id'], 'Callback ' . $to); Audit::log((int)$u['id'], 'callback.' . $to, 'callback', $cb['id']); });
        return ['callback' => self::out(self::find($u, (int)$pa['id']))]; }
    /** Worker job: notify the assignee when a pending callback comes due. */
    public static function runReminder(int $id, int $rev): string { $cb = Db::one("SELECT cb.*, CONCAT_WS(' ', c.first_name, c.last_name) n FROM callbacks cb JOIN contacts c ON c.id=cb.contact_id WHERE cb.id=?", [$id]);
        if (!$cb || $cb['status'] !== 'pending' || (int)$cb['reschedule_count'] !== $rev) return 'Callback no longer pending or rescheduled';
        Events::notify((int)$cb['assigned_to'], 'task_assignment', 'Callback due now: ' . mb_substr($cb['n'], 0, 150), 'task', $cb['task_id'] ? (int)$cb['task_id'] : null, 'event', 'cbrem:' . $id . ':' . $rev); return 'Reminder sent for callback ' . $id; }
}
