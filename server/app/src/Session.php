<?php
declare(strict_types=1);
namespace LTI;
final class Session {
    public static function start(): void {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $c = $GLOBALS['LTI_CFG']['session'];
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == '443');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name($c['name'] ?? 'LTISESS');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
        $now = time();
        if (!empty($_SESSION['uid'])) {
            $idle = (int)($c['idle_minutes'] ?? 30) * 60; $abs = (int)($c['absolute_hours'] ?? 12) * 3600;
            if ($now - (int)($_SESSION['last'] ?? 0) > $idle || $now - (int)($_SESSION['born'] ?? 0) > $abs) { self::destroy(); session_start(); }
        }
        $_SESSION['last'] = $now;
    }
    public static function login(int $uid): void { session_regenerate_id(true); $_SESSION = ['uid' => $uid, 'born' => time(), 'last' => time(), 'csrf' => bin2hex(random_bytes(32))]; }
    public static function destroy(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) { $p = session_get_cookie_params(); setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']); }
        session_destroy();
    }
    public static function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
    public static function checkCsrf(): void {
        $t = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($t) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) throw new HttpError(403, 'Invalid or missing CSRF token');
    }
}
