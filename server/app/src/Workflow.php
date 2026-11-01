<?php
declare(strict_types=1);
namespace LTI;
/** Server-side workflow engine. Definitions are validated against allowlists; no SQL, code or expressions are ever executed. */
final class Workflow {
    const TRIGGERS = ['contact.created' => 'contact', 'contact.updated' => 'contact', 'trip.created' => 'trip', 'trip.status_changed' => 'trip', 'booking.created' => 'booking', 'booking.status_changed' => 'booking',
        'invoice.issued' => 'invoice', 'payment.recorded' => 'payment', 'task.completed' => 'task', 'call.logged' => 'call', 'invoice.due_approaching' => 'invoice', 'trip.departure_approaching' => 'trip', 'schedule.date' => null];
    const DATE_TRIGGERS = ['invoice.due_approaching', 'trip.departure_approaching', 'schedule.date'];
    const TABLES = ['contact' => 'contacts', 'trip' => 'trips', 'booking' => 'bookings', 'invoice' => 'invoices', 'payment' => 'payments', 'task' => 'tasks', 'call' => 'calls'];
    const FIELDS = ['contact' => ['contact_type' => 'contact_type', 'lead_status' => 'lead_status', 'source' => 'source'], 'trip' => ['status' => 'status', 'destination' => 'destination', 'trip_type' => 'trip_type', 'currency' => 'currency'],
        'booking' => ['status' => 'status', 'category' => 'booking_type', 'amount' => 'amount', 'currency' => 'currency'], 'invoice' => ['status' => 'status', 'currency' => 'currency', 'total' => 'total', 'balance' => 'balance'],
        'payment' => ['method' => 'method', 'amount' => 'amount', 'currency' => 'currency'], 'task' => ['priority' => 'priority', 'status' => 'status'], 'call' => ['direction' => 'direction', 'status' => 'status', 'disposition' => 'disposition']];
    const OPS = ['eq', 'neq', 'contains', 'gt', 'gte', 'lt', 'lte', 'in', 'empty', 'not_empty'];
    const ACTIONS = ['create_task', 'notify', 'queue_email', 'update_status', 'schedule_followup', 'timeline'];
    const STATUS_TARGETS = ['trip.status' => ['Inquiry', 'Planning', 'Proposal', 'Approved', 'Booked', 'Traveling', 'Traveled'], 'contact.lead_status' => ['new', 'contacted', 'qualified', 'quoted', 'won', 'lost']];
    public static function meta(): array {
        return ['triggers' => array_map(function ($k, $v) { return ['code' => $k, 'entity' => $v, 'dateBased' => in_array($k, self::DATE_TRIGGERS, true)]; }, array_keys(self::TRIGGERS), self::TRIGGERS),
            'fields' => self::FIELDS, 'operators' => self::OPS, 'actions' => self::ACTIONS, 'statusTargets' => self::STATUS_TARGETS, 'variables' => Template::VARS];
    }
    /** Validate and normalise a definition. Throws 422 with field messages. */
    public static function validate(array $d): array {
        $trig = (string)($d['triggerType'] ?? ''); if (!array_key_exists($trig, self::TRIGGERS)) throw new HttpError(422, Validate::MSG, ['triggerType' => 'Choose a supported trigger']);
        $entity = self::TRIGGERS[$trig]; $cfg = [];
        if ($trig === 'invoice.due_approaching' || $trig === 'trip.departure_approaching') { $n = (int)($d['triggerConfig']['daysBefore'] ?? -1); if ($n < 0 || $n > 365) throw new HttpError(422, Validate::MSG, ['triggerConfig.daysBefore' => '0 to 365 days']); $cfg['daysBefore'] = $n; }
        if ($trig === 'schedule.date') { $dt = Validate::date($d['triggerConfig'] ?? [], 'date'); if (!$dt) throw new HttpError(422, Validate::MSG, ['triggerConfig.date' => 'Choose a date']); $cfg['date'] = $dt; }
        $conds = [];
        foreach (array_values(is_array($d['conditions'] ?? null) ? $d['conditions'] : []) as $i => $c) {
            $f = (string)($c['field'] ?? ''); $op = (string)($c['op'] ?? ''); [$g, $k] = array_pad(explode('.', $f, 2), 2, '');
            $okField = ($g === 'event' && in_array($k, ['from', 'to'], true) && in_array($trig, ['trip.status_changed', 'booking.status_changed'], true)) || ($entity && $g === $entity && isset(self::FIELDS[$entity][$k]));
            if (!$okField) throw new HttpError(422, Validate::MSG, ["conditions.$i.field" => "Field $f is not available for this trigger"]);
            if (!in_array($op, self::OPS, true)) throw new HttpError(422, Validate::MSG, ["conditions.$i.op" => 'Unsupported operator']);
            $conds[] = ['field' => $f, 'op' => $op, 'value' => mb_substr((string)($c['value'] ?? ''), 0, 190)];
        }
        if (count($conds) > 20) throw new HttpError(422, 'Maximum 20 conditions');
        $acts = []; $list = array_values(is_array($d['actions'] ?? null) ? $d['actions'] : []); if (!$list) throw new HttpError(422, Validate::MSG, ['actions' => 'Add at least one action']); if (count($list) > 10) throw new HttpError(422, 'Maximum 10 actions');
        foreach ($list as $i => $a) {
            $t = (string)($a['type'] ?? ''); if (!in_array($t, self::ACTIONS, true)) throw new HttpError(422, Validate::MSG, ["actions.$i.type" => 'Unsupported action']);
            $p = is_array($a['params'] ?? null) ? $a['params'] : []; $n = ['type' => $t, 'params' => []]; $txt = function ($k, $max, $req = false) use ($p, $i) { $v = Validate::str($p, $k, $max, $req); if ($v !== null && ($bad = Template::validate($v))) throw new HttpError(422, Validate::MSG, ["actions.$i.params.$k" => 'Unknown placeholder: ' . implode(', ', $bad)]); return $v; };
            $assign = function () use ($p, $i) { $a = $p['assignTo'] ?? 'entity_owner'; if ($a === 'entity_owner' || $a === 'workflow_owner') return $a; if (is_numeric($a) && Db::one('SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.is_active=1 AND r.is_staff=1', [(int)$a])) return (int)$a; throw new HttpError(422, Validate::MSG, ["actions.$i.params.assignTo" => 'Unknown staff member']); };
            switch ($t) {
                case 'create_task': $n['params'] = ['title' => $txt('title', 255, true), 'description' => $txt('description', 2000), 'priority' => Validate::enum($p, 'priority', ['low', 'normal', 'high', 'urgent'], 'normal'), 'dueInDays' => max(0, min(365, (int)($p['dueInDays'] ?? 1))), 'assignTo' => $assign()]; break;
                case 'notify': $to = $p['to'] ?? 'entity_owner'; if (!($to === 'entity_owner' || $to === 'workflow_owner' || (is_string($to) && preg_match('/^role:(owner|admin|supervisor|advisor|sales|operations|finance)$/', $to)) || (is_numeric($to) && Db::one('SELECT id FROM users WHERE id=? AND is_active=1', [(int)$to])))) throw new HttpError(422, Validate::MSG, ["actions.$i.params.to" => 'Unknown recipient']);
                    $n['params'] = ['to' => is_numeric($to) ? (int)$to : $to, 'message' => $txt('message', 255, true), 'category' => Validate::enum($p, 'category', ['workflow', 'task_assignment', 'new_booking', 'upcoming_departure', 'pending_invoice', 'payment_update', 'missing_document', 'group_registration'], 'workflow')]; break;
                case 'queue_email': $tpl = Db::one("SELECT id FROM email_templates WHERE id=? AND status='active'", [(int)($p['templateId'] ?? 0)]); if (!$tpl) throw new HttpError(422, Validate::MSG, ["actions.$i.params.templateId" => 'Choose an active email template']);
                    if (!$entity) throw new HttpError(422, Validate::MSG, ["actions.$i.type" => 'Email needs a trigger with a client record']); $n['params'] = ['templateId' => (int)$tpl['id']]; break;
                case 'update_status': $target = (string)($p['target'] ?? ''); if (!isset(self::STATUS_TARGETS[$target])) throw new HttpError(422, Validate::MSG, ["actions.$i.params.target" => 'Only trip status and contact lead status can be changed by workflows']);
                    if (!in_array((string)($p['value'] ?? ''), self::STATUS_TARGETS[$target], true)) throw new HttpError(422, Validate::MSG, ["actions.$i.params.value" => 'Not an allowed value']); $n['params'] = ['target' => $target, 'value' => $p['value']]; break;
                case 'schedule_followup': $fa = Validate::enum($p, 'action', ['create_task', 'notify'], 'create_task'); if (!$entity) throw new HttpError(422, Validate::MSG, ["actions.$i.type" => 'Follow-ups need a trigger with a record']);
                    $n['params'] = ['inDays' => max(0, min(365, (int)($p['inDays'] ?? 1))), 'atTime' => preg_match('/^\d{2}:\d{2}$/', (string)($p['atTime'] ?? '')) ? $p['atTime'] : '09:00', 'action' => $fa, 'text' => $txt('text', 255, true), 'assignTo' => $assign()]; break;
                default: $n['params'] = ['text' => $txt('text', 255, true)];
            }
            $acts[] = $n;
        }
        return ['trigger_type' => $trig, 'trigger_config_json' => json_encode((object)$cfg), 'conditions_json' => json_encode($conds, JSON_UNESCAPED_UNICODE), 'actions_json' => json_encode($acts, JSON_UNESCAPED_UNICODE), 'delay_minutes' => max(0, min(525600, (int)($d['delayMinutes'] ?? 0))), 'scope' => $entity];
    }
    public static function agencyTz(): \DateTimeZone { $v = Db::one("SELECT setting_value v FROM app_settings WHERE setting_key='agency_timezone'")['v'] ?? 'UTC'; try { return new \DateTimeZone($v); } catch (\Throwable $e) { return new \DateTimeZone('UTC'); } }
    public static function agencyToday(): string { return (new \DateTime('now', self::agencyTz()))->format('Y-m-d'); }
    /** Claim unprocessed events and create executions for matching active workflows. */
    public static function processEvents(int $limit = 200): int {
        $n = 0;
        foreach (Db::all('SELECT * FROM domain_events WHERE processed_at IS NULL ORDER BY id LIMIT ' . (int)$limit) as $ev) {
            if (!Db::exec('UPDATE domain_events SET processed_at=UTC_TIMESTAMP() WHERE id=? AND processed_at IS NULL', [$ev['id']])) continue;   // another worker took it
            foreach (Db::all("SELECT * FROM workflows WHERE status='active' AND trigger_type=?", [$ev['event_type']]) as $wf) self::schedule($wf, (int)$ev['id'], $ev['entity_type'], (int)$ev['entity_id'], 'wf:' . $wf['id'] . ':ev:' . $ev['id']);
            $n++;
        }
        return $n;
    }
    /** Date-based triggers, evaluated in the agency time zone; one execution per workflow, record and date. */
    public static function scanDates(): int {
        $today = self::agencyToday(); $n = 0;
        foreach (Db::all("SELECT * FROM workflows WHERE status='active' AND trigger_type IN ('invoice.due_approaching','trip.departure_approaching','schedule.date')") as $wf) {
            $cfg = json_decode((string)$wf['trigger_config_json'], true) ?: []; $target = (new \DateTime($today))->modify('+' . (int)($cfg['daysBefore'] ?? 0) . ' days')->format('Y-m-d');
            if ($wf['trigger_type'] === 'schedule.date') { if (($cfg['date'] ?? '') === $today) $n += self::schedule($wf, null, null, null, "wf:$wf[id]:date:$today") ? 1 : 0; continue; }
            $rows = $wf['trigger_type'] === 'invoice.due_approaching' ? Db::all("SELECT id FROM invoices WHERE status IN ('issued','partially_paid') AND balance > 0 AND due_date=?", [$target])
                : Db::all("SELECT id FROM trips WHERE trip_type IN ('standard','sub') AND deleted_at IS NULL AND archived_at IS NULL AND status NOT IN ('Cancelled','Archived') AND start_date=?", [$target]);
            foreach ($rows as $r) $n += self::schedule($wf, null, self::TRIGGERS[$wf['trigger_type']], (int)$r['id'], "wf:$wf[id]:" . self::TRIGGERS[$wf['trigger_type']] . ":$r[id]:$target") ? 1 : 0;
        }
        return $n;
    }
    private static function schedule(array $wf, ?int $eventId, ?string $etype, ?int $eid, string $dedupe): bool {
        $when = gmdate('Y-m-d H:i:s', time() + 60 * (int)$wf['delay_minutes']);
        Db::exec('INSERT IGNORE INTO workflow_executions (workflow_id, workflow_version, event_id, trigger_type, entity_type, entity_id, dedupe_key, status, scheduled_for, created_at) VALUES (?,?,?,?,?,?,?,\'pending\',?,UTC_TIMESTAMP())', [$wf['id'], $wf['version'], $eventId, $wf['trigger_type'], $etype, $eid, $dedupe, $when]);
        $xid = (int)Db::pdo()->lastInsertId(); if (!$xid) return false;
        Jobs::enqueue('workflow.run', ['executionId' => $xid], $when, 'wx:' . $xid, 3); return true;
    }
    public static function entity(?string $type, ?int $id): ?array { if (!$type || !$id || !isset(self::TABLES[$type])) return null; return Db::one('SELECT * FROM ' . self::TABLES[$type] . ' WHERE id=?', [$id]); }
    private static function decCmp(string $a, string $b): ?int { if (!is_numeric($a) || !is_numeric($b)) return null; $na = $a[0] === '-'; $nb = $b[0] === '-'; if ($na !== $nb) return $na ? -1 : 1;
        [$ai, $af] = array_pad(explode('.', ltrim($a, '-+'), 2), 2, ''); [$bi, $bf] = array_pad(explode('.', ltrim($b, '-+'), 2), 2, ''); $ai = ltrim($ai, '0'); $bi = ltrim($bi, '0'); $l = max(strlen($af), strlen($bf));
        $r = strlen($ai) <=> strlen($bi) ?: strcmp($ai . str_pad($af, $l, '0'), $bi . str_pad($bf, $l, '0')) <=> 0; return $na ? -$r : $r; }   // exact decimal comparison, no floats
    public static function conditionsMet(array $conds, ?array $row, ?string $entity, array $eventPayload): array {
        foreach ($conds as $c) {
            [$g, $k] = explode('.', $c['field'], 2); $v = $g === 'event' ? (string)($eventPayload[$k] ?? '') : (string)($row[self::FIELDS[$entity][$k]] ?? '');
            $x = $c['value']; $ok = false;
            switch ($c['op']) { case 'eq': $ok = strcasecmp($v, $x) === 0; break; case 'neq': $ok = strcasecmp($v, $x) !== 0; break; case 'contains': $ok = $x !== '' && stripos($v, $x) !== false; break;
                case 'in': $ok = in_array(mb_strtolower($v), array_map('trim', explode(',', mb_strtolower($x))), true); break; case 'empty': $ok = $v === ''; break; case 'not_empty': $ok = $v !== ''; break;
                default: $cmp = self::decCmp($v, $x); $ok = $cmp !== null && (($c['op'] === 'gt' && $cmp > 0) || ($c['op'] === 'gte' && $cmp >= 0) || ($c['op'] === 'lt' && $cmp < 0) || ($c['op'] === 'lte' && $cmp <= 0)); }
            if (!$ok) return [false, "Condition not met: {$c['field']} {$c['op']} {$c['value']}"];
        }
        return [true, null];
    }
    /** Related ids, owner and primary contact for any entity. */
    public static function links(?string $type, ?array $row): array {
        $l = ['contact_id' => null, 'trip_id' => null, 'booking_id' => null, 'invoice_id' => null, 'owner' => null];
        if (!$row) return $l;
        if ($type === 'contact') { $l['contact_id'] = (int)$row['id']; $l['owner'] = $row['assigned_to'] ? (int)$row['assigned_to'] : null; }
        if ($type === 'trip') { $l['trip_id'] = (int)$row['id']; $l['contact_id'] = $row['primary_contact_id'] ? (int)$row['primary_contact_id'] : null; $l['owner'] = $row['owner_id'] ? (int)$row['owner_id'] : null; }
        if ($type === 'booking') { $l['booking_id'] = (int)$row['id']; $t = Db::one('SELECT id, primary_contact_id, owner_id FROM trips WHERE id=?', [$row['trip_id']]); $l['trip_id'] = (int)$row['trip_id']; $l['contact_id'] = $t && $t['primary_contact_id'] ? (int)$t['primary_contact_id'] : null; $l['owner'] = $row['agent_id'] ? (int)$row['agent_id'] : ($t && $t['owner_id'] ? (int)$t['owner_id'] : null); }
        if ($type === 'invoice' || $type === 'payment') { $inv = $type === 'invoice' ? $row : Db::one('SELECT * FROM invoices WHERE id=?', [$row['invoice_id']]); $l['invoice_id'] = (int)$inv['id']; $l['trip_id'] = $inv['trip_id'] ? (int)$inv['trip_id'] : null; $l['contact_id'] = $inv['contact_id'] ? (int)$inv['contact_id'] : null;
            $t = $l['trip_id'] ? Db::one('SELECT owner_id FROM trips WHERE id=?', [$l['trip_id']]) : null; $l['owner'] = $t && $t['owner_id'] ? (int)$t['owner_id'] : ($inv['created_by'] ? (int)$inv['created_by'] : null); }
        if ($type === 'call') { foreach (['contact_id', 'trip_id', 'booking_id'] as $k) $l[$k] = $row[$k] ? (int)$row[$k] : null; $l['owner'] = $row['staff_id'] ? (int)$row['staff_id'] : null; }
        if ($type === 'task') { foreach (['contact_id', 'trip_id', 'booking_id', 'invoice_id'] as $k) $l[$k] = $row[$k] ? (int)$row[$k] : null; $l['owner'] = $row['assigned_to'] ? (int)$row['assigned_to'] : null; }
        return $l;
    }
    /** Execute one workflow execution. Safe to repeat: every side effect carries a dedupe key derived from the execution. */
    public static function run(int $xid): array {
        $x = Db::one('SELECT * FROM workflow_executions WHERE id=?', [$xid]); if (!$x) return ['status' => 'missing'];
        if (in_array($x['status'], ['completed', 'skipped'], true)) return ['status' => $x['status']];
        Db::exec("UPDATE workflow_executions SET status='running', started_at=COALESCE(started_at, UTC_TIMESTAMP()), attempts=attempts+1 WHERE id=?", [$xid]);
        $wf = Db::one('SELECT * FROM workflows WHERE id=?', [$x['workflow_id']]);
        if (!$wf || $wf['status'] !== 'active') { Db::exec("UPDATE workflow_executions SET status='skipped', error='Workflow was disabled before this execution ran', finished_at=UTC_TIMESTAMP() WHERE id=?", [$xid]); return ['status' => 'skipped']; }
        $row = self::entity($x['entity_type'], $x['entity_id'] ? (int)$x['entity_id'] : null); $ev = $x['event_id'] ? (json_decode((string)(Db::one('SELECT payload_json FROM domain_events WHERE id=?', [$x['event_id']])['payload_json'] ?? ''), true) ?: []) : [];
        if ($x['entity_type'] && !$row) { Db::exec("UPDATE workflow_executions SET status='skipped', error='Record no longer exists', finished_at=UTC_TIMESTAMP() WHERE id=?", [$xid]); return ['status' => 'skipped']; }
        [$met, $why] = self::conditionsMet(json_decode((string)$wf['conditions_json'], true) ?: [], $row, $x['entity_type'], $ev);
        if (!$met) { Db::exec("UPDATE workflow_executions SET status='skipped', error=?, finished_at=UTC_TIMESTAMP() WHERE id=?", [$why, $xid]); return ['status' => 'skipped', 'reason' => $why]; }
        $links = self::links($x['entity_type'], $row); $results = [];
        try {
            foreach (json_decode((string)$wf['actions_json'], true) ?: [] as $i => $a) $results[] = self::act($a, $i, $x, $wf, $row, $links);
            Db::exec("UPDATE workflow_executions SET status='completed', result_json=?, error=NULL, finished_at=UTC_TIMESTAMP() WHERE id=?", [json_encode($results, JSON_UNESCAPED_UNICODE), $xid]);
            if ($x['entity_type']) Events::activity($x['entity_type'], (int)$x['entity_id'], 'workflow.executed', null, 'Workflow “' . $wf['name'] . '” ran (' . count($results) . ' action' . (count($results) === 1 ? '' : 's') . ')', ['workflowId' => (int)$wf['id'], 'executionId' => $xid]);
            return ['status' => 'completed', 'results' => $results];
        } catch (\Throwable $e) {
            $msg = Jobs::redact($e->getMessage()); Db::exec("UPDATE workflow_executions SET status='failed', result_json=?, error=?, finished_at=UTC_TIMESTAMP() WHERE id=?", [json_encode($results, JSON_UNESCAPED_UNICODE), mb_substr($msg, 0, 255), $xid]);
            if ($wf['created_by']) Events::notify((int)$wf['created_by'], 'workflow_failure', 'Workflow “' . mb_substr($wf['name'], 0, 120) . '” failed: ' . mb_substr($msg, 0, 100), 'workflow', (int)$wf['id'], 'system', 'wxfail:' . $xid . ':' . $x['attempts']);
            throw $e;
        }
    }
    private static function who($spec, array $links, array $wf): ?int { if ($spec === 'entity_owner') return $links['owner'] ?: ($wf['created_by'] ? (int)$wf['created_by'] : null); if ($spec === 'workflow_owner') return $wf['created_by'] ? (int)$wf['created_by'] : null; return (int)$spec; }
    private static function text(string $tpl, array $links): string { [$out, $missing] = Template::render($tpl, Template::context($links['contact_id'], $links['trip_id'], $links['booking_id'], $links['invoice_id'])); return $missing ? $tpl : $out; }
    private static function act(array $a, int $i, array $x, array $wf, ?array $row, array $links): array {
        $key = 'wx:' . $x['id'] . ':a' . $i; $p = $a['params'];
        switch ($a['type']) {
            case 'create_task': $assignee = self::who($p['assignTo'], $links, $wf); $due = (new \DateTime(self::agencyToday()))->modify('+' . (int)$p['dueInDays'] . ' days')->format('Y-m-d');
                Db::exec("INSERT IGNORE INTO tasks (title, description, due_date, priority, status, assigned_to, trip_id, contact_id, booking_id, invoice_id, source, dedupe_key, created_by, created_at, updated_at, version) VALUES (?,?,?,?,'todo',?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)",
                    [mb_substr(self::text($p['title'], $links), 0, 255), $p['description'] ? self::text($p['description'], $links) : null, $due, $p['priority'], $assignee, $links['trip_id'], $links['contact_id'], $links['booking_id'], $links['invoice_id'], 'Workflow: ' . mb_substr($wf['name'], 0, 100), $key, $wf['created_by']]);
                $tid = (int)(Db::one('SELECT id FROM tasks WHERE dedupe_key=?', [$key])['id']);
                if ($assignee) Events::notify($assignee, 'task_assignment', 'New task: ' . mb_substr(self::text($p['title'], $links), 0, 200), 'task', $tid, 'workflow', $key . ':n');
                return ['action' => 'create_task', 'taskId' => $tid];
            case 'notify': $to = $p['to']; $ids = [];
                if (is_string($to) && strpos($to, 'role:') === 0) $ids = array_map('intval', array_column(Db::all('SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code=? AND u.is_active=1', [substr($to, 5)]), 'id')); else $ids = array_filter([self::who($to, $links, $wf)]);
                $ref = $x['entity_type'] ?: 'workflow'; $rid = $x['entity_id'] ? (int)$x['entity_id'] : (int)$wf['id'];
                foreach ($ids as $uid) Events::notify($uid, $p['category'], self::text($p['message'], $links), $ref, $rid, 'workflow', $key . ':u' . $uid);
                return ['action' => 'notify', 'recipients' => count($ids)];
            case 'queue_email': $tpl = Db::one('SELECT * FROM email_templates WHERE id=?', [$p['templateId']]); if (!$tpl || $tpl['status'] !== 'active') throw new \RuntimeException('Email template is no longer active');
                $c = $links['contact_id'] ? Db::one('SELECT email FROM contacts WHERE id=? AND deleted_at IS NULL', [$links['contact_id']]) : null; if (!$c || !$c['email']) throw new \RuntimeException('The client has no email address');
                if ($ex = Db::one('SELECT id FROM emails WHERE workflow_execution_id=? AND template_id=?', [$x['id'], $tpl['id']])) return ['action' => 'queue_email', 'emailId' => (int)$ex['id'], 'reused' => true];
                $ctx = Template::context($links['contact_id'], $links['trip_id'], $links['booking_id'], $links['invoice_id']); [$subj, $m1] = Template::render($tpl['subject'], $ctx); [$body, $m2] = Template::render($tpl['body'], $ctx);
                if ($m = array_merge($m1, $m2)) throw new \RuntimeException('Missing values for: ' . implode(', ', array_unique($m)));
                $eid = Db::insert("INSERT INTO emails (direction, status, to_addr, subject, body, contact_id, trip_id, booking_id, invoice_id, template_id, workflow_execution_id, created_by, created_at, updated_at, version) VALUES ('outbound','queued',?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)",
                    [$c['email'], str_replace(["\r", "\n"], ' ', $subj), $body, $links['contact_id'], $links['trip_id'], $links['booking_id'], $links['invoice_id'], $tpl['id'], $x['id'], $wf['created_by']]);
                Jobs::enqueue('email.send', ['emailId' => $eid], null, 'email:' . $eid, 3); Events::activity('contact', (int)$links['contact_id'], 'email.queued', null, 'Email queued by workflow: ' . mb_substr($subj, 0, 150), ['emailId' => $eid]);
                return ['action' => 'queue_email', 'emailId' => $eid];
            case 'update_status': [$ent, $field] = explode('.', $p['target']);
                $id = $ent === 'trip' ? $links['trip_id'] : $links['contact_id']; if (!$id) throw new \RuntimeException("No $ent is linked to this record");
                $cur = Db::one('SELECT ' . ($ent === 'trip' ? 'status' : 'lead_status') . ' v FROM ' . ($ent === 'trip' ? 'trips' : 'contacts') . ' WHERE id=?', [$id]);
                if (($cur['v'] ?? null) === $p['value']) return ['action' => 'update_status', 'unchanged' => true];
                if ($ent === 'trip') { if (in_array($cur['v'], ['Cancelled', 'Archived'], true)) throw new \RuntimeException('Cancelled or archived trips are not changed by workflows'); Db::exec('UPDATE trips SET status=?, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?', [$p['value'], $id]); }
                else Db::exec('UPDATE contacts SET lead_status=?, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?', [$p['value'], $id]);
                Events::activity($ent, (int)$id, $ent . '.status_changed_by_workflow', null, ucfirst($ent) . ' ' . ($ent === 'trip' ? 'status' : 'lead status') . ' changed from ' . ($cur['v'] ?: 'none') . ' to ' . $p['value'] . ' by workflow “' . mb_substr($wf['name'], 0, 80) . '”');
                return ['action' => 'update_status', 'from' => $cur['v'], 'to' => $p['value']];
            case 'schedule_followup': $tz = self::agencyTz(); $local = (new \DateTime(self::agencyToday() . ' ' . $p['atTime'], $tz))->modify('+' . (int)$p['inDays'] . ' days'); $utc = (clone $local)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                if ($ex = Db::one('SELECT id FROM scheduled_followups WHERE workflow_execution_id=? AND result IS NULL AND payload_json LIKE ?', [$x['id'], '%"key":"' . $key . '"%'])) return ['action' => 'schedule_followup', 'followupId' => (int)$ex['id'], 'reused' => true];
                $etype = $x['entity_type'] === 'payment' ? 'invoice' : ($x['entity_type'] === 'task' ? ($links['trip_id'] ? 'trip' : 'contact') : $x['entity_type']); $eid = $x['entity_type'] === 'payment' ? $links['invoice_id'] : ($x['entity_type'] === 'task' ? ($links['trip_id'] ?: $links['contact_id']) : (int)$x['entity_id']);
                $fid = Db::insert("INSERT INTO scheduled_followups (entity_type, entity_id, assigned_to, scheduled_at, timezone, action, payload_json, status, workflow_execution_id, created_by, created_at) VALUES (?,?,?,?,?,?,?,'pending',?,?,UTC_TIMESTAMP())",
                    [$etype, $eid, self::who($p['assignTo'], $links, $wf), $utc, $tz->getName(), $p['action'], json_encode(['text' => self::text($p['text'], $links), 'key' => $key], JSON_UNESCAPED_UNICODE), $x['id'], $wf['created_by']]);
                $jid = Jobs::enqueue('followup.run', ['followupId' => $fid], $utc, 'fu:' . $fid, 3); Db::exec('UPDATE scheduled_followups SET job_id=? WHERE id=?', [$jid, $fid]);
                return ['action' => 'schedule_followup', 'followupId' => $fid, 'runAtUtc' => $utc];
            default: if ($x['entity_type']) Events::activity($x['entity_type'], (int)$x['entity_id'], 'workflow.note', null, mb_substr(self::text($p['text'], $links), 0, 255)); return ['action' => 'timeline'];
        }
    }
    /** Dry run for the builder: evaluates conditions and renders actions; writes nothing. */
    public static function preview(array $wf, ?string $etype, ?int $eid): array {
        $row = self::entity($etype, $eid); [$met, $why] = self::conditionsMet(json_decode((string)$wf['conditions_json'], true) ?: [], $row, $etype, []); $links = self::links($etype, $row);
        $ctx = Template::context($links['contact_id'], $links['trip_id'], $links['booking_id'], $links['invoice_id']);
        $acts = array_map(function ($a) use ($ctx, $links, $wf) { $p = $a['params']; $o = ['type' => $a['type']];
            foreach (['title', 'message', 'text', 'description'] as $k) if (!empty($p[$k])) { [$r, $m] = Template::render($p[$k], $ctx); $o[$k] = $r; if ($m) $o['missing'] = $m; }
            if ($a['type'] === 'queue_email') { $t = Db::one('SELECT subject, body FROM email_templates WHERE id=?', [$p['templateId']]); [$s, $m1] = Template::render((string)$t['subject'], $ctx); [, $m2] = Template::render((string)$t['body'], $ctx); $o['subject'] = $s; $o['missing'] = array_values(array_unique(array_merge($m1, $m2))); }
            if (isset($p['assignTo']) || isset($p['to'])) $o['recipient'] = (string)($p['assignTo'] ?? $p['to']); return $o; }, json_decode((string)$wf['actions_json'], true) ?: []);
        return ['conditionsMet' => $met, 'reason' => $why, 'actions' => $acts, 'note' => 'Preview only: nothing was created, changed or sent.'];
    }
}
