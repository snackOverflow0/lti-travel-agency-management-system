<?php
declare(strict_types=1);
namespace LTI;
/** Records real domain events (consumed by the workflow engine) and the CRM activity timeline.
 *  Summaries never contain email bodies, credentials or payment references. */
final class Events {
    public static function emit(string $type, string $entityType, int $entityId, ?int $actor, string $summary, array $payload = []): void {
        Db::exec('INSERT INTO domain_events (event_type, entity_type, entity_id, actor_id, payload_json, created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())', [$type, $entityType, $entityId, $actor, $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null]);
        self::activity($entityType, $entityId, $type, $actor, $summary, $payload);
    }
    public static function activity(string $entityType, int $entityId, string $type, ?int $actor, string $summary, array $meta = []): void {
        Db::exec('INSERT INTO activity_events (entity_type, entity_id, event_type, actor_id, summary, meta_json, created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())', [$entityType, $entityId, $type, $actor, mb_substr($summary, 0, 255), $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null]);
    }
    public static function notify(int $userId, string $category, string $message, ?string $refType, ?int $refId, string $source = 'event', ?string $dedupe = null): void {
        Db::exec('INSERT IGNORE INTO notifications (user_id, message, ref_type, ref_id, category, source, dedupe_key, created_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())', [$userId, mb_substr($message, 0, 255), $refType, $refId, $category, $source, $dedupe]);
    }
}
