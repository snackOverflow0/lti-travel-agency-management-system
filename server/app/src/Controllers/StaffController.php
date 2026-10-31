<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Auth, Audit, Db, Http, HttpError};
final class StaffController {
    public static function list(): array {
        Auth::require('staff.view');
        return ['items' => array_map(function ($r) { return ['id' => (int)$r['id'], 'email' => $r['email'], 'fullName' => $r['full_name'], 'role' => $r['role'], 'roleName' => $r['role_name'], 'isActive' => (bool)$r['is_active'], 'lastLoginAt' => $r['last_login_at']]; },
            Db::all('SELECT u.id,u.email,u.full_name,u.is_active,u.last_login_at,r.code role,r.name role_name FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.full_name'))];
    }
    public static function setActive(array $p): array {
        $admin = Auth::require('staff.manage'); $d = Http::body(); $id = (int)$p['id']; $active = !empty($d['isActive']);
        if ($id === (int)$admin['id'] && !$active) throw new HttpError(422, 'You cannot deactivate your own account');
        $t = Db::one('SELECT u.id, r.code role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?', [$id]);
        if (!$t) throw new HttpError(404, 'Staff member not found');
        if ($t['role'] === 'owner' && $admin['role'] !== 'owner') throw new HttpError(403, 'Only the owner can change the owner account');
        Db::exec('UPDATE users SET is_active=? WHERE id=?', [$active ? 1 : 0, $id]);
        Audit::log((int)$admin['id'], $active ? 'staff.reactivated' : 'staff.deactivated', 'user', $id);
        return ['ok' => true];
    }
}
