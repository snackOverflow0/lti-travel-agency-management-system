// Vertical slice E2E: real browser -> React build -> PHP API -> MySQL. Run after ./dev-up.sh (fresh test database).
const { chromium } = require(process.env.PW || 'playwright');
const { execSync } = require('child_process');
const BASE = process.env.BASE || 'http://127.0.0.1:8080/';
const sql = q => execSync(`mysql -uroot -N -e "${q.replace(/"/g, '\\"')}" lti_test`).toString().trim();
const results = []; let browser;
const A = (c, m) => { if (!c) throw new Error(m); };
const S = async (label, pr) => { try { return await pr; } catch (e) { throw new Error(label + ': ' + String(e.message).split('\n')[0]); } };
async function T(name, fn, vp) { const ctx = await browser.newContext({ viewport: vp || { width: 1366, height: 900 } }); const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
  let ok = false, d = ''; try { d = (await fn(p, ctx)) || ''; ok = !errs.length; if (errs.length) d += ' JSERR ' + errs.join('|'); } catch (e) { d = String(e.message).split('\n')[0]; }
  results.push({ name, ok, detail: d }); console.log((ok ? 'PASS ' : 'FAIL ') + name + ' — ' + d); await ctx.close(); }
async function login(p, email, pw = 'Dev-Password-2026') { if (!/#\/login/.test(p.url())) await p.goto(BASE + '#/login'); await p.waitForSelector('#em'); await p.fill('#em', email); await p.fill('#pw', pw); await p.click('button:has-text("Sign in")'); }
(async () => { browser = await chromium.launch({ executablePath: process.env.CHROME });
await T('Sign-in rejects a wrong password with a clear message', async p => { await login(p, 'owner@lti.test', 'wrong-password-1'); await p.waitForSelector('[role=alert]:has-text("incorrect")'); return await p.locator('[role=alert]').innerText(); });
await T('VERTICAL SLICE: staff login → create client → saved in MySQL → reload → retrieve (also after a new session)', async p => {
  await login(p, 'owner@lti.test'); await p.waitForSelector('h1:has-text("Contacts")');
  await p.click('button:has-text("New contact")'); await p.fill('input[name=firstName]', 'Isabel'); await p.fill('input[name=lastName]', 'Moreno'); await p.fill('input[name=email]', 'isabel.moreno@example.com');
  await p.fill('input[name=phone]', '+1 (305) 555-0199'); await p.fill('input[name=birthday]', '1979-04-12'); await p.fill('textarea[name=notes]', 'Prefers boutique hotels; anniversary trip in 2027.'); await p.click('button:has-text("Create contact")');
  await S('detail after create', p.waitForSelector('h1:has-text("Isabel Moreno")')); const id = p.url().match(/contacts\/(\d+)/)[1];
  const row = sql(`SELECT CONCAT_WS('|',first_name,last_name,email,phone,birthday,contact_type) FROM contacts WHERE id=${id}`); A(row === 'Isabel|Moreno|isabel.moreno@example.com|+1 (305) 555-0199|1979-04-12|client', 'db row ' + row);
  await p.reload(); await S('detail after reload', p.waitForSelector('h1:has-text("Isabel Moreno")')); A((await p.locator('[data-field="Birthday"]').innerText()) === 'Apr 12, 1979', 'birthday after reload');
  await p.click('button:has-text("Back to contacts")'); await p.fill('input[aria-label="Search contacts"]', 'moreno'); await S('search result', p.waitForSelector('td:has-text("isabel.moreno@example.com")'));
  await p.click('button.pf'); await p.click('a:has-text("Sign out")'); await S('sign-in screen', p.waitForSelector('h1:has-text("Sign in to LTI")'));
  await S('re-login', login(p, 'owner@lti.test')); await S('list after re-login', p.waitForSelector('h1:has-text("Contacts")'));
  await p.evaluate(i => { location.hash = '#/contacts/' + i; }, id); await S('detail after re-login', p.waitForSelector('h1:has-text("Isabel Moreno")')); A(/Prefers boutique hotels/.test(await p.locator('#m').innerText()), 'notes');
  const audit = sql(`SELECT COUNT(*) FROM audit_log WHERE action='contact.created' AND entity_id='${id}'`); A(audit === '1', 'audit ' + audit);
  return `contact ${id} saved, reloaded, retrieved after re-login; audit row present`; });
await T('Edit saves to MySQL and survives reload; server field errors are shown', async p => {
  await login(p, 'owner@lti.test'); await p.waitForSelector('h1:has-text("Contacts")'); const id = sql("SELECT id FROM contacts WHERE email='amelia@example.com'");
  await p.goto(BASE + '#/contacts/' + id); await p.click('button:has-text("Edit")'); await p.fill('input[name=email]', 'not-an-email'); await p.locator('input[name=email]').evaluate(e => e.type = 'text');
  await p.click('button:has-text("Save changes")'); await p.waitForSelector('.err:has-text("valid email")');
  await p.fill('input[name=email]', 'amelia.h@example.com'); await p.selectOption('select[name=leadStatus]', 'qualified'); await p.click('button:has-text("Save changes")'); await p.waitForSelector('[data-field="Email"]:has-text("amelia.h@example.com")');
  await p.reload(); await p.waitForSelector('[data-field="Lead status"]:has-text("qualified")'); A(sql(`SELECT email FROM contacts WHERE id=${id}`) === 'amelia.h@example.com', 'db'); return 'edit persisted'; });
await T('Finance role: no create button, no Staff menu, API refuses create', async p => {
  await login(p, 'finance@lti.test'); await p.waitForSelector('h1:has-text("Contacts")'); A(!(await p.locator('button:has-text("New contact")').count()), 'create button visible');
  A(!(await p.locator('nav.tn a:has-text("Staff")').count()), 'staff nav visible');
  const st = await p.evaluate(async () => { const me = await (await fetch('./api/auth/me')).json(); const r = await fetch('./api/contacts', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': me.csrf }, body: JSON.stringify({ firstName: 'Bypass' }) }); return r.status; });
  A(st === 403, 'api ' + st); return 'UI hides create; direct API call 403'; });
await T('Advisor cannot open another advisor’s client by changing the URL', async p => {
  const shah = sql("SELECT id FROM contacts WHERE email='shah@example.com'"); await login(p, 'advisor2@lti.test'); await p.waitForSelector('h1:has-text("Contacts")');
  await p.waitForSelector('tbody tr'); A((await p.locator('tbody tr').count()) === 1, 'list not scoped: ' + (await p.locator('tbody').innerText())); await p.goto(BASE + '#/contacts/' + shah); await p.waitForSelector('text=Contact not found'); return 'list scoped; direct URL -> Contact not found'; });
await T('Administrator deactivates a staff account; that person can no longer sign in', async p => {
  await login(p, 'admin@lti.test'); await p.waitForSelector('h1:has-text("Contacts")'); await p.click('nav.tn a:has-text("Staff")');
  await p.locator('tr', { hasText: 'sales@lti.test' }).locator('button:has-text("Deactivate")').click(); await p.waitForSelector('tr:has-text("sales@lti.test") >> text=Deactivated');
  const p2 = await p.context().browser().newPage(); await login(p2, 'sales@lti.test'); await p2.waitForSelector('[role=alert]:has-text("deactivated")'); await p2.close(); return 'blocked with message'; });
await T('Mobile 390px: contacts usable, no horizontal overflow, menu drawer works', async p => {
  await login(p, 'owner@lti.test'); await p.waitForSelector('h1:has-text("Contacts")'); const sw = await p.evaluate(() => document.documentElement.scrollWidth); A(sw <= 392, 'overflow ' + sw);
  await p.click('button.hb'); await p.click('[role=dialog] a:has-text("Contacts")'); await p.click('button:has-text("New contact")'); const b = await p.locator('[role=dialog]').boundingBox(); A(b.x >= 0 && b.x + b.width <= 391, 'dialog ' + JSON.stringify(b)); return 'ok'; }, { width: 390, height: 844 });
await T('No emojis rendered; no customer data in browser storage', async p => {
  await login(p, 'owner@lti.test'); await p.waitForSelector('tbody tr'); const t = await p.evaluate(() => document.body.innerText); A(!/\p{Extended_Pictographic}/u.test(t), 'emoji');
  const st = await p.evaluate(() => localStorage.length + sessionStorage.length); A(st === 0, 'storage ' + st); return 'clean'; });
console.log('E2E SUMMARY ' + results.filter(r => r.ok).length + '/' + results.length); require('fs').writeFileSync(__dirname + '/slice_results.json', JSON.stringify(results, null, 1)); await browser.close(); })();
