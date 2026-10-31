<?php
// API integration tests (free, no PHPUnit download needed): run against a live dev server on a freshly seeded TEST database.
// Usage: php server/tests/api_test.php http://127.0.0.1:8080
declare(strict_types=1);
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/') . '/api';
$results = []; $jars = [];
function req(string $who, string $method, string $path, ?array $body = null, ?string $csrf = null, ?string $ip = null): array {
    global $base, $jars; $jars[$who] = $jars[$who] ?? tempnam(sys_get_temp_dir(), 'jar');
    $ch = curl_init($base . $path);
    $h = ['Accept: application/json']; if ($body !== null) $h[] = 'Content-Type: application/json'; if ($csrf !== null) $h[] = 'X-CSRF-Token: ' . $csrf;
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_COOKIEJAR => $jars[$who], CURLOPT_COOKIEFILE => $jars[$who]]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, json_decode((string)$raw, true) ?? []];
}
function t(string $name, callable $fn): void { global $results; try { $d = $fn(); $results[] = [$name, true, (string)$d]; } catch (Throwable $e) { $results[] = [$name, false, $e->getMessage()]; } }
function ok($c, string $m): void { if (!$c) throw new Exception($m); }
function login(string $who, string $email): string { [$c, $d] = req($who, 'POST', '/auth/login', ['email' => $email, 'password' => 'Dev-Password-2026']); ok($c === 200, "login $email -> $c"); return $d['csrf']; }

t('Unauthenticated requests are rejected (401)', function () { [$c] = req('anon', 'GET', '/contacts'); ok($c === 401, "got $c"); return '401'; });
t('Wrong password is rejected without revealing which field (401)', function () { [$c, $d] = req('x', 'POST', '/auth/login', ['email' => 'owner@lti.test', 'password' => 'nope-nope-nope']); ok($c === 401 && $d['error'] === 'Email or password is incorrect', "got $c"); return $d['error']; });
$owner = ''; t('Owner signs in; /auth/me returns role and permissions', function () use (&$owner) { $owner = login('owner', 'owner@lti.test'); [$c, $d] = req('owner', 'GET', '/auth/me'); ok($c === 200 && $d['user']['role'] === 'owner' && in_array('contacts.create', $d['user']['permissions'], true), 'me'); return count($d['user']['permissions']) . ' permissions'; });
t('State-changing request without CSRF token is rejected (403)', function () { [$c] = req('owner', 'POST', '/contacts', ['firstName' => 'NoToken']); ok($c === 403, "got $c"); return '403'; });
t('Validation errors return field messages (422)', function () use (&$owner) { [$c, $d] = req('owner', 'POST', '/contacts', ['firstName' => '', 'email' => 'bad', 'birthday' => '2026-02-31'], $owner); ok($c === 422 && isset($d['fields']['firstName']), "got $c"); return json_encode($d['fields']); });
$cid = 0; t('Create contact persists to MySQL (201) and is retrievable', function () use (&$owner, &$cid) {
    [$c, $d] = req('owner', 'POST', '/contacts', ['firstName' => 'Nora', 'lastName' => 'Quinn', 'email' => 'nora.quinn@example.com', 'phone' => '+1 (212) 555-0100', 'type' => 'lead', 'leadStatus' => 'new', 'birthday' => '1984-06-03'], $owner);
    ok($c === 201, "create $c " . json_encode($d)); $cid = $d['contact']['id']; [$c2, $g] = req('owner', 'GET', '/contacts/' . $cid); ok($c2 === 200 && $g['contact']['email'] === 'nora.quinn@example.com' && $g['contact']['birthday'] === '1984-06-03', 'get'); return 'id ' . $cid; });
t('Duplicate email is detected (409)', function () use (&$owner) { [$c] = req('owner', 'POST', '/contacts', ['firstName' => 'Dup', 'email' => 'NORA.QUINN@example.com'], $owner); ok($c === 409, "got $c"); return '409'; });
t('Search and pagination query MySQL', function () { [$c, $d] = req('owner', 'GET', '/contacts?q=quinn&perPage=1'); ok($c === 200 && $d['total'] === 1 && $d['items'][0]['lastName'] === 'Quinn', json_encode($d)); return 'total ' . $d['total']; });
t('SQL injection in search is treated as text', function () { [$c, $d] = req('owner', 'GET', '/contacts?q=' . urlencode("' OR 1=1 -- ")); ok($c === 200 && $d['total'] === 0, "got $c total " . ($d['total'] ?? '?')); return 'no rows'; });
t('Optimistic concurrency: stale version is rejected (409)', function () use (&$owner, &$cid) {
    [, $g] = req('owner', 'GET', '/contacts/' . $cid); $v = $g['contact']['version'];
    [$c1] = req('owner', 'PUT', '/contacts/' . $cid, ['firstName' => 'Nora', 'lastName' => 'Quinn-Lee', 'email' => 'nora.quinn@example.com', 'type' => 'client', 'version' => $v], $owner);
    [$c2] = req('owner', 'PUT', '/contacts/' . $cid, ['firstName' => 'Nora', 'lastName' => 'Stale', 'email' => 'nora.quinn@example.com', 'version' => $v], $owner);
    ok($c1 === 200 && $c2 === 409, "update $c1 stale $c2"); return 'second writer blocked'; });
t('Advisor sees only assigned contacts; other records return 404 by ID', function () {
    $tok = login('adv2', 'advisor2@lti.test'); [, $l] = req('adv2', 'GET', '/contacts'); $names = array_map(function ($i) { return $i['lastName']; }, $l['items']);
    $shahId = null; [, $all] = req('owner', 'GET', '/contacts?q=shah'); $shahId = $all['items'][0]['id'];
    [$c] = req('adv2', 'GET', '/contacts/' . $shahId); ok($names === ['Okafor'] && $c === 404, json_encode($names) . " get other -> $c"); return 'sees ' . implode(',', $names) . '; other -> 404'; });
t('Advisor cannot assign a contact to another staff member (403)', function () { [, $me] = req('adv2', 'GET', '/auth/me'); [$c] = req('adv2', 'POST', '/contacts', ['firstName' => 'X', 'assignedTo' => 1], $me['csrf']); ok($c === 403, "got $c"); return '403'; });
t('Finance staff can view but not create or delete contacts (403)', function () use (&$cid) { $tok = login('fin', 'finance@lti.test'); [$c1] = req('fin', 'GET', '/contacts'); [$c2] = req('fin', 'POST', '/contacts', ['firstName' => 'Nope'], $tok); [$c3] = req('fin', 'DELETE', '/contacts/' . $cid, null, $tok); ok($c1 === 200 && $c2 === 403 && $c3 === 403, "$c1 $c2 $c3"); return 'view 200, create 403, delete 403'; });
t('Deactivation blocks sign-in and ends an existing session', function () use (&$owner) {
    $tok = login('ops', 'ops@lti.test'); [, $s] = req('owner', 'GET', '/staff'); $id = null; foreach ($s['items'] as $i) if ($i['email'] === 'ops@lti.test') $id = $i['id'];
    [$c] = req('owner', 'PATCH', '/staff/' . $id, ['isActive' => false], $owner); [$c2] = req('ops', 'GET', '/contacts'); [$c3] = req('ops2', 'POST', '/auth/login', ['email' => 'ops@lti.test', 'password' => 'Dev-Password-2026']);
    req('owner', 'PATCH', '/staff/' . $id, ['isActive' => true], $owner); ok($c === 200 && $c2 === 401 && $c3 === 403, "$c $c2 $c3"); return 'session 401, login 403'; });
t('Admin-assisted password reset link works once', function () use (&$owner) {
    [, $s] = req('owner', 'GET', '/staff'); $id = null; foreach ($s['items'] as $i) if ($i['email'] === 'sales@lti.test') $id = $i['id'];
    [$c, $d] = req('owner', 'POST', "/staff/$id/reset-link", null, $owner); preg_match('/token=([0-9a-f]{64})/', $d['resetUrl'], $m);
    [$c1] = req('anon2', 'POST', '/auth/reset', ['token' => $m[1], 'password' => 'short']); [$c2] = req('anon2', 'POST', '/auth/reset', ['token' => $m[1], 'password' => 'New-Sales-Pass-2026']);
    [$c3] = req('anon2', 'POST', '/auth/reset', ['token' => $m[1], 'password' => 'Another-Pass-2026']); [$c4] = req('sales', 'POST', '/auth/login', ['email' => 'sales@lti.test', 'password' => 'New-Sales-Pass-2026']);
    ok($c === 200 && $c1 === 422 && $c2 === 200 && $c3 === 400 && $c4 === 200, "$c $c1 $c2 $c3 $c4"); return 'weak 422, reset 200, reuse 400, login 200'; });
t('Non-admin cannot create reset links or change staff (403)', function () { $tok = login('adv', 'advisor@lti.test'); [$c] = req('adv', 'POST', '/staff/1/reset-link', null, $tok); [$c2] = req('adv', 'PATCH', '/staff/1', ['isActive' => false], $tok); ok($c === 403 && $c2 === 403, "$c $c2"); return '403/403'; });
t('Login rate limit: 6th failure in window returns 429', function () { $last = 0; for ($i = 0; $i < 6; $i++) { [$last] = req('rl', 'POST', '/auth/login', ['email' => 'supervisor@lti.test', 'password' => 'wrong-password-x']); } [$ok] = req('rl', 'POST', '/auth/login', ['email' => 'supervisor@lti.test', 'password' => 'Dev-Password-2026']); ok($last === 429 && $ok === 429, "$last $ok"); return 'blocked even with correct password'; });
t('Soft delete hides the contact; audit log records every change', function () use (&$owner, &$cid) {
    [$c] = req('owner', 'DELETE', '/contacts/' . $cid, null, $owner); [$c2] = req('owner', 'GET', '/contacts/' . $cid);
    $cfg = require getenv('LTI_CONFIG'); $pdo = new PDO("mysql:host={$cfg['db']['host']};dbname={$cfg['db']['name']};charset=utf8mb4", $cfg['db']['user'], $cfg['db']['pass']);
    $acts = $pdo->query("SELECT action FROM audit_log WHERE entity_type='contact' AND entity_id='$cid' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $row = $pdo->query("SELECT deleted_at FROM contacts WHERE id=$cid")->fetchColumn();
    ok($c === 200 && $c2 === 404 && $acts === ['contact.created', 'contact.updated', 'contact.deleted'] && $row, json_encode($acts)); return implode(' > ', $acts); });
t('Logout ends the session (401 afterwards)', function () use (&$owner) { [$c] = req('owner', 'POST', '/auth/logout', null, $owner); [$c2] = req('owner', 'GET', '/auth/me'); ok($c === 200 && $c2 === 401, "$c $c2"); return '401'; });
t('Session cookie is HttpOnly and SameSite=Lax', function () use ($base) { $ch = curl_init($base . '/auth/login'); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['email' => 'admin@lti.test', 'password' => 'Dev-Password-2026']), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_HEADER => true, CURLOPT_RETURNTRANSFER => true]); $r = curl_exec($ch); preg_match_all('/^Set-Cookie: LTISESS=[^\r\n]*/mi', $r, $m); $last = end($m[0]); ok(stripos($last, 'httponly') !== false && stripos($last, 'samesite=lax') !== false, $last); return 'HttpOnly; SameSite=Lax (Secure is added automatically under HTTPS)'; });
$pass = count(array_filter($results, function ($r) { return $r[1]; }));
foreach ($results as [$n, $o, $d]) echo ($o ? 'PASS ' : 'FAIL ') . $n . ' — ' . $d . "\n";
echo "API SUMMARY $pass/" . count($results) . "\n";
file_put_contents(__DIR__ . '/api_results.json', json_encode($results, JSON_PRETTY_PRINT));
exit($pass === count($results) ? 0 : 1);
