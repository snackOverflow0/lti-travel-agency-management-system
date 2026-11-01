// Phase 3: connects the restored Revision 27 trip, itinerary and portal screens to the PHP API.
// Database trips use ids "d<id>", database contacts "dc<id>"; they are held in memory only and stripped before the
// prototype writes its sample data to browser storage. Edits made in the unchanged prototype screens are detected
// (diff after each render and every 3 s) and saved through the API, which returns authoritative records and new ids.
import { api, ApiError } from '../api';
const w = window as any;
let me: any; let can: (p: string) => boolean = () => false;
let STAFF: { id: number; fullName: string }[] = [];
export const isDb = (id: unknown) => typeof id === 'string' && /^d\d+$/.test(id);
const isDbAny = (id: unknown) => typeof id === 'string' && /^dc?\d+$/.test(id);
const isDbRef = (r: unknown) => typeof r === 'string' && /^(trip:d\d+|contact:dc\d+)/.test(r);
const PERM_IN: Record<string, string> = { primary: 'Primary', full: 'Full', limited: 'Limited' };
const PERM_OUT: Record<string, string> = { Primary: 'primary', Full: 'full', Limited: 'limited' };
const h = (s: unknown) => esc(s == null ? '' : s);
const msg = (e: unknown) => e instanceof ApiError ? e.message : 'Request failed';
async function call<T>(m: string, p: string, b?: unknown): Promise<T> {
  try { return await api<T>(m, p, b); }
  catch (e) { if (e instanceof ApiError && e.status === 401) { toast('Your session has ended. Please sign in again.'); setTimeout(() => { location.hash = '#/login'; location.reload(); }, 900); } throw e; }
}
const last = { trip: {} as Record<string, string>, trv: {} as Record<string, string>, itin: {} as Record<string, string> };
const busy: Record<string, boolean> = {}; const again: Record<string, boolean> = {};
const snapCache: Record<number, any> = {}; const threadAt: Record<string, number> = {};
const dialogOpen = () => !!(document.getElementById('ov') && document.getElementById('ov')!.innerHTML.trim());
function redraw() { if (!dialogOpen()) draw(); }
function upsertContact(c: any) {
  const id = 'dc' + c.contactId; let o = S.cm.find((x: any) => x.id === id);
  if (!o) { o = { id, db: 1, g: [], hh: [], prefs: {}, loy: [], ty: 'Client', b: '', pp: '', created: '', src: 'Database' }; S.cm.push(o); }
  o.f = c.firstName; o.l = c.lastName || ''; if (c.email !== undefined) o.e = c.email || ''; if (c.phone !== undefined) o.ph = c.phone || '';
  return o;
}
const tripKey = (p: any) => JSON.stringify([p.n, p.st, p.sd || '', p.ed || '', p.dest || '', p.ow || '', p.tg || [], p.col || [], S.notes[p.id] || '']);
const itinKey = (id: string) => { const it = X.itx[id]; const t = tripById(id); return JSON.stringify([it.opts, it.pack || [], it.guide || '', (t && t.prefs) || '']); };
function toProto(t: any) {
  const id = 'd' + t.id; let p = S.trips.find((x: any) => x.id === id);
  if (!p) { p = { id, db: t.id, x: 1, log: [], type: 'standard', v: 0 }; S.trips.push(p); }
  (t.travelers || []).forEach(upsertContact);
  Object.assign(p, { rev: t.version, n: t.name, c: t.primaryContactName || '–', cid: t.primaryContactId ? 'dc' + t.primaryContactId : '', st: t.status, sd: t.startDate || '', ed: t.endDate || '', dest: t.destination || '',
    ow: t.ownerName || '', col: (t.collaborators || []).map((c: any) => c.fullName), tg: t.tags || [], tvx: (t.travelers || []).map((r: any) => ({ cid: 'dc' + r.contactId, perm: PERM_IN[r.permission] || 'Full' })),
    pub: t.isPublished ? 1 : 0, arch: t.status === 'Archived' ? 1 : 0, s: STATUS_LEGACY[t.status] || t.status });
  p.d = fdr(p.sd, p.ed); if (t.notes !== undefined) S.notes[id] = t.notes || '';
  last.trip[id] = tripKey(p); last.trv[id] = JSON.stringify(p.tvx);
  if (t.activity) applyThread(id, t.activity);
  return p;
}
function applyThread(id: string, act: any[]) {
  const it = X.itx[id] || (X.itx[id] = { opts: [{ id: 'tmp', name: 'Loading itinerary', days: [] }], ver: [], com: [], pack: [], docs: [], appr: { st: 'none' }, _loading: 1 });
  it.com = act.filter(a => a.message && (a.action === 'comment' || a.action === 'changes_requested')).map(a => ({ id: 'c' + a.id, by: a.by, at: String(a.at).slice(0, 16), txt: (a.action === 'changes_requested' ? 'Change request: ' : '') + a.message, day: null }));
  const dec = act.filter(a => a.action === 'approved' || a.action === 'changes_requested').pop();
  it.appr = dec ? { st: dec.action === 'approved' ? 'approved' : 'changes', by: dec.by, at: String(dec.at).slice(0, 10), opt: dec.optionId } : { st: 'none' };
  threadAt[id] = Date.now();
}
function applyItin(id: string, pl: any) {
  const prev = X.itx[id] || {};
  const it = X.itx[id] = Object.assign(prev, { opts: pl.options, sel: pl.selectedOptionId, pack: (pl.meta && pl.meta.pack) || [], guide: (pl.meta && pl.meta.guide) || '', docs: prev.docs || [], com: prev.com || [], appr: prev.appr || { st: 'none' } });
  const t = tripById(id); if (t) { t.prefs = (pl.meta && pl.meta.prefs) || ''; t.pub = pl.isPublished ? 1 : 0; }
  it.pubAt = pl.published ? String(pl.published.publishedAt).slice(0, 10) : '';
  it.pub = pl.isPublished && pl.published ? (pl.published.hasUnpublishedChanges ? JSON.stringify(pl.published.snapshot) : JSON.stringify(it.opts)) : null;
  it.ver = (pl.versions || []).map((v: any) => ({ id: 'v' + v.id, vid: v.id, at: String(v.createdAt).slice(0, 16), label: v.label, snap: snapCache[v.id] ? JSON.stringify(snapCache[v.id]) : '[]' }));
  it._rev = pl.rev; delete it._loading; last.itin[id] = itinKey(id);
}
const loading: Record<string, boolean> = {};
export function ensureItin(id: string, force = false) {
  if (!isDb(id) || loading[id]) return; const it = X.itx[id];
  if (it && !it._loading && !force) { if (Date.now() - (threadAt[id] || 0) > 15000) refreshThread(id); return; }
  if (!it) X.itx[id] = { opts: [{ id: 'tmp', name: 'Loading itinerary', days: [] }], ver: [], com: [], pack: [], docs: [], appr: { st: 'none' }, _loading: 1 };
  loading[id] = true; const db = id.slice(1);
  Promise.all([call<any>('GET', `/trips/${db}/itinerary`), call<any>('GET', `/trips/${db}`)])
    .then(([pl, tr]) => { toProto(tr.trip); applyItin(id, pl); redraw(); })
    .catch(e => toast('Itinerary could not be loaded: ' + msg(e))).finally(() => { loading[id] = false; });
}
function refreshThread(id: string) { threadAt[id] = Date.now(); call<any>('GET', '/trips/' + id.slice(1)).then(r => { toProto(r.trip); redraw(); }).catch(() => {}); }
async function loadAllTrips() {
  const r = await call<any>('GET', '/trips?archived=all&perPage=200');
  S.trips = S.trips.filter((t: any) => !t.db); r.items.forEach(toProto);
}
/* ---------------- saving ---------------- */
async function syncTrip(t: any) {
  const id = t.id; if (busy['t' + id]) { again['t' + id] = true; return; } busy['t' + id] = true;
  try {
    const kv = JSON.stringify(t.tvx || []);
    if (last.trv[id] !== undefined && kv !== last.trv[id]) {
      const list = (t.tvx || []).filter((v: any) => isDbAny(v.cid) && String(v.cid).startsWith('dc'));
      const r = await call<any>('PUT', `/trips/${t.db}/travelers`, { travelers: list.map((v: any) => ({ contactId: Number(String(v.cid).slice(2)), permission: PERM_OUT[v.perm] || 'full' })) });
      toProto(r.trip); redraw();
    }
    if (last.trip[id] !== undefined && tripKey(t) !== last.trip[id]) {
      const owner = STAFF.find(s => s.fullName === t.ow);
      const body: any = { name: t.n, status: t.st, destination: t.dest || '', startDate: t.sd || '', endDate: t.ed || '', tags: t.tg || [], notes: S.notes[id] || '', version: t.rev,
        collaboratorIds: (t.col || []).map((n: string) => (STAFF.find(s => s.fullName === n) || { id: 0 }).id).filter(Boolean) };
      if (owner) body.ownerId = owner.id;
      const r = await call<any>('PUT', '/trips/' + t.db, body); toProto(r.trip); redraw();
    }
  } catch (e) { toast('Trip not saved: ' + msg(e)); try { const r = await call<any>('GET', '/trips/' + t.db); toProto(r.trip); redraw(); } catch { /* ignore */ } }
  finally { busy['t' + id] = false; if (again['t' + id]) { again['t' + id] = false; syncTrip(t); } }
}
function remapIds(it: any, map: Record<string, string>) {
  if (!map || !Object.keys(map).length) return;
  it.opts.forEach((o: any) => { if (map[o.id]) o.id = map[o.id]; o.days.forEach((d: any) => { if (map[d.id]) d.id = map[d.id]; d.acts.forEach((a: any) => { if (map[a.id]) a.id = map[a.id]; }); }); });
  if (map[X.ui.opt]) X.ui.opt = map[X.ui.opt]; if (it.sel && map[it.sel]) it.sel = map[it.sel];
}
async function syncItin(id: string): Promise<void> {
  const it = X.itx[id]; if (!it || it._loading || last.itin[id] === undefined) return;
  if (busy['i' + id]) { again['i' + id] = true; return; }
  const sent = itinKey(id); if (sent === last.itin[id]) return;
  busy['i' + id] = true; const t = tripById(id);
  try {
    const r = await call<any>('PUT', `/trips/${id.slice(1)}/itinerary`, { rev: it._rev, options: it.opts, meta: { pack: it.pack || [], guide: it.guide || '', prefs: (t && t.prefs) || '' } });
    if (itinKey(id) === sent) applyItin(id, r);
    else { remapIds(it, r.idMap); it._rev = r.rev; last.itin[id] = JSON.stringify([r.options, (r.meta && r.meta.pack) || [], (r.meta && r.meta.guide) || '', (r.meta && r.meta.prefs) || '']); again['i' + id] = true;
      it.pub = r.isPublished && r.published ? (r.published.hasUnpublishedChanges ? JSON.stringify(r.published.snapshot) : it.pub) : null; }
    redraw();
  } catch (e) {
    toast((e instanceof ApiError && e.status === 409) ? e.message : 'Itinerary not saved: ' + msg(e));
    try { const pl = await call<any>('GET', `/trips/${id.slice(1)}/itinerary`); applyItin(id, pl); redraw(); } catch { /* ignore */ }
  } finally { busy['i' + id] = false; if (again['i' + id]) { again['i' + id] = false; await syncItin(id); } }
}
export async function flush(id: string) { const t = tripById(id); if (t) await syncTrip(t); await syncItin(id); let n = 0; while ((busy['i' + id] || busy['t' + id]) && n++ < 100) await new Promise(r => setTimeout(r, 50)); }
let timer = 0;
function syncAll() { if (X.role === 'Traveler') return; S.trips.filter((t: any) => t.db).forEach((t: any) => { syncTrip(t); syncItin(t.id); }); }
export function scheduleSync() { clearTimeout(timer); timer = window.setTimeout(syncAll, 300); }
/* ---------------- traveler portal ---------------- */
async function loadPortal() {
  const l = await call<any>('GET', '/portal/trips'); const out: any[] = [];
  for (const it of l.items) { const d = (await call<any>('GET', '/portal/trips/' + it.id)).trip; out.push(d); }
  S.trips = []; X.itx = X.itx || {};
  out.forEach(applyPortalTrip);
  if (out.length) { S.cur = 'd' + out[0].id; X.ui.pv = 'dc' + l.contact.id; }
  return out.length;
}
function applyPortalTrip(d: any) {
  const id = 'd' + d.id; d.travelers.forEach(upsertContact);
  let p = S.trips.find((x: any) => x.id === id); if (!p) { p = { id, db: d.id, x: 1, log: [], type: 'standard', v: 0, tg: [], col: [] }; S.trips.push(p); }
  Object.assign(p, { n: d.name, c: '', st: d.status, sd: d.startDate || '', ed: d.endDate || '', dest: d.destination || '', ow: d.ownerName || '', pub: 1, tvx: d.travelers.map((r: any) => ({ cid: 'dc' + r.contactId, perm: PERM_IN[r.permission] })) });
  p.d = fdr(p.sd, p.ed);
  X.itx[id] = { opts: d.options, pub: JSON.stringify(d.options), sel: d.selectedOptionId, pack: d.packing || [], guide: d.guide || '', ver: [], docs: [], com: [], appr: { st: 'none' } };
  applyThread(id, d.activity); X.ui.pv = 'dc' + d.me;
}
async function portalAct(id: string, path: string, body: any, ok: string) {
  try { const r = await call<any>('POST', `/portal/trips/${id.slice(1)}/${path}`, body); applyPortalTrip(r.trip); cl(); toast(ok); draw(); }
  catch (e) { const er = document.getElementById('er'); if (er) er.textContent = msg(e); else toast(msg(e)); }
}
/* ---------------- dialogs that must use database contacts/staff ---------------- */
function remember(items: any[]) { items.forEach((c: any) => upsertContact({ contactId: c.id, firstName: c.firstName, lastName: c.lastName, email: c.email, phone: c.phone })); }
async function newTripDialog(pre: any) {
  if (!can('trips.edit')) { toast('Your role cannot create trips'); return; }
  let opts: [string, string][] = []; try { const r = await call<any>('GET', '/contacts?perPage=100'); remember(r.items); opts = r.items.map((c: any) => ['dc' + c.id, `${c.firstName} ${c.lastName || ''}`.trim() + (c.email ? ' · ' + c.email : '')]); } catch { /* empty list */ }
  const statuses = X.statuses.filter((s: string) => !['Archived', 'Cancelled'].includes(s) && ['Inquiry', 'Planning', 'Proposal', 'Approved', 'Booked', 'Traveling', 'Traveled'].includes(s));
  dlgW('New trip', `<div class="fm"><label>Trip name</label><input id="a1" value="${h((pre && pre.n) || '')}" placeholder="e.g. Tuscany Harvest Escape"><div class="g g2">
    <div><label>Client (database contact)</label>${sel('a2', [['', '— No client yet —'], ...opts], (pre && pre.cid) || '')}</div><div><label>Destination</label><input id="a3" value="${h((pre && pre.dest) || '')}" placeholder="City, Country"></div>
    <div><label>Start date</label><input type="date" id="a4"></div><div><label>End date</label><input type="date" id="a5"></div>
    <div><label>Owner</label>${sel('a6', STAFF.map(s => [String(s.id), s.fullName]), String(me.id), can('contacts.assign') ? '' : 'disabled')}</div><div><label>Status</label>${sel('a8', statuses, 'Inquiry')}</div>
    <div><label>Tag</label>${sel('a9', [['', '—'], ...X.tripTags], '')}</div></div><p class="mu" style="font-size:12px">Saved to the LTI database. Group trips are created from Group trips (connected in Phase 4).</p></div>`,
    'Create trip', async () => {
      const name = val('a1'); if (!name) { const e = document.getElementById('er'); if (e) e.textContent = 'Give the trip a name.'; return; }
      const cid = val('a2');
      try {
        const r = await call<any>('POST', '/trips', { name, primaryContactId: cid ? Number(cid.slice(2)) : null, destination: val('a3'), startDate: val('a4'), endDate: val('a5'), ownerId: Number(val('a6')) || me.id, status: val('a8'), tags: val('a9') ? [val('a9')] : [] });
        const p = toProto(r.trip); cl(); toast('Trip saved to the database'); S.cur = p.id; ensureItin(p.id, true); go('trip');
      } catch (e) { const er = document.getElementById('er'); if (er) er.textContent = msg(e); }
    }, 640);
}
async function addTravelerDialog(t: any) {
  let opts: [string, string][] = []; try { const r = await call<any>('GET', '/contacts?perPage=100'); remember(r.items); opts = r.items.filter((c: any) => !(t.tvx || []).some((v: any) => v.cid === 'dc' + c.id)).map((c: any) => ['dc' + c.id, `${c.firstName} ${c.lastName || ''}`.trim()]); } catch { /* none */ }
  dlg('Add traveler', `<label>Database contact</label>${sel('tva', [['', '— New contact —'], ...opts], '')}<div class="g g2"><div><label>First name (new)</label><input id="tvf"></div><div><label>Last name (new)</label><input id="tvl"></div></div><label>Email (new)</label><input id="tve" type="email"><label>Permission</label>${sel('tvp', [['Full', 'Full access'], ['Limited', 'Limited access'], ['Primary', 'Primary traveler']], (t.tvx || []).length ? 'Full' : 'Primary')}`,
    'Add', async () => {
      let cid = val('tva');
      try {
        if (!cid) { if (!val('tvf')) { const e = document.getElementById('er'); if (e) e.textContent = 'Choose a contact or enter a name.'; return; }
          const r = await call<any>('POST', '/contacts', { firstName: val('tvf'), lastName: val('tvl'), email: val('tve'), type: 'traveler' }); remember([r.contact]); cid = 'dc' + r.contact.id; }
        const perm = val('tvp'); t.tvx = (t.tvx || []).map((v: any) => perm === 'Primary' && v.perm === 'Primary' ? { ...v, perm: 'Full' } : v); t.tvx.push({ cid, perm });
        cl(); await syncTrip(t); toast('Traveler added');
      } catch (e) { const er = document.getElementById('er'); if (er) er.textContent = msg(e); }
    });
}
/* ---------------- labels & page hooks ---------------- */
const LATER: Record<string, string> = { Insurance: 'Phase 5', Payments: 'Phase 5', 'Service fees': 'Phase 5', Emails: 'Phase 6', Tasks: 'Phase 6', Automations: 'Phase 6', Workflows: 'Phase 6', Activity: 'Phase 6 (approvals and comments under Proposal are live)' };
/** 'live' = no label; string = custom label; null = default prototype label. */
export function pageNote(): string | null {
  if (X.role === 'Traveler') return 'live';
  if (S.pg === 'trips' || S.pg === 'itinerary') return 'live';
  if (S.pg === 'trip' && isDb(S.cur)) return LATER[S.tsec] ? `This trip is a live database record. The ${S.tsec} section is still prototype sample data and connects in ${LATER[S.tsec]}.` : 'live';
  if (S.pg === 'portal' && isDb(S.cur)) return 'Live preview of the published version stored in the database. Approvals and comments can only be submitted by travelers from their own portal sign-in.';
  if (S.pg === 'dashboard') return 'Database trips appear in the trip lists here; all other dashboard figures are prototype sample data until their phases connect. Contacts, Staff accounts, Trips, Itineraries, the traveler portal, Group trips, Suppliers, Bookings, Documents, Forms and the content library (activities, packing lists) are live.';
  return null;
}
export function contactTripsCard(contactId: number): string {
  const list = S.trips.filter((t: any) => t.db && (t.cid === 'dc' + contactId || (t.tvx || []).some((v: any) => v.cid === 'dc' + contactId)));
  return cd(`Trips (${list.length})`, list.length ? `<div class="tw"><table class="t2"><thead><tr><th>Trip</th><th>Dates</th><th>Status</th><th>Role</th></tr></thead><tbody>${list.map((t: any) => `<tr class="c" onclick="S.cur='${t.id}';go('trip')"><td><span class="lk">${h(t.n)}</span></td><td>${h(fdr(t.sd, t.ed))}</td><td>${pill(t.st)}</td><td>${h(((t.tvx || []).find((v: any) => v.cid === 'dc' + contactId) || { perm: 'Primary' }).perm)}</td></tr>`).join('')}</tbody></table></div>` : emp('plane', 'No trips yet'),
    can('trips.edit') ? `<button class="btn s" onclick="addT({cid:'dc${contactId}'})">New trip</button>` : '', 1);
}
const withDb = (fn: () => string) => { const all = S.trips; S.trips = all.filter((t: any) => t.db); try { return fn(); } finally { S.trips = all; } };
export async function installTrips(user: any, perms: (p: string) => boolean) {
  me = user; can = perms;
  try { STAFF = (await call<any>('GET', '/contacts/staff-options')).items; } catch { STAFF = []; }
  const demoUsers = S.users;
  if (STAFF.length) S.users = STAFF.map(s => [s.fullName, '', '', 0, 1, '']);
  // never persist database records; travelers never persist anything
  const origPersist = w.persist;
  w.persist = function () {
    if (X.role === 'Traveler') return;
    const keep = { trips: S.trips, cm: S.cm, users: S.users, itx: X.itx, act: X.activity, notifs: X.notifs, outbox: X.outbox, tasks: S.tasks, notes: S.notes, cur: S.cur };
    S.trips = keep.trips.filter((t: any) => !t.db); S.cm = keep.cm.filter((c: any) => !c.db); S.users = demoUsers;
    X.itx = Object.fromEntries(Object.entries(keep.itx).filter(([k]) => !isDb(k)));
    X.activity = keep.act.filter((a: any) => !isDbRef(a.ref)); X.notifs = keep.notifs.filter((a: any) => !isDbRef(a.ref)); X.outbox = keep.outbox.filter((a: any) => !isDbRef(a.ref));
    S.tasks = keep.tasks.filter((k: any) => !isDbAny(k.rid)); S.notes = Object.fromEntries(Object.entries(keep.notes || {}).filter(([k]) => !isDbAny(k) && !/^cdc\d+$/.test(k)));
    if (isDb(S.cur)) S.cur = 't1';
    try { origPersist(); } finally { S.trips = keep.trips; S.cm = keep.cm; S.users = keep.users; X.itx = keep.itx; X.activity = keep.act; X.notifs = keep.notifs; X.outbox = keep.outbox; S.tasks = keep.tasks; S.notes = keep.notes; S.cur = keep.cur; }
  };
  // prototype automations (Phase 6) must not act on database records
  const origFire = w.fire; w.fire = function (ev: string, rt: string, id: string, o: any) { return isDbAny(id) ? [] : origFire(ev, rt, id, o); };
  const origRecords = w.recordsFor; w.recordsFor = function (a: any) { return origRecords(a).filter((r: any[]) => !isDbAny(r[1])); };
  // page wrappers
  const pTrips = Pg.trips; Pg.trips = () => { const all = S.trips; S.trips = all.filter((t: any) => t.db && (t.type || 'standard') === 'standard'); try { return pTrips(); } finally { S.trips = all; } };
  const pItin = Pg.itinerary; Pg.itinerary = () => { if (!isDb(S.cur)) { const f = S.trips.find((t: any) => t.db && t.st !== 'Archived'); if (f) S.cur = f.id; else return hd('Itinerary editor') + emp('plane', 'No database trips yet', 'Create a trip to start an itinerary.', `<button class="btn" onclick="addT()">New trip</button>`); } ensureItin(S.cur); return withDb(pItin); };
  const pTrip = Pg.trip; Pg.trip = () => { if (isDb(S.cur)) ensureItin(S.cur); return pTrip(); };
  const pPortal = Pg.portal; Pg.portal = () => {
    if (X.role === 'Traveler') { if (!S.trips.length) return hd('Your trips') + emp('plane', 'No published trips yet', 'Your advisor will let you know when your itinerary is ready.', `<button class="btn o" onclick="ltiLogout()">Sign out</button>`); return pPortal(); }
    if (!isDb(S.cur)) { const f = S.trips.find((t: any) => t.db && t.pub); if (f) S.cur = f.id; else return hd('Client portal') + emp('eye', 'No published database trips yet', 'Publish an itinerary to preview what travelers see.'); }
    ensureItin(S.cur); return withDb(pPortal);
  };
  // actions
  const o = { addT: w.addT, tArchive: w.tArchive, tdel: w.tdel, tdup: w.tdup, tgrp: w.tgrp, tpub: w.tpub, itPublish: w.itPublish, trvAdd: w.trvAdd, itVersions: w.itVersions, itReply: w.itReply, ptChoose: w.ptChoose, ptApprove: w.ptApprove, ptEdits: w.ptEdits, ptComment: w.ptComment };
  w.addT = (pre: any) => { if (X.role === 'Traveler') return; return newTripDialog(pre || {}); };
  w.tdel = () => { if (!isDb(S.cur)) return o.tdel(); S.menu = null; const t = tripById(S.cur); if (!can('trips.delete')) { toast('Your role cannot archive trips'); return; }
    dlg('Archive trip', `<p>Database trips are archived rather than deleted, so bookings, itineraries and history stay intact. Archive <b>${h(t.n)}</b>?</p>`, 'Archive', async () => { try { const r = await call<any>('POST', `/trips/${t.db}/archive`); toProto(r.trip); cl(); toast('Trip archived'); go('trips'); } catch (e) { const er = document.getElementById('er'); if (er) er.textContent = msg(e); } }); };
  w.tArchive = () => { if (!isDb(S.cur)) return o.tArchive(); S.menu = null; const t = tripById(S.cur); if (!can('trips.delete')) { toast('Your role cannot archive trips'); return; } call<any>('POST', `/trips/${t.db}/archive`).then(r => { toProto(r.trip); toast('Trip archived'); draw(); }).catch(e => toast(msg(e))); };
  w.tdup = async () => { if (!isDb(S.cur)) return o.tdup(); S.menu = null; const t = tripById(S.cur); await flush(t.id);
    try { const r = await call<any>('POST', '/trips', { name: t.n + ' (copy)', destination: t.dest, startDate: t.sd, endDate: t.ed, tags: t.tg, primaryContactId: t.cid ? Number(t.cid.slice(2)) : null });
      const it = X.itx[t.id]; const copy = it && !it._loading ? JSON.parse(JSON.stringify(it.opts)).map((op: any, i: number) => ({ ...op, id: 'n' + i, days: op.days.map((d: any, j: number) => ({ ...d, id: 'n' + i + '_' + j, acts: d.acts.map((a: any, k: number) => ({ ...a, id: 'n' + i + '_' + j + '_' + k })) })) })) : null;
      if (copy) { const pl = await call<any>('GET', `/trips/${r.trip.id}/itinerary`); await call<any>('PUT', `/trips/${r.trip.id}/itinerary`, { rev: pl.rev, options: copy, meta: { pack: it.pack || [], guide: it.guide || '', prefs: t.prefs || '' } }); }
      const p = toProto(r.trip); S.cur = p.id; ensureItin(p.id, true); toast('Trip duplicated in the database (approvals and published versions are not copied)'); go('trip');
    } catch (e) { toast(msg(e)); } };
  w.tgrp = () => { if (isDb(S.cur)) { S.menu = null; toast('Moving database trips into groups arrives with group trips in Phase 4'); draw(); return; } o.tgrp(); };
  w.itPublish = async (id: string) => { if (!isDb(id)) return o.itPublish(id); if (!can('trips.publish')) { toast('Your role cannot publish trips'); return; }
    try { await flush(id); const pl = await call<any>('POST', `/trips/${id.slice(1)}/publish`); applyItin(id, pl); toast('Published to the client portal'); draw(); } catch (e) { toast(msg(e)); } };
  w.tpub = (a: number) => { const t = tripById(S.cur); if (!t || !isDb(t.id)) return o.tpub(a); S.menu = null; if (a !== 0) return w.itPublish(t.id);
    if (!can('trips.publish')) { toast('Your role cannot unpublish trips'); return; } call<any>('POST', `/trips/${t.db}/unpublish`).then(pl => { applyItin(t.id, pl); toast('Unpublished — travelers no longer see this trip'); draw(); }).catch(e => toast(msg(e))); };
  w.trvAdd = () => { const t = tripById(S.cur); if (!t || !isDb(t.id)) return o.trvAdd(); addTravelerDialog(t); };
  w.itVersions = async () => { if (!isDb(S.cur)) return o.itVersions(); const it = X.itx[S.cur];
    try { for (const v of it.ver.slice(0, 15)) if (!snapCache[v.vid]) { snapCache[v.vid] = (await call<any>('GET', `/trips/${S.cur.slice(1)}/versions/${v.vid}`)).version.snapshot; v.snap = JSON.stringify(snapCache[v.vid]); } } catch (e) { toast(msg(e)); }
    o.itVersions(); };
  w.itReply = (id: string) => { if (!isDb(id)) return o.itReply(id); dlg('Reply to client comments', '<label>Message (visible on the traveler portal)</label><textarea id="a1" rows="3"></textarea>', 'Post reply', async () => {
    const m = val('a1'); if (!m) return; try { const r = await call<any>('POST', `/trips/${id.slice(1)}/comments`, { message: m }); applyThread(id, r.activity); cl(); draw(); } catch (e) { const er = document.getElementById('er'); if (er) er.textContent = msg(e); } }); };
  const preview = () => toast('Preview only: travelers approve, choose options and comment from their own portal sign-in.');
  w.ptChoose = (id: string, oid: string) => { if (!isDb(id)) return o.ptChoose(id, oid); if (X.role !== 'Traveler') return preview(); portalAct(id, 'select', { optionId: oid }, 'Option selected — your advisor has been notified'); };
  w.ptApprove = (id: string, oid: string) => { if (!isDb(id)) return o.ptApprove(id, oid); if (X.role !== 'Traveler') return preview(); const it = X.itx[id], t = tripById(id);
    dlg('Approve trip', `<p>Approve <b>${h(t.n)}</b>${it.opts.length > 1 ? ' with “' + h((it.opts.find((x: any) => x.id === (it.sel || oid)) || { name: '' }).name) + '”' : ''}? Your advisor will start booking.</p>`, 'Approve', () => portalAct(id, 'approve', { optionId: it.sel || oid }, 'Thank you! Trip approved')); };
  w.ptEdits = (id: string) => { if (!isDb(id)) return o.ptEdits(id); if (X.role !== 'Traveler') return preview();
    dlg('Request changes', '<label>What would you like to change?</label><textarea id="a1" rows="4"></textarea>', 'Send request', () => { const m = val('a1'); if (!m) { const e = document.getElementById('er'); if (e) e.textContent = 'Describe the change.'; return; } portalAct(id, 'request-changes', { message: m }, 'Request sent to your advisor'); }); };
  w.ptComment = (id: string) => { if (!isDb(id)) return o.ptComment(id); if (X.role !== 'Traveler') return preview(); const m = val('pcm'); if (!m) return; portalAct(id, 'comments', { message: m }, 'Comment posted'); };
  // initial data
  if (X.role === 'Traveler') await loadPortal(); else { await loadAllTrips(); window.setInterval(syncAll, 3000); }
}
export function afterDraw() {
  if (X.role === 'Traveler') { const s = document.querySelector('.pctl select[aria-label="Viewing as traveler"]') as HTMLElement | null; if (s) { const lab = s.previousElementSibling; if (lab && lab.tagName === 'LABEL') lab.remove(); s.remove(); } return; }
  scheduleSync();
}
