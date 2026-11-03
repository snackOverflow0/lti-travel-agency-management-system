<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Auth, Db, Workflow};
/** Live dashboard figures, limited to records the signed-in user may see. Empty databases return zeros and empty lists. */
final class DashboardController {
    public static function get(): array {
        $u = Auth::require('contacts.view'); $uid = (int)$u['id']; $today = Workflow::agencyToday(); $in30 = (new \DateTime($today))->modify('+30 days')->format('Y-m-d');
        [$cw, $cp] = ContactController::scope($u); [$tw, $tp] = TripController::scope($u);
        $contacts = Db::one("SELECT COUNT(*) n, SUM(c.contact_type='Client') clients, SUM(c.contact_type='Lead') leads FROM contacts c WHERE c.deleted_at IS NULL AND $cw", $cp);
        $trips = Db::one("SELECT SUM(t.status='Traveling') traveling, SUM(t.status='Inquiry') inquiries, SUM(t.status NOT IN ('Cancelled','Archived','Traveled')) open_trips,
            SUM(t.start_date BETWEEN ? AND ? AND t.status NOT IN ('Cancelled','Archived')) departing FROM trips t WHERE t.deleted_at IS NULL AND t.archived_at IS NULL AND t.trip_type IN ('standard','group') AND $tw", array_merge([$today, $in30], $tp));
        $upcoming = array_map(function ($r) { return ['id' => (int)$r['id'], 'name' => $r['name'], 'startDate' => $r['start_date'], 'status' => $r['status'], 'destination' => $r['destination']]; },
            Db::all("SELECT t.id, t.name, t.start_date, t.status, t.destination FROM trips t WHERE t.deleted_at IS NULL AND t.archived_at IS NULL AND t.trip_type IN ('standard','group') AND t.start_date >= ? AND t.status NOT IN ('Cancelled','Archived','Traveled') AND $tw ORDER BY t.start_date LIMIT 6", array_merge([$today], $tp)));
        $bk = Db::one("SELECT COUNT(*) n, SUM(b.status='confirmed') confirmed, SUM(b.status='pending') pending FROM bookings b JOIN trips t ON t.id=b.trip_id WHERE b.status<>'cancelled' AND $tw", $tp);
        $tasks = Db::one("SELECT SUM(status IN ('todo','in_progress','blocked')) open_tasks, SUM(status IN ('todo','in_progress','blocked') AND due_date < ?) overdue, SUM(status IN ('todo','in_progress','blocked') AND due_date BETWEEN ? AND ?) due_week FROM tasks WHERE assigned_to=?", [$today, $today, (new \DateTime($today))->modify('+7 days')->format('Y-m-d'), $uid]);
        $myTasks = array_map(function ($r) use ($today) { return ['id' => (int)$r['id'], 'title' => $r['title'], 'dueDate' => $r['due_date'], 'priority' => $r['priority'], 'overdue' => $r['due_date'] !== null && $r['due_date'] < $today]; },
            Db::all("SELECT id, title, due_date, priority FROM tasks WHERE assigned_to=? AND status IN ('todo','in_progress','blocked') ORDER BY (due_date IS NULL), due_date, FIELD(priority,'urgent','high','normal','low') LIMIT 6", [$uid]));
        $cb = (int)Db::one("SELECT COUNT(*) n FROM callbacks WHERE assigned_to=? AND status='pending'", [$uid])['n']; $fu = (int)Db::one("SELECT COUNT(*) n FROM scheduled_followups WHERE assigned_to=? AND status='pending'", [$uid])['n'];
        $unread = (int)Db::one('SELECT COUNT(*) n FROM notifications WHERE user_id=? AND read_at IS NULL AND dismissed_at IS NULL', [$uid])['n'];
        $act = Db::all("SELECT a.entity_type, a.entity_id, a.summary, a.created_at, u.full_name FROM activity_events a LEFT JOIN users u ON u.id=a.actor_id
            WHERE (a.entity_type='contact' AND EXISTS (SELECT 1 FROM contacts c WHERE c.id=a.entity_id AND c.deleted_at IS NULL AND $cw)) OR (a.entity_type='trip' AND EXISTS (SELECT 1 FROM trips t WHERE t.id=a.entity_id AND $tw))
            ORDER BY a.id DESC LIMIT 8", array_merge($cp, $tp));
        return ['asOf' => $today, 'contacts' => ['total' => (int)$contacts['n'], 'clients' => (int)$contacts['clients'], 'leads' => (int)$contacts['leads']],
            'trips' => ['open' => (int)$trips['open_trips'], 'traveling' => (int)$trips['traveling'], 'inquiries' => (int)$trips['inquiries'], 'departingIn30Days' => (int)$trips['departing'], 'upcoming' => $upcoming],
            'bookings' => ['active' => (int)$bk['n'], 'confirmed' => (int)$bk['confirmed'], 'pending' => (int)$bk['pending']],
            'tasks' => ['open' => (int)$tasks['open_tasks'], 'overdue' => (int)$tasks['overdue'], 'dueThisWeek' => (int)$tasks['due_week'], 'mine' => $myTasks],
            'followUps' => ['callbacks' => $cb, 'scheduled' => $fu], 'notificationsUnread' => $unread,
            'recentActivity' => array_map(function ($r) { return ['entityType' => $r['entity_type'], 'entityId' => (int)$r['entity_id'], 'summary' => $r['summary'], 'by' => $r['full_name'] ?: 'System', 'at' => $r['created_at']]; }, $act),
            'financeVisible' => Auth::can('finance.view')];
    }
}
