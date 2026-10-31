<?php
declare(strict_types=1);
namespace LTI;
final class Audit {
    public static function log(?int $userId, string $action, string $entity = '', $entityId = null, array $detail = []): void {
        Db::exec('INSERT INTO audit_log (user_id, action, entity_type, entity_id, detail, ip, created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())',
            [$userId, $action, $entity, $entityId === null ? null : (string)$entityId, $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null, Http::ip()]);
    }
}
