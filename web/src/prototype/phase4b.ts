// Phase 4 increment 2: connects the restored Group trips, Forms and Content library screens to the PHP API.
// Database records are in memory only; trips.ts strips database trips (groups and sub-trips included) before persistence.
import { api, ApiError } from '../api';
import { ensureItin } from './trips';
const w = window as any;
let can: (p: string) => boolean = () => false; let meId = 0;
const h = (s: unknown) => esc(s == null ? '' : s);
const msg = (e: unknown) => e instanceof ApiError ? e.message : 'Request failed';
const errBox = (m: string) => { const e = document.getElementById('er'); if (e) e.textContent = m; else toast(m); };
async function call<T>(m: string, p: string, b?: unknown): Promise<T> {
  try { return await api<T>(m, p, b); } catch (e) { if (e instanceof ApiError && e.status === 401) { toast('Your session has ended. Please sign in again.'); setTimeout(() => { location.hash = '#/login'; location.reload(); }, 900); } throw e; }
}
const dialogOpen = () => !!(document.getElementById('ov') && document.getElementById('ov')!.innerHTML.trim());
const redraw = () => { if (!dialogOpen()) draw(); };
const isDbId = (id: unknown, pre: string) => typeof id === 'string' && new RegExp('^' + pre + '\\d+$').test(id);
function upsertContact(id: number, f: string, l: string | null, e?: string | null) {
  const cid = 'dc' + id; let o = S.cm.find((x: any) => x.id === cid);
  if (!o) { o = { id: cid, db: 1, g: [], hh: [], prefs: {}, loy: [], ty: 'Client', b: '', pp: '', created: '', src: 'Database' }; S.cm.push(o); }
  o.f = f; o.l = l || ''; if (e !== undefined) o.e = e || ''; return o;
}
let STAFF: { id: number; fullName: string }[] = [];
/* =============================== GROUPS =============================== */
const GRP: Record<number, any> = {}; const gKey: Record<number, string> = {}; const gBusy: Record<number, boolean> = {}; const SYNC: Record<string, any> = {};
const grpSig = (gid: number) => { const p = tripById('d' + gid); if (!p || !p.grp) return ''; const G = p.grp; return JSON.stringify([G.landing.head, G.landing.blurb, G.landing.deadline || '', Number(G.deposit) || 0, Number(G.seats) || 0, G.rooms.map((r: any) => [r.db || r.id, r.n, Number(r.p) || 0, Number(r.cap) || 0]), p.pub ? 1 : 0, G.landing.pub ? 1 : 0]); };
function applyGroup(g: any) {
  GRP[g.id] = g; const id = 'd' + g.id; let p = S.trips.find((t: any) => t.id === id);
  if (!p) { p = { id, db: g.id, x: 1, log: [], v: 0 }; S.trips.push(p); }
  const det = g.details || {};
  Object.assign(p, { type: 'group', n: g.name, st: g.status, sd: g.startDate || '', ed: g.endDate || '', dest: g.destination || '', ow: g.ownerName || '', c: g.coordinatorName || '—', col: [], tg: ['Group'], tvx: [], cid: '', pub: g.isPublished ? 1 : 0, rev: g.version, arch: g.status === 'Archived' ? 1 : 0, s: g.status });
  p.d = fdr(p.sd, p.ed);
  p.grp = { landing: { head: det.headline || g.name, blurb: det.description || '', deadline: g.registrationDeadline || '', pub: g.isPublished ? 1 : 0, img: '' }, rooms: g.rooms.map((r: any) => ({ id: 'r' + r.id, db: r.id, n: r.name, p: r.pricePerPerson, cap: r.capacity })),
    subs: [] as string[], seats: g.capacity ?? 0, deposit: det.depositPerPerson ?? 0 };
  const regBySub: Record<number, any> = {}; g.registrations.forEach((r: any) => { regBySub[r.subTripId] = r; });
  g.subTrips.forEach((st: any) => {
    const r = regBySub[st.id]; const sid = 'd' + st.id; let s = S.trips.find((t: any) => t.id === sid);
    if (!s) { s = { id: sid, db: st.id, x: 1, log: [], v: 0 }; S.trips.push(s); }
    if (r) r.travelers.forEach((t: any) => upsertContact(t.contactId, t.firstName, t.lastName, t.email));
    const names = r ? r.travelers.map((t: any) => `${t.firstName} ${t.lastName || ''}`.trim()).join(' & ') : (s.c || '—');
    Object.assign(s, { type: 'sub', parent: id, n: st.name, st: st.status, sd: st.startDate || '', ed: st.endDate || '', dest: p.dest, ow: p.ow, col: [], tg: ['Group'], c: names,
      cid: r && r.primaryContactId ? 'dc' + r.primaryContactId : (s.cid || ''), tvx: r ? r.travelers.map((t: any) => ({ cid: 'dc' + t.contactId, perm: t.contactId === r.primaryContactId ? 'Primary' : 'Full' })) : (s.tvx || []),
      room: r && r.roomId ? 'r' + r.roomId : '', custom: st.custom > 0 ? 1 : 0, pub: p.pub, reg: r || null });
    s.d = fdr(s.sd, s.ed); if (st.status !== 'Cancelled') p.grp.subs.push(sid);
  });
  gKey[g.id] = grpSig(g.id);
}
async function refreshGroup(gid: number) { applyGroup((await call<any>('GET', '/groups/' + gid)).group); redraw(); }
async function loadGroups() { const r = await call<any>('GET', '/groups?archived=all'); for (const g of r.items) applyGroup((await call<any>('GET', '/groups/' + g.id)).group); }
async function syncGroup(gid: number) {
  if (gBusy[gid] || !GRP[gid] || gKey[gid] === undefined) return; const sig = grpSig(gid); if (sig === gKey[gid]) return;
  gBusy[gid] = true; const p = tripById('d' + gid), G = p.grp, cur = GRP[gid];
  try {
    const wantPub = (p.pub || G.landing.pub) ? 1 : 0; const pubChanged = (p.pub !== (cur.isPublished ? 1 : 0)) || (G.landing.pub !== (cur.isPublished ? 1 : 0));
    if (pubChanged) { const goPub = p.pub !== (cur.isPublished ? 1 : 0) ? p.pub : G.landing.pub; await call('POST', `/trips/${gid}/${goPub ? 'publish' : 'unpublish'}`); void wantPub; }
    const roomsNow = JSON.stringify(G.rooms.map((r: any) => [r.db || 0, r.n, Number(r.p) || 0, Number(r.cap) || 0])), roomsWas = JSON.stringify(cur.rooms.map((r: any) => [r.id, r.name, r.pricePerPerson, r.capacity]));
    if (roomsNow !== roomsWas) await call('PUT', `/groups/${gid}/rooms`, { rooms: G.rooms.map((r: any) => ({ id: r.db || undefined, name: r.n, pricePerPerson: Number(r.p) || 0, capacity: Number(r.cap) || 1 })) });
    const d = cur.details || {};
    if (G.landing.head !== (d.headline || cur.name) || G.landing.blurb !== (d.description || '') || (G.landing.deadline || '') !== (cur.registrationDeadline || '') || (Number(G.deposit) || 0) !== (d.depositPerPerson ?? 0) || (Number(G.seats) || 0) !== (cur.capacity ?? 0)) {
      const latest = (await call<any>('GET', '/groups/' + gid)).group;
      await call('PUT', '/groups/' + gid, { version: latest.version, registrationDeadline: G.landing.deadline || '', capacity: Number(G.seats) || null, details: { headline: G.landing.head, description: G.landing.blurb, depositPerPerson: Number(G.deposit) || 0 } });
    }
    await refreshGroup(gid);
  } catch (e) { toast('Group not saved: ' + msg(e)); try { await refreshGroup(gid); } catch { /* ignore */ } }
  finally { gBusy[gid] = false; }
}
const coordOpts = () => [['', '— None —'], ...STAFF.map(s => [String(s.id), s.fullName])];
function groupDialog(g?: any) {
  if (!can('groups.manage')) { toast('Your role cannot manage group trips'); return; }
  dlgW(g ? 'Edit group trip' : 'New group trip', `<div class="fm"><label>Group name</label><input id="gn1" value="${h(g ? g.name : '')}" maxlength="190"><div class="g g2">
    <div><label>Destination</label><input id="gn2" value="${h(g ? g.destination || '' : '')}"></div><div><label>Status</label>${sel('gn8', ['Planning', 'Open', 'Closed', 'Booked', 'Traveling', 'Traveled', 'Cancelled'], g ? g.status : 'Planning')}</div>
    <div><label>Departure</label><input type="date" id="gn3" value="${h(g ? g.startDate || '' : '')}"></div><div><label>Return</label><input type="date" id="gn4" value="${h(g ? g.endDate || '' : '')}"></div>
    <div><label>Capacity (travelers)</label><input type="number" min="1" id="gn5" value="${g && g.capacity ? g.capacity : ''}"></div><div><label>Registration deadline</label><input type="date" id="gn6" value="${h(g ? g.registrationDeadline || '' : '')}"></div>
    <div><label>Group coordinator</label>${sel('gn7', coordOpts(), g && g.coordinatorId ? String(g.coordinatorId) : String(meId))}</div><div><label>Deposit per person</label><input type="number" min="0" id="gn9" value="${g && g.details && g.details.depositPerPerson != null ? g.details.depositPerPerson : ''}"></div></div>
    <p class="mu" style="font-size:12px">Saved to the LTI database.</p></div>`, g ? 'Save group' : 'Create group', async () => {
    const name = val('gn1'); if (!name) return errBox('Enter a group name.');
    const body: any = { name, destination: val('gn2'), status: val('gn8'), startDate: val('gn3'), endDate: val('gn4'), capacity: val('gn5') || null, registrationDeadline: val('gn6'), coordinatorId: val('gn7') || null,
      details: { headline: g && g.details && g.details.headline ? g.details.headline : name, description: g && g.details ? g.details.description || '' : '', depositPerPerson: val('gn9') || 0 } };
    try { if (g) body.version = GRP[g.id].version; const r = await call<any>(g ? 'PUT' : 'POST', g ? '/groups/' + g.id : '/groups', body); applyGroup(r.group); cl(); toast(g ? 'Group updated' : 'Group trip saved to the database'); X.ui.grp = 'd' + r.group.id; go('groups'); }
    catch (e) { errBox(msg(e)); }
  }, 680);
}
async function registrationDialog(gid: number, rid?: number) {
  if (!can('groups.manage')) { toast('Your role cannot register travelers'); return; }
  const g = GRP[gid]; const reg = rid ? g.registrations.find((r: any) => r.id === rid) : null;
  let contacts: any[] = []; try { contacts = (await call<any>('GET', '/contacts?perPage=100')).items; } catch (e) { toast(msg(e)); return; }
  const taken = new Set<number>(); g.registrations.filter((r: any) => r.status === 'active' && r.id !== rid).forEach((r: any) => r.travelers.forEach((t: any) => taken.add(t.contactId)));
  const chosen = new Set<number>(reg ? reg.travelers.map((t: any) => t.contactId) : []);
  const list = contacts.map((c: any) => `<label class="ck" style="font-size:13px;min-width:200px"><input type="checkbox" class="grc" value="${c.id}" ${chosen.has(c.id) ? 'checked' : ''}> ${h(c.firstName)} ${h(c.lastName || '')}${taken.has(c.id) ? ' <span class="mu">(registered)</span>' : ''}</label>`).join('');
  const used = g.registered, cap = g.capacity;
  dlgW(reg ? 'Edit registration' : 'Register travelers', `<div class="fm"><p class="mu" style="font-size:12px">${used} of ${cap ?? 'unlimited'} places taken${g.registrationDeadline ? ' · deadline ' + h(fd(g.registrationDeadline)) : ''}. The first traveler is the primary contact for the sub-trip.</p>
    <label>Existing contacts</label><div class="row" style="gap:6px 14px;max-height:180px;overflow:auto">${list || '<span class="mu">No contacts</span>'}</div>
    <label>New travelers (creates contacts)</label>${[1, 2].map(i => `<div class="g g3"><input id="grn${i}f" placeholder="First name" aria-label="New traveler ${i} first name"><input id="grn${i}l" placeholder="Last name" aria-label="New traveler ${i} last name"><input id="grn${i}e" type="email" placeholder="Email" aria-label="New traveler ${i} email"></div>`).join('')}
    <div class="g g2"><div><label>Room category</label>${sel('grr', [['', '— Not assigned —'], ...g.rooms.map((r: any) => [String(r.id), `${r.name} (${r.taken}/${r.capacity})`])], reg && reg.roomId ? String(reg.roomId) : '')}</div><div><label>Room preference</label><input id="grp" value="${h(reg ? reg.roomPreference || '' : '')}"></div></div>
    <label>Special requests</label><textarea id="grq" rows="2">${h(reg ? reg.specialRequests || '' : '')}</textarea>
    ${can('groups.override_capacity') ? '<label class="ck" style="font-size:13px"><input type="checkbox" id="gro"> Authorize beyond capacity, room limit or deadline (administrator)</label>' : ''}</div>`, reg ? 'Save registration' : 'Register', async () => {
    const ids = Array.from(document.querySelectorAll<HTMLInputElement>('.grc:checked')).map(e => Number(e.value));
    if (reg) { const p0 = reg.primaryContactId; ids.sort((a, b) => (a === p0 ? -1 : b === p0 ? 1 : 0)); }
    const travelers: any[] = ids.map(id => ({ contactId: id }));
    [1, 2].forEach(i => { if (val(`grn${i}f`)) travelers.push({ firstName: val(`grn${i}f`), lastName: val(`grn${i}l`), email: val(`grn${i}e`) }); });
    if (!travelers.length) return errBox('Choose or add at least one traveler.');
    const o = document.getElementById('gro') as HTMLInputElement | null;
    const body = { travelers, roomId: val('grr') || null, roomPreference: val('grp'), specialRequests: val('grq'), overrideCapacity: !!(o && o.checked) };
    try { const r = await call<any>(reg ? 'PUT' : 'POST', reg ? `/groups/${gid}/registrations/${rid}` : `/groups/${gid}/registrations`, body); applyGroup(r.group); cl(); toast(reg ? 'Registration updated' : 'Registration saved and sub-trip created'); draw(); }
    catch (e) { errBox(msg(e)); }
  }, 760);
}
function travelersTab(g: any): string {
  const G = GRP[g.db]; const roomName = (id: number | null) => (G.rooms.find((r: any) => r.id === id) || { name: '—' }).name; const m = can('groups.manage');
  const regRows = G.registrations.map((r: any) => `<tr${r.status === 'cancelled' ? ' style="opacity:.6"' : ''}><td><a class="lk" onclick="S.cur='d${r.subTripId}';S.tsec='Overview';go('trip')">${h(r.subTripName)}</a></td>
    <td style="white-space:normal">${r.travelers.map((t: any) => h(`${t.firstName} ${t.lastName || ''}`.trim()) + (t.contactId === r.primaryContactId ? ' (primary)' : '')).join(', ')}</td><td>${h(roomName(r.roomId))}${r.roomPreference ? `<div class="mu" style="font-size:12px">${h(r.roomPreference)}</div>` : ''}</td>
    <td style="white-space:normal;font-size:12px">${h(r.specialRequests || '—')}</td><td>${h(fd(String(r.registeredAt).slice(0, 10)))}</td><td>${pill(r.status === 'active' ? 'Active' : 'Cancelled')}${r.overCapacity ? ' <span class="tag">Authorized over limit</span>' : ''}</td>
    <td>${m && r.status === 'active' ? `<div class="row" style="flex-wrap:nowrap"><button class="btn o s" onclick="grpRegDlg(${G.id},${r.id})">Edit</button><button class="btn o s" onclick="grpRegCancel(${G.id},${r.id})">Cancel</button></div>` : ''}</td></tr>`).join('');
  const manual = G.subTrips.filter((s: any) => !G.registrations.some((r: any) => r.subTripId === s.id));
  return `<div class="g g4" style="margin-bottom:14px">${kpi('Travelers', G.registered + ' / ' + (G.capacity ?? 'unlimited'))}${kpi('Active registrations', G.registrations.filter((r: any) => r.status === 'active').length)}${kpi('Sub-trips', G.subTrips.length)}${kpi('Deadline', G.registrationDeadline ? fd(G.registrationDeadline) : '—')}</div>`
    + cd('Registrations', `<div class="tw"><table class="t2" data-live="registrations"><thead><tr><th>Party (sub-trip)</th><th>Travelers</th><th>Room</th><th>Special requests</th><th>Registered</th><th>Status</th><th></th></tr></thead><tbody>${regRows || '<tr><td colspan="7" class="mu">No registrations yet.</td></tr>'}</tbody></table></div>`,
      m ? `<button class="btn s" onclick="grpRegDlg(${G.id})">Register travelers</button>` : '', 1)
    + cd('Other sub-trips', manual.length ? manual.map((s: any) => `<div class="ts"><a class="lk" onclick="S.cur='d${s.id}';S.tsec='Overview';go('trip')">${h(s.name)}</a><span class="mu">${h(fdr(s.startDate || '', s.endDate || ''))}</span>${pill(s.status)}</div>`).join('') : '<p class="mu">Sub-trips for hosts, staff or separate parties appear here.</p>',
      m ? `<button class="btn o s" onclick="grpSubNew(${G.id})">New sub-trip</button>` : '');
}
function syncTab(g: any): string {
  const G = GRP[g.db]; const res = SYNC[G.id]; if (!res && !SYNC['_' + G.id]) { SYNC['_' + G.id] = 1; w.grpSyncRun(G.id, false, true); }
  const name = (sid: number) => (G.subTrips.find((s: any) => s.id === sid) || { name: 'Sub-trip ' + sid }).name;
  return `<div class="info"><div><b>Group itinerary → sub-trips</b><p>The group itinerary is the master copy. Sync adds, updates and removes group activities in every active sub-trip. Activities added or edited inside a sub-trip are kept and never overwritten.</p></div></div>
    <div class="row" style="margin-bottom:12px"><button class="btn o" onclick="S.cur='${g.id}';go('itinerary')">Edit group itinerary</button><button class="btn o" onclick="grpSyncRun(${G.id},false)">Refresh preview</button>${can('groups.manage') ? `<button class="btn" onclick="grpSyncRun(${G.id},true)">Apply sync to sub-trips</button>` : ''}</div>`
    + cd(res && res.applied ? 'Last sync result' : 'Synchronization preview', res ? `<div class="tw"><table class="t2" data-live="sync"><thead><tr><th>Sub-trip</th><th>Days added</th><th>Activities added</th><th>Updated</th><th>Removed</th><th>Custom kept</th></tr></thead><tbody>${res.results.map((r: any) => `<tr><td>${h(name(r.subTripId))}</td><td>${r.daysAdded}</td><td>${r.added}</td><td>${r.updated}</td><td>${r.removed}</td><td>${r.customKept}</td></tr>`).join('') || '<tr><td colspan="6" class="mu">No active sub-trips.</td></tr>'}</tbody></table></div>` : '<p class="mu">Loading preview</p>');
}
/* =============================== FORMS =============================== */
const FORMS: Record<number, any> = {}; const fKey: Record<number, string> = {}; const fBusy: Record<number, boolean> = {}; const respAt: Record<number, number> = {};
const safeKey = (k: string, i: number) => (String(k).toLowerCase().replace(/[^a-z0-9_]/g, '') || 'field_' + (i + 1)).slice(0, 40);
const formSig = (pf: any) => JSON.stringify([pf.n, pf.cat, pf.steps, pf.fields.map((x: any) => [x.id, x.label, x.type, x.req ? 1 : 0, Number(x.step) || 1, x.opts || '', x.show || '']), pf.createTrip ? 1 : 0]);
function applyForm(f: any) {
  FORMS[f.id] = f; const id = 'df' + f.id; const pf = { id, db: f.id, n: f.name, cat: f.category || 'Custom', steps: f.steps, resp: f.submissionCount, wf: '', createTrip: f.settings && f.settings.createTrip ? 1 : 0, status: f.status, token: f.token,
    fields: f.fields.map((x: any) => ({ id: x.key, fid: Number(String(x.id).replace(/^f/, '')), label: x.label, type: x.type, req: x.required ? 1 : 0, step: x.step, opts: (x.options || []).join(','), show: x.showIf || '' })) };
  const i = X.forms.findIndex((x: any) => x.id === id); if (f.status === 'archived') { if (i >= 0) X.forms.splice(i, 1); } else if (i >= 0) X.forms[i] = pf; else X.forms.push(pf);
  fKey[f.id] = formSig(pf);
}
async function loadForms() { if (!can('forms.manage')) return; X.forms = X.forms.filter((x: any) => !x.db); (await call<any>('GET', '/forms')).items.forEach(applyForm); }
async function loadResponses(fid: number, force = false) {
  if (!force && Date.now() - (respAt[fid] || 0) < 20000) return; respAt[fid] = Date.now();
  const r = await call<any>('GET', `/forms/${fid}/submissions?perPage=200`); X.responses = X.responses.filter((x: any) => !(x.db && x.formDb === fid));
  r.items.forEach((s: any) => { if (s.contactId && s.contactName) { const [f0, ...l0] = String(s.contactName).split(' '); upsertContact(s.contactId, f0, l0.join(' ')); }
    X.responses.push({ id: 'dr' + s.id, db: s.id, formDb: fid, form: 'df' + fid, at: String(s.createdAt).slice(0, 16), ref: s.contactId ? 'contact:dc' + s.contactId : '', trip: s.tripId ? 'd' + s.tripId : '', data: s.answers, st: s.status === 'reviewed' ? 'Reviewed' : 'New', source: s.source }); });
  if (FORMS[fid]) { const pf = X.forms.find((x: any) => x.db === fid); if (pf) pf.resp = r.total; }
  redraw();
}
async function syncForm(fid: number) {
  const pf = X.forms.find((x: any) => x.db === fid); if (!pf || fBusy[fid] || fKey[fid] === undefined) return;
  pf.fields.forEach((x: any, i: number) => { const k = safeKey(x.id, i); if (k !== x.id) { pf.fields.forEach((y: any) => { if (y.show && y.show.split('=')[0] === x.id) y.show = k + '=' + y.show.split('=').slice(1).join('='); }); x.id = k; } });
  const sent = formSig(pf); if (sent === fKey[fid]) return; fBusy[fid] = true;
  try {
    const r = await call<any>('PUT', '/forms/' + fid, { name: pf.n, category: pf.cat, steps: pf.steps, settings: { createContact: true, createTrip: !!pf.createTrip }, version: FORMS[fid].version,
      fields: pf.fields.map((x: any) => ({ id: x.fid ? 'f' + x.fid : undefined, key: x.id, label: x.label, type: x.type, required: !!x.req, step: Number(x.step) || 1, options: String(x.opts || '').split(',').map((o: string) => o.trim()).filter(Boolean), showIf: x.show || '' })) });
    if (formSig(pf) === sent) applyForm(r.form); else { FORMS[fid] = r.form; r.form.fields.forEach((sf: any) => { const lf = pf.fields.find((x: any) => x.id === sf.key); if (lf) lf.fid = Number(String(sf.id).replace(/^f/, '')); }); fKey[fid] = JSON.stringify(['saved', r.form.version]); }
    redraw();
  } catch (e) { toast('Form not saved: ' + msg(e)); try { applyForm((await call<any>('GET', '/forms/' + fid)).form); redraw(); } catch { /* ignore */ } }
  finally { fBusy[fid] = false; }
}
async function formAction(fid: number, action: string) {
  try { await syncForm(fid); const r = await call<any>('POST', `/forms/${fid}/${action}`); applyForm(r.form);
    if (action === 'duplicate') { X.ui.form = 'df' + r.form.id; toast('Form duplicated as a draft'); } else if (action === 'archive') { X.ui.form = ''; toast('Form archived'); } else toast(action === 'publish' ? 'Form published' : 'Form unpublished'); draw(); }
  catch (e) { toast(msg(e)); }
}
/* =============================== LIBRARY =============================== */
const TPL: Record<number, any> = {}; let FOLDERS: any[] = []; const lKey: Record<string, string> = {};
const actSig = (a: any) => JSON.stringify([a.title, a.type, a.desc, a.loc, Number(a.price) || 0]);
const packSig = (p: any) => JSON.stringify([p.n, p.items]);
function applyTemplate(t: any) {
  TPL[t.id] = t; X.lib = X.lib.filter((x: any) => x.db !== t.id); X.packing = X.packing.filter((x: any) => x.db !== t.id); if (t.status !== 'active') return;
  const b = t.body || {};
  if (t.kind === 'activity') { const a = { id: 'dl' + t.id, db: t.id, type: ACT_TYPES[b.type] ? b.type : 'tour', title: t.title, desc: b.description || '', loc: b.location || '', price: Number(b.price) || 0, supplier: b.supplier || '', f: b.f || {}, img: '', folderId: t.folderId, tags: t.tags || [] }; X.lib.unshift(a); lKey['a' + t.id] = actSig(a); }
  if (t.kind === 'checklist') { const p = { id: 'dp' + t.id, db: t.id, n: t.title, items: (b.items || []).map(String) }; X.packing.push(p); lKey['p' + t.id] = packSig(p); }
}
async function loadLibrary() { if (!can('library.view')) return; FOLDERS = (await call<any>('GET', '/library/folders')).items; X.lib = X.lib.filter((x: any) => !x.db); X.packing = X.packing.filter((x: any) => !x.db); (await call<any>('GET', '/library/templates')).items.forEach(applyTemplate); }
async function reloadFolders() { FOLDERS = (await call<any>('GET', '/library/folders')).items; }
let libBusy = false;
async function syncLibrary() {
  if (libBusy || !can('library.manage')) return; libBusy = true;
  try {
    for (const t of Object.values(TPL) as any[]) {
      if (t.status !== 'active') continue;
      if (t.kind === 'activity') { const a = X.lib.find((x: any) => x.db === t.id); if (!a) { applyTemplate((await call<any>('POST', `/library/templates/${t.id}/archive`)).template); toast('Template archived'); continue; } }
      if (t.kind === 'checklist') { const p = X.packing.find((x: any) => x.db === t.id); if (!p) continue; if (packSig(p) !== lKey['p' + t.id]) applyTemplate((await call<any>('PUT', '/library/templates/' + t.id, { title: p.n, body: { items: p.items }, version: t.version })).template); }
    }
  } catch (e) { toast('Library not saved: ' + msg(e)); try { await loadLibrary(); } catch { /* ignore */ } }
  finally { libBusy = false; }
}
const folderOpts = (withAll: boolean) => { const depth = (f: any): number => { let d = 0, p = f.parentId; while (p && d < 10) { d++; p = (FOLDERS.find(x => x.id === p) || {}).parentId; } return d; };
  return [...(withAll ? [['', 'All templates'], ['none', 'Unfiled']] : [['', 'Unfiled']]), ...FOLDERS.map(f => [String(f.id), '\u2003'.repeat(depth(f)) + f.name])]; };
function activityDialog(t?: any) {
  if (!can('library.manage')) { toast('Your role cannot edit the content library'); return; } const b = t ? t.body || {} : {};
  dlg(t ? 'Edit activity template' : 'New activity template', `<label>Type</label>${sel('la1', Object.entries(ACT_TYPES).map(([k, v]: any) => [k, v.l]), b.type || 'tour')}<label>Title</label><input id="la2" value="${h(t ? t.title : '')}"><label>Location</label><input id="la3" value="${h(b.location || '')}"><label>Description</label><textarea id="la4" rows="2">${h(b.description || '')}</textarea><label>Price</label><input id="la5" type="number" min="0" value="${b.price || 0}"><label>Folder</label>${sel('la6', folderOpts(false), t && t.folderId ? String(t.folderId) : (X.ui.lfold && X.ui.lfold !== 'none' ? X.ui.lfold : ''))}`,
    'Save', async () => { if (!val('la2')) return errBox('Enter a title.');
      const body = { kind: 'activity', title: val('la2'), folderId: val('la6') || null, body: { type: val('la1'), title: val('la2'), location: val('la3'), description: val('la4'), price: Number(val('la5')) || 0 } };
      try { const r = await call<any>(t ? 'PUT' : 'POST', t ? '/library/templates/' + t.id : '/library/templates', t ? { ...body, version: t.version } : body); applyTemplate(r.template); await reloadFolders(); cl(); toast(t ? 'Template updated' : 'Template saved to the library'); draw(); } catch (e) { errBox(msg(e)); } });
}
/* =============================== LABELS / INSTALL =============================== */
export function note4b(): string | null | undefined {
  if (S.pg === 'groups' || S.pg === 'forms') return 'live';
  if (S.pg === 'content') return 'Activity templates (with folders) and Packing lists are live database records. Destination guides, Task templates and More libraries remain prototype sample data until Phase 6.';
  return undefined;
}
const withDb = (fn: () => string) => { const t = S.trips, f = X.forms, r = X.responses, l = X.lib, p = X.packing;
  S.trips = t.filter((x: any) => !(x.type === 'group' || x.type === 'sub') || x.db); X.forms = f.filter((x: any) => x.db); X.responses = r.filter((x: any) => x.db);
  X.lib = l.filter((x: any) => x.db && (!X.ui.lfold || (X.ui.lfold === 'none' ? !x.folderId : String(x.folderId) === X.ui.lfold))); X.packing = p.filter((x: any) => x.db);
  try { return fn(); } finally { S.trips = t; X.forms = f; X.responses = r; X.lib = l; X.packing = p; } };
export async function installPhase4b(perms: (p: string) => boolean, userId: number) {
  can = perms; meId = userId; if (X.role === 'Traveler') return;
  try { STAFF = (await call<any>('GET', '/contacts/staff-options')).items; } catch { STAFF = []; }
  if (!FTYPES.some((t: any) => t[0] === 'phone')) FTYPES.push(['phone', 'Phone'], ['number', 'Number'], ['radio', 'Multiple choice']);
  const prevPersist = w.persist;
  w.persist = function () { const k = { f: X.forms, r: X.responses, l: X.lib, p: X.packing }; X.forms = k.f.filter((x: any) => !x.db); X.responses = k.r.filter((x: any) => !x.db); X.lib = k.l.filter((x: any) => !x.db); X.packing = k.p.filter((x: any) => !x.db);
    try { prevPersist(); } finally { X.forms = k.f; X.responses = k.r; X.lib = k.l; X.packing = k.p; } };
  // ---- groups ----
  const pG = Pg.groups; Pg.groups = () => {
    let html = withDb(pG);
    html = html.replace(/onclick="addT\(\);setTimeout\([^"]*"/, 'onclick="grpNew()"');
    const g = X.ui.grp && tripById(X.ui.grp);
    if (g && g.db && can('groups.manage')) html = html.replace('← Group trips</button>', `← Group trips</button><button class="btn o" onclick="grpEditDlg(${g.db})">Edit group</button><button class="btn o" onclick="grpArchive(${g.db})">${g.st === 'Archived' ? 'Restore group' : 'Archive group'}</button>`);
    return html; };
  const oGD = w.groupDetail; w.groupDetail = (g: any, inTrip: any) => {
    if (!g || !g.db || !GRP[g.db]) return oGD(g, inTrip); const L = ['Overview', 'Landing page', 'Pricing & rooms', 'Travelers & sub-trips', 'Rooming list', 'Itinerary sync', 'Reports']; const k = tb('gd', L);
    if (k === 'Travelers & sub-trips') return tabs('gd', L) + travelersTab(g); if (k === 'Itinerary sync') return tabs('gd', L) + syncTab(g);
    return oGD(g, inTrip); };
  w.grpNew = () => groupDialog(); w.grpEditDlg = (id: number) => groupDialog(GRP[id]);
  w.grpArchive = (id: number) => { const g = GRP[id]; const restore = g.status === 'Archived'; dlg(restore ? 'Restore group' : 'Archive group', `<p>${restore ? 'Restore' : 'Archive'} <b>${h(g.name)}</b>? Registrations, sub-trips and bookings are kept.</p>`, restore ? 'Restore' : 'Archive', async () => {
    try { const r = await call<any>('POST', `/groups/${id}/${restore ? 'restore' : 'archive'}`); applyGroup(r.group); cl(); toast(restore ? 'Group restored' : 'Group archived'); if (!restore) X.ui.grp = ''; draw(); } catch (e) { errBox(msg(e)); } }); };
  w.grpRegDlg = (gid: number, rid?: number) => registrationDialog(gid, rid);
  w.grpRegCancel = (gid: number, rid: number) => dlg('Cancel registration', '<p>The registration is kept for history, its sub-trip is marked Cancelled and the places are released.</p><label>Reason</label><input id="a1">', 'Cancel registration', async () => {
    try { const r = await call<any>('POST', `/groups/${gid}/registrations/${rid}/cancel`, { reason: val('a1') }); applyGroup(r.group); cl(); toast('Registration cancelled'); draw(); } catch (e) { errBox(msg(e)); } });
  w.grpSubNew = (gid: number) => { const g = GRP[gid]; dlg('New sub-trip', `<label>Name</label><input id="sb1" value="${h(g.name)} — "><div class="g g2"><div><label>Departure</label><input type="date" id="sb2" value="${h(g.startDate || '')}"></div><div><label>Return</label><input type="date" id="sb3" value="${h(g.endDate || '')}"></div></div><p class="mu" style="font-size:12px">The group itinerary is copied in. Add travelers from the sub-trip’s Travelers section.</p>`, 'Create sub-trip', async () => {
    if (!val('sb1')) return errBox('Enter a name.'); try { const r = await call<any>('POST', `/groups/${gid}/subtrips`, { name: val('sb1'), startDate: val('sb2'), endDate: val('sb3') }); applyGroup(r.group); cl(); toast('Sub-trip created'); draw(); } catch (e) { errBox(msg(e)); } }); };
  w.grpSyncRun = async (gid: number, apply: boolean, quiet?: boolean) => {
    try { const r = await call<any>('POST', `/groups/${gid}/sync`, { apply }); SYNC[gid] = r;
      if (apply) { r.results.forEach((x: any) => { delete X.itx['d' + x.subTripId]; }); await refreshGroup(gid); toast(`Synced ${r.results.length} sub-trip${r.results.length === 1 ? '' : 's'}`); } else if (!quiet) toast('Preview updated'); redraw(); }
    catch (e) { toast(msg(e)); } };
  const oReg = w.grpRegister; w.grpRegister = async (gid: string) => { const g = tripById(gid); if (!g || !g.db) return oReg(gid);
    const f = val('gr1'), l = val('gr2'), e = val('gr3'), guest = val('gr4'), room = (document.querySelector('input[name=grm]:checked') as HTMLInputElement | null || { value: '' }).value;
    if (!f || !l || !/^\S+@\S+\.\S+$/.test(e)) return toast('Enter first name, last name and a valid email'); if (!val('gr5')) return toast('Please accept the terms');
    const travelers: any[] = [{ firstName: f, lastName: l, email: e }]; if (guest) { const p = guest.split(' '); travelers.push({ firstName: p[0], lastName: p.slice(1).join(' ') }); }
    try { const r = await call<any>('POST', `/groups/${g.db}/registrations`, { travelers, roomId: room && /^r\d+$/.test(room) ? Number(room.slice(1)) : null }); applyGroup(r.group); toast('Registration saved and sub-trip created'); draw(); } catch (x) { toast(msg(x)); } };
  w.grpSync = (gid: string) => { const g = tripById(gid); if (g && g.db) return w.grpSyncRun(g.db, true); };
  // ---- forms ----
  const pF = Pg.forms; Pg.forms = () => { if (tb('fm', ['Forms', 'Responses']) === 'Responses' && !X.ui.form) Object.keys(FORMS).forEach(id => loadResponses(Number(id)).catch(() => {}));
    return withDb(pF).replace(/onclick="const f=\{id:uid\('f'\)[^"]*"/, 'onclick="frNew()"'); };
  const oFB = w.formBuilder; w.formBuilder = (f: any) => {
    if (!f || !f.db) return oFB(f); if (X.ui.fmv === 'Responses') loadResponses(f.db).catch(() => {});
    let html = withDb(() => oFB(f)); const st = (FORMS[f.db] || {}).status || 'draft';
    const btns = `<span class="tag">${st === 'published' ? 'Published' : 'Draft'}</span>${can('forms.manage') ? (st === 'published' ? `<button class="btn o" onclick="frAct(${f.db},'unpublish')">Unpublish</button>` : `<button class="btn" onclick="frAct(${f.db},'publish')">Publish</button>`) + `<button class="btn o" onclick="frAct(${f.db},'duplicate')">Duplicate</button><button class="btn o" onclick="frAct(${f.db},'archive')">Archive</button>` : ''}`;
    html = html.replace('>Share</button>', '>Share</button>' + btns);
    if (X.ui.fmv === 'Responses') html += `<p style="margin-top:10px"><a class="btn o s" href="./api/forms/${f.db}/submissions.csv">Export responses (CSV)</a></p>`;
    return html; };
  w.frNew = async () => { if (!can('forms.manage')) return toast('Your role cannot create forms');
    try { const r = await call<any>('POST', '/forms', { name: 'New form', category: 'Custom', steps: ['Step 1'], settings: { createContact: true, createTrip: false }, fields: [{ key: 'first', label: 'First name', type: 'text', required: true }, { key: 'email', label: 'Email', type: 'email', required: true }] });
      applyForm(r.form); X.ui.form = 'df' + r.form.id; X.ui.fmv = 'Build'; draw(); } catch (e) { toast(msg(e)); } };
  w.frAct = (fid: number, a: string) => formAction(fid, a);
  const oShare = w.frShare; w.frShare = (id: string) => { const pf = X.forms.find((x: any) => x.id === id); if (!pf || !pf.db) return oShare(id);
    if (FORMS[pf.db].status !== 'published') return dlg('Share form', '<p>Publish the form first. Draft forms cannot be opened from a link.</p>', null);
    const link = location.origin + location.pathname + '#/f/' + FORMS[pf.db].token; dlg('Share form', `<label>Public link</label><div class="row"><code class="lnkc">${h(link)}</code><button class="btn o s" onclick="navigator.clipboard&&navigator.clipboard.writeText('${h(link)}');toast('Link copied')">Copy</button></div><p class="mu" style="font-size:12px">Anyone with the link can submit. Responses are saved to the database and limited to 20 per network per hour.</p>`, null); };
  const oSub = w.frSubmit; w.frSubmit = async (id: string) => { const pf = X.forms.find((x: any) => x.id === id); if (!pf || !pf.db) return oSub(id); if (!w.frCheck(pf, X.ui.fstep || 0)) return;
    try { await syncForm(pf.db); await call('POST', `/forms/${pf.db}/submissions`, { answers: X.ui.fans || {} }); X.ui.fans = {}; X.ui.fstep = 0; toast('Response saved to the database'); X.ui.fmv = 'Responses'; await loadResponses(pf.db, true); draw(); }
    catch (e) { const q = document.getElementById('fqe'); if (q) q.textContent = msg(e); else toast(msg(e)); } };
  const oView = w.frView; w.frView = async (id: string) => { const r = X.responses.find((x: any) => x.id === id); if (!r || !r.db) return oView(id); const pf = X.forms.find((x: any) => x.id === r.form) || { fields: [], n: 'Form' };
    let contacts: any[] = []; try { contacts = (await call<any>('GET', '/contacts?perPage=100')).items; } catch { /* none */ }
    dlgW(h(pf.n) + ' · response', `<p class="mu">${h(r.at)} · ${h(r.source === 'public' ? 'Public link' : 'Entered by staff')}</p>${pf.fields.map((x: any) => `<div class="ts"><b style="min-width:180px;font-weight:500">${h(x.label)}</b><span>${h(r.data[x.id] === true ? 'Yes' : r.data[x.id] || '—')}</span></div>`).join('')}
      <div class="fm"><label>Associate with contact</label>${sel('fr1', [['', '—'], ...contacts.map((c: any) => [String(c.id), `${c.firstName} ${c.lastName || ''}`.trim()])], r.ref ? r.ref.replace('contact:dc', '') : '')}<label>Associate with trip</label>${sel('fr2', [['', '—'], ...S.trips.filter((t: any) => t.db).map((t: any) => [String(t.db), t.n])], r.trip ? r.trip.slice(1) : '')}</div>`,
      'Save & mark reviewed', async () => { try { await call('PUT', '/form-submissions/' + r.db, { contactId: val('fr1') || null, tripId: val('fr2') || null, status: 'reviewed' }); cl(); await loadResponses(r.formDb, true); toast('Response updated'); draw(); } catch (e) { errBox(msg(e)); } }, 680); };
  // ---- library ----
  const pC = Pg.content; Pg.content = () => {
    let html = withDb(pC); const k = tb('ct', ['Activities', 'Destination guides', 'Packing lists', 'Task templates', 'More libraries']);
    html = html.replace(/onclick="X\.packing\.push\(\{id:uid\('pk'\)[^"]*"/, 'onclick="libNewList()"');
    html = html.replace(/onclick="libUse\('dl(\d+)'\)">Use in trip<\/button>/g, (_m, id) => `onclick="libUse('dl${id}')">Use in trip</button>${can('library.manage') ? `<button class="btn o s" onclick="libEdit(${id})">Edit</button><button class="btn o s" onclick="libDup(${id})">Duplicate</button>` : ''}`);
    if (k === 'Activities') { const cur = FOLDERS.find(f => String(f.id) === X.ui.lfold);
      const bar = `<div class="row" style="margin-bottom:10px" data-live="folders"><label class="mu" for="lfold" style="margin:0">Folder</label>${sel('lfold', folderOpts(true), X.ui.lfold || '', 'style="width:auto" onchange="X.ui.lfold=this.value;draw()"')}${can('library.manage') ? `<button class="btn o s" onclick="libFolderNew()">New folder</button>${cur ? `<button class="btn o s" onclick="libFolderEdit(${cur.id})">Rename or move</button><button class="btn o s" onclick="libFolderDel(${cur.id})">Delete folder</button>` : ''}` : ''}</div>`;
      const i = html.indexOf('<div class="row" style="margin-bottom:10px">'); if (i >= 0) html = html.slice(0, i) + bar + html.slice(i); }
    return html; };
  w.libAdd = () => activityDialog(); w.libEdit = (id: number) => activityDialog(TPL[id]);
  w.libDup = async (id: number) => { try { applyTemplate((await call<any>('POST', `/library/templates/${id}/duplicate`)).template); toast('Template duplicated'); draw(); } catch (e) { toast(msg(e)); } };
  w.libNewList = async () => { if (!can('library.manage')) return toast('Your role cannot edit the content library'); try { applyTemplate((await call<any>('POST', '/library/templates', { kind: 'checklist', title: 'New packing list', body: { items: [] } })).template); draw(); } catch (e) { toast(msg(e)); } };
  w.libFolderNew = () => dlg('New folder', `<label>Name</label><input id="lf1"><label>Inside</label>${sel('lf2', [['', '— Top level —'], ...FOLDERS.map(f => [String(f.id), f.name])], X.ui.lfold && X.ui.lfold !== 'none' ? X.ui.lfold : '')}`, 'Create', async () => {
    if (!val('lf1')) return errBox('Enter a name.'); try { const r = await call<any>('POST', '/library/folders', { name: val('lf1'), parentId: val('lf2') || null }); await reloadFolders(); X.ui.lfold = String(r.folder.id); cl(); toast('Folder created'); draw(); } catch (e) { errBox(msg(e)); } });
  w.libFolderEdit = (id: number) => { const f = FOLDERS.find(x => x.id === id); dlg('Rename or move folder', `<label>Name</label><input id="lf1" value="${h(f.name)}"><label>Inside</label>${sel('lf2', [['', '— Top level —'], ...FOLDERS.filter(x => x.id !== id).map(x => [String(x.id), x.name])], f.parentId ? String(f.parentId) : '')}`, 'Save', async () => {
    try { await call('PUT', '/library/folders/' + id, { name: val('lf1'), parentId: val('lf2') || null }); await reloadFolders(); cl(); toast('Folder updated'); draw(); } catch (e) { errBox(msg(e)); } }); };
  w.libFolderDel = (id: number) => dlg('Delete folder', '<p>Only empty folders can be deleted.</p>', 'Delete', async () => { try { await call('DELETE', '/library/folders/' + id); await reloadFolders(); X.ui.lfold = ''; cl(); toast('Folder deleted'); draw(); } catch (e) { errBox(msg(e)); } });
  const oUse = w.libUse; w.libUse = (id: string) => { const a = X.lib.find((x: any) => x.id === id); if (!a || !a.db) return oUse(id);
    dlg('Add “' + h(a.title) + '” to a trip', `<label>Trip</label>${sel('lu1', S.trips.filter((t: any) => t.db && t.st !== 'Archived').map((t: any) => [String(t.db), t.n]), '')}<label>Day number</label><input id="lu2" type="number" min="1" value="1">`, 'Add', async () => {
      const tid = Number(val('lu1')), n = Math.max(1, Number(val('lu2')) || 1); if (!tid) return errBox('Choose a trip.');
      try { const it = await call<any>('GET', `/trips/${tid}/itinerary`); const day = (it.options[0] || { days: [] }).days[n - 1]; if (!day) return errBox(`That trip has ${(it.options[0] || { days: [] }).days.length} day(s). Add the day in the itinerary first.`);
        await call('POST', `/library/templates/${a.db}/use`, { tripId: tid, dayId: Number(String(day.id).slice(2)) }); if (X.itx['d' + tid]) { delete X.itx['d' + tid]; ensureItin('d' + tid, true); } cl(); toast('Added to the trip itinerary'); } catch (e) { errBox(msg(e)); } }); };
  try { await loadGroups(); await loadForms(); await loadLibrary(); } catch (e) { toast('Some Phase 4 data could not be loaded: ' + msg(e)); }
  window.setInterval(() => { Object.keys(GRP).forEach(id => syncGroup(Number(id))); Object.keys(FORMS).forEach(id => syncForm(Number(id))); syncLibrary(); }, 2500);
}
export function afterDraw4b() { if (X.role === 'Traveler') return; Object.keys(GRP).forEach(id => syncGroup(Number(id))); Object.keys(FORMS).forEach(id => syncForm(Number(id))); syncLibrary(); }
export const isDbForm = (id: string) => isDbId(id, 'df');
