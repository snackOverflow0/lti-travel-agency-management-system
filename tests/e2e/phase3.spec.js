// Phase 3 E2E (TC01–TC14): restored Revision 27 trip, itinerary and portal screens -> PHP API -> MySQL.
const { chromium } = require(process.env.PW || 'playwright');
const { execSync } = require('child_process');
const BASE = process.env.BASE || 'http://127.0.0.1:8000/';
const sql = q => execSync(`mysql -uroot -N -e "${q.replace(/"/g, '\\"')}" lti_test`).toString().trim();
const results = []; let browser; const st = {};
const A = (c, m) => { if (!c) throw new Error(m); };
const S = async (label, pr) => { try { return await pr; } catch (e) { throw new Error(label + ': ' + String(e.message).split('\n')[0]); } };
const waitDb = async (label, q, ok, ms = 12000) => { const t0 = Date.now(); let v; while (Date.now() - t0 < ms) { v = sql(q); if (ok(v)) return v; await new Promise(r => setTimeout(r, 300)); } throw new Error(label + ': database value ' + JSON.stringify(v)); };
async function T(name, fn, vp) { const ctx = await browser.newContext({ viewport: vp || { width: 1366, height: 900 }, reducedMotion: 'reduce' }); const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
  let ok = false, d = ''; try { d = (await fn(p, ctx)) || ''; ok = !errs.length; if (errs.length) d += ' JSERR ' + errs.slice(0, 3).join('|'); } catch (e) { d = String(e.message).split('\n')[0]; }
  results.push({ name, ok, detail: d }); console.log((ok ? 'PASS ' : 'FAIL ') + name + ' — ' + d); await ctx.close(); }
async function login(p, email, pw = 'Dev-Password-2026') { await p.goto(BASE + '#/login'); await S('login form', p.waitForSelector('#em')); await p.fill('#em', email); await p.fill('#pw', pw); await p.click('button:has-text("Sign in")'); await S('boot', p.waitForFunction(() => window.__LTI_LIVE, null, { timeout: 15000 })); }
const openTrip = async (p, id) => { await p.evaluate(i => { S.cur = 'd' + i; S.tsec = 'Overview'; go('trip'); }, id); await S('trip page', p.waitForSelector('#m input[aria-label="Trip name"]')); };
(async () => { browser = await chromium.launch({ executablePath: process.env.CHROME });
const amelia = sql("SELECT id FROM contacts WHERE email='amelia@example.com'"), okafor = sql("SELECT id FROM contacts WHERE email='okafor@example.com'");
await T('TC01 Create a trip in the restored Trips screen; record is in MySQL', async p => {
  await login(p, 'owner@lti.test'); await p.click('nav.tn a:has-text("Trips")'); await p.click('#m button:has-text("New trip")'); await S('dialog', p.waitForSelector('#ov #a1'));
  await p.fill('#a1', 'Douro Discovery 2027'); await p.selectOption('#a2', 'dc' + amelia); await p.fill('#a3', 'Lisbon, Portugal'); await p.fill('#a4', '2027-05-10'); await p.fill('#a5', '2027-05-18'); await p.click('#ov .btn.g1');
  await S('trip page', p.waitForSelector('#m input[aria-label="Trip name"]')); st.id = sql("SELECT id FROM trips WHERE name='Douro Discovery 2027'");
  const row = sql(`SELECT CONCAT_WS('|',status,destination,start_date,end_date,primary_contact_id) FROM trips WHERE id=${st.id}`); A(row === `Inquiry|Lisbon, Portugal|2027-05-10|2027-05-18|${amelia}`, row);
  A(!(await p.evaluate(() => Object.values(localStorage).join(' ').includes('Douro Discovery 2027'))), 'trip copied into browser storage'); return 'trip ' + st.id + ': ' + row; });
await T('TC02 Refresh: the trip remains (loaded from MySQL)', async p => {
  await login(p, 'owner@lti.test'); await p.click('nav.tn a:has-text("Trips")'); await S('board card', p.waitForSelector('#m .kd:has-text("Douro Discovery 2027")')); await p.reload(); await S('boot', p.waitForFunction(() => window.__LTI_LIVE));
  await p.evaluate(() => go('trips')); await S('after reload', p.waitForSelector('#m .kd:has-text("Douro Discovery 2027")')); A(!(await p.locator('#m .kd:has-text("Kyoto")').count()), 'sample trips mixed into live board'); return 'present after reload; sample trips not shown'; });
await T('TC03 Edit the trip (status, destination, internal notes); MySQL changes', async p => {
  await login(p, 'owner@lti.test'); await openTrip(p, st.id); await p.selectOption('select[aria-label="Trip status"]', 'Planning');
  await p.locator('label:has-text("Destination") + input').fill('Lisbon & Porto, Portugal'); await p.locator('label:has-text("Destination") + input').dispatchEvent('change');
  await p.evaluate(() => { S.tsec = 'Notes'; draw(); }); await p.fill('textarea[aria-label="Notes"]', 'Prefers boutique hotels');
  const v = await waitDb('edit', `SELECT CONCAT_WS('|',status,destination,notes) FROM trips WHERE id=${st.id}`, x => x === 'Planning|Lisbon & Porto, Portugal|Prefers boutique hotels'); return v; });
await T('TC04 Link a real CRM contact as a traveler from the Travelers section', async p => {
  await login(p, 'owner@lti.test'); await openTrip(p, st.id); await p.evaluate(() => { S.tsec = 'Travelers'; draw(); }); await p.click('#m button:has-text("Add traveler")');
  await S('dialog', p.waitForSelector('#ov #tva')); await p.selectOption('#tva', 'dc' + okafor); await p.selectOption('#tvp', 'Full'); await p.click('#ov .btn.g1');
  const v = await waitDb('traveler', `SELECT GROUP_CONCAT(CONCAT(contact_id,':',permission) ORDER BY permission) FROM trip_travelers WHERE trip_id=${st.id}`, x => x.includes(okafor + ':full') && x.includes(amelia + ':primary'));
  await S('listed', p.waitForSelector('#m :text("Chidi Okafor")')); return v; });
await T('TC05–TC07 Itinerary: add a day and activities in the editor, reorder, saved order in MySQL', async p => {
  await login(p, 'owner@lti.test'); await p.evaluate(i => { S.cur = 'd' + i; X.ui.opt = ''; go('itinerary'); }, st.id); await S('editor loaded', p.waitForFunction(() => X.itx[S.cur] && !X.itx[S.cur]._loading));
  A(sql(`SELECT COUNT(*) FROM itinerary_options WHERE trip_id=${st.id}`) === '1', 'TC05 itinerary option');
  const add = async (day, type, title, inote) => { await p.locator('.dayc').nth(day).locator('button:has-text("Add activity")').click(); await p.locator(`.dayc .dd a:has-text("${type}")`).first().click(); await S('activity dialog', p.waitForSelector('#ov #ea1')); await p.fill('#ea1', title); if (inote) await p.fill('#ea9', inote); await p.fill('#ea10', 'See you there'); await p.click('#ov .btn.g1'); };
  await add(0, 'Tour', 'Alfama walk', 'INTERNAL guide tip'); await add(0, 'Dining', 'Belcanto dinner');
  await p.click('#m button:has-text("Add day")'); await p.locator('.dayc').nth(1).locator('input[aria-label="Day title"]').fill('Sintra'); await p.locator('.dayc').nth(1).locator('input[aria-label="Day title"]').dispatchEvent('change');
  await add(1, 'Excursion', 'Pena Palace');
  const q = `SELECT GROUP_CONCAT(a.title ORDER BY d.sort_order, a.sort_order SEPARATOR '>') FROM itinerary_activities a JOIN itinerary_days d ON d.id=a.day_id JOIN itinerary_options o ON o.id=d.option_id WHERE o.trip_id=${st.id}`;
  await waitDb('TC06 saved', q, x => x === 'Alfama walk>Belcanto dinner>Pena Palace');
  A(sql(`SELECT GROUP_CONCAT(d.title ORDER BY d.sort_order) FROM itinerary_days d JOIN itinerary_options o ON o.id=d.option_id WHERE o.trip_id=${st.id}`) === 'Day 1,Sintra', 'days');
  await p.locator('.actc', { hasText: 'Belcanto dinner' }).locator('button[aria-label="Move up"]').click();
  const v = await waitDb('TC07 reorder', q, x => x === 'Belcanto dinner>Alfama walk>Pena Palace');
  await p.reload(); await S('boot', p.waitForFunction(() => window.__LTI_LIVE)); await p.evaluate(i => { S.cur = 'd' + i; go('itinerary'); }, st.id); await S('after reload', p.waitForFunction(() => X.itx[S.cur] && !X.itx[S.cur]._loading));
  const ui = await p.evaluate(() => X.itx[S.cur].opts[0].days[0].acts.map(a => a.title).join('>')); A(ui === 'Belcanto dinner>Alfama walk', 'UI order after reload ' + ui); return v; });
await T('TC08–TC09 Publish creates a version; editing the draft leaves the published version unchanged', async p => {
  await login(p, 'owner@lti.test'); await p.evaluate(i => { S.cur = 'd' + i; go('itinerary'); }, st.id); await S('loaded', p.waitForFunction(() => X.itx[S.cur] && !X.itx[S.cur]._loading));
  await p.click('#m .hd button.g1:has-text("Publish")'); const vid = await waitDb('TC08 publish', `SELECT published_version_id FROM trips WHERE id=${st.id} AND is_published=1`, x => x && x !== 'NULL');
  const snap = sql(`SELECT snapshot_json FROM itinerary_versions WHERE id=${vid}`); A(snap.includes('Alfama walk') && !snap.includes('INTERNAL'), 'snapshot content');
  await S('published state', p.waitForFunction(() => !/Unpublished changes/.test(document.querySelector('#m').innerText)));
  await p.locator('.actc', { hasText: 'Alfama walk' }).locator('button:has-text("Edit")').click(); await p.fill('#ea1', 'Alfama walk with fado'); await p.click('#ov .btn.g1');
  await waitDb('TC09 draft saved', `SELECT COUNT(*) FROM itinerary_activities a JOIN itinerary_days d ON d.id=a.day_id JOIN itinerary_options o ON o.id=d.option_id WHERE o.trip_id=${st.id} AND a.title='Alfama walk with fado'`, x => x === '1');
  const snap2 = sql(`SELECT v.snapshot_json FROM itinerary_versions v JOIN trips t ON t.published_version_id=v.id WHERE t.id=${st.id}`); A(!snap2.includes('with fado') && snap2.includes('Alfama walk'), 'published version changed');
  await S('unpublished indicator', p.waitForSelector('#m :text("Unpublished changes")')); return 'version ' + vid + ' unchanged by draft edit; indicator shown'; });
await T('TC10 Permitted traveler signs in and opens the published itinerary (no internal notes); approval saved', async p => {
  const amalfi = sql("SELECT id FROM trips WHERE name='Amalfi Coast Anniversary'"); await login(p, 'traveler@lti.test');
  await S('portal', p.waitForSelector('#m .portal h1:has-text("Amalfi Coast Anniversary")')); const txt = await p.locator('#m').innerText(); A(/Le Sirenuse/.test(txt) && !/INTERNAL/.test(txt), 'content');
  A(!(await p.locator('select[aria-label="Viewing as traveler"]').count()), 'viewing-as switch exposed to traveler'); A(!/Back office|Trips/.test(await p.locator('#top').innerText()), 'staff navigation visible');
  await p.click('#m button:has-text("Approve trip")'); await p.click('#ov .btn.g1'); await S('approved', p.waitForSelector('#m :text("Approved by")'));
  const v = await waitDb('approval', `SELECT CONCAT(status,'|',(SELECT COUNT(*) FROM trip_approvals WHERE trip_id=${amalfi} AND action='approved')) FROM trips WHERE id=${amalfi}`, x => x === 'Approved|1'); return 'portal shows published itinerary; ' + v; });
await T('TC11 A traveler cannot access another client’s trip (UI list and direct API)', async p => {
  const amalfi = sql("SELECT id FROM trips WHERE name='Amalfi Coast Anniversary'"); await login(p, 'traveler2@lti.test'); await S('portal', p.waitForSelector('#m .portal'));
  const names = await p.evaluate(() => S.trips.map(t => t.n)); A(!names.includes('Amalfi Coast Anniversary'), 'list ' + names);
  const codes = await p.evaluate(async id => [await fetch('./api/portal/trips/' + id).then(r => r.status), await fetch('./api/trips/' + id).then(r => r.status), await fetch('./api/trips').then(r => r.status)], amalfi);
  A(codes.join() === '404,403,403', 'codes ' + codes); return 'sees ' + names.join(', ') + '; other trip 404; staff API 403'; });
await T('TC12 Archive a trip from the trip Actions menu; related records intact', async p => {
  const before = sql(`SELECT CONCAT((SELECT COUNT(*) FROM trip_travelers WHERE trip_id=${st.id}),'/',(SELECT COUNT(*) FROM itinerary_activities a JOIN itinerary_days d ON d.id=a.day_id JOIN itinerary_options o ON o.id=d.option_id WHERE o.trip_id=${st.id}),'/',(SELECT COUNT(*) FROM itinerary_versions WHERE trip_id=${st.id}))`);
  await login(p, 'owner@lti.test'); await openTrip(p, st.id); await p.click('#m button:has-text("Actions")'); await p.click('#m .dd a:has-text("Archive trip")');
  await waitDb('archived', `SELECT CONCAT(status,'|',archived_at IS NOT NULL) FROM trips WHERE id=${st.id}`, x => x === 'Archived|1');
  const after = sql(`SELECT CONCAT((SELECT COUNT(*) FROM trip_travelers WHERE trip_id=${st.id}),'/',(SELECT COUNT(*) FROM itinerary_activities a JOIN itinerary_days d ON d.id=a.day_id JOIN itinerary_options o ON o.id=d.option_id WHERE o.trip_id=${st.id}),'/',(SELECT COUNT(*) FROM itinerary_versions WHERE trip_id=${st.id}))`);
  A(before === after, before + ' vs ' + after); await p.evaluate(() => { trF().arch = 0; go('trips'); }); A(!(await p.locator('#m .kd:has-text("Douro Discovery 2027")').count()), 'still on board'); return 'travelers/activities/versions ' + after + ' unchanged'; });
await T('TC13 Contacts and Staff still work (live)', async p => {
  await login(p, 'admin@lti.test'); await p.click('nav.tn a:has-text("Contacts")'); await S('contacts', p.waitForSelector('[data-live=contacts] tbody tr')); await p.click('#m button:has-text("New contact")'); await p.fill('#lcf_firstName', 'Phase3'); await p.fill('#lcf_lastName', 'Check'); await p.click('#ov .btn.g1');
  await S('saved', p.waitForSelector('#m h1:has-text("Phase3 Check")')); A(sql("SELECT COUNT(*) FROM contacts WHERE first_name='Phase3'") === '1', 'db'); await p.click('button.pf'); await p.click('.dd a:has-text("Staff accounts")'); await S('staff', p.waitForSelector('[data-live=staff] tbody tr')); return 'ok'; });
await T('TC14 Dashboard and prototype modules remain accessible and labelled', async p => {
  await login(p, 'owner@lti.test'); const r = await p.evaluate(() => { const bad = []; for (const k of Object.keys(Pg)) { if (k == 'lcontact') continue; try { if (k == 'trip') S.cur = 't1'; go(k); } catch (e) { bad.push(k + ':' + e.message); } } go('dashboard'); return { bad, n: Object.keys(Pg).length, dash: !!document.querySelector('#m .proto-note'), bookings: (go('payments'), !!document.querySelector('#m .proto-note')) }; });
  A(!r.bad.length && r.dash && r.bookings, JSON.stringify(r)); return r.n + ' pages render; prototype pages labelled'; });
console.log('PHASE3 E2E SUMMARY ' + results.filter(r => r.ok).length + '/' + results.length); require('fs').writeFileSync(__dirname + '/phase3_results.json', JSON.stringify(results, null, 1)); await browser.close(); })();
