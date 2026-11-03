<?php
declare(strict_types=1);
namespace LTI;
final class Http {
    public static function body(): array {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') return [];
        if (strlen($raw) > 1048576) throw new HttpError(413, 'Request too large');
        $d = json_decode($raw, true);
        if (!is_array($d)) throw new HttpError(400, 'Invalid JSON body');
        return $d;
    }
    public static function json($data, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        self::securityHeaders();
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    public static function ip(): string {
        if (!empty($GLOBALS['LTI_CFG']['security']['trust_proxy']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    /** Security headers for every API response (JSON, downloads, CSV). */
    public static function securityHeaders(): void {
        if (headers_sent()) return; header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY'); header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; sandbox"); header('Cross-Origin-Resource-Policy: same-origin');
        if (self::isHttps()) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    public static function isHttps(): bool { return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' && !empty($GLOBALS['LTI_CFG']['trust_proxy_https'])) || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443; }
}
