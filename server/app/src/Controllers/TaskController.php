<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Auth, Db, Events, Http, HttpError, Validate, Workflow};
final class TaskController {
    const STATUSES = ['todo', 'in_progress', 'blocked', 'completed', 'archived']; const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    private static function scope(array $u): array { return Auth::can('tasks.view_all') ? ['1=1', []] : ['(k.assigned_to=? OR k.created_by=?)', [(int)$u['id'], (int)$u['id']]]; }
    private static function find(array $u, int $id): array { [$w, $p] = self::scope($u); $r = Db::one("SELECT k.*, a.full_name assignee, c.full_name creator FROM tasks k LEFT JOIN users a ON a.id=k.assigned_to LEFT JOIN users c ON c.id=k.created_by WHERE k.id=? AND $w", array_merge([$id], $p)); if (!$r) throw new HttpError(404, 'Task not found'); return $r; }
    /** Linked-record labels are shown only when the viewer may see that record; a task never grants access to CRM data. */
    private static function label(array $u, string $type, ?int $id): ?array {
        if (!$id) return null;
        try { if ($type === 'contact') { $r = ContactController::find($u, $id); return ['id' => $id, 'label' => trim($r['first_name'] . ' ' . $r['last_name'])]; }
            if ($type === 'trip') { $r = TripController::findRow($u, $id); return ['id' => $id, 'label' => $r['name']]; }
            if ($type === 'booking') { $b = Db::one('SELECT reference, trip_id FROM bookings WHERE id=?', [$id]); if ($b) { TripController::findRow($u, (int)$b['trip_id']); return ['id' => $id, 'label' => $b['reference']]; } }
            if ($type === 'invoice' && Auth::can('finance.view')) { $r = InvoiceController::find($u, $id); return ['id' => $id, 'label' => $r['number']]; } }
        catch (HttpError $e) { }
        return ['id' => null, 'label' => 'Restricted record'];
    }
    private static function out(array $u, array $r, bool $full = false): array {
        $today = Workflow::agencyToday();
        $o = ['id' => (int)$r['id'], 'title' => $r['title'], 'description' => $r['description'], 'status' => $r['status'], 'priority' => $r['priority'], 'dueDate' => $r['due_date'],
            'overdue' => $r['due_date'] && $r['due_date'] < $today && !in_array($r['status'], ['completed', 'archived'], true), 'assignedTo' => $r['assigned_to'] === null ? null : (int)$r['assigned_to'], 'assigneeName' => $r['assignee'] ?? null,
            'createdBy' => $r['creator'] ?? null, 'source' => $r['source'], 'completedAt' => $r['completed_at'], 'version' => (int)$r['version'], 'createdAt' => $r['created_at'],
            'contact' => self::label($u, 'contact', $r['contact_id'] ? (int)$r['contact_id'] : null), 'trip' => self::label($u, 'trip', $r['trip_id'] ? (int)$r['trip_id'] : null),
            'booking' => self::label($u, 'booking', $r['booking_id'] ? (int)$r['booking_id'] : null), 'invoice' => self::label($u, 'invoice', $r['invoice_id'] ? (int)$r['invoice_id'] : null)];
        if ($full) { $o['comments'] = array_map(function ($c) { return ['id' => (int)$c['id'], 'body' => $c['body'], 'by' => $c['full_name'], 'at' => $c['created_at']]; }, Db::all('SELECT c.*, u.full_name FROM task_comments c LEFT JOIN users u ON u.id=c.user_id WHERE c.task_id=? ORDER BY c.id', [$r['id']]));
            $o['history'] = array_map(function ($h) { return ['field' => $h['field'], 'from' => $h['old_value'], 'to' => $h['new_value'], 'by' => $h['full_name'], 'at' => $h['created_at']]; }, Db::all('SELECT h.*, u.full_name FROM task_history h LEFT JOIN users u ON u.id=h.user_id WHERE h.task_id=? ORDER BY h.id', [$r['id']])); }
        return $o;
    }
    public static function list(): array {
        $u = Auth::require('tasks.manage'); [$w, $p] = self::scope($u); $where = [$w]; $today = Workflow::agencyToday();
        $st = (string)($_GET['status'] ?? ''); if ($st === 'open') $where[] = "k.status IN ('todo','in_progress','blocked')"; elseif ($st !== '') { Validate::enum(['s' => $st], 's', self::STATUSES); $where[] = 'k.status=?'; $p[] = $st; } else $where[] = "k.status<>'archived'";
        if (($pr = (string)($_GET['priority'] ?? '')) !== '') { Validate::enum(['p' => $pr], 'p', self::PRIORITIES); $where[] = 'k.priority=?'; $p[] = $pr; }
        if (($a = (string)($_GET['assignedTo'] ?? '')) !== '') { $where[] = 'k.assigned_to=?'; $p[] = $a === 'me' ? (int)$u['id'] : (int)$a; }
        if (!empty($_GET['overdue'])) { $where[] = "k.due_date < ? AND k.status IN ('todo','in_progress','blocked')"; $p[] = $today; }
        if (($up = (int)($_GET['upcomingDays'] ?? 0)) > 0) { $where[] = "k.due_date BETWEEN ? AND ? AND k.status IN ('todo','in_progress','blocked')"; array_push($p, $today, (new \DateTime($today))->modify("+$up days")->format('Y-m-d')); }
        foreach (['contactId' => 'k.contact_id', 'tripId' => 'k.trip_id', 'bookingId' => 'k.booking_id', 'invoiceId' => 'k.invoice_id'] as $k => $c) if (($v = (int)($_GET[$k] ?? 0)) > 0) { $where[] = "$c=?"; $p[] = $v; }
        if (($q = trim((string)($_GET['q'] ?? ''))) !== '') { $l = '%' . addcslashes($q, '%_\\') . '%'; $where[] = '(k.title LIKE ? OR k.description LIKE ?)'; array_push($p, $l, $l); }
        $page = max(1, (int)($_GET['page'] ?? 1)); $per = min(200, max(1, (int)($_GET['perPage'] ?? 50))); $sql = implode(' AND ', $where);
        $rows = Db::all("SELECT k.*, a.full_name assignee, c.full_name creator FROM tasks k LEFT JOIN users a ON a.id=k.assigned_to LEFT JOIN users c ON c.id=k.created_by WHERE $sql ORDER BY (k.due_date IS NULL), k.due_date, FIELD(k.priority,'urgent','high','normal','low'), k.id LIMIT $per OFFSET " . (($page - 1) * $per), $p);
        return ['total' => (int)Db::one("SELECT COUNT(*) n FROM tasks k WHERE $sql", $p)['n'], 'items' => array_map(function ($r) use ($u) { return self::out($u, $r); }, $rows)];
    }
    public static function get(array $pa): array { $u = Auth::require('tasks.manage'); return ['task' => self::out($u, self::find($u, (int)$pa['id']), true)]; }
    private static function links(array $u, array $d, ?array $cur): array {
        $o = []; foreach (['contactId' => 'contact_id', 'tripId' => 'trip_id', 'bookingId' => 'booking_id', 'invoiceId' => 'invoice_id'] as $k => $col) {
            if (!array_key_exists($k, $d)) { $o[$col] = $cur ? $cur[$col] : null; continue; } $id = $d[$k] ? (int)$d[$k] : null; $o[$col] = $id; if (!$id) continue;
            if ($col === 'contact_id') ContactController::find($u, $id); elseif ($col === 'trip_id') TripController::findRow($u, $id);
            elseif ($col === 'booking_id') { $b = Db::one('SELECT trip_id FROM bookings WHERE id=?', [$id]); if (!$b) throw new HttpError(422, Validate::MSG, ['bookingId' => 'Unknown booking']); TripController::findRow($u, (int)$b['trip_id']); }
            else { Auth::require('finance.view'); InvoiceController::find($u, $id); }
        }
        return $o;
    }
    private static function assignee(array $u, $v): ?int { if ($v === null || $v === '') return null; $id = (int)$v; if (!Db::one('SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.is_active=1 AND r.is_staff=1', [$id])) throw new HttpError(422, Validate::MSG, ['assignedTo' => 'Unknown staff member']); return $id; }
    private static function hist(int $tid, int $uid, string $f, $a, $b): void { Db::exec('INSERT INTO task_history (task_id, user_id, field, old_value, new_value, created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())', [$tid, $uid, $f, $a === null ? null : mb_substr((string)$a, 0, 190), $b === null ? null : mb_substr((string)$b, 0, 190)]); }
    private static function linkedActivity(array $links, string $type, int $actor, string $summary): void { foreach (['contact_id' => 'contact', 'trip_id' => 'trip', 'booking_id' => 'booking'] as $c => $t) if (!empty($links[$c])) { Events::activity($t, (int)$links[$c], $type, $actor, $summary); } }
    public static function create(): array {
        $u = Auth::require('tasks.manage'); $d = Http::body(); $links = self::links($u, $d, null); $as = array_key_exists('assignedTo', $d) ? self::assignee($u, $d['assignedTo']) : (int)$u['id'];
        $id = Db::tx(function () use ($u, $d, $links, $as) {
            $id = Db::insert("INSERT INTO tasks (title, description, due_date, priority, status, assigned_to, contact_id, trip_id, booking_id, invoice_id, source, created_by, created_at, updated_at, version) VALUES (?,?,?,?,?,?,?,?,?,?,'Manual',?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)",
                [Validate::str($d, 'title', 255, true), Validate::str($d, 'description', 5000), Validate::date($d, 'dueDate'), Validate::enum($d, 'priority', self::PRIORITIES, 'normal'), Validate::enum($d, 'status', ['todo', 'in_progress', 'blocked'], 'todo'), $as, $links['contact_id'], $links['trip_id'], $links['booking_id'], $links['invoice_id'], (int)$u['id']]);
            self::hist($id, (int)$u['id'], 'assigned_to', null, $as); if ($as && $as !== (int)$u['id']) Events::notify($as, 'task_assignment', $u['full_name'] . ' assigned you: ' . mb_substr($d['title'], 0, 180), 'task', $id, 'event');
            self::linkedActivity($links, 'task.created', (int)$u['id'], 'Task created: ' . mb_substr($d['title'], 0, 200)); return $id; });
        return self::get(['id' => $id]);
    }
    public static function update(array $pa): array {
        $u = Auth::require('tasks.manage'); $d = Http::body();
        Db::tx(function () use ($u, $pa, $d) { $cur = self::find($u, (int)$pa['id']); if ($cur['status'] === 'archived') throw new HttpError(409, 'Archived tasks cannot be edited');
            if (isset($d['version']) && (int)$d['version'] !== (int)$cur['version']) throw new HttpError(409, 'This task was changed by someone else. Reload and try again.');
            $links = self::links($u, $d, $cur); $as = array_key_exists('assignedTo', $d) ? self::assignee($u, $d['assignedTo']) : ($cur['assigned_to'] === null ? null : (int)$cur['assigned_to']);
            $st = array_key_exists('status', $d) ? Validate::enum($d, 'status', ['todo', 'in_progress', 'blocked'], $cur['status']) : $cur['status']; if ($st === 'completed' && $cur['status'] !== 'completed') throw new HttpError(422, 'Use Complete to finish a task');
            $title = array_key_exists('title', $d) ? Validate::str($d, 'title', 255, true) : $cur['title']; $pri = array_key_exists('priority', $d) ? Validate::enum($d, 'priority', self::PRIORITIES, $cur['priority']) : $cur['priority']; $due = array_key_exists('dueDate', $d) ? Validate::date($d, 'dueDate') : $cur['due_date'];
            Db::exec('UPDATE tasks SET title=?, description=?, due_date=?, priority=?, status=?, assigned_to=?, contact_id=?, trip_id=?, booking_id=?, invoice_id=?, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?',
                [$title, array_key_exists('description', $d) ? Validate::str($d, 'description', 5000) : $cur['description'], $due, $pri, $st, $as, $links['contact_id'], $links['trip_id'], $links['booking_id'], $links['invoice_id'], $cur['id']]);
            foreach ([['assigned_to', $cur['assigned_to'], $as], ['status', $cur['status'], $st], ['priority', $cur['priority'], $pri], ['due_date', $cur['due_date'], $due]] as [$f, $a, $b]) if ((string)$a !== (string)$b) self::hist((int)$cur['id'], (int)$u['id'], $f, $a, $b);
            if ((string)$cur['assigned_to'] !== (string)$as && $as && $as !== (int)$u['id']) Events::notify($as, 'task_assignment', $u['full_name'] . ' assigned you: ' . mb_substr($title, 0, 180), 'task', (int)$cur['id'], 'event'); });
        return self::get($pa);
    }
    public static function complete(array $pa, bool $reopen = false): array {
        $u = Auth::require('tasks.manage');
        Db::tx(function () use ($u, $pa, $reopen) { $t = self::find($u, (int)$pa['id']);
            if ($reopen) { if ($t['status'] !== 'completed') throw new HttpError(409, 'Only completed tasks can be reopened'); Db::exec("UPDATE tasks SET status='todo', completed_at=NULL, completed_by=NULL, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?", [$t['id']]); self::hist((int)$t['id'], (int)$u['id'], 'status', 'completed', 'todo'); return; }
            if (in_array($t['status'], ['completed', 'archived'], true)) throw new HttpError(409, 'Task is already ' . $t['status']);
            Db::exec("UPDATE tasks SET status='completed', completed_at=UTC_TIMESTAMP(), completed_by=?, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?", [(int)$u['id'], $t['id']]); self::hist((int)$t['id'], (int)$u['id'], 'status', $t['status'], 'completed');
            Events::emit('task.completed', 'task', (int)$t['id'], (int)$u['id'], 'Task completed: ' . mb_substr($t['title'], 0, 200)); self::linkedActivity($t, 'task.completed', (int)$u['id'], 'Task completed: ' . mb_substr($t['title'], 0, 200)); });
        return self::get($pa);
    }
    public static function archive(array $pa): array { $u = Auth::require('tasks.manage'); Db::tx(function () use ($u, $pa) { $t = self::find($u, (int)$pa['id']); if ($t['status'] === 'archived') throw new HttpError(409, 'Already archived');
        Db::exec("UPDATE tasks SET status='archived', archived_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?", [$t['id']]); self::hist((int)$t['id'], (int)$u['id'], 'status', $t['status'], 'archived'); }); return self::get($pa); }
    public static function comment(array $pa): array { $u = Auth::require('tasks.manage'); $t = self::find($u, (int)$pa['id']); $b = Validate::str(Http::body(), 'body', 5000, true);
        Db::exec('INSERT INTO task_comments (task_id, user_id, body, created_at) VALUES (?,?,?,UTC_TIMESTAMP())', [$t['id'], (int)$u['id'], $b]);
        if ($t['assigned_to'] && (int)$t['assigned_to'] !== (int)$u['id']) Events::notify((int)$t['assigned_to'], 'task_comment', $u['full_name'] . ' commented on: ' . mb_substr($t['title'], 0, 160), 'task', (int)$t['id'], 'event'); return self::get($pa); }
}
