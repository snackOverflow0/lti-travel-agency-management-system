<?php
declare(strict_types=1);
namespace LTI;
/** Conservative phone-number normalisation to E.164. It is NOT full worldwide validation: international numbers (+CC...) are
 *  checked for a known calling code and a plausible length (8–15 digits); national formats are accepted only for the default
 *  country when its rules are known (NANP fully; selected countries by trunk-prefix rule). */
final class Phone {
    const CODES = ['1' => 'NANP', '7' => 'RU/KZ', '20' => 'EG', '27' => 'ZA', '30' => 'GR', '31' => 'NL', '32' => 'BE', '33' => 'FR', '34' => 'ES', '36' => 'HU', '39' => 'IT', '40' => 'RO', '41' => 'CH', '43' => 'AT', '44' => 'GB', '45' => 'DK', '46' => 'SE', '47' => 'NO', '48' => 'PL', '49' => 'DE',
        '51' => 'PE', '52' => 'MX', '54' => 'AR', '55' => 'BR', '56' => 'CL', '57' => 'CO', '60' => 'MY', '61' => 'AU', '62' => 'ID', '63' => 'PH', '64' => 'NZ', '65' => 'SG', '66' => 'TH', '81' => 'JP', '82' => 'KR', '84' => 'VN', '86' => 'CN', '90' => 'TR', '91' => 'IN',
        '212' => 'MA', '254' => 'KE', '255' => 'TZ', '351' => 'PT', '353' => 'IE', '354' => 'IS', '852' => 'HK', '886' => 'TW', '971' => 'AE', '972' => 'IL', '974' => 'QA', '966' => 'SA'];
    /** country => [calling code, national rule]: 'nanp', 'trunk0' (drop leading 0), 'keep' (no trunk prefix). */
    const COUNTRIES = ['US' => ['1', 'nanp'], 'CA' => ['1', 'nanp'], 'GB' => ['44', 'trunk0'], 'IE' => ['353', 'trunk0'], 'FR' => ['33', 'trunk0'], 'DE' => ['49', 'trunk0'], 'NL' => ['31', 'trunk0'], 'BE' => ['32', 'trunk0'], 'CH' => ['41', 'trunk0'], 'AT' => ['43', 'trunk0'],
        'AU' => ['61', 'trunk0'], 'NZ' => ['64', 'trunk0'], 'JP' => ['81', 'trunk0'], 'PH' => ['63', 'trunk0'], 'IN' => ['91', 'trunk0'], 'ZA' => ['27', 'trunk0'], 'KE' => ['254', 'trunk0'], 'AE' => ['971', 'trunk0'], 'IT' => ['39', 'keep'], 'ES' => ['34', 'keep'], 'PT' => ['351', 'keep'], 'MX' => ['52', 'keep']];
    public static function defaultCountry(): string { $c = (string)(Db::one("SELECT setting_value v FROM app_settings WHERE setting_key='default_phone_country'")['v'] ?? 'US'); return isset(self::COUNTRIES[$c]) ? $c : 'US'; }
    /** @return array{e164:?string, display:string, country:?string, validation:string, error:?string} */
    public static function normalize(string $raw, ?string $country = null): array {
        $raw = trim($raw); $country = $country && isset(self::COUNTRIES[$country]) ? $country : self::defaultCountry();
        $fail = function ($e) use ($raw) { return ['e164' => null, 'display' => $raw, 'country' => null, 'validation' => 'invalid', 'error' => $e]; };
        if ($raw === '' || !preg_match('/^[0-9+().\-\s\/]{3,40}$/', $raw)) return $fail('Use digits, spaces and + ( ) - only');
        $intl = substr(ltrim($raw), 0, 1) === '+'; $d = preg_replace('/\D/', '', $raw);
        if (!$intl && substr($d, 0, 2) === '00') { $intl = true; $d = substr($d, 2); }
        if (!$intl && self::COUNTRIES[$country][1] === 'nanp' && substr($d, 0, 3) === '011') { $intl = true; $d = substr($d, 3); }
        if (!$intl) { [$cc, $rule] = self::COUNTRIES[$country];
            if ($rule === 'nanp') { if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1); if (strlen($d) !== 10) return $fail('US/Canada numbers need 10 digits'); $d = '1' . $d; }
            elseif ($rule === 'trunk0') { if ($d[0] !== '0') return $fail('Enter the number with its leading 0 or in +' . $cc . ' format'); $d = $cc . substr($d, 1); }
            else $d = $cc . $d; }
        if (strlen($d) < 8 || strlen($d) > 15) return $fail('International numbers have 8 to 15 digits');
        $code = null; foreach ([3, 2, 1] as $n) if (isset(self::CODES[substr($d, 0, $n)])) { $code = substr($d, 0, $n); break; }
        if ($code === null) return $fail('Unknown country calling code');
        if ($code === '1') { $n = substr($d, 1); if (strlen($n) !== 10 || !preg_match('/^[2-9]\d{2}[2-9]\d{6}$/', $n)) return $fail('Not a valid North American number'); return ['e164' => '+' . $d, 'display' => sprintf('+1 (%s) %s-%s', substr($n, 0, 3), substr($n, 3, 3), substr($n, 6)), 'country' => 'NANP', 'validation' => 'nanp_rules', 'error' => null]; }
        $nat = substr($d, strlen($code)); return ['e164' => '+' . $d, 'display' => '+' . $code . ' ' . trim(chunk_split($nat, 3, ' ')), 'country' => self::CODES[$code], 'validation' => 'length_only', 'error' => null];
    }
    /** Keep the contact's main phone field mirrored in contact_phones (source contact_field). */
    public static function syncContactField(int $contactId, ?string $phone): void {
        Db::exec("DELETE FROM contact_phones WHERE contact_id=? AND source='contact_field'", [$contactId]);
        if ($phone === null || trim($phone) === '') return;
        $n = self::normalize($phone); $pref = Db::one('SELECT id FROM contact_phones WHERE contact_id=? AND is_preferred=1', [$contactId]) ? 0 : 1;
        Db::exec("INSERT IGNORE INTO contact_phones (contact_id, label, phone_raw, phone_e164, is_preferred, source, created_at) VALUES (?,'mobile',?,?,?,'contact_field',UTC_TIMESTAMP())", [$contactId, mb_substr($phone, 0, 40), $n['e164'], $pref]);
    }
}
