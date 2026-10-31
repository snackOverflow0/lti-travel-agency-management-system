<?php
declare(strict_types=1);
namespace LTI;
use PDO;
final class Db {
    private static ?PDO $pdo = null;
    public static function pdo(): PDO {
        if (self::$pdo === null) {
            $c = $GLOBALS['LTI_CFG']['db'];
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int)($c['port'] ?? 3306), $c['name']);
            self::$pdo = new PDO($dsn, $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
            self::$pdo->exec("SET time_zone = '+00:00'");
        }
        return self::$pdo;
    }
    public static function one(string $sql, array $p = []): ?array { $s = self::pdo()->prepare($sql); $s->execute($p); $r = $s->fetch(); return $r === false ? null : $r; }
    public static function all(string $sql, array $p = []): array { $s = self::pdo()->prepare($sql); $s->execute($p); return $s->fetchAll(); }
    public static function exec(string $sql, array $p = []): int { $s = self::pdo()->prepare($sql); $s->execute($p); return $s->rowCount(); }
    public static function insert(string $sql, array $p = []): int { self::exec($sql, $p); return (int)self::pdo()->lastInsertId(); }
    public static function tx(callable $fn) { $pdo = self::pdo(); $pdo->beginTransaction(); try { $r = $fn(); $pdo->commit(); return $r; } catch (\Throwable $e) { $pdo->rollBack(); throw $e; } }
}
