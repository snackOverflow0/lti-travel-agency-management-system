// Phase 7: connects the restored softphone panel and call pages to the PHP API.
// Modes actually available: external dialing (tel: handoff) and manual call logging. Nothing is simulated:
// no ringing, no connected state, no timer, no working hold/mute/transfer, no recordings or voicemail.
import { api, ApiError } from '../api';
const w = window as any;
let can: (p: string) => boolean = () => false;
const h = (s: unknown) => esc(s == null ? '' : s);
const msg = (e: unknown) => e instanceof ApiError ? e.message : 'Request failed';
const errBox = (m: string) => { const e = document.getElementById('er'); if (e) e.textContent = m; else toast(m); };
async function call<T>(m: string, p: string, b?: unknown): Promise<T> { try { return await api<T>(m, p, b); } catch (e) { if (e instanceof ApiError && e.status === 401) { toast('Your session has ended. Please sign in again.'); setTimeout(() => { location.hash = '#/login'; location.reload(); }, 900); } throw e; } }
const dialogOpen = () => !!(document.getElementById('ov') && document.getElementById('ov')!.innerHTML.trim());
const redraw = () => { if (!dialogOpen()) draw(); };
const COUNTRIES: [string, string][] = [['US', 'US/Canada +1'], ['GB', 'UK +44'], ['IE', 'Ireland +353'], ['FR', 'France +33'], ['DE', 'Germany +49'], ['IT', 'Italy +39'], ['ES', 'Spain +34'], ['PT', 'Portugal +351'], ['NL', 'Netherlands +31'], ['CH', 'Switzerland +41'], ['AU', 'Australia +61'], ['NZ', 'New Zealand +64'], ['JP', 'Japan +81'], ['PH', 'Philippines +63'], ['IN', 'India +91'], ['AE', 'UAE +971'], ['ZA', 'South Africa +27'], ['KE', 'Kenya +254'], ['MX', 'Mexico +52']];
const STATUS: [string, string][] = [['connected', 'Connected'], ['no_answer', 'No answer'], ['busy', 'Busy'], ['voicemail_left', 'Voicemail left'], ['missed', 'Missed (inbound)'], ['failed', 'Failed']];
const PH: any = { num: '', country: 'US', lookup: null, lookupFor: '', pending: null, contactId: null, tripId: null, recent: null, cap: null, disp: [], staff: [] };
const LST: any = { f: { direction: '', status: '', staffId: '', q: '', from: '', to: '' }, page: 1, data: null, loading: false };
let REP: any = null; let lookupT = 0; let lastPanel = '';
const fmtDur = (s: number | null) => s == null ? 'Not recorded' : (s >= 3600 ? Math.floor(s / 3600) + ':' : '') + String(Math.floor(s % 3600 / 60)).padStart(s >= 3600 ? 2 : 1, '0') + ':' + String(s % 60).padStart(2, '0') + ' (entered)';
const localNow = (minutes = 0) => { const d = new Date(Date.now() + minutes * 60000); const p = (n: number) => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`; };
async function doLookup() { const n = PH.num.trim(); if (n.replace(/\D/g, '').length < 5) { PH.lookup = null; PH.lookupFor = ''; w.spR(); return; } const key = n + '|' + PH.country; if (key === PH.lookupFor) return; PH.lookupFor = key;
  try { const r = await call<any>('GET', `/phone/lookup?number=${encodeURIComponent(n)}&country=${PH.country}`); if (PH.lookupFor === key) { PH.lookup = r; if (r.matches.length === 1 && !PH.contactId) PH.contactId = r.matches[0].contactId; w.spR(); } } catch { /* ignore */ } }
async function loadRecent() { try { PH.recent = (await call<any>('GET', '/calls?perPage=5')).items; w.spR(); } catch { PH.recent = []; } }
const disabledControls = () => `<div class="row" style="gap:6px;flex-wrap:wrap;margin-top:10px" data-live="unavailable-controls">${['Answer', 'Hold', 'Mute', 'Transfer', 'Record'].map(l => `<button class="btn o s" disabled aria-disabled="true" title="Not available: no telephony provider is configured">${l}</button>`).join('')}</div>
  <p class="mu" style="font-size:11px;margin-top:4px">Live call controls are unavailable: LTI has no telephony provider. Use your phone’s own controls.</p>`;
function dialerHtml(): string {
  const L = PH.lookup; let info = '';
  if (L) { if (L.normalized.error) info = `<div class="err" style="min-height:0">${h(L.normalized.error)}</div>`;
    else if (L.matches.length) info = L.matches.map((m: any) => `<div class="sp-pre">${ic('user', 13)} <a class="lk" onclick="lcOpen(${m.contactId})">${h(m.name)}</a> <span class="mu">${h(m.type)} · ${h(m.label)}</span>${PH.contactId === m.contactId ? ' <span class="tag">Linked</span>' : ` <button class="btn o s" onclick="phPick(${m.contactId})">Link</button>`}</div>`).join('') + `<div class="mu" style="font-size:11px">${h(L.normalized.display)}</div>`;
    else info = `<div class="sp-unk">${ic('warn', 14)} ${h(L.normalized.display)} is not in your contacts. ${can('contacts.create') ? '<button class="btn o s" onclick="lcNew()">Create contact</button>' : ''}</div>`; }
  const tel = L && L.normalized.e164 ? L.normalized.e164 : '';
  return `<div class="fm"><div class="row" style="flex-wrap:nowrap;gap:6px">${sel('phcc', COUNTRIES, PH.country, 'style="width:132px" aria-label="Country for national numbers" onchange="phCountry(this.value)"')}
    <input id="spnum" aria-label="Phone number" placeholder="Phone number" value="${h(PH.num)}" oninput="phInput(this.value)" onkeydown="if(event.key==='Enter')phHandoff()">${PH.num ? `<button class="ib" aria-label="Clear number" onclick="phClear()">${ic('x', 14)}</button>` : ''}</div>${info}
    <div role="group" aria-label="Dial pad" style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin:10px 0">${['1', '2', '3', '4', '5', '6', '7', '8', '9', '+', '0', '#'].map(k => `<button class="btn o" aria-label="Key ${k}" onclick="phKey('${k}')">${k}</button>`).join('')}</div>
    <div class="row" style="gap:8px"><button class="btn" ${tel ? '' : 'disabled'} onclick="phHandoff()" title="Opens your phone or calling app">${ic('phone', 14)} Open phone app</button><button class="btn o" onclick="phLog()">Log a call</button></div></div>`;
}
function panelHtml(): string {
  const head = `<div class="sp-h"><div class="row" style="gap:8px"><b>Phone</b><span class="tag">External dialing · Manual logging</span></div><button class="ib" aria-label="Close softphone" onclick="spOpen(0)">${ic('x')}</button></div>
    <div class="sp-sim" data-live="phone-mode">Calls are made on your own phone. LTI does not connect, hear or record calls and cannot confirm whether a call connected. Phone-network (PSTN) calling is not available.</div>`;
  let b = '';
  if (PH.pending) { const p = PH.pending; b = `<div class="sp-call" data-live="handoff"><div class="sp-lbl">Dial handoff</div><h3>${h(p.contact && p.contact.label || p.phoneDisplay)}</h3><div class="mu">${h(p.phoneDisplay)}</div>
    <p class="mu" style="font-size:12px;margin:8px 0">Your phone app was asked to dial. LTI cannot see whether the call connected. When you finish, record what happened.</p>
    <div class="row" style="gap:8px"><button class="btn" onclick="phOutcome()">Record outcome</button><button class="btn o" onclick="phCancelHandoff()">No call was made</button></div></div>`; }
  else b = dialerHtml();
  const rec = PH.recent ? (PH.recent.length ? PH.recent.map((c: any) => `<div class="ts" style="font-size:12px"><span style="flex:1"><a class="lk" onclick="phDetail(${c.id})">${h(c.contact && c.contact.label || c.phoneDisplay || 'Unknown')}</a> <span class="mu">${h(c.direction)} · ${h(c.statusLabel)}</span></span><span class="mu">${h((c.startedAtLocal || '').slice(5))}</span></div>`).join('') : '<p class="mu" style="font-size:12px">No calls recorded yet.</p>') : '';
  return head + `<div class="sp-b">${b}${disabledControls()}<h4 style="font-size:12px;margin:12px 0 4px">Recent calls</h4>${rec}<p style="margin-top:6px"><a class="lk" onclick="spOpen(0);go('callhist')">Call history</a></p></div>`;
}
function spR() {
  const el = document.getElementById('sp'), l = document.getElementById('spl'); if (!el || !l) return; const hide = X.role === 'Traveler' || !can('calls.use');
  l.style.display = hide ? 'none' : ''; l.className = 'spl'; l.innerHTML = ic('phone', 22); el.className = 'sphone' + (X.phone.open && !hide ? ' open' : '');
  // Replace the panel only when its content changed: redraws triggered elsewhere must not swallow clicks or move the cursor.
  const html = X.phone.open && !hide ? panelHtml() : '';
  if (html !== lastPanel) { el.innerHTML = html; lastPanel = html; }
  if (html && PH.recent === null) { PH.recent = []; loadRecent(); }
}
async function contactsAndTrips() { const [c, t] = await Promise.all([call<any>('GET', '/contacts?perPage=100'), call<any>('GET', '/trips?type=all&perPage=200')]); return [c.items, t.items]; }
async function logDialog(pre: any = {}) {
  let contacts: any[] = [], trips: any[] = []; try { [contacts, trips] = await contactsAndTrips(); } catch (e) { toast(msg(e)); return; }
  const cid = pre.contactId ?? PH.contactId ?? ''; const disp = PH.disp.filter((d: any) => d.isActive);
  dlgW('Log a call', `<div class="fm"><p class="mu" style="font-size:12px">Record a call made or received on any phone. Saved as “entered by staff”; it is not verified by a phone network.</p><div class="g g2">
    <div><label>Direction</label>${sel('lc1', [['outbound', 'Outbound'], ['inbound', 'Inbound']], pre.direction || 'outbound')}</div><div><label>Outcome</label>${sel('lc2', STATUS, pre.status || 'connected', 'onchange="phDurState()"')}</div>
    <div><label>Phone number</label><input id="lc3" value="${h(pre.phone ?? PH.num)}"></div><div><label>Contact</label>${sel('lc4', [['', '— Unknown caller —'], ...contacts.map((c: any) => [String(c.id), `${c.firstName} ${c.lastName || ''}`.trim()])], String(cid))}</div>
    <div><label>Trip</label>${sel('lc5', [['', '—'], ...trips.map((t: any) => [String(t.id), t.name])], String(pre.tripId ?? PH.tripId ?? ''))}</div><div><label>Started (your local time)</label><input id="lc6" type="datetime-local" value="${localNow()}"></div>
    <div><label>Duration minutes (optional)</label><input id="lc7" type="number" min="0" max="1440" placeholder="Leave empty if unknown"></div><div><label>Seconds</label><input id="lc8" type="number" min="0" max="59" placeholder="0"></div>
    <div><label>Disposition</label>${sel('lc9', [['', '—'], ...disp.map((d: any) => [String(d.id), d.label])], '')}</div><div><label>Schedule callback (optional)</label><input id="lc11" type="datetime-local"></div></div>
    <label>Notes</label><textarea id="lc10" rows="3"></textarea></div>`, 'Save call', async () => {
    const mins = (document.getElementById('lc7') as HTMLInputElement).value, secs = (document.getElementById('lc8') as HTMLInputElement).value; const dur = mins === '' && secs === '' ? null : Number(mins || 0) * 60 + Number(secs || 0);
    const body: any = { direction: val('lc1'), status: val('lc2'), phone: val('lc3'), contactId: val('lc4') ? Number(val('lc4')) : null, tripId: val('lc5') ? Number(val('lc5')) : null, startedAt: val('lc6'), durationSeconds: dur, dispositionId: val('lc9') ? Number(val('lc9')) : null, notes: val('lc10'), timezone: Intl.DateTimeFormat().resolvedOptions().timeZone };
    try { const r = await call<any>('POST', '/calls', body); if (val('lc11') && body.contactId) await call('POST', '/callbacks', { callId: r.call.id, scheduledAt: val('lc11'), timezone: body.timezone });
      cl(); toast(val('lc11') && !body.contactId ? 'Call saved. Link a contact to schedule a callback.' : 'Call saved'); PH.num = ''; PH.lookup = null; PH.contactId = null; PH.recent = null; LST.data = null; REP = null; draw(); w.spR(); } catch (e) { errBox(msg(e)); }
  }, 720);
}
async function outcomeDialog() {
  const p = PH.pending; const disp = PH.disp.filter((d: any) => d.isActive);
  dlg('Record call outcome', `<p class="mu" style="font-size:12px">What happened on your phone? This is saved as entered by staff.</p><label>Outcome</label>${sel('oc1', STATUS.filter(s => s[0] !== 'missed'), 'connected')}
    <div class="g g2"><div><label>Duration minutes (optional)</label><input id="oc2" type="number" min="0" max="1440" placeholder="Leave empty if unknown"></div><div><label>Seconds</label><input id="oc3" type="number" min="0" max="59" placeholder="0"></div></div>
    <label>Disposition</label>${sel('oc4', [['', '—'], ...disp.map((d: any) => [String(d.id), d.label])], '')}<label>Notes</label><textarea id="oc5" rows="2"></textarea><label>Schedule callback (optional)</label><input id="oc6" type="datetime-local">`, 'Save outcome', async () => {
    const m = (document.getElementById('oc2') as HTMLInputElement).value, s = (document.getElementById('oc3') as HTMLInputElement).value; const dur = m === '' && s === '' ? null : Number(m || 0) * 60 + Number(s || 0);
    try { await call('POST', `/calls/${p.id}/outcome`, { status: val('oc1'), durationSeconds: dur, dispositionId: val('oc4') ? Number(val('oc4')) : null, notes: val('oc5') });
      if (val('oc6') && p.contact && p.contact.id) await call('POST', '/callbacks', { callId: p.id, scheduledAt: val('oc6'), timezone: Intl.DateTimeFormat().resolvedOptions().timeZone });
      cl(); PH.pending = null; PH.num = ''; PH.lookup = null; PH.contactId = null; PH.recent = null; LST.data = null; REP = null; toast('Outcome saved'); draw(); w.spR(); } catch (e) { errBox(msg(e)); } });
}
async function detailDialog(id: number) {
  let c: any; try { c = (await call<any>('GET', '/calls/' + id)).call; } catch (e) { toast(msg(e)); return; }
  const cbs = c.callbacks.map((b: any) => `<div class="ts" style="font-size:12px"><span style="flex:1">Callback ${h(b.scheduledAtLocal)} (${h(b.timezone)}) · ${h(b.assigneeName)} · ${pill(b.status === 'pending' ? (b.overdue ? 'Overdue' : 'Pending') : b.status === 'completed' ? 'Completed' : 'Cancelled')}</span>${b.status === 'pending' ? `<button class="btn o s" onclick="phCbResched(${b.id},${id})">Reschedule</button><button class="btn o s" onclick="phCbDone(${b.id},${id})">Mark completed</button>` : ''}</div>`).join('');
  dlgW('Call details', `<div class="g g2"><div class="kv"><b>Direction</b><span>${h(c.direction)}</span></div><div class="kv"><b>Outcome</b><span data-field="outcome">${h(c.statusLabel)}</span></div>
    <div class="kv"><b>Started</b><span>${h(c.startedAtLocal)} (${h(c.timezone)})</span></div><div class="kv"><b>Duration</b><span data-field="duration">${h(fmtDur(c.durationSeconds))}</span></div>
    <div class="kv"><b>Phone</b><span>${h(c.phoneDisplay || '—')}</span></div><div class="kv"><b>Staff</b><span>${h(c.staffName || '—')}</span></div>
    <div class="kv"><b>Contact</b><span>${c.contact ? (c.contact.id ? `<a class="lk" onclick="cl();lcOpen(${c.contact.id})">${h(c.contact.label)}</a>` : h(c.contact.label)) : 'Unknown caller'}</span></div><div class="kv"><b>Trip</b><span>${c.trip ? (c.trip.id ? `<a class="lk" onclick="cl();S.cur='d${c.trip.id}';S.tsec='Overview';go('trip')">${h(c.trip.label)}</a>` : h(c.trip.label)) : '—'}</span></div>
    <div class="kv"><b>Disposition</b><span>${h(c.disposition ? c.disposition.label : '—')}</span></div><div class="kv"><b>Record type</b><span data-field="source">${h(c.sourceLabel)} · ${h(c.verificationLabel)}</span></div></div>
    <h3 class="h3" style="font-size:13px;margin-top:12px">Notes</h3>${c.notes.map((n: any) => `<div class="ts" style="font-size:13px"><span style="flex:1;white-space:pre-wrap">${h(n.body)}</span><span class="mu">${h(n.by)} · ${h(String(n.at).slice(0, 16))}${n.editedAt ? ' (edited)' : ''}</span></div>`).join('') || '<p class="mu">No notes.</p>'}
    <div class="fm"><textarea id="cdn" rows="2" aria-label="New note" placeholder="Add a note"></textarea><button class="btn o s" onclick="phAddNote(${id})">Add note</button></div>
    <h3 class="h3" style="font-size:13px;margin-top:12px">Callbacks</h3>${cbs || '<p class="mu">None scheduled.</p>'}
    ${c.contact && c.contact.id ? `<div class="row" style="gap:6px;margin-top:6px"><input id="cdcb" type="datetime-local" aria-label="Callback date and time" value="${localNow(24 * 60)}" style="width:auto"><button class="btn o s" onclick="phCbNew(${id})">Schedule callback</button></div>` : '<p class="mu" style="font-size:12px">Link a contact to schedule a callback.</p>'}`, null, null, 720);
}
/* ---------------- pages ---------------- */
function capCard(): string { const c = PH.cap; if (!c) return '<p class="mu">Loading</p>';
  return cd('Calling capability', `<div class="tw"><table class="t2" data-live="capability"><tbody>${c.modes.map((m: any) => `<tr><td><b>${h(m.label)}</b></td><td>${pill(m.available ? 'Available' : 'Unavailable', m.available ? 'Active' : 'Cancelled')}</td><td style="white-space:normal;font-size:12px">${h(m.detail)}</td></tr>`).join('')}</tbody></table></div>
    <p class="mu" style="font-size:12px;margin-top:8px">${h(c.pstn.reason)} ${h(c.recording)}</p>`); }
let DIR: any[] | null = null;
function callsPage(): string { if (DIR === null) { DIR = []; call<any>('GET', '/contacts?perPage=100').then(r => { DIR = r.items.filter((c: any) => c.phone); redraw(); }).catch(() => {}); }
  return hd('Calls', `<button class="btn" onclick="spOpen(1)">${ic('phone', 14)} Open phone panel</button><button class="btn o" onclick="phLog()">Log a call</button>`, 'External dialing and manual call logging') + capCard()
    + cd('Contact directory', DIR && DIR.length ? `<div class="tw"><table class="t2"><thead><tr><th>Name</th><th>Phone</th><th></th></tr></thead><tbody>${DIR.map((c: any) => `<tr><td><a class="lk" onclick="lcOpen(${c.id})">${h(c.firstName)} ${h(c.lastName || '')}</a></td><td>${h(c.phone)}</td><td><button class="btn o s" onclick="callTo('${h(c.phone)}','dc${c.id}','')">Call</button></td></tr>`).join('')}</tbody></table></div>` : '<p class="mu">No contacts with phone numbers.</p>'); }
function lstLoad() { LST.loading = true; const p = new URLSearchParams({ ...LST.f, page: String(LST.page), perPage: '25' }); call<any>('GET', '/calls?' + p).then(r => { LST.data = r; }).catch(e => toast(msg(e))).finally(() => { LST.loading = false; if (S.pg === 'callhist') redraw(); }); }
function histPage(): string { if (!LST.data && !LST.loading) lstLoad(); if (!PH.staff.length && can('calls.view_all')) call<any>('GET', '/contacts/staff-options').then(r => { PH.staff = r.items; redraw(); }).catch(() => {});
  const d = LST.data, f = LST.f, pages = d ? Math.max(1, Math.ceil(d.total / d.perPage)) : 1, qs = new URLSearchParams(Object.fromEntries(Object.entries(f).filter(([, v]) => v)) as any).toString();
  const filt = `<div class="row" style="margin-bottom:10px;gap:6px;flex-wrap:wrap">${sel('chd', [['', 'All directions'], ['inbound', 'Inbound'], ['outbound', 'Outbound']], f.direction, 'style="width:auto" aria-label="Direction" onchange="phF(\'direction\',this.value)"')}
    ${sel('chs', [['', 'All outcomes'], ['connected', 'Connected'], ['missed', 'Missed or no answer'], ['followup', 'Follow-up required'], ['attempted', 'Handoffs awaiting outcome']], f.status, 'style="width:auto" aria-label="Outcome" onchange="phF(\'status\',this.value)"')}
    ${can('calls.view_all') ? sel('chst', [['', 'All staff'], ...PH.staff.map((s: any) => [String(s.id), s.fullName])], f.staffId, 'style="width:auto" aria-label="Staff member" onchange="phF(\'staffId\',this.value)"') : ''}
    <input aria-label="Search phone or contact" placeholder="Phone or contact" value="${h(f.q)}" style="width:180px" onchange="phF('q',this.value)"><input type="date" aria-label="From" value="${h(f.from)}" style="width:auto" onchange="phF('from',this.value)"><input type="date" aria-label="To" value="${h(f.to)}" style="width:auto" onchange="phF('to',this.value)"></div>`;
  const rows = d ? d.items.map((c: any) => `<tr class="c" tabindex="0" onclick="phDetail(${c.id})"><td>${h(c.startedAtLocal)}</td><td>${h(c.direction)}</td><td>${h(c.statusLabel)}</td><td>${h(c.contact ? c.contact.label : 'Unknown caller')}</td><td>${h(c.phoneDisplay || '—')}</td><td>${h(c.staffName || '')}</td><td>${h(c.durationSeconds == null ? '—' : fmtDur(c.durationSeconds))}</td><td>${h(c.disposition ? c.disposition.label : '—')}</td><td style="font-size:11px">${h(c.sourceLabel)}</td></tr>`).join('') : '';
  return hd('Call history', `<button class="btn o" onclick="phLog()">Log a call</button><a class="btn o" href="./api/calls/export${qs ? '?' + qs : ''}">Export CSV</a>`, 'All records are staff-entered logs or phone-app handoffs; none are verified by a phone network') + filt
    + (d ? (d.items.length ? `<div class="cd"><div class="tw"><table class="t2" data-live="callhist"><thead><tr><th>Started</th><th>Direction</th><th>Outcome</th><th>Contact</th><th>Phone</th><th>Staff</th><th>Duration</th><th>Disposition</th><th>Record</th></tr></thead><tbody>${rows}</tbody></table></div></div>` : emp('phone', 'No calls match', 'Log a call or change the filters.')) : '<p class="mu">Loading</p>')
    + (d && pages > 1 ? `<div class="pgx"><span class="mu">Page ${LST.page} of ${pages}</span><div class="row"><button class="btn o s" ${LST.page <= 1 ? 'disabled' : ''} onclick="phPg(-1)">Previous</button><button class="btn o s" ${LST.page >= pages ? 'disabled' : ''} onclick="phPg(1)">Next</button></div></div>` : ''); }
function anPage(): string { if (!REP) { REP = { loading: true }; call<any>('GET', '/calls/report').then(r => { REP = r; redraw(); }).catch(e => { REP = { error: msg(e) }; redraw(); }); }
  if (REP.loading) return hd('Call analytics') + '<p class="mu">Loading</p>'; if (REP.error) return hd('Call analytics') + `<div class="err">${h(REP.error)}</div>`; const r = REP, t = r.totals;
  const grp = (title: string, rows: any[]) => cd(title, rows.length ? `<div class="tw"><table class="t2"><tbody>${rows.map(x => `<tr><td>${h(x.label || x.key || 'None')}</td><td style="text-align:right">${x.count}</td></tr>`).join('')}</tbody></table></div>` : '<p class="mu">No data</p>');
  return hd('Call analytics', '', h(r.note)) + `<div class="g g4" style="margin-bottom:14px" data-live="callan">${kpi('Call records', t.records)}${kpi('Outcomes recorded', t.outcomesRecorded)}${kpi('Handoffs awaiting outcome', t.handoffsAwaitingOutcome)}${kpi('Verified by a phone network', t.verifiedCalls)}</div>
    <div class="g g4" style="margin-bottom:14px">${kpi('Entered talk time', fmtDur(t.enteredDurationSeconds || 0))}${kpi('Average entered duration', t.averageEnteredDurationSeconds == null ? '—' : fmtDur(t.averageEnteredDurationSeconds))}${kpi('Callbacks pending / overdue', r.callbacks.pending + ' / ' + r.callbacks.overdue)}${kpi('Missed inbound followed up', r.missedInbound.followedUp + ' of ' + r.missedInbound.total)}</div>
    <div class="g g2">${grp('By direction', r.byDirection)}${grp('By outcome', r.byStatus)}${grp('By staff member', r.byStaff)}${grp('By disposition', r.byDisposition)}${grp('By record type', r.bySource)}${grp('By date', r.byDate.map((x: any) => ({ label: x.date, count: x.count })))}</div>`; }
function vmPage(): string { return hd('Voicemail', '', 'Unavailable') + emp('vm', 'Voicemail is not available', 'LTI has no telephony provider, so it cannot receive or store voicemail. Log voicemails you receive on your own phone as inbound calls.') + `<p style="text-align:center"><button class="btn o" onclick="phLog({direction:'inbound',status:'missed'})">Log an inbound call</button></p>`; }
function setPage(): string { const cfg = can('calls.configure');
  return hd('Call settings', cfg ? '<button class="btn o" onclick="phBackfill()">Sync contact phone numbers</button>' : '', 'Calling capability and call outcomes') + capCard()
    + cd('Call dispositions', `<div class="tw"><table class="t2" data-live="dispositions"><thead><tr><th>Outcome</th><th>Needs follow-up</th><th>Status</th>${cfg ? '<th></th>' : ''}</tr></thead><tbody>${PH.disp.map((d: any) => `<tr><td>${h(d.label)}</td><td>${d.requiresFollowup ? 'Yes' : 'No'}</td><td>${pill(d.isActive ? 'Active' : 'Inactive', d.isActive ? 'Active' : 'Cancelled')}</td>${cfg ? `<td><button class="btn o s" onclick="phDispEdit(${d.id})">Edit</button></td>` : ''}</tr>`).join('')}</tbody></table></div>`, cfg ? '<button class="btn s" onclick="phDispEdit(0)">Add outcome</button>' : '')
    + cd('Recording', '<p>Calls are never recorded by LTI. Call recording would require a telephony provider plus documented consent, notice, access, retention and deletion rules.</p>'); }
/** Hands the number to the device's phone/calling app. Replaceable (window.__ltiTelLauncher) so automated tests can observe the exact URI without invoking an OS handler. */
function launchTel(uri: string) { if (typeof w.__ltiTelLauncher === 'function') { w.__ltiTelLauncher(uri); return; }
  const a = document.createElement('a'); a.href = uri; a.rel = 'noopener'; a.style.display = 'none'; document.body.append(a); try { a.click(); } catch { /* no handler on this device */ } a.remove(); }
export const LIVE_PHONE = ['calls', 'callhist', 'callan', 'voicemail', 'callset'];
export async function installPhone(perms: (p: string) => boolean) {
  can = perms; if (X.role === 'Traveler' || !can('calls.use')) return;
  X.phone.call = null; X.phone.open = 0; ['phIncoming', 'phTick', 'phFire', 'phFireRaw', 'phTransfer', 'phConf', 'vmMark'].forEach(f => { w[f] = () => {}; });
  try { const [cap, disp] = await Promise.all([call<any>('GET', '/calls/capability'), call<any>('GET', '/call-dispositions')]); PH.cap = cap; PH.country = cap.defaultCountry || 'US'; PH.disp = disp.items; } catch { /* labels still show */ }
  w.spR = spR; w.phDial = () => w.phHandoff();
  w.callTo = (num: string, cid?: string, trip?: string) => { if (!num) { toast('No phone number on file. Add one to the contact first.'); return; } if (PH.pending) { toast('Record the outcome of your last handoff first'); X.phone.open = 1; spR(); return; }
    PH.num = num; PH.contactId = cid && /^dc\d+$/.test(cid) ? Number(cid.slice(2)) : null; PH.tripId = trip && /^d\d+$/.test(trip) ? Number(trip.slice(1)) : null; PH.lookupFor = ''; X.phone.open = 1; spR(); doLookup(); };
  w.phInput = (v: string) => { PH.num = v; PH.contactId = null; clearTimeout(lookupT); lookupT = window.setTimeout(() => { doLookup(); const e = document.getElementById('spnum') as HTMLInputElement | null; if (e) { e.focus(); e.setSelectionRange(e.value.length, e.value.length); } }, 300); };
  w.phKey = (k: string) => { PH.num += k; PH.contactId = null; spR(); clearTimeout(lookupT); lookupT = window.setTimeout(doLookup, 300); };
  w.phClear = () => { PH.num = ''; PH.lookup = null; PH.lookupFor = ''; PH.contactId = null; spR(); };
  w.phCountry = (c: string) => { PH.country = c; PH.lookupFor = ''; doLookup(); };
  w.phPick = (id: number) => { PH.contactId = id; spR(); };
  w.phHandoff = async () => { if (!PH.lookup || !PH.lookup.normalized.e164) { toast('Enter a valid phone number'); return; }
    try { const r = await call<any>('POST', '/calls/handoff', { phone: PH.num, country: PH.country, contactId: PH.contactId, tripId: PH.tripId }); PH.pending = r.call; spR();
      launchTel(r.telUri); } catch (e) { toast(msg(e)); } };
  w.phCancelHandoff = async () => { try { await call('POST', `/calls/${PH.pending.id}/outcome`, { cancel: true }); PH.pending = null; PH.recent = null; toast('Handoff discarded'); spR(); } catch (e) { toast(msg(e)); } };
  w.phOutcome = () => outcomeDialog(); w.phLog = (pre?: any) => logDialog(pre || {}); w.phDetail = (id: number) => detailDialog(id);
  w.phDurState = () => {};
  w.phAddNote = async (id: number) => { const b = val('cdn'); if (!b) return; try { await call('POST', `/calls/${id}/notes`, { body: b }); cl(); detailDialog(id); } catch (e) { errBox(msg(e)); } };
  w.phCbNew = async (id: number) => { try { await call('POST', '/callbacks', { callId: id, scheduledAt: val('cdcb'), timezone: Intl.DateTimeFormat().resolvedOptions().timeZone }); toast('Callback scheduled and task created'); cl(); detailDialog(id); } catch (e) { errBox(msg(e)); } };
  w.phCbDone = async (cb: number, id: number) => { try { await call('POST', `/callbacks/${cb}/complete`); toast('Callback completed'); cl(); detailDialog(id); } catch (e) { errBox(msg(e)); } };
  w.phCbResched = (cb: number, id: number) => { cl(); dlg('Reschedule callback', `<label>New date and time</label><input id="rs1" type="datetime-local" value="${localNow(48 * 60)}"><label>Reason</label><input id="rs2">`, 'Reschedule', async () => {
    try { await call('POST', `/callbacks/${cb}/reschedule`, { scheduledAt: val('rs1'), reason: val('rs2'), timezone: Intl.DateTimeFormat().resolvedOptions().timeZone }); cl(); toast('Callback rescheduled'); detailDialog(id); } catch (e) { errBox(msg(e)); } }); };
  w.phF = (k: string, v: string) => { LST.f[k] = v; LST.page = 1; LST.data = null; draw(); }; w.phPg = (d: number) => { LST.page += d; LST.data = null; draw(); };
  w.phBackfill = async () => { try { const r = await call<any>('POST', '/phone/backfill'); toast(`Synced ${r.synced} contact numbers`); } catch (e) { toast(msg(e)); } };
  w.phDispEdit = (id: number) => { const d = PH.disp.find((x: any) => x.id === id) || { label: '', requiresFollowup: false, isActive: true };
    dlg(id ? 'Edit outcome' : 'Add outcome', `<label>Label</label><input id="dp1" value="${h(d.label)}"><label class="ck" style="font-size:13px"><input type="checkbox" id="dp2" ${d.requiresFollowup ? 'checked' : ''}> Needs follow-up</label>${id ? `<label class="ck" style="font-size:13px"><input type="checkbox" id="dp3" ${d.isActive ? 'checked' : ''}> Active</label>` : ''}`, 'Save', async () => {
      try { const r = await call<any>(id ? 'PUT' : 'POST', id ? '/call-dispositions/' + id : '/call-dispositions', { label: val('dp1'), requiresFollowup: (document.getElementById('dp2') as HTMLInputElement).checked, isActive: id ? (document.getElementById('dp3') as HTMLInputElement).checked : true }); PH.disp = r.items; cl(); draw(); } catch (e) { errBox(msg(e)); } }); };
  Pg.calls = callsPage; Pg.callhist = histPage; Pg.callan = anPage; Pg.voicemail = vmPage; Pg.callset = setPage;
  spR();
}
