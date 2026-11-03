// Frontend restoration E2E: real sign-in -> restored Revision 27 interface -> live Contacts/Staff via PHP/MySQL.
const { chromium } = require(process.env.PW || 'playwright');
const { execSync } = require('child_process');
const BASE = process.env.BASE || 'http://127.0.0.1:8000/';
const sql = q => execSync(`mysql -uroot -N -e "${q.replace(/"/g, '\\"')}" ${process.env.DB || 'lti_test'}`).toString().trim();
const results = []; let browser;
const A = (c, m) => { if (!c) throw new Error(m); };
const S = async (label, pr) => { try { return await pr; } catch (e) { throw new Error(label + ': ' + String(e.message).split('\n')[0]); } };
async function T(name, fn, vp) { const ctx = await browser.newContext({ viewport: vp || { width: 1366, height: 900 }, reducedMotion: 'reduce' }); const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
  let ok = false, d = ''; try { d = (await fn(p, ctx)) || ''; ok = !errs.length; if (errs.length) d += ' JSERR ' + errs.slice(0, 3).join('|'); } catch (e) { d = String(e.message).split('\n')[0]; }
  results.push({ name, ok, detail: d }); console.log((ok ? 'PASS ' : 'FAIL ') + name + ' — ' + d); if (process.env.SHOTS) { try { await p.screenshot({ path: process.env.SHOTS + '/' + (process.env.SUITE || 'e2e') + '-' + String(results.length).padStart(2, '0') + '.png' }); } catch (e) { } } await ctx.close(); }
async function login(p, email, pw = 'Dev-Password-2026') { if (!/#\/login/.test(p.url())) await p.goto(BASE + '#/login'); await S('login form', p.waitForSelector('#em')); await p.fill('#em', email); await p.fill('#pw', pw); await p.click('button:has-text("Sign in")'); }
const ready = p => S('interface boot', p.waitForFunction(() => window.__LTI_LIVE, null, { timeout: 15000 }));
(async () => { browser = await chromium.launch({ executablePath: process.env.CHROME });
await T('Opening http://127.0.0.1:8000/ shows sign-in; after login the full Revision 27 interface appears', async p => {
  await p.goto(BASE); await S('sign-in', p.waitForSelector('h1:has-text("Sign in to LTI")')); await login(p, 'owner@lti.test'); await ready(p);
  const nav = (await p.locator('nav.tn').innerText()).split('\n').map(x => x.trim()).filter(Boolean);
  A(nav.join(',') === 'Back office,Trips,Tasks,Library,Automate,Emails,Contacts,Calls,Commission,Reporting,Help', 'nav ' + nav); A(await p.locator('#m h1:has-text("Dashboard")').count(), 'dashboard');
  A(await p.locator('#spl').count() === 1, 'softphone launcher'); return '11 sections: ' + nav.join(', '); });
await T('Every restored page renders without errors (owner); prototype pages labelled, live pages not', async p => {
  await login(p, 'owner@lti.test'); await ready(p);
  const r = await p.evaluate(() => { const bad = [], unl = [], keys = Object.keys(Pg); for (const k of keys) { try { if (k == 'client') S.cc = 'c1'; if (k == 'trip') S.cur = 't1'; if (k == 'lcontact') continue; go(k); const live = ['contacts', 'staff', 'trips', 'itinerary', 'bookings', 'suppliers', 'docs', 'groups', 'forms', 'calls', 'callhist', 'callan', 'voicemail', 'callset', 'smart', 'recordings', 'payments', 'invoices', 'commission', 'cominv', 'reporting', 'reporting2', 'dashboard', 'tasks', 'calendar', 'notifications', 'emails', 'outbox', 'emt', 'automations', 'autobuild', 'autoed', 'workflows', 'libwf', 'libit', 'libph', 'libcr', 'libta', 'libau', 'libt', 'libd', 'content', 'agency', 'settings', 'tags', 'controls', 'roles', 'team', 'terms', 'trav', 'hsup', 'integ', 'ai', 'scheduling', 'libca', 'libch', 'rpcl', 'rpq', 'tpl', 'tact', 'library', 'libem', 'libfo', 'libsu', 'comm', 'client', 'preview', 'rpc', 'rpview', 'help']; if (!live.includes(k) && !document.querySelector('#m .proto-note') && X.role != 'Traveler') unl.push(k); if (live.includes(k) && document.querySelector('#m .proto-note')) unl.push('LIVE-labelled:' + k) } catch (e) { bad.push(k + ':' + e.message) } } return { n: keys.length, bad, unl } });
  A(!r.bad.length, 'errors ' + r.bad.join(';')); A(!r.unl.length, 'label problems ' + r.unl.join(',')); return r.n + ' pages rendered and correctly labelled'; });
await T('Trip sections, tabbed pages and views render (prototype level)', async p => {
  await login(p, 'owner@lti.test'); await ready(p);
  const bad = await p.evaluate(() => { const out = []; const secs = TSEC.flatMap(g => g[1].map(x => x[0])); for (const t of ['t1', 't2', 't7']) for (const s of secs) { try { S.cur = t; go('trip'); S.tsec = s; draw() } catch (e) { out.push(t + s + e.message) } }
    for (const [k, pg, l] of [['au', 'automations', ['Automations', 'Upcoming runs', 'Execution history', 'Templates']], ['pay', 'payments', ['Schedule', 'Authorizations', 'History', 'Refunds', 'Invoices']], ['cmx', 'commission', ['Overview', 'Expected', 'Statements & reconciliation', 'Payouts']], ['st', 'settings', ['Profile', 'Agency & branding', 'Trip statuses & tags', 'Demo data']]]) for (const x of l) { try { S.tab[k] = x; go(pg) } catch (e) { out.push(pg + x + e.message) } }
    for (const v of ['board', 'list', 'cal']) { X.ui.tv = v; go('trips') } return out });
  A(!bad.length, bad.join(';')); return 'ok'; });
await T('LIVE: create a database contact in the restored Contacts screen; it is in MySQL, survives reload, and is retrievable after a new sign-in', async p => {
  await login(p, 'owner@lti.test'); await ready(p); await p.click('nav.tn a:has-text("Contacts")'); await S('live list', p.waitForSelector('[data-live=contacts]'));
  await p.click('#m button:has-text("New contact")'); await S('dialog', p.waitForSelector('#lcf_firstName'));
  await p.fill('#lcf_firstName', 'Isabel'); await p.fill('#lcf_lastName', 'Moreno'); await p.fill('#lcf_email', 'isabel.moreno@example.com'); await p.fill('#lcf_phone', '+1 (305) 555-0199'); await p.fill('#lcf_birthday', '1979-04-12'); await p.fill('#lcf_notes', 'Prefers boutique hotels.');
  await p.click('#ov .btn.g1'); await S('detail after create', p.waitForSelector('#m h1:has-text("Isabel Moreno")')); const id = p.url().match(/lcontact-(\d+)/)[1];
  A(sql(`SELECT CONCAT_WS('|',first_name,last_name,email,birthday) FROM contacts WHERE id=${id}`) === 'Isabel|Moreno|isabel.moreno@example.com|1979-04-12', 'db row');
  A(!(await p.locator('#m .proto-note').count()), 'live page labelled as prototype');
  await p.reload(); await ready(p); await S('detail after reload', p.waitForSelector('#m h1:has-text("Isabel Moreno")')); A((await p.locator('[data-field="Birthday"]').innerText()) === 'Apr 12, 1979', 'birthday');
  const ls = await p.evaluate(() => Object.keys(localStorage).map(k => localStorage.getItem(k)).join(' ')); A(!ls.includes('isabel.moreno@example.com'), 'database contact copied into localStorage');
  await p.click('button.pf'); await p.click('.dd a:has-text("Sign out")'); await S('signed out', p.waitForSelector('h1:has-text("Sign in to LTI")'));
  A((await p.evaluate(() => fetch('./api/auth/me').then(r => r.status))) === 401, 'session still active');
  await login(p, 'owner@lti.test'); await ready(p); await p.click('nav.tn a:has-text("Contacts")'); await p.fill('#lcq', 'moreno'); await S('search', p.waitForSelector('[data-live=contacts] td:has-text("isabel.moreno@example.com")'));
  await p.locator('[data-live=contacts] tbody tr', { hasText: 'isabel.moreno@example.com' }).click(); await S('detail via search', p.waitForSelector('#m h1:has-text("Isabel Moreno")')); return 'contact ' + id + ': DB row, reload, sign-out/in, search, no localStorage copy'; });
await T('LIVE: edit and delete contacts from the restored screens (server validation shown)', async p => {
  await login(p, 'owner@lti.test'); await ready(p); const id = sql("SELECT id FROM contacts WHERE email='amelia@example.com'"); await p.evaluate(i => { location.hash = 'lcontact-' + i }, id);
  await S('detail', p.waitForSelector('#m h1:has-text("Amelia Hartwell")')); await p.click('#m button:has-text("Edit")'); await p.fill('#lcf_email', 'bad-email'); await p.locator('#lcf_email').evaluate(e => e.type = 'text'); await p.click('#ov .btn.g1');
  await S('field error', p.waitForSelector('#lcfe_email:has-text("valid email")')); await p.fill('#lcf_email', 'amelia.h@example.com'); await p.selectOption('#lcf_leadStatus', 'qualified'); await p.click('#ov .btn.g1');
  await S('updated', p.waitForSelector('[data-field="Lead status"]:has-text("qualified")')); A(sql(`SELECT email FROM contacts WHERE id=${id}`) === 'amelia.h@example.com', 'db email');
  await p.click('#m button:has-text("Delete")'); await p.click('#ov .btn.g1'); await S('back to list', p.waitForSelector('[data-live=contacts]')); A(sql(`SELECT deleted_at IS NOT NULL FROM contacts WHERE id=${id}`) === '1', 'soft delete'); return 'edit + delete persisted'; });
await T('LIVE Staff accounts: administrator deactivates a user; that user cannot sign in', async p => {
  await login(p, 'admin@lti.test'); await ready(p); await p.click('button.pf'); await p.click('.dd a:has-text("Staff accounts")'); await S('staff table', p.waitForSelector('[data-live=staff]'));
  await p.locator('[data-live=staff] tr', { hasText: 'sales@lti.test' }).locator('button:has-text("Deactivate")').click(); await S('deactivated', p.waitForSelector('[data-live=staff] tr:has-text("sales@lti.test") >> text=Deactivated'));
  const c2 = await browser.newContext(); const p2 = await c2.newPage(); await login(p2, 'sales@lti.test'); await S('blocked', p2.waitForSelector('[role=alert]:has-text("deactivated")')); await c2.close(); return 'live staff management works'; });
await T('Real roles drive the interface: Finance has no Automate/Library/Calls, no create; demo role switching is disabled', async p => {
  await login(p, 'finance@lti.test'); await ready(p); const nav = await p.locator('nav.tn').innerText(); A(!/Automate|Library|Calls/.test(nav), 'nav ' + nav);
  await p.click('nav.tn a:has-text("Contacts")'); await S('list', p.waitForSelector('[data-live=contacts]')); A(!(await p.locator('#m button:has-text("New contact")').count()), 'create visible');
  const r = await p.evaluate(async () => { setRole('Owner'); const role = X.role; const me = await (await fetch('./api/auth/me')).json(); const st = await fetch('./api/contacts', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': me.csrf }, body: JSON.stringify({ firstName: 'Bypass' }) }).then(x => x.status); return { role, st } });
  A(r.role === 'Finance' && r.st === 403, JSON.stringify(r)); return 'nav filtered; role switch refused; API 403'; });
await T('Advisor sees only assigned database contacts; another advisor’s contact by URL is refused', async p => {
  const shah = sql("SELECT id FROM contacts WHERE email='shah@example.com'"); await login(p, 'advisor2@lti.test'); await ready(p); await p.click('nav.tn a:has-text("Contacts")');
  await S('rows', p.waitForSelector('[data-live=contacts] tbody tr')); A((await p.locator('[data-live=contacts] tbody tr').count()) === 1, 'not scoped');
  await p.evaluate(i => { location.hash = 'lcontact-' + i }, shah); await S('refused', p.waitForSelector('#m :text("Contact not found")')); return 'scoped + direct URL refused'; });
await T('Traveler account sees only the traveler portal and can sign out', async p => {
  await login(p, 'traveler@lti.test'); await ready(p); A(await p.evaluate(() => S.pg === 'portal'), 'not portal'); A(/Traveler portal/.test(await p.locator('#top').innerText()), 'top');
  await p.click('#top button:has-text("Sign out")'); await S('signed out', p.waitForSelector('h1:has-text("Sign in to LTI")')); return 'portal only'; });
await T('Modules stay interactive: create a trip, live automations (no simulated clock), open the softphone', async p => {
  await login(p, 'owner@lti.test'); await ready(p); await p.click('nav.tn a:has-text("Trips")'); await p.click('#m button:has-text("New trip")'); await p.fill('#a1', 'Restoration test trip'); await p.click('#ov .btn.g1');
  await S('trip page', p.waitForSelector('#m input[aria-label="Trip name"]')); A(await p.evaluate(() => S.trips.some(t => t.n == 'Restoration test trip')), 'trip');
  await p.evaluate(() => go('automations')); await S('live automations', p.waitForSelector('#m button:has-text("New workflow")')); A(!(await p.locator('#m button:has-text("+1 day")').count()), 'simulated automation clock still present');
  await p.click('#spl'); A(await p.evaluate(() => document.querySelector('#sp').classList.contains('open')), 'softphone'); return 'prototype interactions intact'; });
await T('Mobile 390px: restored interface usable (drawer, dashboard, live contacts) with no horizontal overflow', async p => {
  await login(p, 'owner@lti.test'); await ready(p); const sw1 = await p.evaluate(() => document.documentElement.scrollWidth); await p.click('button.hb'); await p.locator('#ov a', { hasText: 'Contacts' }).first().click();
  await S('live contacts', p.waitForSelector('[data-live=contacts]')); const sw2 = await p.evaluate(() => document.documentElement.scrollWidth); A(sw1 <= 392 && sw2 <= 392, 'overflow ' + sw1 + '/' + sw2);
  await p.click('#m button:has-text("New contact")'); const b = await p.locator('#ov [role=dialog]').boundingBox(); A(b.x >= 0 && b.x + b.width <= 391, 'dialog'); return 'ok'; }, { width: 390, height: 844 });
await T('No emojis anywhere in the restored interface', async p => { await login(p, 'owner@lti.test'); await ready(p); const bad = await p.evaluate(() => { const o = []; for (const k of Object.keys(Pg)) { if (k == 'lcontact') continue; if (k == 'client') S.cc = 'c1'; if (k == 'trip') S.cur = 't1'; go(k); if (/\p{Extended_Pictographic}/u.test(document.body.innerText)) o.push(k) } return o }); A(!bad.length, bad.join(',')); return 'clean'; });
console.log('E2E SUMMARY ' + results.filter(r => r.ok).length + '/' + results.length); require('fs').writeFileSync(__dirname + '/restored_results.json', JSON.stringify(results, null, 1)); await browser.close(); })();
