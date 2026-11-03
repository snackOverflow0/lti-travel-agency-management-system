<?php
// Phase 9: settings, booking controls (server-enforced), terms, tags, roles and permissions, travelers, support requests, settings import.
declare(strict_types=1);
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/') . '/api'; $results = []; $jars = []; $tok = [];
$cfg = require getenv('LTI_CONFIG'); $pdo = new PDO("mysql:host={$cfg['db']['host']};dbname={$cfg['db']['name']};charset=utf8mb4", $cfg['db']['user'], $cfg['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$one = function ($sql) use ($pdo) { $r = $pdo->query($sql)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; };
function rq(string $who, string $m, string $p, $b = null): array { global $base, $jars, $tok; $jars[$who] = $jars[$who] ?? tempnam(sys_get_temp_dir(), 'jar'); $ch = curl_init($base . $p); $h = ['Accept: application/json']; if (!empty($tok[$who]) && $m !== 'GET') $h[] = 'X-CSRF-Token: ' . $tok[$who];
  if ($b !== null) { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($b)); } curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_COOKIEJAR => $jars[$who], CURLOPT_COOKIEFILE => $jars[$who]]);
  $r = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); unset($ch); return [$c, json_decode((string)$r, true) ?? []]; }
function login(string $who, string $e): void { global $tok; [$c, $d] = rq($who, 'POST', '/auth/login', ['email' => $e, 'password' => 'Dev-Password-2026']); if ($c !== 200) throw new Exception("login $e $c"); $tok[$who] = $d['csrf']; }
function t(string $n, callable $f): void { global $results; try { $results[] = [$n, true, (string)$f()]; } catch (Throwable $e) { $results[] = [$n, false, $e->getMessage()]; } }
function ok($c, string $m): void { if (!$c) throw new Exception($m); }
foreach (['own' => 'owner@lti.test', 'adm' => 'admin@lti.test', 'adv' => 'advisor@lti.test', 'adv2' => 'advisor2@lti.test', 'sal' => 'sales@lti.test', 'trv' => 'traveler@lti.test'] as $k => $e) login($k, $e);
$amalfi = (int)$one("SELECT id FROM trips WHERE name='Amalfi Coast Anniversary'"); $shah = (int)$one("SELECT id FROM contacts WHERE email='shah@example.com'");
t('A01 Settings: staff can read; only administrators change; validation enforced', function () use ($one) { [$c0] = rq('adv', 'GET', '/settings'); [$c1] = rq('adv', 'PUT', '/settings/agency', ['name' => 'X']);
  [$c2] = rq('own', 'PUT', '/settings/agency', ['name' => 'Luxury Travel Institute', 'email' => 'not-an-email']); [$c3] = rq('own', 'PUT', '/settings/regional', ['timezone' => 'Mars/Base', 'dateFormat' => 'YYYY-MM-DD', 'weekStart' => 'Mon', 'defaultPhoneCountry' => 'US']);
  [$c4] = rq('own', 'PUT', '/settings/agency', ['name' => 'Luxury Travel Institute', 'email' => 'advisors@lti.test', 'phone' => '+1 (512) 555-0100', 'address' => '1 Main St', 'website' => 'https://lti.test', 'brandColor' => '#c19a3b']);
  ok($c0 === 200 && $c1 === 403 && $c2 === 422 && $c3 === 422 && $c4 === 200 && $one("SELECT setting_value FROM app_settings WHERE setting_key='agency_email'") === 'advisors@lti.test', json_encode([$c0, $c1, $c2, $c3, $c4])); return 'advisor read 200 / write 403; bad email and time zone 422; saved to app_settings'; });
t('A02 Booking controls are enforced by the server when confirming', function () use ($amalfi, $shah, $one) {
  [$c0] = rq('own', 'PUT', '/settings/booking', ['required' => ['confirmationNumber', 'travelers']]); [, $s] = rq('own', 'POST', '/suppliers', ['name' => 'Controls Hotel', 'category' => 'hotel']);
  [$c1, $b] = rq('adv', 'POST', '/bookings', ['tripId' => $amalfi, 'supplierId' => $s['supplier']['id'], 'category' => 'accommodation', 'amount' => '900.00', 'currency' => 'USD']); $id = $b['booking']['id'];
  [, $g] = rq('adv', 'GET', "/bookings/$id"); [$c2, $e] = rq('adv', 'PUT', "/bookings/$id", ['status' => 'confirmed', 'version' => $g['booking']['version']]);
  [, $g] = rq('adv', 'GET', "/bookings/$id"); [$c3] = rq('adv', 'PUT', "/bookings/$id", ['status' => 'confirmed', 'confirmationNumber' => 'CH-7781', 'travelerIds' => [$shah], 'version' => $g['booking']['version']]);
  rq('own', 'PUT', '/settings/booking', ['required' => []]);
  ok($c0 === 200 && $c1 === 201 && $c2 === 422 && isset($e['fields']['confirmationNumber'], $e['fields']['travelerIds']) && $c3 === 200 && $one("SELECT status FROM bookings WHERE id=$id") === 'confirmed', json_encode([$c0, $c1, $c2, $e, $c3]));
  return 'draft saved without them (201); confirm without confirmation number/travelers 422; confirm with them 200'; });
t('A03 Terms: hidden from clients until published; only administrators edit', function () { [$c0] = rq('adv', 'PUT', '/settings/terms', ['text' => 'x']); rq('own', 'PUT', '/settings/terms', ['text' => 'Deposits are refundable within 14 days.']);
  [, $t1] = rq('trv', 'GET', '/portal/terms'); rq('own', 'PUT', '/settings/terms', ['text' => 'Deposits are refundable within 14 days.', 'publish' => true]); [, $t2] = rq('trv', 'GET', '/portal/terms');
  rq('own', 'PUT', '/settings/terms', ['text' => 'Deposits are refundable within 14 days.', 'publish' => false]); [, $t3] = rq('trv', 'GET', '/portal/terms'); [$c4] = rq('adv', 'GET', '/portal/terms');
  ok($c0 === 403 && $t1['published'] === false && $t1['text'] === null && $t2['text'] === 'Deposits are refundable within 14 days.' && $t3['text'] === null && $c4 === 403, json_encode([$c0, $t1, $t2, $t3, $c4])); return 'draft hidden; published visible to client; unpublished hidden; staff portal call 403'; });
t('A04 Tags: create, duplicate refused, rename, in-use protected, delete unused', function () use ($shah, $pdo) { [$c1, $d] = rq('own', 'POST', '/admin/tags', ['name' => 'Wellness']); [$c2] = rq('own', 'POST', '/admin/tags', ['name' => 'Wellness']); [$c3] = rq('adv', 'POST', '/admin/tags', ['name' => 'X']);
  $id = array_values(array_filter($d['items'], fn($t) => $t['name'] === 'Wellness'))[0]['id']; [$c4] = rq('own', 'PUT', "/admin/tags/$id", ['name' => 'Wellness retreats']);
  $pdo->exec("INSERT INTO contact_tags (contact_id, tag_id) VALUES ($shah, $id)"); [$c5] = rq('own', 'DELETE', "/admin/tags/$id"); $pdo->exec("DELETE FROM contact_tags WHERE tag_id=$id"); [$c6] = rq('own', 'DELETE', "/admin/tags/$id");
  ok($c1 === 200 && $c2 === 409 && $c3 === 403 && $c4 === 200 && $c5 === 409 && $c6 === 200, json_encode([$c1, $c2, $c3, $c4, $c5, $c6])); return 'create 200, duplicate 409, advisor 403, rename 200, in-use delete 409, unused delete 200'; });
t('A05 Roles: only owners edit; change applies on the next request; owner and client roles protected; audited', function () use ($one) {
  [$c0, $r] = rq('adm', 'GET', '/admin/roles'); $sales = array_values(array_filter($r['roles'], fn($x) => $x['code'] === 'sales'))[0]['permissions']; [$c1] = rq('adm', 'PUT', '/admin/roles/sales/permissions', ['permissions' => $sales]);
  [$s0] = rq('sal', 'GET', '/calls/capability'); [$c2] = rq('own', 'PUT', '/admin/roles/sales/permissions', ['permissions' => array_values(array_diff($sales, ['calls.use']))]); [$s1] = rq('sal', 'GET', '/calls/capability');
  [$c3] = rq('own', 'PUT', '/admin/roles/sales/permissions', ['permissions' => $sales]); [$s2] = rq('sal', 'GET', '/calls/capability');
  [$c4] = rq('own', 'PUT', '/admin/roles/owner/permissions', ['permissions' => []]); [$c5] = rq('own', 'PUT', '/admin/roles/traveler/permissions', ['permissions' => ['portal.access', 'contacts.view']]); [$c6] = rq('own', 'PUT', '/admin/roles/sales/permissions', ['permissions' => ['no.such']]);
  $aud = (int)$one("SELECT COUNT(*) FROM audit_log WHERE action='role.permissions_changed'");
  ok($c0 === 200 && $c1 === 403 && $s0 === 200 && $c2 === 200 && $s1 === 403 && $c3 === 200 && $s2 === 200 && $c4 === 409 && $c5 === 422 && $c6 === 422 && $aud === 2, json_encode([$c0, $c1, $s0, $c2, $s1, $c3, $s2, $c4, $c5, $c6, $aud]));
  return 'admin edit 403; sales lost calls access immediately (403) and regained it; owner role 409; client staff permission 422; unknown 422; 2 audit entries'; });
t('A06 Travelers: scoped to visible trips; passport masked', function () use ($amalfi, $shah, $pdo) { $pdo->exec("INSERT IGNORE INTO trip_travelers (trip_id, contact_id, permission) VALUES ($amalfi, $shah, 'primary')"); $pdo->exec("UPDATE contacts SET passport_country='US', passport_expiry='2027-01-15' WHERE id=$shah");
  [$c, $d] = rq('adv', 'GET', '/travelers'); [, $d2] = rq('adv2', 'GET', '/travelers'); $row = array_values(array_filter($d['items'], fn($x) => $x['contactId'] === $shah))[0] ?? null;
  ok($c === 200 && $row && $row['passportCountry'] === 'US' && $row['passportExpiresSoon'] === true && !array_key_exists('passport', $row) && !in_array($shah, array_column($d2['items'], 'contactId'), true), json_encode([$row, array_column($d2['items'], 'contactId')]));
  return 'advisor sees Daniel Shah (passport country US, expiry flagged; no number stored); advisor2 does not'; });
t('A07 Support request creates a real task for an administrator', function () use ($one) { [$c, $d] = rq('adv', 'POST', '/support-requests', ['category' => 'Bug report', 'subject' => 'Cannot export CSV', 'details' => 'Steps…']);
  ok($c === 201 && $one("SELECT CONCAT(title,'|',priority,'|',source) FROM tasks WHERE id={$d['taskId']}") === '[Bug report] Cannot export CSV|high|Support request' && (int)$one("SELECT COUNT(*) FROM notifications WHERE ref_id={$d['taskId']}") === 1, json_encode([$c, $d])); return 'task + notification for an administrator'; });
t('A08 Settings import: configuration only, idempotent; fictional contact details not imported', function () use ($one) { [$c0, $d] = rq('own', 'POST', '/admin/import-revision27-settings', ['dryRun' => true]); $n0 = (int)$one('SELECT COUNT(*) FROM tags');
  [$c1, $r1] = rq('own', 'POST', '/admin/import-revision27-settings', []); [$c2, $r2] = rq('adv', 'POST', '/admin/import-revision27-settings', []); [, $r3] = rq('own', 'POST', '/admin/import-revision27-settings', []);
  ok($c0 === 200 && (int)$one('SELECT COUNT(*) FROM tags') === $n0 + $r1['tags']['imported'] && $r1['tags']['imported'] === 8 && $r3['tags']['imported'] === 0 && $c2 === 403 && $one("SELECT setting_value FROM app_settings WHERE setting_key='agency_email'") === 'advisors@lti.test' && strpos(json_encode($r1), 'lti.example') === false, json_encode([$r1, $r3]));
  return '8 trip tags imported, re-run 0; advisor 403; agency email kept; example email/phone/signature not imported'; });
$p = count(array_filter($results, fn($r) => $r[1])); foreach ($results as [$n, $o, $d]) echo ($o ? 'PASS ' : 'FAIL ') . "$n — $d\n"; echo "ADMIN SUMMARY $p/" . count($results) . "\n";
file_put_contents(__DIR__ . '/api_admin_results.json', json_encode($results, JSON_PRETTY_PRINT)); exit($p === count($results) ? 0 : 1);
