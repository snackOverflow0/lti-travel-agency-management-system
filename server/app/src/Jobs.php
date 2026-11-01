<?php
declare(strict_types=1);
namespace LTI;
/** MySQL-backed job queue. Claiming is a single atomic UPDATE ... LIMIT 1 (works on MySQL 5.7+ and MariaDB without SKIP LOCKED);
 *  each claim carries a lease, so a crashed worker's job is recovered after the lease expires. */
final class Jobs {
    const NON_IDEMPOTENT = ['email.send'];   // never retried blindly after an unknown outcome
    public static function enqueue(string $type, array $payload, ?string $runAtUtc = null, ?string $dedupe = null, int $maxAttempts = 3): ?int {
        Db::exec('INSERT IGNORE INTO scheduled_jobs (job_type, payload_json, run_at, status, attempts, max_attempts, dedupe_key, created_at, updated_at) VALUES (?,?,?,\'pending\',0,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())',
            [$type, json_encode($payload, JSON_UNESCAPED_UNICODE), $runAtUtc ?: gmdate('Y-m-d H:i:s'), $maxAttempts, $dedupe]);
        $id = (int)Db::pdo()->lastInsertId(); if ($id) return $id;
        $r = $dedupe ? Db::one('SELECT id FROM scheduled_jobs WHERE dedupe_key=?', [$dedupe]) : null; return $r ? (int)$r['id'] : null;
    }
    public static function claim(string $worker, int $leaseSeconds = 120): ?array {
        $n = Db::exec("UPDATE scheduled_jobs SET status='running', locked_by=?, locked_until=UTC_TIMESTAMP() + INTERVAL ? SECOND, attempts=attempts+1, started_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP()
                       WHERE status='pending' AND run_at <= UTC_TIMESTAMP() ORDER BY run_at, id LIMIT 1", [$worker, $leaseSeconds]);
        if (!$n) return null;
        return Db::one("SELECT * FROM scheduled_jobs WHERE status='running' AND locked_by=? ORDER BY updated_at DESC, id DESC LIMIT 1", [$worker]);
    }
    private static function log(array $j, string $result, ?string $msg): void {
        Db::exec('INSERT INTO job_execution_logs (job_id, attempt, worker, started_at, finished_at, result, message) VALUES (?,?,?,?,UTC_TIMESTAMP(),?,?)', [$j['id'], $j['attempts'], $j['locked_by'], $j['started_at'], $result, $msg === null ? null : mb_substr($msg, 0, 255)]);
    }
    public static function complete(array $j, ?string $msg = null): void {
        Db::exec("UPDATE scheduled_jobs SET status='completed', finished_at=UTC_TIMESTAMP(), locked_by=NULL, locked_until=NULL, last_error=NULL, updated_at=UTC_TIMESTAMP() WHERE id=? AND locked_by=?", [$j['id'], $j['locked_by']]);
        self::log($j, 'completed', $msg);
    }
    /** Retry with exponential back-off while attempts remain; otherwise fail and flag for review. */
    public static function fail(array $j, string $error, bool $retryable = true, bool $review = false): void {
        $error = self::redact($error);
        if ($retryable && (int)$j['attempts'] < (int)$j['max_attempts']) {
            $delay = min(3600, 60 * (2 ** max(0, (int)$j['attempts'] - 1)));
            Db::exec("UPDATE scheduled_jobs SET status='pending', run_at=UTC_TIMESTAMP() + INTERVAL ? SECOND, locked_by=NULL, locked_until=NULL, last_error=?, updated_at=UTC_TIMESTAMP() WHERE id=?", [$delay, mb_substr($error, 0, 255), $j['id']]);
            self::log($j, 'retry', $error); return;
        }
        Db::exec("UPDATE scheduled_jobs SET status='failed', finished_at=UTC_TIMESTAMP(), locked_by=NULL, locked_until=NULL, last_error=?, needs_review=?, updated_at=UTC_TIMESTAMP() WHERE id=?", [mb_substr($error, 0, 255), $review || $retryable ? 1 : 0, $j['id']]);
        self::log($j, 'failed', $error);
    }
    /** Jobs whose lease expired (worker crashed): idempotent jobs go back to pending; external sends are flagged for review, never resent. */
    public static function recoverAbandoned(): int {
        $n = 0;
        foreach (Db::all("SELECT * FROM scheduled_jobs WHERE status='running' AND locked_until < UTC_TIMESTAMP() LIMIT 100") as $j) {
            if (in_array($j['job_type'], self::NON_IDEMPOTENT, true)) {
                $p = json_decode($j['payload_json'], true) ?: [];
                if (!empty($p['emailId'])) Db::exec("UPDATE emails SET status='unknown', error='Worker stopped during sending; delivery outcome unknown. Review before resending.', updated_at=UTC_TIMESTAMP() WHERE id=? AND status='sending'", [$p['emailId']]);
                Db::exec("UPDATE scheduled_jobs SET status='failed', needs_review=1, finished_at=UTC_TIMESTAMP(), last_error='Abandoned during an external send; outcome unknown', locked_by=NULL, locked_until=NULL, updated_at=UTC_TIMESTAMP() WHERE id=? AND status='running'", [$j['id']]);
            } elseif ((int)$j['attempts'] < (int)$j['max_attempts']) {
                Db::exec("UPDATE scheduled_jobs SET status='pending', locked_by=NULL, locked_until=NULL, last_error='Recovered after an expired worker lease', updated_at=UTC_TIMESTAMP() WHERE id=? AND status='running'", [$j['id']]);
            } else {
                Db::exec("UPDATE scheduled_jobs SET status='failed', needs_review=1, finished_at=UTC_TIMESTAMP(), last_error='Abandoned after the maximum attempts', locked_by=NULL, locked_until=NULL, updated_at=UTC_TIMESTAMP() WHERE id=? AND status='running'", [$j['id']]);
            }
            self::log($j, 'abandoned', 'Lease expired for worker ' . $j['locked_by']); $n++;
        }
        return $n;
    }
    /** Remove anything that looks like a secret before it reaches logs or API responses. */
    public static function redact(string $s): string {
        $s = preg_replace('/(password|passwd|pwd|secret|token|authorization|auth)\s*[:=]\s*\S+/i', '$1=[redacted]', $s);
        $cfg = $GLOBALS['LTI_CFG']['mail'] ?? []; foreach (['password', 'username'] as $k) if (!empty($cfg[$k]) && strlen((string)$cfg[$k]) >= 4) $s = str_replace((string)$cfg[$k], '[redacted]', $s);
        return mb_substr($s, 0, 500);
    }
}
