<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Auth, Audit, Db, Http, HttpError, Jobs, Validate, Workflow};
final class WorkflowController {
    private static function find(int $id): array { $r = Db::one('SELECT w.*, u.full_name creator FROM workflows w LEFT JOIN users u ON u.id=w.created_by WHERE w.id=?', [$id]); if (!$r) throw new HttpError(404, 'Workflow not found'); return $r; }
    private static function out(array $r, bool $full = false): array {
        $o = ['id' => (int)$r['id'], 'name' => $r['name'], 'description' => $r['description'], 'status' => $r['status'], 'triggerType' => $r['trigger_type'], 'triggerConfig' => json_decode((string)$r['trigger_config_json'], true) ?: (object)[],
            'conditions' => json_decode((string)$r['conditions_json'], true) ?: [], 'actions' => json_decode((string)$r['actions_json'], true) ?: [], 'delayMinutes' => (int)$r['delay_minutes'], 'version' => (int)$r['version'], 'createdBy' => $r['creator'] ?? null, 'updatedAt' => $r['updated_at'],
            'stats' => Db::one("SELECT SUM(status='completed') completed, SUM(status='failed') failed, SUM(status='skipped') skipped, SUM(status IN ('pending','running')) pending FROM workflow_executions WHERE workflow_id=?", [$r['id']])];
        if ($full) $o['recentExecutions'] = self::execs((int)$r['id'], 20);
        return $o;
    }
    private static function execs(int $wid, int $n): array { return array_map(function ($x) { return ['id' => (int)$x['id'], 'status' => $x['status'], 'triggerType' => $x['trigger_type'], 'entityType' => $x['entity_type'], 'entityId' => $x['entity_id'] === null ? null : (int)$x['entity_id'],
        'scheduledFor' => $x['scheduled_for'], 'startedAt' => $x['started_at'], 'finishedAt' => $x['finished_at'], 'attempts' => (int)$x['attempts'], 'result' => json_decode((string)$x['result_json'], true), 'error' => $x['error'], 'workflowVersion' => (int)$x['workflow_version']]; },
        Db::all('SELECT * FROM workflow_executions WHERE workflow_id=? ORDER BY id DESC LIMIT ' . $n, [$wid])); }
    public static function meta(): array { Auth::require('workflows.manage'); return Workflow::meta(); }
    public static function list(): array { Auth::require('workflows.manage'); $st = (string)($_GET['status'] ?? ''); $p = []; $w = "w.status<>'archived'"; if ($st !== '') { Validate::enum(['s' => $st], 's', ['draft', 'active', 'disabled', 'archived']); $w = 'w.status=?'; $p[] = $st; }
        return ['items' => array_map([self::class, 'out'], Db::all("SELECT w.*, u.full_name creator FROM workflows w LEFT JOIN users u ON u.id=w.created_by WHERE $w ORDER BY w.name", $p))]; }
    public static function get(array $pa): array { Auth::require('workflows.manage'); return ['workflow' => self::out(self::find((int)$pa['id']), true)]; }
    public static function executions(array $pa): array { Auth::require('workflows.manage'); self::find((int)$pa['id']); return ['items' => self::execs((int)$pa['id'], 200)]; }
    public static function create(): array { $u = Auth::require('workflows.manage'); $d = Http::body(); $v = Workflow::validate($d);
        $id = Db::insert("INSERT INTO workflows (name, description, scope, status, trigger_type, trigger_config_json, conditions_json, actions_json, delay_minutes, definition_json, is_active, created_by, updated_by, created_at, updated_at, version) VALUES (?,?,?,'draft',?,?,?,?,?,NULL,0,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)",
            [Validate::str($d, 'name', 190, true), Validate::str($d, 'description', 500), $v['scope'], $v['trigger_type'], $v['trigger_config_json'], $v['conditions_json'], $v['actions_json'], $v['delay_minutes'], (int)$u['id'], (int)$u['id']]);
        Audit::log((int)$u['id'], 'workflow.created', 'workflow', $id); return ['workflow' => self::out(self::find($id), true)]; }
    public static function update(array $pa): array { $u = Auth::require('workflows.manage'); $cur = self::find((int)$pa['id']); $d = Http::body(); if ($cur['status'] === 'archived') throw new HttpError(409, 'Archived workflows cannot be edited');
        if (isset($d['version']) && (int)$d['version'] !== (int)$cur['version']) throw new HttpError(409, 'This workflow was changed by someone else. Reload and try again.');
        $d = array_merge(['triggerType' => $cur['trigger_type'], 'triggerConfig' => json_decode((string)$cur['trigger_config_json'], true), 'conditions' => json_decode((string)$cur['conditions_json'], true), 'actions' => json_decode((string)$cur['actions_json'], true), 'delayMinutes' => $cur['delay_minutes'], 'name' => $cur['name'], 'description' => $cur['description']], $d);
        $v = Workflow::validate($d);
        Db::exec('UPDATE workflows SET name=?, description=?, scope=?, trigger_type=?, trigger_config_json=?, conditions_json=?, actions_json=?, delay_minutes=?, updated_by=?, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?',
            [Validate::str($d, 'name', 190, true), Validate::str($d, 'description', 500), $v['scope'], $v['trigger_type'], $v['trigger_config_json'], $v['conditions_json'], $v['actions_json'], $v['delay_minutes'], (int)$u['id'], $cur['id']]);
        Audit::log((int)$u['id'], 'workflow.updated', 'workflow', $cur['id']); return ['workflow' => self::out(self::find((int)$cur['id']), true)]; }
    public static function setStatus(array $pa, string $to): array { $u = Auth::require('workflows.manage'); $cur = self::find((int)$pa['id']); if ($cur['status'] === 'archived' && $to !== 'archived') throw new HttpError(409, 'Archived workflows cannot be re-enabled; duplicate it instead');
        Db::exec('UPDATE workflows SET status=?, is_active=?, updated_by=?, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?', [$to, $to === 'active' ? 1 : 0, (int)$u['id'], $cur['id']]);
        if ($to !== 'active') Db::exec("UPDATE scheduled_jobs j JOIN workflow_executions x ON j.dedupe_key=CONCAT('wx:', x.id) SET j.status='cancelled', j.updated_at=UTC_TIMESTAMP() WHERE x.workflow_id=? AND x.status='pending' AND j.status='pending'", [$cur['id']]);
        if ($to !== 'active') Db::exec("UPDATE workflow_executions SET status='skipped', error='Workflow disabled before it ran', finished_at=UTC_TIMESTAMP() WHERE workflow_id=? AND status='pending'", [$cur['id']]);
        Audit::log((int)$u['id'], 'workflow.' . $to, 'workflow', $cur['id']); return ['workflow' => self::out(self::find((int)$cur['id']), true)]; }
    public static function duplicate(array $pa): array { $u = Auth::require('workflows.manage'); $c = self::find((int)$pa['id']);
        $id = Db::insert("INSERT INTO workflows (name, description, scope, status, trigger_type, trigger_config_json, conditions_json, actions_json, delay_minutes, is_active, duplicated_from, created_by, updated_by, created_at, updated_at, version) VALUES (?,?,?,'draft',?,?,?,?,?,0,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)",
            [mb_substr($c['name'] . ' (copy)', 0, 190), $c['description'], $c['scope'], $c['trigger_type'], $c['trigger_config_json'], $c['conditions_json'], $c['actions_json'], $c['delay_minutes'], $c['id'], (int)$u['id'], (int)$u['id']]);
        Audit::log((int)$u['id'], 'workflow.duplicated', 'workflow', $id); return ['workflow' => self::out(self::find($id), true)]; }
    public static function test(array $pa): array { Auth::require('workflows.manage'); $w = self::find((int)$pa['id']); $d = Http::body(); $et = Workflow::TRIGGERS[$w['trigger_type']] ?? null; $id = $et ? (int)($d['entityId'] ?? 0) : null;
        if ($et && !Workflow::entity($et, $id)) throw new HttpError(422, Validate::MSG, ['entityId' => "Choose an existing $et to test with"]); return ['preview' => Workflow::preview($w, $et, $id)]; }
    public static function retryExecution(array $pa): array { $u = Auth::require('workflows.manage'); $x = Db::one('SELECT * FROM workflow_executions WHERE id=?', [(int)$pa['id']]); if (!$x) throw new HttpError(404, 'Execution not found');
        if ($x['status'] !== 'failed') throw new HttpError(409, 'Only failed executions can be retried'); Db::exec("UPDATE workflow_executions SET status='pending', error=NULL WHERE id=?", [$x['id']]);
        Jobs::enqueue('workflow.run', ['executionId' => (int)$x['id']], null, 'wx:' . $x['id'] . ':retry' . ((int)$x['attempts'] + 1), 3); Audit::log((int)$u['id'], 'workflow.execution_retried', 'workflow', $x['workflow_id']); return ['ok' => true]; }
}
