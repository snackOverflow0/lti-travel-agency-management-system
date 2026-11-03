// Phase 4 increment 1 E2E: restored Suppliers, Bookings and Documents screens -> PHP API -> MySQL / private file storage.
const { chromium } = require(process.env.PW || 'playwright');
const { execSync } = require('child_process'); const fs = require('fs'); const path = require('path'); const os = require('os');
const BASE = process.env.BASE || 'http://127.0.0.1:8000/'; const STORE = path.join(__dirname, '../../server/app/storage/documents');
const sql = q => execSync(`mysql -uroot -N -e "${q.replace(/"/g, '\\"')}" ${process.env.DB || 'lti_test'}`).toString().trim();
const results = []; let browser; const st = {};
const A = (c, m) => { if (!c) throw new Error(m); };
const S = async (label, pr) => { try { return await pr; } catch (e) { throw new Error(label + ': ' + String(e.message).split('\n')[0]); } };
const waitDb = async (label, q, ok, ms = 12000) => { const t0 = Date.now(); let v; while (Date.now() - t0 < ms) { v = sql(q); if (ok(v)) return v; await new Promise(r => setTimeout(r, 300)); } throw new Error(label + ': database value ' + JSON.stringify(v)); };
async function T(name, fn, vp) { const ctx = await browser.newContext({ viewport: vp || { width: 1366, height: 900 }, reducedMotion: 'reduce', acceptDownloads: true }); const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
  let ok = false, d = ''; try { d = (await fn(p, ctx)) || ''; ok = !errs.length; if (errs.length) d += ' JSERR ' + errs.slice(0, 3).join('|'); } catch (e) { d = String(e.message).split('\n')[0]; }
  results.push({ name, ok, detail: d }); console.log((ok ? 'PASS ' : 'FAIL ') + name + ' — ' + d); if (process.env.SHOTS) { try { await p.screenshot({ path: process.env.SHOTS + '/' + (process.env.SUITE || 'e2e') + '-' + String(results.length).padStart(2, '0') + '.png' }); } catch (e) { } } await ctx.close(); }
async function login(p, email) { await p.goto(BASE + '#/login'); await S('login form', p.waitForSelector('#em')); await p.fill('#em', email); await p.fill('#pw', 'Dev-Password-2026'); await p.click('button:has-text("Sign in")'); await S('boot', p.waitForFunction(() => window.__LTI_LIVE, null, { timeout: 15000 })); }
const pdf = path.join(os.tmpdir(), 'Amalfi-vouchers.pdf'); fs.writeFileSync(pdf, '%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n');
(async () => { browser = await chromium.launch({ executablePath: process.env.CHROME });
const amalfi = sql("SELECT id FROM trips WHERE name='Amalfi Coast Anniversary'"), shah = sql("SELECT id FROM contacts WHERE email='shah@example.com'");
await T('TC10–TC11 Create a supplier in the restored Suppliers screen and edit it; MySQL updated', async p => {
  await login(p, 'owner@lti.test'); await p.evaluate(() => go('suppliers')); A(!(await p.locator('#m .proto-note').count()), 'labelled as prototype');
  await p.click('#m button[onclick^="supAdd"]'); await S('dialog', p.waitForSelector('#sa1')); await p.fill('#sa1', 'Praiano Test Hotel'); await p.selectOption('#sa2', 'hotel'); await p.fill('#sa3', '12'); await p.fill('#sa4', 'res@sirenuse.example'); await p.fill('#sa7', 'SIR-2027'); await p.click('#ov .btn.g1');
  st.sup = await waitDb('created', "SELECT id FROM suppliers WHERE name='Praiano Test Hotel'", v => !!v); A(sql(`SELECT CONCAT_WS('|',category,commission_rate,email,contract_reference) FROM suppliers WHERE id=${st.sup}`) === 'hotel|12.00|res@sirenuse.example|SIR-2027', 'row');
  await S('profile', p.waitForSelector('#m h1:has-text("Praiano Test Hotel")')); const email = p.locator('#m input[onchange*=".email="]').first(); await email.fill('groups@sirenuse.example'); await email.dispatchEvent('change');
  const v = await waitDb('edit', `SELECT email FROM suppliers WHERE id=${st.sup}`, x => x === 'groups@sirenuse.example'); return 'supplier ' + st.sup + '; email now ' + v; });
await T('TC13–TC15 Create a booking for a trip and supplier with a traveler; confirm, edit and cancel in the restored Bookings screen', async p => {
  await login(p, 'owner@lti.test'); await p.evaluate(() => go('bookings')); A(!(await p.locator('#m .proto-note').count()), 'labelled as prototype');
  await p.click('#m button[onclick^="bkEdit("]'); await S('dialog', p.waitForSelector('#bk1')); await p.selectOption('#bk1', amalfi); await p.selectOption('#bk2', st.sup); await p.selectOption('#bk3', 'accommodation');
  await p.fill('#bk5', '2026-12-03'); await p.fill('#bk6', '2026-12-07'); await p.fill('#bk7', '5400'); await p.fill('#bk8', '12'); await p.check(`.bkt[value="${shah}"]`); await p.click('#ov .btn.g1');
  st.bk = await waitDb('created', `SELECT id FROM bookings WHERE trip_id=${amalfi} AND supplier_id=${st.sup}`, v => !!v);
  A(sql(`SELECT CONCAT_WS('|',booking_type,status,amount,commission_expected,(SELECT GROUP_CONCAT(contact_id) FROM booking_travelers WHERE booking_id=${st.bk})) FROM bookings WHERE id=${st.bk}`) === `accommodation|pending|5400.00|648.00|${shah}`, 'row');
  st.ref = sql(`SELECT reference FROM bookings WHERE id=${st.bk}`); await S('detail', p.waitForSelector(`#m button[onclick^="bkConfirm('${st.ref}')"]`));
  await p.click(`#m button[onclick^="bkConfirm('${st.ref}')"]`); await p.fill('#a1', 'SIR-88412'); await p.click('#ov .btn.g1'); await waitDb('confirmed', `SELECT CONCAT(status,'|',confirmation_number) FROM bookings WHERE id=${st.bk}`, v => v === 'confirmed|SIR-88412');
  await p.click(`#m button[onclick^="bkEdit('${st.ref}')"]`); await S('edit dialog', p.waitForSelector('#bk7')); await p.fill('#bk7', '5600'); await p.click('#ov .btn.g1'); await waitDb('edited', `SELECT amount FROM bookings WHERE id=${st.bk}`, v => v === '5600.00');
  await p.click(`#m button[onclick^="bkCancel('${st.ref}')"]`); await p.fill('#a1', 'Client moved dates'); await p.click('#ov .btn.g1');
  const v = await waitDb('cancelled', `SELECT CONCAT(status,'|',cancel_reason) FROM bookings WHERE id=${st.bk}`, x => x === 'cancelled|Client moved dates'); return st.ref + ': created with traveler, confirmed, edited, ' + v; });
await T('TC12 Archive the supplier from its profile; the booking keeps its supplier', async p => {
  await login(p, 'owner@lti.test'); await p.evaluate(() => { X.ui.sp = 'Praiano Test Hotel'; go('suppliers'); }); await p.click('#m button:has-text("Archive supplier")'); await p.click('#ov .btn.g1');
  await waitDb('archived', `SELECT status FROM suppliers WHERE id=${st.sup}`, v => v === 'archived'); A(sql(`SELECT supplier_id FROM bookings WHERE id=${st.bk}`) === st.sup, 'booking supplier lost');
  await S('list', p.waitForSelector('#m h1:has-text("Suppliers")')); A(!(await p.locator('#m :text("Praiano Test Hotel")').count()), 'archived supplier still listed'); return 'archived; booking still linked'; });
await T('TC21–TC24 Upload, preview, download, refuse other advisor, rename and archive a document in the restored Documents screen', async p => {
  await login(p, 'advisor@lti.test'); await p.evaluate(() => go('docs')); A(!(await p.locator('#m .proto-note').count()), 'labelled as prototype');
  await p.click('#m button[onclick^="docUpload"]'); await S('dialog', p.waitForSelector('#du1')); await p.setInputFiles('#du1', pdf); await p.selectOption('#du2', 'travel_documents'); await p.selectOption('#du3', 'client'); await p.selectOption('#du4', amalfi); await p.click('#ov .btn.g1');
  st.doc = await waitDb('uploaded', "SELECT id FROM documents WHERE original_name='Amalfi-vouchers.pdf'", v => !!v); const row = sql(`SELECT CONCAT_WS('|',mime_type,size_bytes,trip_id,visibility,stored_name) FROM documents WHERE id=${st.doc}`).split('|');
  A(row[0] === 'application/pdf' && Number(row[1]) === fs.statSync(pdf).size && row[2] === amalfi && row[3] === 'client' && fs.existsSync(path.join(STORE, row[4])), 'metadata/file ' + row.join('|'));
  await p.click('#m a.lk:has-text("Amalfi-vouchers.pdf")'); await S('preview', p.waitForSelector('#ov iframe'));
  const dl = await p.request.get(BASE + 'api/documents/' + st.doc + '/download'); A(dl.status() === 200 && (await dl.body()).toString().startsWith('%PDF'), 'download ' + dl.status()); await p.click('#ov .btn.o');
  const c2 = await browser.newContext(); const p2 = await c2.newPage(); await login(p2, 'advisor2@lti.test'); const r2 = await p2.request.get(BASE + 'api/documents/' + st.doc + '/download'); await c2.close(); A(r2.status() === 404, 'advisor2 got ' + r2.status());
  await p.click(`#m button[onclick^="docEdit('dd${st.doc}')"]`); await p.fill('#de1', 'Amalfi hotel vouchers'); await p.click('#ov .btn.g1'); await waitDb('renamed', `SELECT title FROM documents WHERE id=${st.doc}`, v => v === 'Amalfi hotel vouchers');
  await p.click(`#m button[onclick^="docDel('dd${st.doc}')"]`); await p.click('#ov .btn.g1'); await waitDb('archived', `SELECT deleted_at IS NOT NULL FROM documents WHERE id=${st.doc}`, v => v === '1');
  const after = await p.request.get(BASE + 'api/documents/' + st.doc + '/download'); A(after.status() === 404, 'archived download ' + after.status()); return 'doc ' + st.doc + ': stored privately, preview, download, other advisor 404, renamed, archived'; });
await T('TC27–TC28 Records survive refresh and sign-out/sign-in; none are written to browser storage', async p => {
  await login(p, 'owner@lti.test'); await p.evaluate(() => go('bookings')); await S('booking row', p.waitForSelector(`#m :text("${st.ref}")`)); await p.reload(); await S('boot', p.waitForFunction(() => window.__LTI_LIVE));
  await p.evaluate(() => go('bookings')); await S('after reload', p.waitForSelector(`#m :text("${st.ref}")`));
  const where = await p.evaluate(vals => { const out = []; const walk = (o, path) => { if (typeof o === 'string') { vals.forEach(v => { if (o.includes(v)) out.push(path + ' contains ' + v + ': ' + o.slice(0, 80)); }); return; } if (o && typeof o === 'object') for (const k of Object.keys(o)) walk(o[k], path + '.' + k); };
    for (const k of Object.keys(localStorage)) { const v = localStorage.getItem(k); try { walk(JSON.parse(v), k); } catch (e) { vals.forEach(x => { if (v.includes(x)) out.push(k + ' (raw) contains ' + x); }); } } return out; }, [st.ref, 'SIR-88412', 'Praiano Test Hotel']);
A(!where.length, 'database records in browser storage: ' + where.slice(0, 2).join(' ; '));
  await p.click('button.pf'); await p.click('.dd a:has-text("Sign out")'); await S('signed out', p.waitForSelector('h1:has-text("Sign in to LTI")')); await login(p, 'owner@lti.test');
  const n = await p.evaluate(() => X.bookings.filter(b => b.db).length + '/' + Object.keys(X.supx).length); await p.evaluate(() => go('bookings')); await S('after re-login', p.waitForSelector(`#m :text("${st.ref}")`)); return 'present after refresh and re-login (' + n + ')'; });
await T('TC29 Role restrictions in the restored screens (backend enforced)', async p => {
  await login(p, 'advisor@lti.test'); await p.evaluate(() => go('suppliers')); const btn = p.locator('#m button[onclick^="supAdd"]'); const dis = await btn.isDisabled();
  if (!dis) { await btn.click(); await S('toast', p.waitForSelector('text=Your role cannot add suppliers')); }
  const c2 = await browser.newContext(); const f = await c2.newPage(); await login(f, 'finance@lti.test'); await f.evaluate(() => go('bookings')); await f.click('#m button[onclick^="bkEdit("]'); await S('finance toast', f.waitForSelector('text=Your role cannot create or edit bookings'));
  const codes = await f.evaluate(async () => { const me = await (await fetch('./api/auth/me')).json(); return [await fetch('./api/bookings', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': me.csrf }, body: '{"tripId":1,"category":"tour"}' }).then(r => r.status), await fetch('./api/documents', { method: 'POST', headers: { 'X-CSRF-Token': me.csrf }, body: new FormData() }).then(r => r.status)]; });
  await c2.close(); A(codes.join() === '403,403', 'api ' + codes); return 'advisor: New supplier ' + (dis ? 'disabled' : 'refused') + '; finance: booking refused in UI; API 403 for finance booking and upload'; });
await T('Mobile 390px: Suppliers, Bookings and Documents usable without horizontal overflow', async p => {
  await login(p, 'owner@lti.test'); const out = []; for (const pg of ['suppliers', 'bookings', 'docs']) { await p.evaluate(x => go(x), pg); out.push(await p.evaluate(() => document.documentElement.scrollWidth)); }
  A(out.every(x => x <= 392), 'scrollWidth ' + out); return out.join('/'); }, { width: 390, height: 844 });
console.log('PHASE4 E2E SUMMARY ' + results.filter(r => r.ok).length + '/' + results.length); fs.writeFileSync(__dirname + '/phase4_results.json', JSON.stringify(results, null, 1)); await browser.close(); })();
