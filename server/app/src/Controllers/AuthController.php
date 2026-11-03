<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Auth, Audit, Db, Http, HttpError, Session};
final class AuthController {
    public static function login(): array {
        if (!empty($GLOBALS['LTI_CFG']['require_https']) && !\LTI\Http::isHttps()) throw new HttpError(403, 'Sign-in requires a secure (HTTPS) connection.');
        $d = Http::body();
        $u = Auth::login((string)($d['email'] ?? ''), (string)($d['password'] ?? ''));
        return ['user' => Auth::publicUser($u), 'csrf' => Session::csrf()];
    }
    public static function logout(): array { $u = Auth::user(); if ($u) Audit::log((int)$u['id'], 'auth.logout', 'user', $u['id']); Session::destroy(); return ['ok' => true]; }
    public static function me(): array { $u = Auth::require(); return ['user' => Auth::publicUser($u), 'csrf' => Session::csrf()]; }
    /** Administrator-assisted recovery: works without email. Email delivery is added once the company SMTP mailbox is verified. */
    public static function createResetLink(array $p): array {
        $admin = Auth::require('staff.manage');
        $u = Db::one('SELECT id, email FROM users WHERE id=?', [(int)$p['id']]);
        if (!$u) throw new HttpError(404, 'Staff member not found');
        return self::issueResetLink((int)$admin['id'], (int)$u['id']);
    }
    public static function issueResetLink(int $adminId, int $userId): array {
        $token = bin2hex(random_bytes(32));
        Db::exec('UPDATE password_resets SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL', [$userId]);
        Db::exec('INSERT INTO password_resets (user_id, token_hash, expires_at, created_by, created_at) VALUES (?,?,UTC_TIMESTAMP() + INTERVAL 60 MINUTE,?,UTC_TIMESTAMP())', [$userId, hash('sha256', $token), $adminId]);
        Audit::log($adminId, 'auth.reset_link_created', 'user', $userId);
        return ['resetUrl' => rtrim($GLOBALS['LTI_CFG']['app_url'], '/') . '/#/reset?token=' . $token, 'expiresInMinutes' => 60];
    }
    public static function resetPassword(): array {
        $d = Http::body(); $token = (string)($d['token'] ?? ''); $pw = (string)($d['password'] ?? '');
        Auth::validatePassword($pw);
        $r = Db::one('SELECT id, user_id FROM password_resets WHERE token_hash=? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()', [hash('sha256', $token)]);
        if (!$r) throw new HttpError(400, 'This reset link is invalid or has expired');
        Db::tx(function () use ($r, $pw) {
            Db::exec('UPDATE users SET password_hash=? WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $r['user_id']]);
            Db::exec('UPDATE password_resets SET used_at=UTC_TIMESTAMP() WHERE id=?', [$r['id']]);
        });
        Audit::log((int)$r['user_id'], 'auth.password_reset', 'user', $r['user_id']);
        return ['ok' => true];
    }
}
