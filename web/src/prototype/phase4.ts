// Phase 4 (increment 1): connects the restored Suppliers, Bookings and Documents screens to the PHP API.
// Records are held in memory only (stripped before the prototype writes sample data to browser storage).
import { api, ApiError, getCsrf } from '../api';
const w = window as any;
let can: (p: string) => boolean = () => false;
const h = (s: unknown) => esc(s == null ? '' : s);
const msg = (e: unknown) => e instanceof ApiError ? e.message : 'Request failed';
const errBox = (m: string) => { const e = document.getElementById('er'); if (e) e.textContent = m; else toast(m); };
async function call<T>(m: string, p: string, b?: unknown): Promise<T> {
  try { return await api<T>(m, p, b); } catch (e) { if (e instanceof ApiError && e.status === 401) { toast('Your session has ended. Please sign in again.'); setTimeout(() => { location.hash = '#/login'; location.reload(); }, 900); } throw e; }
}
const dialogOpen = () => !!(document.getElementById('ov') && document.getElementById('ov')!.innerHTML.trim());
const redraw = () => { if (!dialogOpen()) draw(); };
/* ---------- labels ---------- */
const SUP_CAT: Record<string, string> = { hotel: 'Lodging', airline: 'Air Travel', cruise: 'Cruises', ground_transport: 'Transportation', tour_operator: 'Packaged Travel', restaurant: 'Dining & Drinks', dmc: 'DMC', activity: 'Activities', insurance: 'Trip Protection', other: 'Other' };
const BK_TYPE: Record<string, string> = { accommodation: 'Hotel', flight: 'Flight', cruise: 'Cruise', transfer: 'Transfer', activity: 'Activity', tour: 'Tour', dining: 'Dining', insurance: 'Insurance', other: 'Other' };
const DOC_CAT: Record<string, string> = { travel_documents: 'Travel documents', passports_ids: 'Passports & IDs', insurance: 'Insurance', invoices_receipts: 'Invoices & receipts', supplier: 'Supplier documents', contracts: 'Contracts', images: 'Images', other: 'Other' };
const fmtSize = (b: number) => b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
/* ---------- suppliers ---------- */
const SUP: Record<number, any> = {}; const supKey: Record<number, string> = {}; const supBusy: Record<number, boolean> = {};
const supSig = (id: number) => { const s = SUP[id]; const arr = S.sup.find((x: any) => x.db === id); const x = X.supx[s.name] || {}; return JSON.stringify([arr ? arr[1] : 0, x.contact || '', x.email || '', x.phone || '', String(x.rate ?? ''), x.notes || '']); };
function applySupplier(s: any) {
  s._full = !!(s._full || s.contacts && s.contacts.length || (SUP[s.id] && SUP[s.id]._full)); SUP[s.id] = s; S.sup = S.sup.filter((x: any) => x.db !== s.id);
  if (s.status === 'active') { const arr: any = [s.name, s.isPreferred ? 1 : 0, 'ds' + s.id, SUP_CAT[s.category] || 'Other', s.destination || '', 1]; arr.db = s.id; S.sup.push(arr); }
  const primary = (s.contacts || []).find((c: any) => c.isPrimary) || (s.contacts || [])[0];
  X.supx[s.name] = Object.assign(X.supx[s.name] || { content: [] }, { _db: s.id, contact: primary ? primary.name : (X.supx[s.name] && X.supx[s.name]._db === s.id ? X.supx[s.name].contact : ''), email: s.email || '', phone: s.phone || '', rate: s.commissionRate ?? '', notes: s.notes || '' });
  supKey[s.id] = supSig(s.id);
}
async function loadSuppliers() { const r = await call<any>('GET', '/suppliers?status=all&perPage=200'); S.sup = S.sup.filter((x: any) => !x.db); r.items.forEach((s: any) => applySupplier({ ...s, contacts: [] })); }
async function syncSupplier(id: number) {
  if (supBusy[id] || !SUP[id] || SUP[id].status !== 'active') return; const sig = supSig(id); if (sig === supKey[id]) return; supBusy[id] = true;
  const s = SUP[id], arr = S.sup.find((x: any) => x.db === id), x = X.supx[s.name] || {};
  try {
    const r = await call<any>('PUT', '/suppliers/' + id, { name: s.name, category: s.category, email: x.email || '', phone: x.phone || '', commissionRate: x.rate === '' ? null : x.rate, notes: x.notes || '', isPreferred: !!(arr && arr[1]), version: s.version });
    let sup = r.supplier;
    if ((x.contact || '') !== ((sup.contacts.find((c: any) => c.isPrimary) || {}).name || '')) sup = (await call<any>('PUT', `/suppliers/${id}/contacts`, { contacts: x.contact ? [{ name: x.contact, email: x.email || '', isPrimary: true }] : [] })).supplier;
    applySupplier(sup); redraw();
  } catch (e) { toast('Supplier not saved: ' + msg(e)); try { applySupplier((await call<any>('GET', '/suppliers/' + id)).supplier); redraw(); } catch { /* ignore */ } }
  finally { supBusy[id] = false; }
}
function supplierDialog() {
  if (!can('suppliers.manage')) { toast('Your role cannot add suppliers'); return; }
  dlg('New supplier', `<label>Name</label><input id="sa1" maxlength="190"><label>Category</label>${sel('sa2', Object.entries(SUP_CAT).map(([k, v]) => [k, v]), 'hotel')}<div class="g g2"><div><label>Commission rate %</label><input id="sa3" type="number" min="0" max="100" value="10"></div><div><label>Destination</label><input id="sa6"></div></div><label>Email</label><input id="sa4" type="email"><label>Phone</label><input id="sa5" type="tel"><label>Contract reference</label><input id="sa7"><label class="ck" style="font-size:13px"><input type="checkbox" id="sa8"> Preferred supplier</label><p class="mu" style="font-size:12px">Saved to the LTI database.</p>`,
    'Create', async () => { const name = val('sa1'); if (!name) return errBox('Enter a name.');
      try { const r = await call<any>('POST', '/suppliers', { name, category: val('sa2'), commissionRate: val('sa3'), email: val('sa4'), phone: val('sa5'), destination: val('sa6'), contractReference: val('sa7'), isPreferred: (document.getElementById('sa8') as HTMLInputElement).checked });
        applySupplier(r.supplier); cl(); toast('Supplier saved to the database'); X.ui.sp = r.supplier.name; draw(); } catch (e) { errBox(msg(e)); } });
}
/* ---------- bookings ---------- */
const BK: Record<number, any> = {};
function applyBooking(b: any) {
  b._full = !!(b.history || (BK[b.id] && BK[b.id]._full)); BK[b.id] = b; X.bookings = X.bookings.filter((x: any) => x.db !== b.id);
  const t = tripById('d' + b.tripId); const prev = X.bookings.find((x: any) => x.ref === b.reference);
  X.bookings.push({ ref: b.reference, db: b.id, tripId: 'd' + b.tripId, tripName: b.tripName, client: t ? t.c : b.tripName, supplier: b.supplierName || '', type: BK_TYPE[b.category] || 'Other', amt: b.amount, st: b.status === 'confirmed' ? 'Confirmed' : 'Pending',
    date: b.startDate || '', end: b.endDate || '', conf: b.confirmationNumber || '', comm: b.commissionExpected, rate: b.commissionRate ?? 0, agent: b.agentName || '', trv: (b.travelerIds || (b.travelers || []).map((x: any) => x.contactId)).map((i: number) => 'dc' + i),
    docs: [], hist: (b.history || (prev && prev.hist) || []).map((x: any) => x.txt ? x : { at: String(x.at).slice(0, 10), txt: x.action.replace('booking.', '').replace('_', ' ') + (x.by ? ' · ' + x.by : '') }), cxl: b.status === 'cancelled' ? 1 : 0, notes: b.notes || '' });
}
async function loadBookings() { const r = await call<any>('GET', '/bookings?perPage=200'); X.bookings = X.bookings.filter((x: any) => !x.db); r.items.forEach(applyBooking); }
async function refreshBooking(id: number) { const r = await call<any>('GET', '/bookings/' + id); applyBooking(r.booking); return r.booking; }
let tripCache: any[] = [];
async function bookingDialog(ref?: string, pre?: any) {
  if (!can('bookings.manage')) { toast('Your role cannot create or edit bookings'); return; }
  const cur = ref ? X.bookings.find((x: any) => x.ref === ref) : null; const b = cur ? BK[cur.db] : null;
  if (b && b.status === 'cancelled') { toast('Cancelled bookings cannot be edited'); return; }
  try { tripCache = (await call<any>('GET', '/trips?type=all&perPage=200')).items; } catch (e) { toast(msg(e)); return; }
  const sups = Object.values(SUP).filter((s: any) => s.status === 'active' || (b && s.id === b.supplierId));
  const tripId = b ? b.tripId : (pre && pre.tripId && /^d\d+$/.test(pre.tripId) ? Number(pre.tripId.slice(1)) : '');
  const tvBox = (tid: any, chosen: number[]) => { const t = tripCache.find(x => x.id === Number(tid)); return t && t.travelers.length ? t.travelers.map((v: any) => `<label class="ck" style="font-size:13px"><input type="checkbox" class="bkt" value="${v.contactId}" ${chosen.includes(v.contactId) ? 'checked' : ''}> ${h(v.firstName)} ${h(v.lastName || '')}</label>`).join('') : '<span class="mu" style="font-size:12px">No travelers on this trip yet.</span>'; };
  w.bkTrv = (tid: string) => { const el = document.getElementById('bktv'); if (el) el.innerHTML = tvBox(tid, []); };
  dlgW(b ? 'Edit booking ' + b.reference : 'New booking', `<div class="fm"><div class="g g2">
    <div><label>Trip</label>${sel('bk1', [['', '— Choose trip —'], ...tripCache.filter(t => t.status !== 'Archived').map(t => [String(t.id), t.name + (t.tripType === 'group' ? ' (group)' : t.tripType === 'sub' ? ' (sub-trip)' : '')])], String(tripId), 'onchange="bkTrv(this.value)"')}</div>
    <div><label>Supplier</label>${sel('bk2', [['', '— None —'], ...sups.map((s: any) => [String(s.id), s.name])], b && b.supplierId ? String(b.supplierId) : '')}</div>
    <div><label>Category</label>${sel('bk3', Object.entries(BK_TYPE).map(([k, v]) => [k, v]), b ? b.category : 'accommodation')}</div>
    <div><label>Status</label>${sel('bk4', [['pending', 'Pending'], ['confirmed', 'Confirmed']], b ? b.status : 'pending')}</div>
    <div><label>Start date</label><input type="date" id="bk5" value="${h(b ? b.startDate || '' : '')}"></div><div><label>End date</label><input type="date" id="bk6" value="${h(b ? b.endDate || '' : '')}"></div>
    <div><label>Amount</label><input type="number" min="0" step="0.01" id="bk7" value="${b ? b.amount : ''}"></div><div><label>Commission rate %</label><input type="number" min="0" max="100" step="0.01" id="bk8" value="${b && b.commissionRate !== null ? b.commissionRate : ''}"></div>
    <div><label>Confirmation number</label><input id="bk9" value="${h(b ? b.confirmationNumber || '' : '')}"></div><div><label>Confirmation details</label><input id="bk10" value="${h(b ? b.confirmationDetails || '' : '')}"></div></div>
    <label>Travelers on this booking</label><div id="bktv" class="row" style="gap:12px">${tvBox(tripId, b ? (b.travelers || []).map((x: any) => x.contactId) : [])}</div>
    <label>Internal notes</label><textarea id="bk11" rows="2">${h(b ? b.notes || '' : '')}</textarea><p class="mu" style="font-size:12px">Saved to the LTI database. Payments and commission statements connect in Phase 5.</p></div>`,
    b ? 'Save booking' : 'Create booking', async () => {
      const body: any = { tripId: Number(val('bk1')) || 0, supplierId: val('bk2') ? Number(val('bk2')) : null, category: val('bk3'), status: val('bk4'), startDate: val('bk5'), endDate: val('bk6'), amount: val('bk7'), commissionRate: val('bk8'),
        confirmationNumber: val('bk9'), confirmationDetails: val('bk10'), notes: val('bk11'), travelerIds: Array.from(document.querySelectorAll<HTMLInputElement>('.bkt:checked')).map(e => Number(e.value)) };
      if (!body.tripId) return errBox('Choose a trip.'); if (b) body.version = b.version;
      try { const r = await call<any>(b ? 'PUT' : 'POST', b ? '/bookings/' + b.id : '/bookings', body); applyBooking(r.booking); cl(); toast(b ? 'Booking updated' : 'Booking saved to the database'); X.ui.bkOpen = r.booking.reference; draw(); } catch (e) { errBox(msg(e)); }
    }, 720);
}
/* ---------- documents ---------- */
const DOCS: Record<number, any> = {};
function applyDoc(d: any) {
  DOCS[d.id] = d; X.docs = X.docs.filter((x: any) => x.db !== d.id); if (d.archivedAt) return;
  const bk = d.bookingId ? X.bookings.find((x: any) => x.db === d.bookingId) : null;
  X.docs.push({ id: 'dd' + d.id, db: d.id, n: d.name, cat: DOC_CAT[d.category] || 'Other', kind: d.mimeType === 'application/pdf' ? 'pdf' : d.mimeType.indexOf('image/') === 0 ? 'image' : 'file', size: fmtSize(d.size), trip: d.tripId ? 'd' + d.tripId : '', contact: d.contactId ? 'dc' + d.contactId : '',
    bk: bk ? bk.ref : '', supplierId: d.supplierId, vis: d.visibility, at: String(d.createdAt).slice(0, 10), by: d.uploadedBy || '', data: null });
}
async function loadDocs() { if (!can('documents.view')) return; const r = await call<any>('GET', '/documents'); X.docs = X.docs.filter((x: any) => !x.db); r.items.forEach(applyDoc); }
async function uploadDialog(pre: any) {
  if (!can('documents.manage')) { toast('Your role cannot upload documents'); return; }
  let trips: any[] = [], contacts: any[] = []; try { [trips, contacts] = await Promise.all([call<any>('GET', '/trips?type=all&perPage=200').then(r => r.items), call<any>('GET', '/contacts?perPage=100').then(r => r.items)]); } catch (e) { toast(msg(e)); return; }
  const preTrip = pre && pre.trip && /^d\d+$/.test(pre.trip) ? pre.trip.slice(1) : ''; const preBk = pre && pre.bk ? (X.bookings.find((x: any) => x.ref === pre.bk) || {}).db || '' : '';
  dlgW('Upload document', `<div class="fm"><label>File (PDF, images, Word, Excel, text or CSV; up to 10 MB)</label><input type="file" id="du1" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.xls,.xlsx,.txt,.csv"><label>Name</label><input id="du0" placeholder="Defaults to the file name">
    <div class="g g2"><div><label>Category</label>${sel('du2', Object.entries(DOC_CAT), preBk ? 'travel_documents' : 'other')}</div><div><label>Visibility</label>${sel('du3', [['internal', 'Internal'], ['client', 'Client-visible']], 'internal')}</div>
    <div><label>Trip</label>${sel('du4', [['', '—'], ...trips.map(t => [String(t.id), t.name])], String(preTrip))}</div><div><label>Contact</label>${sel('du5', [['', '—'], ...contacts.map((c: any) => [String(c.id), `${c.firstName} ${c.lastName || ''}`.trim()])], pre && pre.contact && /^dc\d+$/.test(pre.contact) ? pre.contact.slice(2) : '')}</div>
    <div><label>Booking</label>${sel('du6', [['', '—'], ...Object.values(BK).filter((b: any) => b.status !== 'cancelled').map((b: any) => [String(b.id), b.reference + ' · ' + (b.supplierName || b.tripName)])], String(preBk))}</div><div><label>Supplier</label>${sel('du7', [['', '—'], ...Object.values(SUP).filter((s: any) => s.status === 'active').map((s: any) => [String(s.id), s.name])], '')}</div></div>
    <p class="mu" style="font-size:12px">Stored privately on the server and downloadable only by permitted staff (and, if client-visible, by travelers on the linked trip).</p></div>`, 'Upload', async () => {
    const f = (document.getElementById('du1') as HTMLInputElement).files; if (!f || !f.length) return errBox('Choose a file.');
    const fd = new FormData(); fd.append('file', f[0]); fd.append('title', val('du0')); fd.append('category', val('du2')); fd.append('visibility', val('du3'));
    [['tripId', 'du4'], ['contactId', 'du5'], ['bookingId', 'du6'], ['supplierId', 'du7']].forEach(([k, id]) => { if (val(id)) fd.append(k, val(id)); });
    try { const res = await fetch('./api/documents', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': getCsrf(), Accept: 'application/json' }, body: fd }); const d = await res.json().catch(() => ({}));
      if (!res.ok) return errBox(d.error || 'Upload failed (' + res.status + ')'); applyDoc(d.document); cl(); toast('Document uploaded'); draw(); } catch { errBox('Upload failed. Check your connection.'); }
  }, 640);
}
/* ---------- install ---------- */
const withDbOnly = (fn: () => string) => { const s = S.sup, b = X.bookings, d = X.docs; S.sup = s.filter((x: any) => x.db); X.bookings = b.filter((x: any) => x.db); X.docs = d.filter((x: any) => x.db); try { return fn(); } finally { S.sup = s; X.bookings = b; X.docs = d; } };
export const LIVE4 = ['bookings', 'suppliers', 'docs'];
export async function installPhase4(perms: (p: string) => boolean) {
  can = perms; if (X.role === 'Traveler') return;
  const prevPersist = w.persist;
  w.persist = function () { const keep = { sup: S.sup, supx: X.supx, bk: X.bookings, docs: X.docs, sp: X.ui.sp, bko: X.ui.bkOpen };
    if (Object.values(SUP).some((x: any) => x.name === X.ui.sp)) X.ui.sp = '';
    // prototype screens derive records (e.g. expected commission) from bookings; drop any that copy database values
    const marks = [...Object.values(BK).flatMap((b: any) => [b.reference, b.confirmationNumber].filter(Boolean)), ...Object.values(SUP).map((x: any) => x.name)].map(v => JSON.stringify(String(v)));
    const derived: Record<string, any[]> = {};
    if (marks.length) Object.keys(X).forEach(k => { const a = X[k]; if (!Array.isArray(a) || ['bookings', 'docs', 'forms', 'responses', 'lib', 'packing'].includes(k)) return;
      const f = a.filter((it: any) => { const j = JSON.stringify(it); return !marks.some(m => j.includes(m)); }); if (f.length !== a.length) { derived[k] = a; X[k] = f; } }); if (X.bookings.some((x: any) => x.db && x.ref === X.ui.bkOpen)) X.ui.bkOpen = '';
    S.sup = keep.sup.filter((x: any) => !x.db); X.supx = Object.fromEntries(Object.entries(keep.supx).filter(([, v]: any) => !v || !v._db)); X.bookings = keep.bk.filter((x: any) => !x.db); X.docs = keep.docs.filter((x: any) => !x.db);
    try { prevPersist(); } finally { S.sup = keep.sup; X.supx = keep.supx; X.bookings = keep.bk; X.docs = keep.docs; X.ui.sp = keep.sp; X.ui.bkOpen = keep.bko; Object.keys(derived).forEach(k => { X[k] = derived[k]; }); } };
  const pS = Pg.suppliers; Pg.suppliers = () => {
    const name = X.ui.sp; const s = name ? Object.values(SUP).find((x: any) => x.name === name) as any : null;
    if (s && !s._full) { s._full = 1; call<any>('GET', '/suppliers/' + s.id).then(r => { applySupplier({ ...r.supplier, _full: 1 }); redraw(); }).catch(() => {}); }
    let html = withDbOnly(pS);
    if (s && can('suppliers.manage')) html = html.replace('← Suppliers</button>', `← Suppliers</button><button class="btn o" onclick="supArchive(${s.id})">Archive supplier</button>`);
    if (s && s.contractReference) html = html.replace('</h1>', `</h1><p class="mu" style="font-size:12px">Contract ${h(s.contractReference)}${s.destination ? ' · ' + h(s.destination) : ''}</p>`);
    return html; };
  const pB = Pg.bookings; Pg.bookings = () => { const o = X.ui.bkOpen && X.bookings.find((x: any) => x.ref === X.ui.bkOpen && x.db); if (o && !BK[o.db]._full) { BK[o.db]._full = true; refreshBooking(o.db).then(redraw).catch(() => {}); } return withDbOnly(pB); };
  const pD = Pg.docs; Pg.docs = () => withDbOnly(pD);
  const o = { supAdd: w.supAdd, bkEdit: w.bkEdit, bkConfirm: w.bkConfirm, bkCancel: w.bkCancel, payAdd: w.payAdd, docUpload: w.docUpload, docEdit: w.docEdit, docDel: w.docDel, docPreview: w.docPreview };
  w.supAdd = () => supplierDialog();
  w.supArchive = (id: number) => { const s = SUP[id]; dlg('Archive supplier', `<p>Archive <b>${h(s.name)}</b>? Existing bookings keep their link to this supplier; it can no longer be chosen for new bookings.</p>`, 'Archive', async () => {
    try { const r = await call<any>('POST', `/suppliers/${id}/archive`); applySupplier(r.supplier); cl(); X.ui.sp = ''; toast('Supplier archived'); draw(); } catch (e) { errBox(msg(e)); } }); };
  w.bkEdit = (ref: string, pre: any) => { const b = ref ? X.bookings.find((x: any) => x.ref === ref) : null; if (b && !b.db) return o.bkEdit(ref, pre); return bookingDialog(ref || undefined, pre); };
  w.bkConfirm = (ref: string) => { const b = X.bookings.find((x: any) => x.ref === ref); if (!b || !b.db) return o.bkConfirm(ref); if (!can('bookings.manage')) return toast('Your role cannot confirm bookings');
    dlg('Confirm booking ' + ref, `<label>Supplier confirmation number</label><input id="a1" value="${h(b.conf)}">`, 'Mark confirmed', async () => { if (!val('a1')) return errBox('Enter the confirmation number.');
      try { const r = await call<any>('PUT', '/bookings/' + b.db, { status: 'confirmed', confirmationNumber: val('a1'), version: BK[b.db].version }); applyBooking(r.booking); cl(); toast('Booking confirmed'); draw(); } catch (e) { errBox(msg(e)); } }); };
  w.bkCancel = (ref: string) => { const b = X.bookings.find((x: any) => x.ref === ref); if (!b || !b.db) return o.bkCancel(ref); if (!can('bookings.manage')) return toast('Your role cannot cancel bookings');
    dlg('Cancel booking ' + ref, `<p>Cancel ${h(b.supplier || b.type)}? The booking is kept for history and becomes read-only.</p><label>Reason</label><input id="a1" placeholder="e.g. client changed dates">`, 'Cancel booking', async () => {
      try { const r = await call<any>('POST', `/bookings/${b.db}/cancel`, { reason: val('a1') }); applyBooking(r.booking); cl(); toast('Booking cancelled'); draw(); } catch (e) { errBox(msg(e)); } }); };
  w.payAdd = (ref: string) => { const b = X.bookings.find((x: any) => x.ref === ref); if (b && b.db) return toast('Payment records for database bookings connect in Phase 5'); return o.payAdd(ref); };
  w.docUpload = (pre: any) => uploadDialog(pre || {});
  w.docPreview = (id: string) => { const d = X.docs.find((x: any) => x.id === id); if (!d || !d.db) return o.docPreview(id); const url = `./api/documents/${d.db}/download`;
    dlgW(h(d.n), (d.kind === 'image' ? `<img src="${url}?inline=1" alt="${h(d.n)}" style="max-width:100%;border-radius:6px">` : d.kind === 'pdf' ? `<iframe title="${h(d.n)}" src="${url}?inline=1" style="width:100%;height:60vh;border:1px solid var(--bd);border-radius:6px"></iframe>` : `<p class="mu">Preview is not available for this file type.</p>`)
      + `<p class="mu" style="font-size:12px">${h(d.cat)} · ${h(d.size)} · added ${h(d.at)}${d.by ? ' by ' + h(d.by) : ''}</p><a class="btn" href="${url}" download>Download</a>`, null, null, 760); };
  w.docEdit = (id: string) => { const d = X.docs.find((x: any) => x.id === id); if (!d || !d.db) return o.docEdit(id); const m = DOCS[d.db];
    dlg('Edit document', `<label>Name</label><input id="de1" value="${h(d.n)}"><label>Category</label>${sel('de2', Object.entries(DOC_CAT), m.category)}<label>Visibility</label>${sel('de3', [['internal', 'Internal'], ['client', 'Client-visible']], m.visibility)}`, 'Save', async () => {
      if (!val('de1')) return errBox('Enter a name.'); try { const r = await call<any>('PUT', '/documents/' + d.db, { name: val('de1'), category: val('de2'), visibility: val('de3') }); applyDoc(r.document); cl(); toast('Document updated'); draw(); } catch (e) { errBox(msg(e)); } }); };
  w.docDel = (id: string) => { const d = X.docs.find((x: any) => x.id === id); if (!d || !d.db) return o.docDel(id);
    dlg('Archive document', `<p>Archive <b>${h(d.n)}</b>? It disappears from lists and can no longer be downloaded. The file is retained on the server for recovery.</p>`, 'Archive', async () => {
      try { await call('POST', `/documents/${d.db}/archive`); DOCS[d.db].archivedAt = 'now'; applyDoc(DOCS[d.db]); cl(); toast('Document archived'); draw(); } catch (e) { errBox(msg(e)); } }); };
  try { await loadSuppliers(); await loadBookings(); await loadDocs(); } catch (e) { toast('Phase 4 data could not be loaded: ' + msg(e)); }
  window.setInterval(() => Object.keys(SUP).forEach(id => syncSupplier(Number(id))), 2500);
}
export function afterDraw4() { if (X.role !== 'Traveler') Object.keys(SUP).forEach(id => syncSupplier(Number(id))); }
