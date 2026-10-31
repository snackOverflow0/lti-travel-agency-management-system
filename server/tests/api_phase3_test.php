<?php
// Phase 3 API integration tests: trips, travelers, itineraries, publishing, client portal. Run on a freshly seeded TEST database.
declare(strict_types=1);
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/') . '/api'; $results = []; $jars = []; $tok = [];
$cfg = require getenv('LTI_CONFIG'); $pdo = new PDO("mysql:host={$cfg['db']['host']};dbname={$cfg['db']['name']};charset=utf8mb4", $cfg['db']['user'], $cfg['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$q = function ($sql) use ($pdo) { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); };
function req(string $who, string $method, string $path, $body = null): array { global $base, $jars, $tok; $jars[$who] = $jars[$who] ?? tempnam(sys_get_temp_dir(), 'jar');
  $ch = curl_init($base . $path); $h = ['Accept: application/json']; if ($body !== null) $h[] = 'Content-Type: application/json'; if (!empty($tok[$who]) && $method !== 'GET') $h[] = 'X-CSRF-Token: ' . $tok[$who];
  curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_COOKIEJAR => $jars[$who], CURLOPT_COOKIEFILE => $jars[$who]]);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); $raw = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return [$c, json_decode((string)$raw, true) ?? []]; }
function login(string $who, string $email, string $pw = 'Dev-Password-2026'): void { global $tok; [$c, $d] = req($who, 'POST', '/auth/login', ['email' => $email, 'password' => $pw]); if ($c !== 200) throw new Exception("login $email $c"); $tok[$who] = $d['csrf']; }
function t(string $n, callable $f): void { global $results; try { $results[] = [$n, true, (string)$f()]; } catch (Throwable $e) { $results[] = [$n, false, $e->getMessage()]; } }
function ok($c, string $m): void { if (!$c) throw new Exception($m); }
login('own', 'owner@lti.test'); login('adv', 'advisor@lti.test'); login('adv2', 'advisor2@lti.test'); login('fin', 'finance@lti.test'); login('trv', 'traveler@lti.test'); login('trv2', 'traveler2@lti.test');
$amelia = (int)$q("SELECT id FROM contacts WHERE email='amelia@example.com'")[0]['id']; $okafor = (int)$q("SELECT id FROM contacts WHERE email='okafor@example.com'")[0]['id'];
$amalfi = (int)$q("SELECT id FROM trips WHERE name='Amalfi Coast Anniversary'")[0]['id'];
$tid = 0; $rev = 0;
t('Create trip (201) is stored in MySQL with primary traveler and default itinerary', function () use (&$tid, $amelia, $q) { [$c, $d] = req('own', 'POST', '/trips', ['name' => 'Lisbon & Douro', 'destination' => 'Lisbon, Portugal', 'startDate' => '2027-05-10', 'endDate' => '2027-05-18', 'primaryContactId' => $amelia, 'tags' => ['VIP']]);
  ok($c === 201, "create $c " . json_encode($d)); $tid = $d['trip']['id']; $row = $q("SELECT name,status,primary_contact_id FROM trips WHERE id=$tid")[0]; $tt = $q("SELECT permission FROM trip_travelers WHERE trip_id=$tid AND contact_id=$amelia");
  $opt = $q("SELECT COUNT(*) n FROM itinerary_options WHERE trip_id=$tid")[0]['n']; ok($row['status'] === 'Inquiry' && (int)$row['primary_contact_id'] === $amelia && $tt[0]['permission'] === 'primary' && (int)$opt === 1, json_encode([$row, $tt, $opt])); return "trip $tid"; });
t('Validation: end before start (422); archived on create refused (422)', function () { [$c] = req('own', 'POST', '/trips', ['name' => 'Bad', 'startDate' => '2027-05-10', 'endDate' => '2027-05-01']); [$c2] = req('own', 'POST', '/trips', ['name' => 'Bad', 'status' => 'Archived']); ok($c === 422 && $c2 === 422, "$c $c2"); return '422/422'; });
t('Update trip with version; stale version returns 409', function () use (&$tid, $q) { [, $g] = req('own', 'GET', "/trips/$tid"); $v = $g['trip']['version'];
  [$c] = req('own', 'PUT', "/trips/$tid", ['name' => 'Lisbon & Douro Valley', 'status' => 'Planning', 'version' => $v]); [$c2] = req('own', 'PUT', "/trips/$tid", ['name' => 'Stale', 'version' => $v]);
  $r = $q("SELECT name,status FROM trips WHERE id=$tid")[0]; ok($c === 200 && $c2 === 409 && $r['name'] === 'Lisbon & Douro Valley' && $r['status'] === 'Planning', "$c $c2 " . json_encode($r)); return 'saved; stale blocked'; });
t('Travelers: link a second contact; two primaries rejected (422)', function () use (&$tid, $amelia, $okafor, $q) {
  [$c] = req('own', 'PUT', "/trips/$tid/travelers", ['travelers' => [['contactId' => $amelia, 'permission' => 'primary'], ['contactId' => $okafor, 'permission' => 'primary']]]);
  [$c2, $d] = req('own', 'PUT', "/trips/$tid/travelers", ['travelers' => [['contactId' => $amelia, 'permission' => 'primary'], ['contactId' => $okafor, 'permission' => 'limited']]]);
  $n = $q("SELECT COUNT(*) n FROM trip_travelers WHERE trip_id=$tid")[0]['n']; ok($c === 422 && $c2 === 200 && (int)$n === 2 && count($d['trip']['travelers']) === 2, "$c $c2 $n"); return '2 travelers'; });
t('Scoping: another advisor cannot read or list this trip (404 / hidden); finance cannot create (403)', function () use (&$tid) { [$c] = req('adv2', 'GET', "/trips/$tid"); [, $l] = req('adv2', 'GET', '/trips?archived=all'); $names = array_column($l['items'], 'name');
  [$c3] = req('fin', 'POST', '/trips', ['name' => 'x']); ok($c === 404 && !in_array('Lisbon & Douro Valley', $names, true) && $c3 === 403, "$c " . json_encode($names) . " $c3"); return 'advisor2 sees ' . implode(',', $names); });
t('Itinerary save: new option/day/activities get ids; order persisted; stale rev 409', function () use (&$tid, &$rev, $q) {
  [, $g] = req('own', 'GET', "/trips/$tid/itinerary"); $o = $g['options'][0];
  $o['days'][0]['acts'] = [['id' => 'act-x1', 'type' => 'tour', 'title' => 'Alfama walk', 'inote' => 'INTERNAL tip 10%', 'cnote' => 'Wear good shoes', 'price' => 280], ['id' => 'act-x2', 'type' => 'dining', 'title' => 'Belcanto dinner', 'time' => '20:00']];
  $o['days'][] = ['id' => 'day-x3', 't' => 'Sintra', 'acts' => [['id' => 'act-x4', 'type' => 'excursion', 'title' => 'Pena Palace']]];
  [$c, $d] = req('own', 'PUT', "/trips/$tid/itinerary", ['rev' => $g['rev'], 'options' => [$o], 'meta' => ['pack' => [['t' => 'Passport', 'x' => 0]]]]);
  ok($c === 200 && isset($d['idMap']['act-x1']) && isset($d['idMap']['day-x3']), "save $c " . json_encode($d['idMap'] ?? $d));
  [$c2] = req('own', 'PUT', "/trips/$tid/itinerary", ['rev' => $g['rev'], 'options' => [$o]]); ok($c2 === 409, "stale $c2");
  $acts = $d['options'][0]['days'][0]['acts']; $swap = [$acts[1], $acts[0]]; $o2 = $d['options'][0]; $o2['days'][0]['acts'] = $swap;
  [$c3, $d3] = req('own', 'PUT', "/trips/$tid/itinerary", ['rev' => $d['rev'], 'options' => [$o2]]); $rev = $d3['rev'];
  $ord = array_column($q("SELECT a.title FROM itinerary_activities a JOIN itinerary_days dd ON dd.id=a.day_id JOIN itinerary_options op ON op.id=dd.option_id WHERE op.trip_id=$tid AND dd.sort_order=0 ORDER BY a.sort_order"), 'title');
  ok($c3 === 200 && $ord === ['Belcanto dinner', 'Alfama walk'], json_encode($ord)); return 'reordered: ' . implode(' > ', $ord); });
t('Publish creates a version; internal notes are not in the client snapshot', function () use (&$tid, $q) { [$c, $d] = req('own', 'POST', "/trips/$tid/publish"); $v = $q("SELECT snapshot_json FROM itinerary_versions WHERE trip_id=$tid ORDER BY id DESC LIMIT 1")[0]['snapshot_json'];
  $t = $q("SELECT is_published, published_version_id FROM trips WHERE id=$tid")[0]; ok($c === 200 && (int)$t['is_published'] === 1 && $t['published_version_id'] && strpos($v, 'INTERNAL') === false && strpos($v, 'Alfama walk') !== false && $d['published']['hasUnpublishedChanges'] === false, json_encode($t)); return 'version ' . $t['published_version_id']; });
t('Editing the draft does not change the published version', function () use (&$tid, $q) { [, $g] = req('own', 'GET', "/trips/$tid/itinerary"); $o = $g['options'][0]; $o['days'][0]['acts'][0]['title'] = 'Belcanto tasting menu (draft change)';
  [$c, $d] = req('own', 'PUT', "/trips/$tid/itinerary", ['rev' => $g['rev'], 'options' => [$o]]); $pv = $q("SELECT v.snapshot_json FROM itinerary_versions v JOIN trips t ON t.published_version_id=v.id WHERE t.id=$tid")[0]['snapshot_json'];
  ok($c === 200 && strpos($pv, 'draft change') === false && $d['published']['hasUnpublishedChanges'] === true, 'leaked'); return 'published snapshot unchanged; draft flagged'; });
t('Traveler sees only own published trips; snapshot excludes internal notes', function () use ($amalfi) { [$c, $l] = req('trv', 'GET', '/portal/trips'); [$c2, $d] = req('trv', 'GET', "/portal/trips/$amalfi");
  ok($c === 200 && array_column($l['items'], 'name') === ['Amalfi Coast Anniversary'] && $c2 === 200 && strpos(json_encode($d), 'INTERNAL') === false, json_encode($l)); return 'ok'; });
t('Traveler cannot open another client’s trip or staff endpoints', function () use ($amalfi) { [$c] = req('trv2', 'GET', "/portal/trips/$amalfi"); [$c2] = req('trv', 'GET', '/trips'); [$c3] = req('trv', 'GET', '/contacts'); [, $l] = req('trv2', 'GET', '/portal/trips');
  ok($c === 404 && $c2 === 403 && $c3 === 403 && !in_array('Amalfi Coast Anniversary', array_column($l['items'], 'name'), true), "$c $c2 $c3"); return 'portal 404, staff APIs 403'; });
t('Traveler approves: approval recorded, trip status becomes Approved, staff sees it', function () use ($amalfi, $q) { [, $d] = req('trv', 'GET', "/portal/trips/$amalfi"); $oid = $d['trip']['options'][0]['id'];
  [$c] = req('trv', 'POST', "/portal/trips/$amalfi/approve", ['optionId' => $oid]); [$c2] = req('trv', 'POST', "/portal/trips/$amalfi/approve", ['optionId' => 'o999999']);
  $st = $q("SELECT status FROM trips WHERE id=$amalfi")[0]['status']; [, $g] = req('adv', 'GET', "/trips/$amalfi"); $acts = array_column($g['trip']['activity'], 'action');
  ok($c === 200 && $c2 === 422 && $st === 'Approved' && in_array('approved', $acts, true), "$c $c2 $st"); return 'approved; bad option 422'; });
t('Limited-access traveler cannot approve and sees no prices', function () use (&$tid, $okafor) { $pdo2 = null; [$c] = req('own', 'POST', "/contacts/$okafor/portal-account"); // already linked to traveler2
  [$c2, $d] = req('trv2', 'GET', "/portal/trips/$tid"); [$c3] = req('trv2', 'POST', "/portal/trips/$tid/approve", ['optionId' => $d['trip']['options'][0]['id'] ?? 'o0']);
  $hasPrice = strpos(json_encode($d['trip']['options'] ?? []), '"price"') !== false; ok($c2 === 200 && $c3 === 403 && !$hasPrice && $d['trip']['permission'] === 'limited', "$c2 $c3 price=" . (int)$hasPrice); return 'limited: view only, no prices'; });
t('Archive keeps travelers, itinerary and versions; trip leaves the portal', function () use (&$tid, $q) { $before = $q("SELECT (SELECT COUNT(*) FROM trip_travelers WHERE trip_id=$tid) tv, (SELECT COUNT(*) FROM itinerary_options WHERE trip_id=$tid) op, (SELECT COUNT(*) FROM itinerary_versions WHERE trip_id=$tid) ve")[0];
  [$c] = req('own', 'POST', "/trips/$tid/archive"); $after = $q("SELECT (SELECT COUNT(*) FROM trip_travelers WHERE trip_id=$tid) tv, (SELECT COUNT(*) FROM itinerary_options WHERE trip_id=$tid) op, (SELECT COUNT(*) FROM itinerary_versions WHERE trip_id=$tid) ve")[0];
  $t = $q("SELECT status, archived_at FROM trips WHERE id=$tid")[0]; [$c2] = req('trv2', 'GET', "/portal/trips/$tid"); [, $l] = req('own', 'GET', '/trips'); [, $la] = req('own', 'GET', '/trips?archived=1');
  ok($c === 200 && $t['status'] === 'Archived' && $t['archived_at'] && $before == $after && $c2 === 404 && !in_array($tid, array_column($l['items'], 'id'), true) && in_array($tid, array_column($la['items'], 'id'), true), json_encode([$before, $after, $t, $c2])); return 'related rows intact: ' . json_encode($after); });
t('Staff creates a traveler portal login for a contact (one-time link), traveler signs in', function () use ($amelia) { [$c, $d] = req('own', 'POST', "/contacts/$amelia/portal-account"); preg_match('/token=([0-9a-f]{64})/', $d['resetUrl'] ?? '', $m);
  [$c2] = req('anon', 'POST', '/auth/reset', ['token' => $m[1] ?? '', 'password' => 'Amelia-Portal-2027']); [$c3, $d3] = req('am', 'POST', '/auth/login', ['email' => 'amelia@example.com', 'password' => 'Amelia-Portal-2027']); [$c4] = req('adv', 'POST', "/contacts/$amelia/portal-account");
  ok($c === 200 && $c2 === 200 && $c3 === 200 && $d3['user']['role'] === 'traveler' && $c4 === 403, "$c $c2 $c3 $c4"); return 'traveler account works; advisor cannot create (403)'; });
t('Audit log records trip, itinerary and portal actions', function () use (&$tid, $q) { $a = array_column($q("SELECT DISTINCT action FROM audit_log WHERE entity_type='trip'"), 'action'); foreach (['trip.created', 'trip.status_changed', 'trip.travelers_updated', 'itinerary.saved', 'itinerary.published', 'portal.approved', 'trip.archived'] as $x) ok(in_array($x, $a, true), "missing $x"); return implode(', ', $a); });
$pass = count(array_filter($results, function ($r) { return $r[1]; })); foreach ($results as [$n, $o, $d]) echo ($o ? 'PASS ' : 'FAIL ') . "$n — $d\n";
echo "PHASE3 API SUMMARY $pass/" . count($results) . "\n"; file_put_contents(__DIR__ . '/api_phase3_results.json', json_encode($results, JSON_PRETTY_PRINT)); exit($pass === count($results) ? 0 : 1);
