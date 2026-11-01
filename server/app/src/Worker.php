<?php
declare(strict_types=1);
namespace LTI;
/** One scheduler tick: recover abandoned jobs, turn new events into workflow executions, scan date triggers hourly, then run due jobs. */
final class Worker {
    public static function tick(string $source, int $maxJobs = 50, int $maxSeconds = 50): array {
        $worker = substr(gethostname() ?: 'host', 0, 30) . ':' . getmypid() . ':' . bin2hex(random_bytes(3)); $start = time();
        $rid = Db::insert('INSERT INTO scheduler_runs (source, worker, started_at) VALUES (?,?,UTC_TIMESTAMP())', [$source, $worker]);
        $out = ['recovered' => 0, 'events' => 0, 'jobs' => 0, 'errors' => 0];
        try {
            $out['recovered'] = Jobs::recoverAbandoned(); $out['events'] = Workflow::processEvents();
            Jobs::enqueue('workflow.scan', [], null, 'scan:' . gmdate('YmdH'), 2);
            while ($out['jobs'] < $maxJobs && time() - $start < $maxSeconds && ($j = Jobs::claim($worker))) {
                $out['jobs']++;
                try { $msg = self::handle($j); if ($msg !== '__handled__') Jobs::complete($j, $msg); }
                catch (\Throwable $e) { $out['errors']++; error_log('[LTI worker] job ' . $j['id'] . ' ' . $j['job_type'] . ': ' . Jobs::redact($e->getMessage())); Jobs::fail($j, $e->getMessage(), true); }
            }
        } catch (\Throwable $e) { Db::exec('UPDATE scheduler_runs SET error=? WHERE id=?', [mb_substr(Jobs::redact($e->getMessage()), 0, 255), $rid]); throw $e; }
        finally { Db::exec('UPDATE scheduler_runs SET finished_at=UTC_TIMESTAMP(), events_processed=?, jobs_processed=?, recovered=? WHERE id=?', [$out['events'], $out['jobs'], $out['recovered'], $rid]); }
        return $out;
    }
    private static function handle(array $j): ?string {
        $p = json_decode($j['payload_json'], true) ?: [];
        switch ($j['job_type']) {
            case 'workflow.run': $r = Workflow::run((int)$p['executionId']); return 'Execution ' . $p['executionId'] . ': ' . $r['status'];
            case 'workflow.scan': return Workflow::scanDates() . ' date-based executions scheduled';
            case 'followup.run': return self::followup((int)$p['followupId']);
            case 'email.send': return self::sendEmail($j, (int)$p['emailId']);
            case 'callback.remind': return \LTI\Controllers\CallbackController::runReminder((int)$p['callbackId'], (int)$p['rev']);
            default: throw new \RuntimeException('Unknown job type ' . $j['job_type']);
        }
    }
    private static function followup(int $id): string {
        $f = Db::one('SELECT * FROM scheduled_followups WHERE id=?', [$id]); if (!$f || $f['status'] !== 'pending') return 'Follow-up not pending';
        $p = json_decode($f['payload_json'], true) ?: []; $links = Workflow::links($f['entity_type'], Workflow::entity($f['entity_type'], (int)$f['entity_id']));
        if ($f['action'] === 'create_task') { Db::exec("INSERT IGNORE INTO tasks (title, due_date, priority, status, assigned_to, trip_id, contact_id, booking_id, invoice_id, source, dedupe_key, created_by, created_at, updated_at, version) VALUES (?,?,'normal','todo',?,?,?,?,?,'Scheduled follow-up',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)",
                [mb_substr((string)$p['text'], 0, 255), Workflow::agencyToday(), $f['assigned_to'], $links['trip_id'], $links['contact_id'], $links['booking_id'], $links['invoice_id'], 'fu:' . $id, $f['created_by']]);
            if ($f['assigned_to']) Events::notify((int)$f['assigned_to'], 'task_assignment', 'Follow-up due: ' . mb_substr((string)$p['text'], 0, 200), 'task', (int)(Db::one('SELECT id FROM tasks WHERE dedupe_key=?', ['fu:' . $id])['id']), 'followup', 'fu:' . $id . ':n'); }
        elseif ($f['action'] === 'notify' && $f['assigned_to']) Events::notify((int)$f['assigned_to'], 'workflow', mb_substr((string)$p['text'], 0, 255), $f['entity_type'], (int)$f['entity_id'], 'followup', 'fu:' . $id . ':n');
        elseif ($f['action'] === 'queue_email') throw new \RuntimeException('Email follow-ups are created through workflows with a template');
        Db::exec("UPDATE scheduled_followups SET status='completed', executed_at=UTC_TIMESTAMP(), result='Done' WHERE id=?", [$id]);
        Events::activity($f['entity_type'], (int)$f['entity_id'], 'followup.executed', null, 'Scheduled follow-up ran: ' . mb_substr((string)$p['text'], 0, 180));
        return 'Follow-up ' . $id . ' done';
    }
    /** Sends at most once per message: the message is moved to "sending" before contacting SMTP; an unclear outcome becomes "unknown" for review. */
    private static function sendEmail(array $j, int $id): string {
        $e = Db::one('SELECT * FROM emails WHERE id=?', [$id]); if (!$e) return 'Email no longer exists';
        if ($e['status'] === 'sending') { Db::exec("UPDATE emails SET status='unknown', error='A previous attempt did not finish; delivery outcome unknown. Review before resending.', updated_at=UTC_TIMESTAMP() WHERE id=? AND status='sending'", [$id]); return 'Previous attempt unfinished; marked unknown (not resent)'; }
        if ($e['status'] !== 'queued') return 'Email is ' . $e['status'] . '; nothing to send';
        if (!Mailer::configured()) { Db::exec("UPDATE emails SET status='failed', error='Email transport is not configured on this server. The message was not sent.', updated_at=UTC_TIMESTAMP() WHERE id=? AND status='queued'", [$id]);
            Events::activity('contact', (int)$e['contact_id'], 'email.failed', null, 'Email not sent (no email transport configured): ' . mb_substr((string)$e['subject'], 0, 120)); return 'Transport not configured; marked failed'; }
        $max = (int)(Mailer::status()['maxPerHour']); $sent = (int)Db::one("SELECT COUNT(*) n FROM email_send_attempts WHERE started_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR", [])['n'];
        if ($sent >= $max) { Db::exec("UPDATE scheduled_jobs SET status='pending', run_at=UTC_TIMESTAMP() + INTERVAL 10 MINUTE, attempts=GREATEST(attempts-1,0), locked_by=NULL, locked_until=NULL, last_error='Hourly send limit reached; deferred', updated_at=UTC_TIMESTAMP() WHERE id=?", [$j['id']]); return '__handled__'; }
        if (!Db::exec("UPDATE emails SET status='sending', attempts=attempts+1, updated_at=UTC_TIMESTAMP() WHERE id=? AND status='queued'", [$id])) return 'Another worker took this email';
        $n = (int)Db::one('SELECT attempts FROM emails WHERE id=?', [$id])['attempts']; $aid = Db::insert("INSERT INTO email_send_attempts (email_id, attempt_no, job_id, transport, started_at) VALUES (?,?,?,'smtp',UTC_TIMESTAMP())", [$id, $n, $j['id']]);
        $r = Mailer::send($e);
        Db::exec('UPDATE email_send_attempts SET finished_at=UTC_TIMESTAMP(), result=?, response=? WHERE id=?', [$r['result'], mb_substr($r['response'], 0, 255), $aid]);
        $cid = (int)$e['contact_id'];
        if ($r['result'] === 'accepted') { Db::exec("UPDATE emails SET status='accepted', accepted_at=UTC_TIMESTAMP(), sent_at=UTC_TIMESTAMP(), transport_message_id=?, error=NULL, updated_at=UTC_TIMESTAMP() WHERE id=?", [$r['messageId'], $id]);
            if ($cid) Events::activity('contact', $cid, 'email.accepted', null, 'Email accepted by the mail server: ' . mb_substr((string)$e['subject'], 0, 140), ['emailId' => $id]); return 'Accepted by SMTP server'; }
        if ($r['result'] === 'failed' && (int)$j['attempts'] < (int)$j['max_attempts']) { Db::exec("UPDATE emails SET status='queued', error=?, updated_at=UTC_TIMESTAMP() WHERE id=?", [mb_substr($r['response'], 0, 255), $id]); Jobs::fail($j, 'Send failed (will retry): ' . $r['response'], true); return '__handled__'; }
        Db::exec('UPDATE emails SET status=?, error=?, updated_at=UTC_TIMESTAMP() WHERE id=?', [$r['result'], mb_substr($r['response'], 0, 255), $id]);
        if ($cid) Events::activity('contact', $cid, 'email.' . $r['result'], null, ($r['result'] === 'unknown' ? 'Email outcome unknown (needs review): ' : 'Email failed: ') . mb_substr((string)$e['subject'], 0, 120), ['emailId' => $id]);
        Jobs::fail($j, 'Send ' . $r['result'] . ': ' . $r['response'], false, true); return '__handled__';
    }
}
