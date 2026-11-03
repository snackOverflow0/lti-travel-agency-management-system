<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Audit, Auth, Db, Http, HttpError, Mailer, Phone, Validate};
/** Agency settings stored in app_settings. Reading is open to staff; changing requires staff.manage. Every change is audited. */
final class SettingsController {
    const DATE_FORMATS = ['MMM D, YYYY', 'D MMM YYYY', 'YYYY-MM-DD', 'MM/DD/YYYY', 'DD/MM/YYYY'];
    const BOOKING_FIELDS = ['confirmationNumber' => 'Confirmation number', 'supplier' => 'Supplier', 'travelers' => 'At least one traveler', 'amount' => 'Booking amount'];
    public static function get_(string $k, string $def = ''): string { $r = Db::one('SELECT setting_value v FROM app_settings WHERE setting_key=?', [$k]); return $r ? (string)$r['v'] : $def; }
    private static function set(string $k, string $v, int $uid): void { Db::exec('INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at) VALUES (?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by=VALUES(updated_by), updated_at=VALUES(updated_at)', [$k, $v, $uid]); }
    public static function bookingRequired(): array { $v = json_decode(self::get_('booking_required_fields', '[]'), true); return is_array($v) ? array_values(array_intersect($v, array_keys(self::BOOKING_FIELDS))) : []; }
    public static function get(): array { $u = Auth::require('contacts.view');
        return ['agency' => ['name' => self::get_('agency_name', 'Luxury Travel Institute'), 'email' => self::get_('agency_email'), 'phone' => self::get_('agency_phone'), 'address' => self::get_('agency_address'), 'website' => self::get_('agency_website'), 'brandColor' => self::get_('brand_color', '#c19a3b')],
            'regional' => ['timezone' => self::get_('agency_timezone', 'UTC'), 'dateFormat' => self::get_('date_format', 'MMM D, YYYY'), 'weekStart' => self::get_('week_start', 'Sun'), 'defaultPhoneCountry' => self::get_('default_phone_country', 'US'), 'reportingCurrency' => self::get_('reporting_currency', 'USD')],
            'email' => ['signature' => self::get_('email_signature'), 'transport' => Mailer::status()],
            'bookingRequired' => self::bookingRequired(), 'bookingFieldOptions' => self::BOOKING_FIELDS,
            'terms' => ['text' => self::get_('terms_text'), 'publishedAt' => self::get_('terms_published_at') ?: null],
            'canEdit' => Auth::can('staff.manage'), 'options' => ['dateFormats' => self::DATE_FORMATS, 'phoneCountries' => array_keys(Phone::COUNTRIES)]]; }
    public static function update(array $pa): array { $u = Auth::require('staff.manage'); $d = Http::body(); $sec = (string)$pa['section']; $uid = (int)$u['id']; $before = self::get();
        switch ($sec) {
            case 'agency': $n = Validate::str($d, 'name', 120, true); $e = Validate::str($d, 'email', 190); if ($e && !filter_var($e, FILTER_VALIDATE_EMAIL)) throw new HttpError(422, Validate::MSG, ['email' => 'Enter a valid email']);
                $ph = Validate::str($d, 'phone', 40); if ($ph && Phone::normalize($ph)['error']) throw new HttpError(422, Validate::MSG, ['phone' => Phone::normalize($ph)['error']]);
                $web = Validate::str($d, 'website', 190); if ($web && !preg_match('#^https?://[^\s<>"]+$#i', $web)) throw new HttpError(422, Validate::MSG, ['website' => 'Start with http:// or https://']);
                $col = (string)($d['brandColor'] ?? '#c19a3b'); if (!preg_match('/^#[0-9a-fA-F]{6}$/', $col)) throw new HttpError(422, Validate::MSG, ['brandColor' => 'Use a colour like #c19a3b']);
                foreach (['agency_name' => $n, 'agency_email' => (string)$e, 'agency_phone' => (string)$ph, 'agency_address' => (string)Validate::str($d, 'address', 500), 'agency_website' => (string)$web, 'brand_color' => $col] as $k => $v) self::set($k, $v, $uid); break;
            case 'regional': try { new \DateTimeZone((string)($d['timezone'] ?? '')); } catch (\Throwable $x) { throw new HttpError(422, Validate::MSG, ['timezone' => 'Unknown time zone']); }
                $df = Validate::enum($d, 'dateFormat', self::DATE_FORMATS); $ws = Validate::enum($d, 'weekStart', ['Sun', 'Mon']); $pc = (string)($d['defaultPhoneCountry'] ?? ''); if (!$df || !$ws || !isset(Phone::COUNTRIES[$pc])) throw new HttpError(422, Validate::MSG, ['regional' => 'Choose valid regional options']);
                foreach (['agency_timezone' => (string)$d['timezone'], 'date_format' => $df, 'week_start' => $ws, 'default_phone_country' => $pc] as $k => $v) self::set($k, $v, $uid); break;
            case 'email': self::set('email_signature', (string)Validate::str($d, 'signature', 2000), $uid); break;
            case 'booking': $req = is_array($d['required'] ?? null) ? $d['required'] : []; foreach ($req as $f) if (!isset(self::BOOKING_FIELDS[$f])) throw new HttpError(422, Validate::MSG, ['required' => 'Unknown field ' . $f]); self::set('booking_required_fields', json_encode(array_values(array_unique($req))), $uid); break;
            case 'terms': self::set('terms_text', (string)Validate::str($d, 'text', 50000), $uid); if (array_key_exists('publish', $d)) self::set('terms_published_at', !empty($d['publish']) ? gmdate('Y-m-d H:i:s') : '', $uid); break;
            default: throw new HttpError(404, 'Unknown settings section');
        }
        Audit::log($uid, 'settings.updated', 'settings', null, ['section' => $sec]); return self::get(); }
    /** Clients see the agency terms only after they are published. */
    public static function portalTerms(): array { Auth::require('portal.access'); $p = self::get_('terms_published_at'); return ['published' => $p !== '', 'text' => $p !== '' ? self::get_('terms_text') : null, 'publishedAt' => $p ?: null]; }
}
