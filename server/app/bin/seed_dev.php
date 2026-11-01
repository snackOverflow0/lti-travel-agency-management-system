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
// Phase 3 sample trips and traveler portal accounts (development/test only)
$tr = function ($e) { return (int)Db::one('SELECT id FROM users WHERE email=?', [$e])['id']; };
$shah = (int)Db::one("SELECT id FROM contacts WHERE email='shah@example.com'")['id']; $okafor = (int)Db::one("SELECT id FROM contacts WHERE email='okafor@example.com'")['id'];
Db::exec("INSERT IGNORE INTO users (email, full_name, password_hash, role_id, is_active, created_at, updated_at) SELECT 'traveler2@lti.test','Chidi Okafor',?,id,1,UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM roles WHERE code='traveler'", [$pw]);
Db::exec('UPDATE users SET full_name=? WHERE email=?', ['Daniel Shah', 'traveler@lti.test']);
Db::exec('UPDATE contacts SET portal_user_id=? WHERE id=?', [$tr('traveler@lti.test'), $shah]); Db::exec('UPDATE contacts SET portal_user_id=? WHERE id=?', [$tr('traveler2@lti.test'), $okafor]);
if (!Db::one("SELECT id FROM trips WHERE name='Amalfi Coast Anniversary'")) {
  $mk = function ($name, $dest, $sd, $ed, $owner, $cid, $status, array $days) {
    $tid = Db::insert("INSERT INTO trips (name,status,trip_type,destination,start_date,end_date,owner_id,created_by,primary_contact_id,tags_json,created_at,updated_at,version) VALUES (?,?,'standard',?,?,?,?,?,?,'[]',UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)", [$name, $status, $dest, $sd, $ed, $owner, $owner, $cid]);
    Db::exec("INSERT INTO trip_travelers (trip_id,contact_id,permission) VALUES (?,?,'primary')", [$tid, $cid]);
    $oid = Db::insert('INSERT INTO itinerary_options (trip_id,name,sort_order) VALUES (?,?,0)', [$tid, 'Itinerary']);
    foreach ($days as $i => [$title, $acts]) { $did = Db::insert('INSERT INTO itinerary_days (option_id,title,sort_order) VALUES (?,?,?)', [$oid, $title, $i]);
      foreach ($acts as $j => [$type, $t, $note]) Db::exec('INSERT INTO itinerary_activities (day_id,activity_type,title,internal_note,client_note,price,details_json,sort_order) VALUES (?,?,?,?,?,?,?,?)', [$did, $type, $t, $note, 'See you there', 250, '{}', $j]); }
    \LTI\Controllers\ItineraryController::publishInternal($tid, $owner, 'Published (seed)'); return $tid; };
  $mk('Amalfi Coast Anniversary', 'Amalfi Coast, Italy', '2026-12-03', '2026-12-11', $tr('advisor@lti.test'), $shah, 'Proposal', [['Arrive in Naples', [['transfer', 'Private transfer to Positano', 'INTERNAL: driver tip included'], ['hotel', 'Le Sirenuse, 4 nights', 'INTERNAL: net rate 2,700 EUR']]], ['Anniversary day', [['excursion', 'Private boat to Capri', 'INTERNAL: surprise cake arranged']]]]);
  $mk('Kenya Family Safari', 'Masai Mara, Kenya', '2027-01-09', '2027-01-20', $tr('advisor2@lti.test'), $okafor, 'Planning', [['Arrive Nairobi', [['flight', 'Arrival into NBO', 'INTERNAL: check visas']]]]);
}
foreach (Db::all("SELECT id, phone FROM contacts WHERE phone IS NOT NULL") as $r) \LTI\Phone::syncContactField((int)$r['id'], $r['phone']);
echo "Seeded dev staff (password: Dev-Password-2026) and sample contacts\n";
