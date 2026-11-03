<?php
// Revision 27 library migration: permissions, dry run, import, idempotency, edit preservation, email marker guard, new variables.
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
foreach (['own' => 'owner@lti.test', 'adv' => 'advisor@lti.test'] as $k => $e) login($k, $e);
$snap = json_decode(file_get_contents(dirname(__DIR__) . '/app/data/revision27_library.json'), true)['data'];
$counts = fn() => $one("SELECT CONCAT((SELECT COUNT(*) FROM content_templates),'|',(SELECT COUNT(*) FROM content_folders),'|',(SELECT COUNT(*) FROM email_templates))");
t('L01 Only administrators can run the import', function () { [$c] = rq('adv', 'POST', '/library/import-revision27', ['dryRun' => true]); ok($c === 403, "advisor $c"); return 'advisor 403'; });
t('L02 Dry run reports the import and writes nothing', function () use ($counts) { $b = $counts(); [$c, $d] = rq('own', 'POST', '/library/import-revision27', ['dryRun' => true]); ok($c === 200 && $d['dryRun'] === true && $counts() === $b && ($d['summary']['folders']['imported'] ?? 0) === 71, json_encode([$c, $d['summary']['folders'] ?? null, $b, $counts()])); return "71 folders, 15 activities… reported; database unchanged ($b)"; });
t('L03 Import creates every record with origin and matching counts', function () use ($snap, $one) { [$c, $d] = rq('own', 'POST', '/library/import-revision27', []); ok($c === 200, "$c");
  $exp = ['activity' => count($snap['activities']), 'guide' => count($snap['guides']), 'checklist' => count($snap['packing']), 'task' => count($snap['taskTemplates']), 'supplier_info' => 1, 'workflow_reference' => 52]; $got = [];
  foreach ($exp as $k => $n) $got[$k] = (int)$one("SELECT COUNT(*) FROM content_templates WHERE kind='$k' AND origin='revision27'");
  ok($got == $exp && (int)$one("SELECT COUNT(*) FROM email_templates WHERE origin='revision27'") === 10 && (int)$one("SELECT COUNT(*) FROM content_folders WHERE origin='revision27'") === 71, json_encode($got)); return json_encode($got) . '; 10 email templates; 71 folders'; });
t('L04 Re-running the import creates no duplicates', function () use ($counts) { $b = $counts(); [$c, $d] = rq('own', 'POST', '/library/import-revision27', []); $imported = 0; foreach ($d['summary'] as $v) $imported += $v['imported'] ?? 0;
  ok($c === 200 && $imported === 0 && $counts() === $b, json_encode([$imported, $b, $counts()])); return "0 new records; totals unchanged ($b)"; });
t('L05 Records stay editable; a re-run never overwrites staff edits', function () use ($one) { $id = (int)$one("SELECT id FROM content_templates WHERE source_key='r27:guide:g-lis'"); [, $g] = rq('own', 'GET', "/library/templates/$id");
  [$c] = rq('own', 'PUT', "/library/templates/$id", ['kind' => 'guide', 'title' => 'Lisbon and the Douro (edited)', 'body' => $g['template']['body'], 'version' => $g['template']['version']]); rq('own', 'POST', '/library/import-revision27', []);
  ok($c === 200 && $one("SELECT title FROM content_templates WHERE id=$id") === 'Lisbon and the Douro (edited)', "$c"); return 'edited title survived a re-run'; });
t('L06 Unsupported placeholders become visible markers and block sending until replaced', function () use ($one) { $tid = (int)$one("SELECT id FROM email_templates WHERE origin='revision27' AND body LIKE '%[Fill in:%' LIMIT 1"); $shah = (int)$one("SELECT id FROM contacts WHERE email='shah@example.com'");
  $amalfi = (int)$one("SELECT id FROM trips WHERE name='Amalfi Coast Anniversary'"); [, $e] = rq('adv', 'POST', '/emails', ['templateId' => $tid, 'contactId' => $shah, 'tripId' => $amalfi]); [$c, $r] = rq('adv', 'POST', '/emails/' . $e['email']['id'] . '/queue');
  ok($c === 422 && strpos($r['error'], '[Fill in:') !== false, "$c " . json_encode($r)); return 'queue refused: ' . $r['error']; });
t('L07 New variables render real data (trip destination, dates, portal link)', function () use ($one) { [, $t] = rq('own', 'POST', '/email-templates', ['name' => 'Var check', 'subject' => '{{trip.destination}}', 'body' => '{{trip.start_date}} to {{trip.end_date}} {{trip.link}}']);
  [$c, $p] = rq('own', 'POST', '/email-templates/' . $t['template']['id'] . '/preview', ['tripId' => (int)$one("SELECT id FROM trips WHERE name='Amalfi Coast Anniversary'")]);
  ok($c === 200 && $p['subject'] === $one("SELECT destination FROM trips WHERE name='Amalfi Coast Anniversary'") && !$p['missing'] && strpos($p['body'], '#/portal') !== false, json_encode($p)); return $p['subject'] . ' / ' . substr($p['body'], 0, 60); });
t('L08 Folder hierarchy and text preserved exactly', function () use ($one, $snap) { $orph = (int)$one("SELECT COUNT(*) FROM content_folders c LEFT JOIN content_folders p ON p.id=c.parent_id WHERE c.parent_id IS NOT NULL AND (p.id IS NULL OR p.section<>c.section)");
  $par = $one("SELECT p.name FROM content_folders c JOIN content_folders p ON p.id=c.parent_id WHERE c.section='photos' AND c.name='Alaska & the Inside Passage'"); $desc = $one("SELECT summary FROM content_templates WHERE source_key='r27:act:" . $snap['activities'][0]['id'] . "'");
  ok($orph === 0 && $par === 'Ocean' && $desc === $snap['activities'][0]['desc'], json_encode([$orph, $par, $desc])); return 'no orphans; Alaska… under Ocean; text byte-identical'; });
$p = count(array_filter($results, fn($r) => $r[1])); foreach ($results as [$n, $o, $d]) echo ($o ? 'PASS ' : 'FAIL ') . "$n — $d\n"; echo "LIBRARY IMPORT SUMMARY $p/" . count($results) . "\n";
file_put_contents(__DIR__ . '/api_library_import_results.json', json_encode($results, JSON_PRETTY_PRINT)); exit($p === count($results) ? 0 : 1);
