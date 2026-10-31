<?php
declare(strict_types=1);
namespace LTI;
final class Validate {
    const MSG = 'Please correct the highlighted fields';
    public static function str(array $d, string $k, int $max, bool $req = false): ?string {
        $v = isset($d[$k]) ? trim((string)$d[$k]) : '';
        if ($v === '') { if ($req) throw new HttpError(422, self::MSG, [$k => 'Required']); return null; }
        if (mb_strlen($v) > $max) throw new HttpError(422, self::MSG, [$k => "Maximum $max characters"]);
        return $v;
    }
    public static function email(array $d, string $k, bool $req = false): ?string {
        $v = self::str($d, $k, 190, $req); if ($v === null) return null;
        if (!filter_var($v, FILTER_VALIDATE_EMAIL)) throw new HttpError(422, self::MSG, [$k => 'Enter a valid email address']);
        return mb_strtolower($v);
    }
    public static function date(array $d, string $k): ?string {
        $v = self::str($d, $k, 10); if ($v === null) return null;
        $dt = \DateTime::createFromFormat('!Y-m-d', $v);
        if (!$dt || $dt->format('Y-m-d') !== $v) throw new HttpError(422, self::MSG, [$k => 'Use YYYY-MM-DD']);
        return $v;
    }
    public static function enum(array $d, string $k, array $allowed, ?string $default = null): ?string {
        $v = isset($d[$k]) && $d[$k] !== '' ? (string)$d[$k] : $default;
        if ($v !== null && !in_array($v, $allowed, true)) throw new HttpError(422, self::MSG, [$k => 'Invalid value']);
        return $v;
    }
}
