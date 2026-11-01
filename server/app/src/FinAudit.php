<?php
declare(strict_types=1);
namespace LTI;
/** Append-only, hash-chained financial audit log. Each row stores the previous row's hash, so any edit or deletion
 *  of past rows is detectable with verify(). The application exposes no update or delete for this table. */
final class FinAudit {
    public static function log(?int $actor, string $action, string $entity, int $id, $before = null, $after = null, ?string $reason = null): void {
        $prev = Db::one('SELECT hash FROM financial_audit_events ORDER BY id DESC LIMIT 1 FOR UPDATE'); $prevHash = $prev ? $prev['hash'] : str_repeat('0', 64);
        $now = gmdate('Y-m-d H:i:s'); $b = $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE); $a = $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE);
        $hash = hash('sha256', implode('|', [$prevHash, (string)$actor, $action, $entity, (string)$id, (string)$b, (string)$a, (string)$reason, $now]));
        Db::exec('INSERT INTO financial_audit_events (actor_id, action, entity_type, entity_id, before_json, after_json, reason, created_at, prev_hash, hash) VALUES (?,?,?,?,?,?,?,?,?,?)', [$actor, $action, $entity, $id, $b, $a, $reason, $now, $prevHash, $hash]);
    }
    public static function verify(): array {
        $prev = str_repeat('0', 64); $n = 0;
        foreach (Db::all('SELECT * FROM financial_audit_events ORDER BY id') as $r) {
            $h = hash('sha256', implode('|', [$prev, (string)$r['actor_id'], $r['action'], $r['entity_type'], (string)$r['entity_id'], (string)$r['before_json'], (string)$r['after_json'], (string)$r['reason'], $r['created_at']]));
            if ($r['prev_hash'] !== $prev || $r['hash'] !== $h) return ['valid' => false, 'brokenAt' => (int)$r['id'], 'checked' => $n];
            $prev = $r['hash']; $n++;
        }
        return ['valid' => true, 'checked' => $n];
    }
}
