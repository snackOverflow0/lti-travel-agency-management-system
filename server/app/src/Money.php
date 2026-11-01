<?php
declare(strict_types=1);
namespace LTI;
/** Exact money arithmetic. Amounts are integers in each currency's minor unit; products and quotients use
 *  decimal-string long arithmetic, so no binary floating point is ever used for authoritative values. */
final class Money {
    private static array $minor = [];
    public static function minorUnits(string $ccy): int {
        if (!isset(self::$minor[$ccy])) { $r = Db::one('SELECT minor_units FROM currencies WHERE code=? AND is_active=1', [$ccy]); if (!$r) throw new HttpError(422, 'Unsupported currency', ['currency' => "Unknown or inactive currency $ccy"]); self::$minor[$ccy] = (int)$r['minor_units']; }
        return self::$minor[$ccy];
    }
    public static function currency($v): string { $c = strtoupper(trim((string)$v)); if (!preg_match('/^[A-Z]{3}$/', $c)) throw new HttpError(422, 'Unsupported currency', ['currency' => 'Use a 3-letter ISO code']); self::minorUnits($c); return $c; }
    /** Parse a decimal amount ("1234.50", 1234.5 is refused as a float would be imprecise in JSON only if given more decimals than allowed). */
    public static function parse($v, int $minor, string $field = 'amount', bool $allowNegative = false): int {
        $s = is_int($v) ? (string)$v : trim((string)$v);
        if (is_float($v)) $s = rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');
        if (!preg_match('/^(-)?(\d{1,13})(?:\.(\d+))?$/', $s, $m)) throw new HttpError(422, 'Please correct the highlighted fields', [$field => 'Enter a valid amount']);
        $frac = $m[3] ?? ''; if (strlen(rtrim($frac, '0')) > $minor) throw new HttpError(422, 'Please correct the highlighted fields', [$field => "At most $minor decimal places for this currency"]);
        $val = (int)($m[2] . str_pad(substr($frac, 0, $minor), $minor, '0'));
        if ($m[1] === '-') { if (!$allowNegative) throw new HttpError(422, 'Please correct the highlighted fields', [$field => 'Must not be negative']); $val = -$val; }
        return $val;
    }
    /** Display a stored DECIMAL in the currency's own minor units (e.g. JPY 146000.00 -> 146000). */
    public static function show($v, string $ccy): string { if ($v === null) return Money::fmt(0, self::minorUnits($ccy)); $m = self::minorUnits($ccy); return self::fmt(self::parse((string)$v, $m, 'v', true), $m); }
    public static function fmt(int $amount, int $minor): string { $neg = $amount < 0; $a = (string)abs($amount); if ($minor === 0) return ($neg ? '-' : '') . $a; $a = str_pad($a, $minor + 1, '0', STR_PAD_LEFT); return ($neg ? '-' : '') . substr($a, 0, -$minor) . '.' . substr($a, -$minor); }
    /** Fixed-point decimal string -> scaled integer string (e.g. "12.5", 4 -> "125000"). */
    public static function scaled(string $v, int $places, string $field): string {
        $v = trim($v); if (!preg_match('/^(\d{1,10})(?:\.(\d+))?$/', $v, $m) || strlen(rtrim($m[2] ?? '', '0')) > $places) throw new HttpError(422, 'Please correct the highlighted fields', [$field => "Enter a number with at most $places decimals"]);
        return ltrim($m[1] . str_pad(substr($m[2] ?? '', 0, $places), $places, '0'), '0') ?: '0';
    }
    /** round_half_up(a * b / c) for integers given as int or decimal strings. */
    public static function mulDiv($a, $b, $c): int {
        $a = (string)$a; $b = (string)$b; $c = (string)$c; $neg = (($a[0] === '-') xor ($b[0] === '-')); $a = ltrim($a, '-'); $b = ltrim($b, '-');
        $p = self::mul($a, $b); [$q, $r] = self::divmod($p, $c);
        if (self::cmp(self::mul($r, '2'), $c) >= 0) $q = self::add($q, '1');
        if (strlen($q) > 18) throw new HttpError(422, 'Amount out of range');
        return $neg ? -(int)$q : (int)$q;
    }
    public static function pct(int $amount, string $pctScaled4): int { return self::mulDiv($amount, $pctScaled4, '1000000'); }   // pct with 4 decimals scaled
    private static function norm(string $s): string { $s = ltrim($s, '0'); return $s === '' ? '0' : $s; }
    private static function cmp(string $a, string $b): int { $a = self::norm($a); $b = self::norm($b); return strlen($a) <=> strlen($b) ?: strcmp($a, $b) <=> 0; }
    private static function add(string $a, string $b): string { $r = ''; $c = 0; for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0 || $j >= 0 || $c; $i--, $j--) { $s = ($i >= 0 ? (int)$a[$i] : 0) + ($j >= 0 ? (int)$b[$j] : 0) + $c; $r = ($s % 10) . $r; $c = intdiv($s, 10); } return self::norm($r); }
    private static function sub(string $a, string $b): string { $r = ''; $br = 0; for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0; $i--, $j--) { $s = (int)$a[$i] - ($j >= 0 ? (int)$b[$j] : 0) - $br; $br = $s < 0 ? 1 : 0; $r = ($s + ($br ? 10 : 0)) . $r; } return self::norm($r); }
    private static function mul(string $a, string $b): string { $a = self::norm($a); $b = self::norm($b); if ($a === '0' || $b === '0') return '0'; $res = array_fill(0, strlen($a) + strlen($b), 0);
        for ($i = strlen($a) - 1; $i >= 0; $i--) for ($j = strlen($b) - 1; $j >= 0; $j--) { $k = $i + $j + 1; $res[$k] += (int)$a[$i] * (int)$b[$j]; $res[$k - 1] += intdiv($res[$k], 10); $res[$k] %= 10; }
        return self::norm(implode('', $res)); }
    private static function divmod(string $a, string $b): array { $b = self::norm($b); if ($b === '0') throw new HttpError(500, 'Division by zero'); $q = ''; $r = '0';
        for ($i = 0, $n = strlen($a); $i < $n; $i++) { $r = self::norm($r . $a[$i]); $d = 0; while (self::cmp($r, $b) >= 0) { $r = self::sub($r, $b); $d++; } $q .= $d; } return [self::norm($q), $r]; }
}
