<?php
declare(strict_types=1);
namespace LTI;
/** Allowlisted placeholder renderer: {{group.field}} only. No expressions, code or filters are ever evaluated. */
final class Template {
    const VARS = ['contact.first_name' => 'Contact first name', 'contact.last_name' => 'Contact last name', 'trip.name' => 'Trip name', 'trip.departure_date' => 'Trip departure date', 'trip.start_date' => 'Trip start date', 'trip.end_date' => 'Trip end date', 'trip.destination' => 'Trip destination', 'trip.link' => 'Client portal link',
        'booking.reference' => 'Booking reference', 'invoice.number' => 'Invoice number', 'invoice.balance' => 'Invoice balance (with currency)', 'advisor.name' => 'Assigned advisor name', 'agency.name' => 'Agency name'];
    const ALIASES = ['client.first_name' => 'contact.first_name', 'client.last_name' => 'contact.last_name'];   // used by the Revision 27 sample templates
    public static function tokens(string $text): array { preg_match_all('/\{\{\s*([^{}]*?)\s*\}\}/', $text, $m); return $m[1]; }
    /** Unknown or malformed placeholders -> list of problems (empty when valid). */
    public static function validate(string $text): array {
        $bad = []; foreach (self::tokens($text) as $t) { $k = self::ALIASES[$t] ?? $t; if (!isset(self::VARS[$k])) $bad[] = '{{' . $t . '}}'; }
        if (preg_match('/\{\{[^}]*$|<\?|<script/i', $text)) $bad[] = 'unclosed placeholder or executable markup';
        return array_values(array_unique($bad));
    }
    /** Build values for the linked records (caller has already checked the user may see them). */
    public static function context(?int $contactId, ?int $tripId, ?int $bookingId, ?int $invoiceId): array {
        $v = ['agency.name' => (string)(Db::one("SELECT setting_value v FROM app_settings WHERE setting_key='agency_name'")['v'] ?? 'Luxury Travel Institute')];
        if ($bookingId && ($b = Db::one('SELECT reference, trip_id FROM bookings WHERE id=?', [$bookingId]))) { $v['booking.reference'] = $b['reference']; $tripId = $tripId ?: (int)$b['trip_id']; }
        if ($invoiceId && ($i = Db::one('SELECT number, balance, currency, trip_id, contact_id FROM invoices WHERE id=?', [$invoiceId]))) { $v['invoice.number'] = $i['number']; $v['invoice.balance'] = $i['currency'] . ' ' . Money::show($i['balance'], $i['currency']); $tripId = $tripId ?: ($i['trip_id'] ? (int)$i['trip_id'] : null); $contactId = $contactId ?: ($i['contact_id'] ? (int)$i['contact_id'] : null); }
        if ($tripId && ($t = Db::one('SELECT t.name, t.start_date, t.end_date, t.destination, t.primary_contact_id, u.full_name advisor FROM trips t LEFT JOIN users u ON u.id=t.owner_id WHERE t.id=?', [$tripId]))) { $v['trip.name'] = $t['name']; if ($t['start_date']) { $v['trip.departure_date'] = $v['trip.start_date'] = date('F j, Y', strtotime($t['start_date'])); } if ($t['end_date']) $v['trip.end_date'] = date('F j, Y', strtotime($t['end_date'])); if ($t['destination']) $v['trip.destination'] = $t['destination']; if (!empty($GLOBALS['LTI_CFG']['app_url'])) $v['trip.link'] = rtrim($GLOBALS['LTI_CFG']['app_url'], '/') . '/#/portal'; if ($t['advisor']) $v['advisor.name'] = $t['advisor']; $contactId = $contactId ?: ($t['primary_contact_id'] ? (int)$t['primary_contact_id'] : null); }
        if ($contactId && ($c = Db::one('SELECT c.first_name, c.last_name, u.full_name advisor FROM contacts c LEFT JOIN users u ON u.id=c.assigned_to WHERE c.id=?', [$contactId]))) { $v['contact.first_name'] = $c['first_name']; $v['contact.last_name'] = (string)$c['last_name']; if (empty($v['advisor.name']) && $c['advisor']) $v['advisor.name'] = $c['advisor']; }
        return $v;
    }
    /** Returns [renderedText, missing[]]. Values are inserted as plain text; HTML is produced later by escaping. */
    public static function render(string $text, array $ctx): array {
        $missing = [];
        $out = preg_replace_callback('/\{\{\s*([^{}]*?)\s*\}\}/', function ($m) use ($ctx, &$missing) { $k = self::ALIASES[$m[1]] ?? $m[1]; if (!isset(self::VARS[$k])) { $missing[] = $m[1]; return ''; } if (!isset($ctx[$k]) || $ctx[$k] === '') { $missing[] = $k; return ''; } return str_replace(["\r", "\0"], '', (string)$ctx[$k]); }, $text);
        return [$out, array_values(array_unique($missing))];
    }
    public static function html(string $plain): string { return '<div style="font-family:Georgia,serif;font-size:15px;line-height:1.5;color:#1d2433">' . nl2br(htmlspecialchars($plain, ENT_QUOTES, 'UTF-8')) . '</div>'; }
}
