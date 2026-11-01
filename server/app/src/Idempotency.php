<?php
declare(strict_types=1);
namespace LTI;
/** Replays the stored response when a financial POST is repeated with the same Idempotency-Key header. */
final class Idempotency {
    public static function run(string $path, callable $handler): array {
        $key = (string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
        if ($key === '') return [$handler(), null];
        if (!preg_match('/^[A-Za-z0-9_\-:.]{8,80}$/', $key)) throw new HttpError(422, 'Invalid Idempotency-Key');
        $u = Auth::require(); $raw = file_get_contents('php://input') ?: ''; $h = hash('sha256', $path . '|' . $raw);
        try { Db::insert('INSERT INTO idempotency_keys (user_id, idem_key, request_path, request_hash, created_at) VALUES (?,?,?,?,UTC_TIMESTAMP())', [$u['id'], $key, $path, $h]); }
        catch (\PDOException $e) {
            $r = Db::one('SELECT * FROM idempotency_keys WHERE user_id=? AND idem_key=?', [$u['id'], $key]);
            if (!$r) throw $e;
            if ($r['request_hash'] !== $h) throw new HttpError(422, 'This Idempotency-Key was already used for a different request');
            if ($r['status_code'] === null) throw new HttpError(409, 'The same request is still being processed');
            return [json_decode($r['response_json'], true), (int)$r['status_code']];
        }
        try { $out = $handler(); Db::exec('UPDATE idempotency_keys SET status_code=200, response_json=? WHERE user_id=? AND idem_key=?', [json_encode($out, JSON_UNESCAPED_UNICODE), $u['id'], $key]); return [$out, null]; }
        catch (\Throwable $e) { Db::exec('DELETE FROM idempotency_keys WHERE user_id=? AND idem_key=? AND status_code IS NULL', [$u['id'], $key]); throw $e; }   // failed requests may be retried
    }
}
