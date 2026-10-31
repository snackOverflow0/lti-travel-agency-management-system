<?php
declare(strict_types=1);
namespace LTI;
final class Auth {
    private static ?array $user = null;
    public static function user(): ?array {
        if (self::$user !== null) return self::$user;
        $uid = (int)($_SESSION['uid'] ?? 0);
        if (!$uid) return null;
        $u = Db::one('SELECT u.id, u.email, u.full_name, u.role_id, r.code AS role, u.is_active FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?', [$uid]);
        if (!$u || !(int)$u['is_active']) { Session::destroy(); return null; }   // deactivation takes effect on the next request
        $u['permissions'] = array_column(Db::all('SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=? ORDER BY p.code', [$u['role_id']]), 'code');
        return self::$user = $u;
    }
    public static function require(?string $perm = null): array {
        $u = self::user();
        if (!$u) throw new HttpError(401, 'Sign in required');
        if ($perm !== null && !in_array($perm, $u['permissions'], true)) throw new HttpError(403, 'Your role does not allow this action');
        return $u;
    }
    public static function can(string $perm): bool { $u = self::user(); return $u !== null && in_array($perm, $u['permissions'], true); }
    public static function publicUser(array $u): array { return ['id' => (int)$u['id'], 'email' => $u['email'], 'fullName' => $u['full_name'], 'role' => $u['role'], 'permissions' => $u['permissions']]; }
    public static function login(string $email, string $password): array {
        $cfg = $GLOBALS['LTI_CFG']['security']; $ip = Http::ip(); $email = mb_strtolower(trim($email));
        $win = (int)($cfg['login_window_minutes'] ?? 15); $max = (int)($cfg['login_max_failures'] ?? 5);
        $fails = (int)(Db::one('SELECT COUNT(*) n FROM login_attempts WHERE success=0 AND (email=? OR ip=?) AND created_at > (UTC_TIMESTAMP() - INTERVAL ? MINUTE)', [$email, $ip, $win])['n'] ?? 0);
        if ($fails >= $max) { Audit::log(null, 'auth.rate_limited', 'user', null, ['email' => $email]); throw new HttpError(429, "Too many failed sign-in attempts. Try again in $win minutes."); }
        $u = Db::one('SELECT id, password_hash, is_active FROM users WHERE email=?', [$email]);
        $ok = $u && password_verify($password, $u['password_hash']);
        if (!$u) password_hash($password, PASSWORD_DEFAULT); // keep timing similar for unknown emails
        Db::exec('INSERT INTO login_attempts (email, ip, success, created_at) VALUES (?,?,?,UTC_TIMESTAMP())', [$email, $ip, $ok && (int)$u['is_active'] ? 1 : 0]);
        if (!$ok) { Audit::log($u ? (int)$u['id'] : null, 'auth.login_failed', 'user', $u['id'] ?? null); throw new HttpError(401, 'Email or password is incorrect'); }
        if (!(int)$u['is_active']) { Audit::log((int)$u['id'], 'auth.login_blocked_inactive', 'user', $u['id']); throw new HttpError(403, 'This account is deactivated. Contact your administrator.'); }
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) Db::exec('UPDATE users SET password_hash=? WHERE id=?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        Session::login((int)$u['id']);
        Db::exec('UPDATE users SET last_login_at=UTC_TIMESTAMP() WHERE id=?', [$u['id']]);
        Audit::log((int)$u['id'], 'auth.login', 'user', $u['id']);
        self::$user = null; return self::require();
    }
    public static function validatePassword(string $p): void { if (mb_strlen($p) < 12) throw new HttpError(422, 'Password must be at least 12 characters', ['password' => 'At least 12 characters']); }
}
