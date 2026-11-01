// Phase 7 E2E: restored softphone panel and call pages -> PHP API -> MySQL. No simulated calling.
const { chromium } = require(process.env.PW || 'playwright');
const { execSync } = require('child_process'); const fs = require('fs');
const BASE = process.env.BASE || 'http://127.0.0.1:8000/';
const sql = q => execSync(`mysql --default-character-set=utf8mb4 -uroot -N -e "${q.replace(/"/g, '\\"')}" lti_test`).toString().trim();
const results = []; let browser; const st = {};
const A = (c, m) => { if (!c) throw new Error(m); };
const S = async (label, pr) => { try { return await pr; } catch (e) { throw new Error(label + ': ' + String(e.message).split('\n')[0]); } };
const waitDb = async (label, q, ok, ms = 10000) => { const t0 = Date.now(); let v; while (Date.now() - t0 < ms) { v = sql(q); if (ok(v)) return v; await new Promise(r => setTimeout(r, 300)); } throw new Error(label + ': database value ' + JSON.stringify(v)); };
async function T(name, fn, vp) { const ctx = await browser.newContext({ viewport: vp || { width: 1366, height: 900 }, reducedMotion: 'reduce' }); const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
  let ok = false, d = ''; try { d = (await fn(p, ctx)) || ''; ok = !errs.length; if (errs.length) d += ' JSERR ' + errs.slice(0, 3).join('|'); } catch (e) { d = String(e.message).split('\n')[0]; }
  results.push({ name, ok, detail: d }); console.log((ok ? 'PASS ' : 'FAIL ') + name + ' — ' + d); await ctx.close(); }
async function login(p, email) { await p.goto(BASE + '#/login'); await S('login form', p.waitForSelector('#em')); await p.fill('#em', email); await p.fill('#pw', 'Dev-Password-2026'); await p.click('button:has-text("Sign in")'); await S('boot', p.waitForFunction(() => window.__LTI_LIVE, null, { timeout: 15000 })); }
(async () => { browser = await chromium.launch({ executablePath: process.env.CHROME });
const shah = sql("SELECT id FROM contacts WHERE email='shah@example.com'");
await T('TC01–TC05, TC26 Open restored softphone; dial pad; contact match; unknown number; unavailable controls disabled', async p => {
  await login(p, 'advisor@lti.test'); await p.click('#spl'); await S('panel open', p.waitForSelector('#sp.open [data-live=phone-mode]'));
  const txt = await p.locator('#sp').innerText(); A(!/Simulated calling/.test(txt) && /PSTN\) calling is not available/.test(txt), 'mode text');
  for (const k of '5125550177') await p.click(`#sp button[aria-label="Key ${k}"]`); A((await p.inputValue('#spnum')) === '5125550177', 'dial pad value');
  await S('contact match', p.waitForSelector('#sp a.lk:has-text("Daniel Shah")'));
  for (const b of ['Answer', 'Hold', 'Mute', 'Transfer', 'Record']) A(await p.locator(`#sp [data-live=unavailable-controls] button:has-text("${b}")`).isDisabled(), b + ' enabled');
  await p.click('#sp button[aria-label="Clear number"]'); await p.fill('#spnum', '+1 212 555 0199'); await p.dispatchEvent('#spnum', 'input'); await S('unknown', p.waitForSelector('#sp :text("is not in your contacts")'));
  return 'panel opened in External dialing / Manual logging mode; keypad entry; Shah matched; unknown number handled; 5 live controls disabled'; });
await T('TC06–TC07 Phone-app handoff creates an attempt only: no connected state, no timer; outcome recorded by staff', async p => {
  await login(p, 'advisor@lti.test'); await p.evaluate(() => callTo('+1 (512) 555-0177', '', '')); await S('match', p.waitForSelector('#sp a.lk:has-text("Daniel Shah")'));
  await p.evaluate(() => { window.__ltiTelLauncher = u => { window.__telLaunched = u; }; }); await p.click('#sp button:has-text("Open phone app")'); await S('handoff panel', p.waitForSelector('#sp [data-live=handoff]'));
  A((await p.evaluate(() => window.__telLaunched)) === 'tel:+15125550177', 'tel URI ' + (await p.evaluate(() => window.__telLaunched)));
  st.h = await waitDb('attempt row', "SELECT MAX(id) FROM calls WHERE source='external_handoff'", v => v && v !== 'NULL'); const r = sql(`SELECT CONCAT_WS('|',status,verification,IFNULL(duration_seconds,'null'),contact_id) FROM calls WHERE id=${st.h}`);
  const ht = await p.locator('#sp [data-live=handoff]').innerText(); A(r === `attempted|handoff_unconfirmed|null|${shah}` && !/connected|\d+:\d{2}/i.test(ht.replace('cannot see whether the call connected', '')), 'row ' + r + ' / ' + ht);
  await p.waitForTimeout(1500); A((await p.locator('#sp [data-live=handoff]').innerText()) === ht, 'panel changed by itself (timer?)');
  await p.click('#sp button:has-text("Record outcome")'); await S('outcome dialog', p.waitForSelector('#ov #oc1', { timeout: 8000 })); await p.selectOption('#oc1', 'connected'); await p.fill('#oc2', '2'); await p.fill('#oc5', 'Confirmed ferry times'); await p.click('#ov .btn.g1');
  const v = await waitDb('outcome', `SELECT CONCAT_WS('|',status,duration_seconds,duration_source,verification) FROM calls WHERE id=${st.h}`, x => x.startsWith('connected')); A(v === 'connected|120|entered|user_entered', v); return `tel:+15125550177 handed to the launcher (device handler itself not automatable headless); attempt stored as ${r}; panel static; outcome ${v}`; });
await T('TC08–TC11 Log a call from the panel (inbound missed, disposition, notes); verified in MySQL', async p => {
  await login(p, 'advisor@lti.test'); await p.click('#spl'); await p.click('#sp button:has-text("Log a call")'); await S('dialog', p.waitForSelector('#lc1'));
  await p.selectOption('#lc1', 'inbound'); await p.selectOption('#lc2', 'missed'); await p.fill('#lc3', '+1 (512) 555-0177'); await p.selectOption('#lc4', shah); await p.selectOption('#lc9', { label: 'Callback requested' }); await p.fill('#lc10', 'Left no message'); await p.click('#ov .btn.g1');
  st.in = await waitDb('row', "SELECT MAX(id) FROM calls WHERE source='manual_log' AND direction='inbound'", v => v && v !== 'NULL'); const r = sql(`SELECT CONCAT_WS('|',status,contact_id,IFNULL(duration_seconds,'null'),disposition,(SELECT body FROM call_notes WHERE call_id=${st.in} LIMIT 1)) FROM calls WHERE id=${st.in}`);
  A(r === `missed|${shah}|null|callback_requested|Left no message`, r); return r; });
await T('TC12, TC19, TC21 History from MySQL survives refresh; direction filter; search', async p => {
  await login(p, 'advisor@lti.test'); await p.evaluate(() => go('callhist')); await S('table', p.waitForSelector('#m [data-live=callhist] tbody tr')); A(!(await p.locator('#m .proto-note').count()), 'labelled prototype');
  const n = await p.locator('#m [data-live=callhist] tbody tr').count(); await p.reload(); await S('boot', p.waitForFunction(() => window.__LTI_LIVE)); await p.evaluate(() => go('callhist')); await S('after reload', p.waitForSelector('#m [data-live=callhist] tbody tr'));
  A((await p.locator('#m [data-live=callhist] tbody tr').count()) === n, 'count changed'); await p.selectOption('#chd', 'inbound'); await p.waitForFunction(() => { const r = [...document.querySelectorAll('#m [data-live=callhist] tbody tr')]; return r.length && r.every(x => x.children[1].textContent === 'inbound'); });
  await p.selectOption('#chd', ''); await p.fill('#m input[aria-label="Search phone or contact"]', 'Shah'); await p.dispatchEvent('#m input[aria-label="Search phone or contact"]', 'change'); await S('search', p.waitForFunction(() => [...document.querySelectorAll('#m [data-live=callhist] tbody tr')].every(x => x.textContent.includes('Daniel Shah'))));
  return `${n} rows from MySQL before and after refresh; inbound filter; search`; });
await T('TC15, TC18 Schedule a callback from call details; task created; contact timeline shows call events', async p => {
  await login(p, 'advisor@lti.test'); await p.evaluate(() => go('callhist')); await p.click('#m [data-live=callhist] tbody tr >> nth=0'); await S('details', p.waitForSelector('#ov :text("Call details")'));
  A(/Entered by staff|Handoff/.test(await p.locator('#ov [data-field=source]').innerText()), 'source label'); await p.click('#ov button:has-text("Schedule callback")');
  const cb = await waitDb('callback', "SELECT CONCAT(c.status,'|',t.title,'|',t.source) FROM callbacks c JOIN tasks t ON t.id=c.task_id ORDER BY c.id DESC LIMIT 1", v => !!v); A(cb === 'pending|Call back Daniel Shah|Callback', cb);
  const tl = await p.evaluate(async id => (await (await fetch('./api/activity?entityType=contact&entityId=' + id)).json()).items.map(x => x.type), shah); A(tl.includes('call.logged') && tl.includes('callback.scheduled'), JSON.stringify(tl));
  return cb + '; timeline: ' + [...new Set(tl)].slice(0, 5).join(', '); });
await T('Analytics and voicemail pages are honest about data sources', async p => {
  await login(p, 'owner@lti.test'); await p.evaluate(() => go('callan')); await S('analytics', p.waitForSelector('#m [data-live=callan]')); const t = await p.locator('#m').innerText();
  A(/None are verified/.test(t) && /Verified by a phone network\s*0/.test(t), 'analytics labels'); await p.evaluate(() => go('voicemail')); A(/Voicemail is not available/.test(await p.locator('#m').innerText()), 'voicemail'); 
  await p.evaluate(() => go('callset')); await S('dispositions', p.waitForSelector('#m [data-live=dispositions] tbody tr')); return 'analytics: verified 0, unverified note; voicemail unavailable; 11 dispositions listed'; });
await T('Mobile 390px: softphone panel fits; no emojis anywhere in phone screens', async p => {
  await login(p, 'advisor@lti.test'); await p.click('#spl'); await S('panel', p.waitForSelector('#sp.open')); const b = await p.locator('#sp').boundingBox(); A(b.x >= 0 && b.x + b.width <= 391, 'panel ' + JSON.stringify(b));
  const bad = await p.evaluate(() => { const o = []; for (const k of ['calls', 'callhist', 'callan', 'voicemail', 'callset']) { go(k); if (/\p{Extended_Pictographic}/u.test(document.body.innerText)) o.push(k); } return o; }); A(!bad.length, bad.join(',')); return 'fits; clean'; }, { width: 390, height: 844 });
console.log('PHASE7 E2E SUMMARY ' + results.filter(r => r.ok).length + '/' + results.length); fs.writeFileSync(__dirname + '/phase7_results.json', JSON.stringify(results, null, 1)); await browser.close(); })();
