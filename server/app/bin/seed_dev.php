<?php
// Local development / test seed ONLY. Refuses to run when env=production.
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use LTI\Db;
if (PHP_SAPI !== 'cli' || ($GLOBALS['LTI_CFG']['env'] ?? 'production') === 'production') exit("Refusing to seed outside development\n");
$pw = password_hash('Dev-Password-2026', PASSWORD_DEFAULT);
$staff = [['owner@lti.test','Maya Reyes','owner'],['admin@lti.test','Ana Admin','admin'],['supervisor@lti.test','Dana Cruz','supervisor'],['advisor@lti.test','Jordan Ellis','advisor'],
  ['advisor2@lti.test','Sam Whitaker','advisor'],['sales@lti.test','Leo Park','sales'],['ops@lti.test','Olive Ops','operations'],['finance@lti.test','Rina Santos','finance'],['traveler@lti.test','Tara Traveler','traveler']];
foreach ($staff as [$e, $n, $r]) Db::exec('INSERT IGNORE INTO users (email, full_name, password_hash, role_id, is_active, created_at, updated_at) SELECT ?,?,?,id,1,UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM roles WHERE code=?', [$e, $n, $pw, $r]);
$id = function ($e) { return (int)Db::one('SELECT id FROM users WHERE email=?', [$e])['id']; };
$contacts = [['Amelia','Hartwell','amelia@example.com','+1 (503) 555-0142','client',$id('owner@lti.test')],['Daniel','Shah','shah@example.com','+1 (512) 555-0177','client',$id('advisor@lti.test')],
  ['Chidi','Okafor','okafor@example.com','+1 (404) 555-0119','client',$id('advisor2@lti.test')],['Marcus','Bell','marcus.bell@example.com','+1 (646) 555-0156','lead',$id('sales@lti.test')]];
foreach ($contacts as [$f, $l, $e, $p, $t, $a]) if (!Db::one('SELECT id FROM contacts WHERE email=?', [$e]))
  Db::exec('INSERT INTO contacts (first_name,last_name,email,phone,contact_type,assigned_to,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())', [$f, $l, $e, $p, $t, $a, $a]);
echo "Seeded dev staff (password: Dev-Password-2026) and sample contacts\n";
