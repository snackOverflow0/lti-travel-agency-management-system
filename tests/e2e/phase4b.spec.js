// Phase 4 increment 2 E2E: restored Group trips, Forms and Content library screens -> PHP API -> MySQL.
const { chromium } = require(process.env.PW || 'playwright');
const { execSync } = require('child_process'); const fs = require('fs');
const BASE = process.env.BASE || 'http://127.0.0.1:8000/';
const sql = q => execSync(`mysql --default-character-set=utf8mb4 -uroot -N -e "${q.replace(/"/g, '\\"')}" ${process.env.DB || 'lti_test'}`).toString().trim();
const results = []; let browser; const st = {};
const A = (c, m) => { if (!c) throw new Error(m); };
const S = async (label, pr) => { try { return await pr; } catch (e) { throw new Error(label + ': ' + String(e.message).split('\n')[0]); } };
const waitDb = async (label, q, ok, ms = 12000) => { const t0 = Date.now(); let v; while (Date.now() - t0 < ms) { v = sql(q); if (ok(v)) return v; await new Promise(r => setTimeout(r, 300)); } throw new Error(label + ': database value ' + JSON.stringify(v)); };
async function T(name, fn, vp) { const ctx = await browser.newContext({ viewport: vp || { width: 1366, height: 900 }, reducedMotion: 'reduce' }); const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
  let ok = false, d = ''; try { d = (await fn(p, ctx)) || ''; ok = !errs.length; if (errs.length) d += ' JSERR ' + errs.slice(0, 3).join('|'); } catch (e) { d = String(e.message).split('\n')[0]; }
  results.push({ name, ok, detail: d }); console.log((ok ? 'PASS ' : 'FAIL ') + name + ' — ' + d); if (process.env.SHOTS) { try { await p.screenshot({ path: process.env.SHOTS + '/' + (process.env.SUITE || 'e2e') + '-' + String(results.length).padStart(2, '0') + '.png' }); } catch (e) { } } await ctx.close(); }
async function login(p, email) { await p.goto(BASE + '#/login'); await S('login form', p.waitForSelector('#em')); await p.fill('#em', email); await p.fill('#pw', 'Dev-Password-2026'); await p.click('button:has-text("Sign in")'); await S('boot', p.waitForFunction(() => window.__LTI_LIVE, null, { timeout: 15000 })); }
const openGroup = async (p, tab) => { await p.evaluate(g => { X.ui.grp = 'd' + g; go('groups'); }, st.g); await S('group page', p.waitForSelector('#m button:has-text("Edit group")')); if (tab) { await p.click(`#m .tabs button:has-text("${tab}")`); } };
const addAct = async (p, day, type, title) => { await p.locator('.dayc').nth(day).locator('button:has-text("Add activity")').click(); await p.locator(`.dayc .dd a:has-text("${type}")`).first().click(); await S('activity dialog', p.waitForSelector('#ov #ea1')); await p.fill('#ea1', title); await p.click('#ov .btn.g1'); };
const actsOf = id => `SELECT GROUP_CONCAT(a.title ORDER BY d.sort_order, a.sort_order SEPARATOR '>') FROM itinerary_activities a JOIN itinerary_days d ON d.id=a.day_id JOIN itinerary_options o ON o.id=d.option_id WHERE o.trip_id=${id}`;
(async () => { browser = await chromium.launch({ executablePath: process.env.CHROME });
const amelia = sql("SELECT id FROM contacts WHERE email='amelia@example.com'"), amalfi = sql("SELECT id FROM trips WHERE name='Amalfi Coast Anniversary'"), advId = sql("SELECT id FROM users WHERE email='advisor@lti.test'");
await T('TC01–TC02 Create a group trip, edit details, landing page and room categories in the restored Group trips screen', async p => {
  await login(p, 'owner@lti.test'); await p.evaluate(() => go('groups')); A(!(await p.locator('#m .proto-note').count()), 'labelled as prototype'); A(!(await p.locator('#m .gt:has-text("Danube Christmas Markets — LTI Group 2026")').count()), 'sample group shown');
  await p.click('#m button[onclick="grpNew()"]'); await S('dialog', p.waitForSelector('#gn1')); await p.fill('#gn1', 'Danube Christmas Markets 2027'); await p.fill('#gn2', 'Budapest to Passau'); await p.fill('#gn3', '2027-12-10'); await p.fill('#gn4', '2027-12-18');
  await p.fill('#gn5', '3'); await p.fill('#gn6', '2027-11-20'); await p.selectOption('#gn7', advId); await p.fill('#gn9', '500'); await p.click('#ov .btn.g1');
  st.g = await waitDb('created', "SELECT id FROM trips WHERE name='Danube Christmas Markets 2027' AND trip_type='group'", v => !!v);
  A(sql(`SELECT CONCAT_WS('|',group_capacity,registration_deadline,coordinator_id,start_date) FROM trips WHERE id=${st.g}`) === `3|2027-11-20|${advId}|2027-12-10`, 'row');
  await S('detail', p.waitForSelector('#m button:has-text("Edit group")')); await p.click('#m button:has-text("Edit group")'); await p.fill('#gn2', 'Budapest to Passau (Danube)'); await p.click('#ov .btn.g1');
  await waitDb('edit', `SELECT destination FROM trips WHERE id=${st.g}`, v => v === 'Budapest to Passau (Danube)');
  await p.click('#m .tabs button:has-text("Landing page")'); const head = p.locator('#m label:has-text("Headline") + input'); await head.fill('Sparkle along the Danube'); await head.dispatchEvent('change');
  await waitDb('landing', `SELECT group_details_json FROM trips WHERE id=${st.g}`, v => v.includes('Sparkle along the Danube'));
  await p.click('#m .tabs button:has-text("Pricing & rooms")'); await p.click('#m button:has-text("Add category")');
  await waitDb('room', `SELECT COUNT(*) FROM group_rooms WHERE trip_id=${st.g}`, v => v === '1'); st.room = sql(`SELECT id FROM group_rooms WHERE trip_id=${st.g}`); return 'group ' + st.g + ': details, landing headline and room category saved'; });
await T('TC03–TC06 Register existing and new travelers; duplicate and capacity rules; administrator override; cancellation', async p => {
  await login(p, 'owner@lti.test'); await openGroup(p, 'Travelers & sub-trips'); await S('live table', p.waitForSelector('#m [data-live=registrations]'));
  await p.click('#m button:has-text("Register travelers")'); await S('dialog', p.waitForSelector('.grc')); await p.check(`.grc[value="${amelia}"]`); await p.selectOption('#grr', st.room); await p.fill('#grp', 'High floor'); await p.fill('#grq', 'Vegetarian meals'); await p.click('#ov .btn.g1');
  st.reg1 = await waitDb('TC03', `SELECT id FROM group_registrations WHERE group_trip_id=${st.g} AND contact_id=${amelia}`, v => !!v); st.sub1 = sql(`SELECT sub_trip_id FROM group_registrations WHERE id=${st.reg1}`);
  A(sql(`SELECT CONCAT_WS('|',room_preference,special_requests,room_id) FROM group_registrations WHERE id=${st.reg1}`) === `High floor|Vegetarian meals|${st.room}`, 'TC03 row'); A(sql(`SELECT CONCAT(trip_type,'|',parent_trip_id) FROM trips WHERE id=${st.sub1}`) === `sub|${st.g}`, 'sub-trip');
  await p.click('#m button:has-text("Register travelers")'); await S('dialog2', p.waitForSelector('#grn1f')); await p.fill('#grn1f', 'Grace'); await p.fill('#grn1l', 'Lin'); await p.fill('#grn1e', 'grace.lin@example.com'); await p.click('#ov .btn.g1');
  await waitDb('TC04', "SELECT source FROM contacts WHERE email='grace.lin@example.com'", v => v === 'Group registration');
  await p.click('#m button:has-text("Register travelers")'); await S('dialog3', p.waitForSelector('.grc')); await p.check(`.grc[value="${amelia}"]`); await p.click('#ov .btn.g1'); await S('TC05 message', p.waitForSelector('#er:has-text("Already registered")')); await p.click('#ov .btn.o');
  await p.click('#m button:has-text("Register travelers")'); await S('dialog4', p.waitForSelector('#grn1f')); await p.fill('#grn1f', 'Extra'); await p.fill('#grn1e', 'extra1@example.com'); await p.fill('#grn2f', 'Extra'); await p.fill('#grn2l', 'Two'); await p.fill('#grn2e', 'extra2@example.com');
  await p.click('#ov .btn.g1'); await S('TC06 refused', p.waitForSelector('#er:has-text("capacity")')); A(sql(`SELECT COUNT(*) FROM group_registrations WHERE group_trip_id=${st.g}`) === '2', 'over-capacity registration saved');
  await p.check('#gro'); await p.click('#ov .btn.g1'); const over = await waitDb('TC06 override', `SELECT id FROM group_registrations WHERE group_trip_id=${st.g} AND over_capacity_by IS NOT NULL`, v => !!v);
  await p.locator('#m [data-live=registrations] tr', { hasText: 'Extra Two' }).locator('button:has-text("Cancel")').click(); await p.fill('#a1', 'Changed plans'); await p.click('#ov .btn.g1');
  await waitDb('cancel', `SELECT status FROM group_registrations WHERE id=${over}`, v => v === 'cancelled'); return 'existing + new traveler registered; duplicate and capacity refused; override recorded; cancellation released places'; });
await T('TC07–TC09 Create a sub-trip; sync the group itinerary; custom sub-trip activity preserved', async p => {
  await login(p, 'owner@lti.test'); await openGroup(p, 'Travelers & sub-trips'); await p.click('#m button:has-text("New sub-trip")'); await p.fill('#sb1', 'Danube — staff hosts'); await p.fill('#sb2', '2027-12-09'); await p.click('#ov .btn.g1');
  st.sub2 = await waitDb('TC07', `SELECT id FROM trips WHERE name='Danube — staff hosts' AND parent_trip_id=${st.g}`, v => !!v);
  await p.evaluate(g => { S.cur = 'd' + g; X.ui.opt = ''; go('itinerary'); }, st.g); await S('group editor', p.waitForFunction(() => X.itx[S.cur] && !X.itx[S.cur]._loading)); await addAct(p, 0, 'Tour', 'Vienna markets walk');
  await waitDb('group act', actsOf(st.g), v => v === 'Vienna markets walk');
  await p.evaluate(s => { S.cur = 'd' + s; X.ui.opt = ''; go('itinerary'); }, st.sub1); await S('sub editor', p.waitForFunction(() => X.itx[S.cur] && !X.itx[S.cur]._loading)); await addAct(p, 0, 'Dining', 'Private dinner for Amelia');
  await waitDb('custom act', actsOf(st.sub1), v => v === 'Private dinner for Amelia');
  await openGroup(p, 'Itinerary sync'); await S('preview', p.waitForSelector('#m [data-live=sync] tbody tr td:has-text("Danube Christmas Markets 2027")'));
  const added = await p.locator('#m [data-live=sync] tbody tr').first().locator('td').nth(2).innerText(); A(Number(added) >= 1, 'preview added ' + added);
  await p.click('#m button:has-text("Apply sync to sub-trips")'); const v = await waitDb('TC08/09', actsOf(st.sub1), x => x && x.includes('Vienna markets walk') && x.includes('Private dinner for Amelia'));
  A(sql(`SELECT COUNT(*) FROM itinerary_activities a JOIN itinerary_days d ON d.id=a.day_id JOIN itinerary_options o ON o.id=d.option_id WHERE o.trip_id=${st.sub1} AND a.title='Vienna markets walk' AND a.origin_activity_id IS NOT NULL`) === '1', 'synced origin link');
  A((await waitDb('sub2', actsOf(st.sub2), x => x === 'Vienna markets walk')) === 'Vienna markets walk', 'sub2'); return 'preview showed ' + added + ' to add; after sync: ' + v; });
await T('TC17–TC18 Create a form; rename; add, edit, require and reorder fields in the restored builder', async p => {
  await login(p, 'sales@lti.test'); await p.evaluate(() => go('forms')); A(!(await p.locator('#m .proto-note').count()), 'labelled as prototype');
  await p.click('#m button[onclick="frNew()"]'); await S('builder', p.waitForSelector('#m input[aria-label="Form name"]')); st.form = await waitDb('created', "SELECT MAX(id) FROM forms", v => v && v !== 'NULL');
  const nm = p.locator('#m input[aria-label="Form name"]'); await nm.fill('Danube group intake'); await nm.dispatchEvent('change'); await waitDb('rename', `SELECT name FROM forms WHERE id=${st.form}`, v => v === 'Danube group intake');
  await p.click('#m button:has-text("Add field")'); const lab = p.locator('#m label:has-text("Label") + input').nth(2); await lab.fill('Cabin preference'); await lab.dispatchEvent('change');
  await p.locator('#m label.ck input[type=checkbox]').nth(2).check(); await p.locator('#m button[aria-label="Move up"]').nth(2).click();
  const v = await waitDb('fields', `SELECT GROUP_CONCAT(CONCAT(label,':',is_required) ORDER BY sort_order) FROM form_fields WHERE form_id=${st.form}`, x => x === 'First name:1,Cabin preference:1,Email:1');
  st.key = sql(`SELECT field_key FROM form_fields WHERE form_id=${st.form} AND label='Cabin preference'`); return 'form ' + st.form + ': ' + v; });
await T('TC19–TC20 Publish; public link submission without sign-in; response stored, linked to a trip, exported; duplicate and archive', async p => {
  await login(p, 'owner@lti.test'); await p.evaluate(f => { X.ui.form = 'df' + f; X.ui.fmv = 'Build'; go('forms'); }, st.form); await S('builder', p.waitForSelector('#m button:has-text("Publish")')); await p.click('#m button:has-text("Publish")');
  await waitDb('published', `SELECT status FROM forms WHERE id=${st.form}`, v => v === 'published'); const token = sql(`SELECT public_token FROM forms WHERE id=${st.form}`);
  const c2 = await browser.newContext(); const pub = await c2.newPage(); await pub.goto(BASE + '#/f/' + token); await S('public form', pub.waitForSelector('h1:has-text("Danube group intake")'));
  await pub.fill('#pf_first', 'Helen'); await pub.fill('#pf_email', 'helen.ruiz@example.com'); await pub.click('button:has-text("Submit")'); await S('required check', pub.waitForSelector('.err:has-text("Required")'));
  await pub.fill('#pf_' + st.key, 'Veranda'); await pub.click('button:has-text("Submit")'); await S('thanks', pub.waitForSelector('h1:has-text("Thank you")')); await c2.close();
  const sid = await waitDb('TC20', `SELECT id FROM form_submissions WHERE form_id=${st.form}`, v => !!v); A(sql(`SELECT value FROM form_answers WHERE submission_id=${sid} AND field_key='${st.key}'`) === 'Veranda', 'answer');
  A(sql(`SELECT email FROM contacts WHERE id=(SELECT contact_id FROM form_submissions WHERE id=${sid})`) === 'helen.ruiz@example.com', 'contact');
  await p.click('#m .seg button:has-text("Responses")'); await S('responses', p.waitForSelector(`#m button[onclick^="frView('dr"]`)); await p.click(`#m button[onclick^="frView('dr"]`); await p.selectOption('#fr2', amalfi); await p.click('#ov .btn.g1');
  await waitDb('linked', `SELECT CONCAT(trip_id,'|',status) FROM form_submissions WHERE id=${sid}`, v => v === amalfi + '|reviewed');
  const csv = await p.request.get(BASE + `api/forms/${st.form}/submissions.csv`); A(csv.status() === 200 && (await csv.text()).includes('Veranda'), 'csv');
  await p.click('#m button:has-text("Duplicate")'); await waitDb('duplicate', "SELECT COUNT(*) FROM forms WHERE name='Danube group intake (copy)'", v => v === '1'); await S('copy opened', p.waitForFunction(f => X.ui.form && X.ui.form !== 'df' + f, st.form));
  await p.evaluate(f => { X.ui.form = 'df' + f; go('forms'); }, st.form); await p.click('#m button:has-text("Archive")'); await waitDb('archive', `SELECT status FROM forms WHERE id=${st.form}`, v => v === 'archived');
  return 'public submission ' + sid + ' stored with answers and contact; linked to trip; CSV; duplicate; archive'; });
await T('TC25–TC26 Content library: folders, activity templates (create, edit, duplicate, move), use on a trip; packing list', async p => {
  await login(p, 'advisor@lti.test'); await p.evaluate(() => go('content')); A(!(await p.locator('#m .proto-note').count()), 'content hub still labelled prototype');
  await p.click('#m button:has-text("New folder")'); await p.fill('#lf1', 'Europe'); await p.click('#ov .btn.g1'); st.fold = await waitDb('folder', "SELECT id FROM content_folders WHERE name='Europe'", v => !!v);
  await S('folder selected', p.waitForSelector('#m button:has-text("Rename or move")')); await p.click('#m button:has-text("Rename or move")'); await p.fill('#lf1', 'Europe 2027'); await p.click('#ov .btn.g1'); await waitDb('rename', `SELECT name FROM content_folders WHERE id=${st.fold}`, v => v === 'Europe 2027');
  await p.click('#m button[onclick="libAdd()"]'); await p.fill('#la2', 'Melk Abbey visit'); await p.fill('#la3', 'Melk, Austria'); await p.click('#ov .btn.g1');
  st.tpl = await waitDb('template', "SELECT id FROM content_templates WHERE title='Melk Abbey visit'", v => !!v); A(sql(`SELECT folder_id FROM content_templates WHERE id=${st.tpl}`) === st.fold, 'created in folder');
  await p.click(`#m button[onclick="libEdit(${st.tpl})"]`); await p.fill('#la2', 'Melk Abbey private visit'); await p.selectOption('#la6', ''); await p.click('#ov .btn.g1');
  await waitDb('edit + move', `SELECT CONCAT(title,'|',IFNULL(folder_id,'none')) FROM content_templates WHERE id=${st.tpl}`, v => v === 'Melk Abbey private visit|none');
  await p.evaluate(() => { X.ui.lfold = ''; draw(); }); await p.click(`#m button[onclick="libDup(${st.tpl})"]`); await waitDb('duplicate', "SELECT COUNT(*) FROM content_templates WHERE title LIKE 'Melk Abbey private visit%'", v => v === '2');
  await p.click(`#m button[onclick="libUse('dl${st.tpl}')"]`); await p.selectOption('#lu1', amalfi); await p.fill('#lu2', '1'); await p.click('#ov .btn.g1');
  await waitDb('use', `SELECT COUNT(*) FROM itinerary_activities a JOIN itinerary_days d ON d.id=a.day_id JOIN itinerary_options o ON o.id=d.option_id WHERE o.trip_id=${amalfi} AND a.title='Melk Abbey private visit'`, v => v === '1');
  await p.click('#m .tabs button:has-text("Packing lists")'); await p.click('#m button[onclick="libNewList()"]'); const pl = await waitDb('list', "SELECT id FROM content_templates WHERE kind='checklist' AND title='New packing list'", v => !!v);
  const add = p.locator('#m input[placeholder="Add item + Enter"]').last(); await add.fill('Thermal gloves'); await add.press('Enter'); await waitDb('item', `SELECT body_json FROM content_templates WHERE id=${pl}`, v => v.includes('Thermal gloves'));
  return 'folder created/renamed; template created in folder, edited and moved, duplicated, added to Amalfi day 1; packing list item saved'; });
await T('TC27–TC28 Groups, forms and library survive refresh and sign-out/sign-in; nothing written to browser storage', async p => {
  await login(p, 'owner@lti.test'); await p.reload(); await S('boot', p.waitForFunction(() => window.__LTI_LIVE)); await p.evaluate(() => go('groups')); await S('group after reload', p.waitForSelector('#m :text("Danube Christmas Markets 2027")'));
  const ls = await p.evaluate(() => Object.values(localStorage).join(' ')); const leaked = ['Danube Christmas Markets 2027', 'Melk Abbey private visit', 'Danube group intake', 'Private dinner for Amelia', 'Europe 2027'].filter(x => ls.includes(x)); A(!leaked.length, 'database records in browser storage: ' + leaked);
  await p.click('button.pf'); await p.click('.dd a:has-text("Sign out")'); await S('signed out', p.waitForSelector('h1:has-text("Sign in to LTI")')); await login(p, 'advisor@lti.test');
  await p.evaluate(() => go('content')); await S('library after re-login', p.waitForSelector('#m :text("Melk Abbey private visit")')); await p.evaluate(() => go('forms')); await S('forms after re-login', p.waitForSelector('#m :text("Danube group intake (copy)")'));
  return 'present after refresh and re-login'; });
await T('TC29 Roles: sales cannot create groups; another advisor cannot see the group; finance has no forms access', async p => {
  await login(p, 'sales@lti.test'); await p.evaluate(() => go('groups')); const b = p.locator('#m button[onclick="grpNew()"]'); if (!(await b.isDisabled())) { await b.click(); await S('toast', p.waitForSelector('text=Your role cannot manage group trips')); }
  const c2 = await browser.newContext(); const a2 = await c2.newPage(); await login(a2, 'advisor2@lti.test'); const seen = await a2.evaluate(() => S.trips.filter(t => t.db && t.type === 'group').map(t => t.n)); await c2.close(); A(!seen.includes('Danube Christmas Markets 2027'), 'advisor2 sees ' + seen);
  const c3 = await browser.newContext(); const f = await c3.newPage(); await login(f, 'finance@lti.test'); const r = await f.evaluate(async () => [X.forms.filter(x => x.db).length, await fetch('./api/forms').then(x => x.status), await fetch('./api/groups/' + 1).then(x => x.status)]); await c3.close();
  A(r[0] === 0 && r[1] === 403, 'finance ' + r); return 'sales blocked; advisor2 does not see the group; finance forms 403'; });
await T('Mobile 390px: Group trips, Forms and Content library without horizontal overflow', async p => {
  await login(p, 'owner@lti.test'); const out = []; for (const pg of ['groups', 'forms', 'content']) { await p.evaluate(x => { X.ui.grp = ''; X.ui.form = ''; go(x); }, pg); out.push(await p.evaluate(() => document.documentElement.scrollWidth)); }
  A(out.every(x => x <= 392), 'scrollWidth ' + out); return out.join('/'); }, { width: 390, height: 844 });
console.log('PHASE4B E2E SUMMARY ' + results.filter(r => r.ok).length + '/' + results.length); fs.writeFileSync(__dirname + '/phase4b_results.json', JSON.stringify(results, null, 1)); await browser.close(); })();
