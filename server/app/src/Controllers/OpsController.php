<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Auth, Audit, Db, Events, Http, HttpError, Jobs, Mailer, Validate, Workflow, Worker};
/** Notifications, activity timeline, scheduled follow-ups, job monitoring and the token-protected scheduler endpoint. */
final class OpsController {
    /* ---- notifications (each user sees only their own) ---- */
    public static function notifications(): array { $u = Auth::require(); $w = ['n.user_id=?', 'n.dismissed_at IS NULL']; $p = [(int)$u['id']];
        if (!empty($_GET['unread'])) $w[] = 'n.read_at IS NULL'; if (($c = (string)($_GET['category'] ?? '')) !== '') { $w[] = 'n.category=?'; $p[] = $c; }
        $rows = Db::all('SELECT * FROM notifications n WHERE ' . implode(' AND ', $w) . ' ORDER BY n.id DESC LIMIT 200', $p);
        return ['unread' => (int)Db::one('SELECT COUNT(*) n FROM notifications WHERE user_id=? AND read_at IS NULL AND dismissed_at IS NULL', [(int)$u['id']])['n'],
            'items' => array_map(function ($r) { return ['id' => (int)$r['id'], 'category' => $r['category'], 'message' => $r['message'], 'refType' => $r['ref_type'], 'refId' => $r['ref_id'] === null ? null : (int)$r['ref_id'], 'source' => $r['source'], 'read' => $r['read_at'] !== null, 'createdAt' => $r['created_at']]; }, $rows)]; }
    public static function markNotification(array $pa, string $what): array { $u = Auth::require(); $sql = ['read' => 'read_at=COALESCE(read_at, UTC_TIMESTAMP())', 'unread' => 'read_at=NULL', 'dismiss' => 'dismissed_at=UTC_TIMESTAMP()'][$what];
        if (!Db::exec("UPDATE notifications SET $sql WHERE id=? AND user_id=?", [(int)$pa['id'], (int)$u['id']]) && !Db::one('SELECT id FROM notifications WHERE id=? AND user_id=?', [(int)$pa['id'], (int)$u['id']])) throw new HttpError(404, 'Notification not found');
        return self::notifications(); }
    public static function readAll(): array { $u = Auth::require(); Db::exec('UPDATE notifications SET read_at=UTC_TIMESTAMP() WHERE user_id=? AND read_at IS NULL', [(int)$u['id']]); return self::notifications(); }
    /* ---- activity timeline (visible only when the record is visible) ---- */
    public static function activity(): array { $u = Auth::require('tasks.manage'); $t = Validate::enum($_GET, 'entityType', ['contact', 'trip', 'booking', 'invoice', 'task']); $id = (int)($_GET['entityId'] ?? 0); if (!$t || !$id) throw new HttpError(422, 'entityType and entityId are required');
        if ($t === 'contact') ContactController::find($u, $id); elseif ($t === 'trip') TripController::findRow($u, $id); elseif ($t === 'invoice') { Auth::require('finance.view'); InvoiceController::find($u, $id); }
        elseif ($t === 'booking') { $b = Db::one('SELECT trip_id FROM bookings WHERE id=?', [$id]); if (!$b) throw new HttpError(404, 'Not found'); TripController::findRow($u, (int)$b['trip_id']); } else TaskController::get(['id' => $id]);
        $rows = Db::all('SELECT a.*, u.full_name FROM activity_events a LEFT JOIN users u ON u.id=a.actor_id WHERE a.entity_type=? AND a.entity_id=? ORDER BY a.id DESC LIMIT 200', [$t, $id]);
        return ['items' => array_map(function ($r) { return ['id' => (int)$r['id'], 'type' => $r['event_type'], 'summary' => $r['summary'], 'by' => $r['full_name'] ?: ($r['actor_id'] ? null : 'System'), 'at' => $r['created_at']]; }, $rows)]; }
    /* ---- scheduled follow-ups ---- */
    private static function fuOut(array $r): array { $tz = new \DateTimeZone($r['timezone']); $local = (new \DateTime($r['scheduled_at'], new \DateTimeZone('UTC')))->setTimezone($tz);
        return ['id' => (int)$r['id'], 'entityType' => $r['entity_type'], 'entityId' => (int)$r['entity_id'], 'assignedTo' => $r['assigned_to'] === null ? null : (int)$r['assigned_to'], 'scheduledAtUtc' => $r['scheduled_at'], 'scheduledAtLocal' => $local->format('Y-m-d H:i'), 'timezone' => $r['timezone'],
            'action' => $r['action'], 'text' => (json_decode($r['payload_json'], true) ?: [])['text'] ?? '', 'status' => $r['status'], 'executedAt' => $r['executed_at'], 'result' => $r['result']]; }
    public static function followups(): array { $u = Auth::require('tasks.manage'); $w = Auth::can('tasks.view_all') ? ['1=1'] : ['(f.assigned_to=' . (int)$u['id'] . ' OR f.created_by=' . (int)$u['id'] . ')']; $p = [];
        if (($s = (string)($_GET['status'] ?? '')) !== '') { Validate::enum(['s' => $s], 's', ['pending', 'completed', 'cancelled', 'failed']); $w[] = 'f.status=?'; $p[] = $s; }
        return ['items' => array_map([self::class, 'fuOut'], Db::all('SELECT f.* FROM scheduled_followups f WHERE ' . implode(' AND ', $w) . ' ORDER BY f.scheduled_at LIMIT 300', $p))]; }
    public static function createFollowup(): array {
        $u = Auth::require('tasks.manage'); $d = Http::body(); $t = Validate::enum($d, 'entityType', ['contact', 'trip', 'booking', 'invoice']); $id = (int)($d['entityId'] ?? 0); if (!$t || !$id) throw new HttpError(422, Validate::MSG, ['entityId' => 'Choose a record']);
        if ($t === 'contact') ContactController::find($u, $id); elseif ($t === 'trip') TripController::findRow($u, $id); elseif ($t === 'invoice') { Auth::require('finance.view'); InvoiceController::find($u, $id); } else { $b = Db::one('SELECT trip_id FROM bookings WHERE id=?', [$id]); if (!$b) throw new HttpError(422, 'Unknown booking'); TripController::findRow($u, (int)$b['trip_id']); }
        $tzName = (string)($d['timezone'] ?? Workflow::agencyTz()->getName()); try { $tz = new \DateTimeZone($tzName); } catch (\Throwable $e) { throw new HttpError(422, Validate::MSG, ['timezone' => 'Unknown time zone']); }
        $at = \DateTime::createFromFormat('Y-m-d\TH:i', (string)($d['scheduledAt'] ?? ''), $tz); if (!$at) throw new HttpError(422, Validate::MSG, ['scheduledAt' => 'Use date and time (YYYY-MM-DDTHH:MM)']);
        $utc = (clone $at)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'); $act = Validate::enum($d, 'action', ['create_task', 'notify'], 'create_task'); $text = Validate::str($d, 'text', 255, true);
        $as = !empty($d['assignedTo']) ? (int)$d['assignedTo'] : (int)$u['id']; if (!Db::one('SELECT id FROM users WHERE id=? AND is_active=1', [$as])) throw new HttpError(422, Validate::MSG, ['assignedTo' => 'Unknown staff member']);
        $fid = Db::tx(function () use ($t, $id, $as, $utc, $tz, $act, $text, $u) { $fid = Db::insert("INSERT INTO scheduled_followups (entity_type, entity_id, assigned_to, scheduled_at, timezone, action, payload_json, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,'pending',?,UTC_TIMESTAMP())", [$t, $id, $as, $utc, $tz->getName(), $act, json_encode(['text' => $text], JSON_UNESCAPED_UNICODE), (int)$u['id']]);
            $jid = Jobs::enqueue('followup.run', ['followupId' => $fid], $utc, 'fu:' . $fid, 3); Db::exec('UPDATE scheduled_followups SET job_id=? WHERE id=?', [$jid, $fid]); Events::activity($t, $id, 'followup.scheduled', (int)$u['id'], 'Follow-up scheduled for ' . $tz->getName() . ' time: ' . mb_substr($text, 0, 150)); return $fid; });
        return ['followup' => self::fuOut(Db::one('SELECT * FROM scheduled_followups WHERE id=?', [$fid]))];
    }
    public static function cancelFollowup(array $pa): array { $u = Auth::require('tasks.manage'); $f = Db::one('SELECT * FROM scheduled_followups WHERE id=?', [(int)$pa['id']]); if (!$f || (!Auth::can('tasks.view_all') && (int)$f['created_by'] !== (int)$u['id'] && (int)$f['assigned_to'] !== (int)$u['id'])) throw new HttpError(404, 'Follow-up not found');
        if (!Db::exec("UPDATE scheduled_followups SET status='cancelled' WHERE id=? AND status='pending'", [$f['id']])) throw new HttpError(409, 'Only pending follow-ups can be cancelled');
        if ($f['job_id']) Db::exec("UPDATE scheduled_jobs SET status='cancelled', updated_at=UTC_TIMESTAMP() WHERE id=? AND status='pending'", [$f['job_id']]); return ['followup' => self::fuOut(Db::one('SELECT * FROM scheduled_followups WHERE id=?', [$f['id']]))]; }
    /* ---- job monitoring (payloads are never returned) ---- */
    public static function jobs(): array { Auth::require('jobs.view'); $w = []; $p = []; if (($s = (string)($_GET['status'] ?? '')) !== '') { Validate::enum(['s' => $s], 's', ['pending', 'running', 'completed', 'failed', 'cancelled']); $w[] = 'status=?'; $p[] = $s; }
        if (!empty($_GET['needsReview'])) $w[] = 'needs_review=1';
        $rows = Db::all('SELECT id, job_type, status, attempts, max_attempts, run_at, started_at, finished_at, last_error, needs_review, created_at FROM scheduled_jobs ' . ($w ? 'WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY id DESC LIMIT 300', $p);
        return ['items' => array_map(function ($r) { return ['id' => (int)$r['id'], 'type' => $r['job_type'], 'status' => $r['status'], 'attempts' => (int)$r['attempts'], 'maxAttempts' => (int)$r['max_attempts'], 'runAt' => $r['run_at'], 'startedAt' => $r['started_at'], 'finishedAt' => $r['finished_at'], 'error' => $r['last_error'] ? Jobs::redact($r['last_error']) : null, 'needsReview' => (bool)$r['needs_review']]; }, $rows)]; }
    public static function schedulerStatus(): array { Auth::require('jobs.view'); $last = Db::one('SELECT * FROM scheduler_runs ORDER BY id DESC LIMIT 1');
        $counts = []; foreach (Db::all('SELECT status, COUNT(*) n FROM scheduled_jobs GROUP BY status') as $r) $counts[$r['status']] = (int)$r['n'];
        $healthy = $last && strtotime($last['started_at'] . ' UTC') > time() - 600;
        return ['schedulerHealthy' => $healthy, 'lastRun' => $last ? ['source' => $last['source'], 'startedAt' => $last['started_at'], 'finishedAt' => $last['finished_at'], 'jobs' => (int)$last['jobs_processed'], 'events' => (int)$last['events_processed'], 'error' => $last['error']] : null,
            'counts' => (object)$counts, 'needsReview' => (int)Db::one('SELECT COUNT(*) n FROM scheduled_jobs WHERE needs_review=1 AND status=\'failed\'')['n'], 'overduePending' => (int)Db::one('SELECT COUNT(*) n FROM scheduled_jobs WHERE status=\'pending\' AND run_at < UTC_TIMESTAMP() - INTERVAL 10 MINUTE')['n'],
            'mail' => Mailer::status(), 'message' => $healthy ? 'The scheduler ran in the last 10 minutes.' : 'No scheduler run in the last 10 minutes. Queued work (emails, delayed workflows, follow-ups) waits until the scheduler runs.']; }
    public static function retryJob(array $pa): array { $u = Auth::require('jobs.view'); $j = Db::one('SELECT * FROM scheduled_jobs WHERE id=?', [(int)$pa['id']]); if (!$j) throw new HttpError(404, 'Job not found'); if ($j['status'] !== 'failed') throw new HttpError(409, 'Only failed jobs can be retried');
        if ($j['job_type'] === 'email.send') throw new HttpError(409, 'Email sends are never retried from here. Resolve the message, then queue a new one if it was not delivered.');
        Db::exec("UPDATE scheduled_jobs SET status='pending', attempts=0, run_at=UTC_TIMESTAMP(), needs_review=0, last_error=NULL, updated_at=UTC_TIMESTAMP() WHERE id=?", [$j['id']]);
        if ($j['job_type'] === 'workflow.run') { $p = json_decode($j['payload_json'], true); Db::exec("UPDATE workflow_executions SET status='pending' WHERE id=? AND status='failed'", [$p['executionId'] ?? 0]); }
        Audit::log((int)$u['id'], 'job.retried', 'job', $j['id']); return ['ok' => true]; }
    public static function cancelJob(array $pa): array { $u = Auth::require('jobs.view'); if (!Db::exec("UPDATE scheduled_jobs SET status='cancelled', updated_at=UTC_TIMESTAMP() WHERE id=? AND status='pending'", [(int)$pa['id']])) throw new HttpError(409, 'Only pending jobs can be cancelled'); Audit::log((int)$u['id'], 'job.cancelled', 'job', (int)$pa['id']); return ['ok' => true]; }
    /** For hosts whose cron can only call a URL: requires a long secret token from config.php (header X-Cron-Token); disabled when no token is configured. */
    public static function cronRun(): array {
        $cfg = (string)($GLOBALS['LTI_CFG']['cron_token'] ?? ''); $given = (string)($_SERVER['HTTP_X_CRON_TOKEN'] ?? '');
        if (strlen($cfg) < 32) throw new HttpError(404, 'Not found');
        if (!hash_equals($cfg, $given)) { \LTI\Audit::log(null, 'cron.rejected', 'scheduler', null, ['ip' => \LTI\Http::ip()]); throw new HttpError(403, 'Forbidden'); }
        if (Db::one("SELECT id FROM scheduler_runs WHERE source='http' AND started_at > UTC_TIMESTAMP() - INTERVAL 30 SECOND")) throw new HttpError(429, 'The scheduler ran less than 30 seconds ago');
        return Worker::tick('http', 30, 20);
    }
}
