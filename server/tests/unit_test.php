<?php
// Unit tests (no database, no server): money arithmetic, phone normalisation, template validation/rendering,
// workflow condition evaluation, secret redaction. Run: php server/tests/unit_test.php
declare(strict_types=1);
$src = dirname(__DIR__) . '/app/src/'; foreach (['HttpError', 'Validate', 'Money', 'Phone', 'Template', 'Workflow', 'Jobs'] as $c) require_once $src . $c . '.php';
use LTI\{Money, Phone, Template, Workflow, Jobs, HttpError};
$GLOBALS['LTI_CFG'] = ['mail' => ['username' => 'mailer-user', 'password' => 'S3cret-Pass-123']];
$res = []; function u(string $n, callable $f): void { global $res; try { $f(); $res[] = [$n, true, '']; } catch (Throwable $e) { $res[] = [$n, false, $e->getMessage()]; } }
function eq($a, $b, string $m = ''): void { if ($a !== $b) throw new Exception($m . ' expected ' . var_export($b, true) . ' got ' . var_export($a, true)); }
function throws(callable $f, string $m): void { try { $f(); } catch (Throwable $e) { return; } throw new Exception("$m did not throw"); }
// ---- money (integer minor units, half-up, no floats) ----
u('Money: parse decimal strings to minor units', function () { eq(Money::parse('1234.56', 2), 123456); eq(Money::parse('0.1', 2), 10); eq(Money::parse('5', 0), 5); throws(fn() => Money::parse('1,234.50', 2), 'thousands separator (ambiguous across locales)'); });
u('Money: rejects too many decimals, negatives and junk', function () { throws(fn() => Money::parse('1.234', 2), '3dp'); throws(fn() => Money::parse('-5.00', 2), 'negative'); throws(fn() => Money::parse('abc', 2), 'junk'); throws(fn() => Money::parse('1e3', 2), 'exponent'); });
u('Money: negative allowed only when requested', function () { eq(Money::parse('-12.50', 2, 'amount', true), -1250); });
u('Money: format minor units', function () { eq(Money::fmt(123456, 2), '1234.56'); eq(Money::fmt(-5, 2), '-0.05'); eq(Money::fmt(7, 0), '7'); });
u('Money: mulDiv half-up rounding', function () { eq(Money::mulDiv(1000, 1, 3), 333); eq(Money::mulDiv(1000, 2, 3), 667); eq(Money::mulDiv(5, 1, 2), 3); eq(Money::mulDiv(-5, 1, 2), -3); });
u('Money: percentage of amount (scaled x10000)', function () { eq(Money::pct(100000, '125000'), 12500, '12.5%'); eq(Money::pct(999, '333333'), 333, '33.3333%'); });
u('Money: no float drift on large values', function () { eq(Money::mulDiv(9007199254740993, 1, 1), 9007199254740993); eq(Money::pct(123456789, '100000'), 12345679); });
// ---- phone ----
u('Phone: US national and +1 formats', function () { eq(Phone::normalize('(512) 555-0177', 'US')['e164'], '+15125550177'); eq(Phone::normalize('1-512-555-0177', 'US')['e164'], '+15125550177'); eq(Phone::normalize('(512) 555-0177', 'US')['display'], '+1 (512) 555-0177'); });
u('Phone: invalid North American numbers rejected', function () { eq(Phone::normalize('(123) 555-0177', 'US')['e164'], null, 'area 1xx'); eq(Phone::normalize('555-0177', 'US')['e164'], null, '7 digits'); });
u('Phone: international, 00 and 011 prefixes', function () { eq(Phone::normalize('+44 20 7946 0958', 'US')['e164'], '+442079460958'); eq(Phone::normalize('0044 20 7946 0958', 'US')['e164'], '+442079460958'); eq(Phone::normalize('011 63 917 123 4567', 'US')['e164'], '+639171234567'); });
u('Phone: national formats by country rule', function () { eq(Phone::normalize('020 7946 0958', 'GB')['e164'], '+442079460958'); eq(Phone::normalize('0917 123 4567', 'PH')['e164'], '+639171234567'); eq(Phone::normalize('06 1234 5678', 'IT')['e164'], '+390612345678'); eq(Phone::normalize('7946 0958', 'GB')['e164'], null, 'missing trunk 0'); });
u('Phone: unknown codes, lengths and characters rejected', function () { eq(Phone::normalize('+999 123456789', 'US')['e164'], null); eq(Phone::normalize('+44 12', 'US')['e164'], null); eq(Phone::normalize('call me', 'US')['e164'], null); eq(Phone::normalize('', 'US')['validation'], 'invalid'); });
u('Phone: validation level is reported honestly', function () { eq(Phone::normalize('+15125550177', 'US')['validation'], 'nanp_rules'); eq(Phone::normalize('+442079460958', 'US')['validation'], 'length_only'); });
// ---- templates ----
u('Template: allowlisted variables pass, unknown fail', function () { eq(Template::validate('Hi {{contact.first_name}}, {{trip.name}}'), []); eq(Template::validate('{{contact.ssn}}'), ['{{contact.ssn}}']); eq(Template::validate('{{client.first_name}}'), [], 'alias'); });
u('Template: expressions, code and script refused', function () { eq(count(Template::validate("{{ system('id') }}")) > 0, true); eq(count(Template::validate('<?php echo 1; ?>')) > 0, true); eq(count(Template::validate('<script>x</script>')) > 0, true); eq(count(Template::validate('{{contact.first_name')) > 0, true, 'unclosed'); });
u('Template: render fills values, reports missing, never evaluates', function () { [$o, $m] = Template::render('Hi {{contact.first_name}} {{trip.name}}', ['contact.first_name' => 'Ana']); eq($o, 'Hi Ana '); eq($m, ['trip.name']);
  [$o2] = Template::render('{{contact.first_name}}', ['contact.first_name' => '{{trip.name}}']); eq($o2, '{{trip.name}}', 'no recursive expansion'); });
u('Template: HTML output is escaped', function () { $h = Template::html("<b>Hi</b> & \"x\"\nline2"); eq(strpos($h, '<b>'), false); eq(strpos($h, '&lt;b&gt;') !== false, true); eq(strpos($h, '<br />') !== false, true); });
// ---- workflow conditions ----
u('Workflow: equality, contains, in, empty', function () { $r = ['status' => 'Booked', 'destination' => 'Amalfi Coast, Italy', 'trip_type' => 'standard', 'currency' => 'USD'];
  eq(Workflow::conditionsMet([['field' => 'trip.status', 'op' => 'eq', 'value' => 'booked']], $r, 'trip', [])[0], true, 'case-insensitive eq');
  eq(Workflow::conditionsMet([['field' => 'trip.destination', 'op' => 'contains', 'value' => 'italy']], $r, 'trip', [])[0], true);
  eq(Workflow::conditionsMet([['field' => 'trip.status', 'op' => 'in', 'value' => 'Planning, Booked']], $r, 'trip', [])[0], true);
  eq(Workflow::conditionsMet([['field' => 'trip.status', 'op' => 'neq', 'value' => 'Booked']], $r, 'trip', [])[0], false); });
u('Workflow: exact decimal comparisons (no floats)', function () { $r = ['status' => 'issued', 'currency' => 'USD', 'total' => '1000.10', 'balance' => '0.30'];
  eq(Workflow::conditionsMet([['field' => 'invoice.total', 'op' => 'gt', 'value' => '1000.09']], $r, 'invoice', [])[0], true); eq(Workflow::conditionsMet([['field' => 'invoice.total', 'op' => 'gt', 'value' => '1000.1']], $r, 'invoice', [])[0], false);
  eq(Workflow::conditionsMet([['field' => 'invoice.balance', 'op' => 'lte', 'value' => '0.3']], $r, 'invoice', [])[0], true); eq(Workflow::conditionsMet([['field' => 'invoice.total', 'op' => 'gt', 'value' => 'abc']], $r, 'invoice', [])[0], false, 'non-numeric never matches'); });
u('Workflow: event payload fields and all-conditions-must-match', function () { $r = ['status' => 'Booked']; $c = [['field' => 'event.to', 'op' => 'eq', 'value' => 'Booked'], ['field' => 'event.from', 'op' => 'eq', 'value' => 'Planning']];
  eq(Workflow::conditionsMet($c, $r, 'trip', ['from' => 'Planning', 'to' => 'Booked'])[0], true); [$ok, $why] = Workflow::conditionsMet($c, $r, 'trip', ['from' => 'Proposal', 'to' => 'Booked']); eq($ok, false); eq(strpos($why, 'event.from') !== false, true); });
// ---- redaction ----
u('Jobs: secrets redacted from messages', function () { $s = Jobs::redact('SMTP Error: password=hunter2 token: abc123 auth failed for mailer-user using S3cret-Pass-123');
  foreach (['hunter2', 'abc123', 'mailer-user', 'S3cret-Pass-123'] as $x) eq(strpos($s, $x), false, "leaked $x"); });
$p = count(array_filter($res, fn($r) => $r[1])); foreach ($res as [$n, $o, $d]) echo ($o ? 'PASS ' : 'FAIL ') . "$n" . ($d ? " — $d" : '') . "\n"; echo "UNIT SUMMARY $p/" . count($res) . "\n"; exit($p === count($res) ? 0 : 1);
