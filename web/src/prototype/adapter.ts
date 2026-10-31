// Connects the restored Revision 27 interface to the real PHP/MySQL backend.
// Live (database): sign-in/out, role, Contacts list/detail/create/edit/delete, Staff accounts.
// Everything else stays at prototype level with browser-local sample data and a visible label.
import { api, ApiError, Contact, User, ROLE_LABEL, TYPE_LABEL } from '../api';
import { installTrips, pageNote, afterDraw, contactTripsCard } from './trips';
const w = window as any;
const ROLE_MAP: Record<string, string> = { owner: 'Owner', admin: 'Admin', supervisor: 'Supervisor', advisor: 'Advisor', sales: 'Sales', operations: 'Operations', finance: 'Finance', traveler: 'Traveler' };
const LIVE = new Set(['contacts', 'lcontact', 'staff']);  // trips/itinerary/portal decided by pageNote()
const PHASE: Record<string, string> = { tr: 'Phase 3 (trips, itineraries, client portal)', co: 'Phase 4 (forms, documents)', bo: 'Phases 4–5 (suppliers, bookings, finance)', cm: 'Phase 5 (finance)', ta: 'Phase 6 (tasks, calendar)', em: 'Phase 6 (email)', au: 'Phase 6 (automations, workflows)', li: 'Phase 3–6 (content library)', ca: 'Phase 7 (calling)', rp: 'Phase 8 (reporting)', he: 'Phase 8' };
let me: User;
const can = (p: string) => me.permissions.includes(p);
const h = (s: unknown) => esc(s == null ? '' : s);
async function call<T>(m: string, p: string, b?: unknown): Promise<T> {
  try { return await api<T>(m, p, b); }
  catch (e) { if (e instanceof ApiError && e.status === 401) { toast('Your session has ended. Please sign in again.'); setTimeout(() => { location.hash = '#/login'; location.reload(); }, 900); } throw e; }
}
async function logout() { try { await api('POST', '/auth/logout'); } catch { /* already signed out */ } location.hash = '#/login'; location.reload(); }

/* ---------------- Contacts (live) ---------------- */
const LC: { q: string; type: string; page: number; data: { items: Contact[]; total: number; perPage: number } | null; loading: boolean; err: string } = { q: '', type: '', page: 1, data: null, loading: false, err: '' };
let lcTimer = 0; let lcSeq = 0;
function lcLoad() {
  LC.loading = true; LC.err = ''; const seq = ++lcSeq;
  const p = new URLSearchParams({ q: LC.q, type: LC.type, page: String(LC.page), perPage: '25' });
  call<any>('GET', '/contacts?' + p).then(d => { if (seq === lcSeq) LC.data = d; }).catch(e => { if (seq === lcSeq) LC.err = e instanceof ApiError ? e.message : 'Could not load contacts'; })
    .finally(() => { if (seq !== lcSeq) return; LC.loading = false; if (S.pg === 'contacts') { draw(); const i = document.getElementById('lcq') as HTMLInputElement | null; if (i && document.activeElement !== i && LC.q) { i.focus(); i.setSelectionRange(i.value.length, i.value.length); } } });
}
function contactsPage(legacy: () => string): string {
  if (S.q) { LC.q = S.q; S.q = ''; LC.page = 1; LC.data = null; }
  const t = tb('lcTab', ['Database contacts', 'Demo CRM records (prototype)']);
  const head = hd('Contacts', can('contacts.create') && t === 'Database contacts' ? `<button class="btn" onclick="lcNew()">${ic('plus', 14)} New contact</button>` : '', t === 'Database contacts' ? 'Live records from the LTI database' : 'Sample records used by the prototype trips, portal and calls');
  const tabsH = tabs('lcTab', ['Database contacts', 'Demo CRM records (prototype)']);
  if (t !== 'Database contacts') return head + tabsH + `<div class="dm">Prototype sample data stored in this browser only. These demo clients still power the group trip, booking, payment and softphone screens until their phases connect.</div>` + withoutDbContacts(legacy).replace(/^<div class="hd">[\s\S]*?<\/div><\/div>/, '');
  if (!LC.data && !LC.loading) lcLoad();
  const d = LC.data, pages = d ? Math.max(1, Math.ceil(d.total / d.perPage)) : 1;
  const rows = d ? d.items.map(c => `<tr class="c" tabindex="0" onclick="lcOpen(${c.id})" onkeydown="if(event.key=='Enter')lcOpen(${c.id})"><td><span class="lk">${h(c.firstName)} ${h(c.lastName)}</span></td><td>${h(c.email || '—')}</td><td>${h(c.phone || '—')}</td><td><span class="tag">${h(TYPE_LABEL[c.type])}</span></td><td>${h(c.leadStatus || '—')}</td><td>${h(c.assignedName || 'Unassigned')}</td></tr>`).join('') : '';
  return head + tabsH + `<div class="row" style="margin-bottom:12px">${searchBox('lcq', LC.q, 'lcSearch(this.value)', 'Search name, email or phone')}${sel('lct', [['', 'All types'], ...Object.entries(TYPE_LABEL)], LC.type, 'style="width:auto" aria-label="Contact type" onchange="lcType(this.value)"')}<span class="mu">${d ? d.total + ' contact' + (d.total === 1 ? '' : 's') : LC.loading ? 'Loading' : ''}</span></div>`
    + (LC.err ? `<div class="err" role="alert">${h(LC.err)}</div>` : '')
    + (d ? (d.items.length ? `<div class="cd" data-live="contacts"><div class="tw"><table class="t2"><thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Type</th><th>Lead status</th><th>Assigned to</th></tr></thead><tbody>${rows}</tbody></table></div></div>`
      : emp('users', 'No contacts found', LC.q || LC.type ? 'Try a different search or filter.' : 'Create your first contact.')) : '')
    + (d && pages > 1 ? `<div class="pgx"><span class="mu">Page ${LC.page} of ${pages}</span><div class="row"><button class="btn o s" ${LC.page <= 1 ? 'disabled' : ''} onclick="lcPage(${LC.page - 1})">Previous</button><button class="btn o s" ${LC.page >= pages ? 'disabled' : ''} onclick="lcPage(${LC.page + 1})">Next</button></div></div>` : '');
}
let staffOpts: { id: number; fullName: string }[] | null = null;
async function staffOptions() { if (!staffOpts) staffOpts = (await call<any>('GET', '/contacts/staff-options')).items; return staffOpts!; }
const F = (id: string, label: string, input: string) => `<div><label for="lcf_${id}">${label}</label>${input}<div class="err" id="lcfe_${id}" style="min-height:0"></div></div>`;
async function contactForm(c?: Contact) {
  const st = await staffOptions().catch(() => []);
  const v = (k: keyof Contact) => h(c ? (c[k] ?? '') : '');
  const inp = (k: keyof Contact, type = 'text', extra = '') => `<input id="lcf_${k}" type="${type}" value="${v(k)}" ${extra}>`;
  const body = `<div class="g g2 fm">${F('firstName', 'First name', inp('firstName', 'text', 'required maxlength="100"'))}${F('lastName', 'Last name', inp('lastName'))}${F('email', 'Email', inp('email', 'email'))}${F('phone', 'Phone', inp('phone', 'tel'))}
    ${F('type', 'Type', sel('lcf_type', Object.entries(TYPE_LABEL), c ? c.type : 'client'))}${F('leadStatus', 'Lead status', sel('lcf_leadStatus', [['', '—'], 'new', 'contacted', 'qualified', 'quoted', 'won', 'lost'], c ? c.leadStatus || '' : ''))}
    ${F('source', 'Source', inp('source'))}${F('assignedTo', 'Assigned to', sel('lcf_assignedTo', [['', 'Unassigned'], ...st.map(s => [String(s.id), s.fullName])], c ? String(c.assignedTo ?? '') : String(me.id), can('contacts.assign') ? '' : 'disabled'))}
    ${F('birthday', 'Birthday', inp('birthday', 'date'))}${F('anniversary', 'Anniversary', inp('anniversary', 'date'))}${F('passportExpiry', 'Passport expiration', inp('passportExpiry', 'date'))}</div>
    <div class="fm">${F('notes', 'Notes', `<textarea id="lcf_notes" rows="3">${v('notes')}</textarea>`)}</div><p class="mu" style="font-size:12px">Saved to the LTI database.</p>`;
  dlgW(c ? 'Edit contact' : 'New contact', body, c ? 'Save changes' : 'Create contact', () => saveContact(c), 680);
}
async function saveContact(c?: Contact, allowDuplicate = false) {
  const keys = ['firstName', 'lastName', 'email', 'phone', 'type', 'leadStatus', 'source', 'birthday', 'anniversary', 'passportExpiry', 'notes'];
  const body: Record<string, unknown> = Object.fromEntries(keys.map(k => [k, val('lcf_' + k)]));
  const a = val('lcf_assignedTo'); body.assignedTo = a ? Number(a) : null; body.allowDuplicate = allowDuplicate; if (c) body.version = c.version;
  document.querySelectorAll('[id^="lcfe_"]').forEach(e => { e.textContent = ''; });
  try {
    const r = await call<{ contact: Contact }>(c ? 'PUT' : 'POST', c ? '/contacts/' + c.id : '/contacts', body);
    cl(); LC.data = null; LD.data = r.contact; LD.id = r.contact.id; toast(c ? 'Contact updated' : 'Contact saved to the database'); S.pg = 'lcontact'; location.hash = 'lcontact-' + r.contact.id; draw();
  } catch (e) {
    if (!(e instanceof ApiError)) return; const er = document.getElementById('er'); if (er) er.textContent = e.message;
    Object.entries(e.fields || {}).forEach(([k, m]) => { const el = document.getElementById('lcfe_' + k); if (el) el.textContent = String(m); });
    if (e.status === 409 && e.fields && (e.fields as any).email && !c && confirm('A contact with this email already exists. Create anyway?')) return saveContact(c, true);
  }
}
const withoutDbContacts = (fn: () => string) => { const all = S.cm; S.cm = all.filter((c: any) => !c.db); try { return fn(); } finally { S.cm = all; } };
/* ---------------- Contact detail (live) ---------------- */
const LD: { id: number; data: Contact | null; err: string; loading: boolean } = { id: 0, data: null, err: '', loading: false };
function lcLoadOne(id: number) { LD.loading = true; LD.err = ''; call<{ contact: Contact }>('GET', '/contacts/' + id).then(r => { LD.data = r.contact; }).catch(e => { LD.data = null; LD.err = e instanceof ApiError ? e.message : 'Could not load contact'; }).finally(() => { LD.loading = false; if (S.pg === 'lcontact') draw(); }); }
const fmt = (s: string | null) => { if (!s) return '—'; const d = new Date(s.length === 10 ? s + 'T00:00:00Z' : s.replace(' ', 'T') + 'Z'); return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', timeZone: s.length === 10 ? 'UTC' : undefined }); };
function contactDetailPage(): string {
  if (!LD.id) return hd('Contact') + emp('users', 'No contact selected', '', `<button class="btn o" onclick="go('contacts')">Back to contacts</button>`);
  if ((!LD.data || LD.data.id !== LD.id) && !LD.loading && !LD.err) lcLoadOne(LD.id);
  if (LD.err) return hd('Contact', `<button class="btn o" onclick="go('contacts')">Back to contacts</button>`) + emp('users', LD.err);
  const c = LD.data; if (!c || c.id !== LD.id) return hd('Contact') + '<p class="mu">Loading</p>';
  const phone = c.phone ? c.phone.replace(/[^0-9+]/g, '') : '';
  const rows: [string, string][] = [['Email', c.email || '—'], ['Phone', c.phone || '—'], ['Type', TYPE_LABEL[c.type]], ['Lead status', c.leadStatus || '—'], ['Source', c.source || '—'], ['Assigned to', c.assignedName || 'Unassigned'], ['Birthday', fmt(c.birthday)], ['Anniversary', fmt(c.anniversary)], ['Passport expiration', fmt(c.passportExpiry)], ['Created', fmt(c.createdAt)], ['Last updated', fmt(c.updatedAt)]];
  return hd(h(`${c.firstName} ${c.lastName || ''}`.trim()), `<button class="btn o" onclick="go('contacts')">Back to contacts</button>${phone ? `<a class="btn o" href="tel:${h(phone)}">${ic('phone', 14)} Open phone app</a><button class="btn o" onclick="callTo('${h(c.phone)}','','')">Softphone (simulated)</button>` : ''}${can('contacts.edit') ? `<button class="btn o" onclick="lcEdit()">Edit</button>` : ''}${can('contacts.delete') ? `<button class="btn o" onclick="lcDelete()">Delete</button>` : ''}`, `${h(TYPE_LABEL[c.type])} · live database record`)
    + cd('Details', `<div class="g g2">${rows.map(([k, v]) => `<div class="kv"><b>${k}</b><span data-field="${k}">${h(v)}</span></div>`).join('')}</div>${c.notes ? `<h3 class="h3" style="font-size:13px;margin-top:12px">Notes</h3><p style="white-space:pre-wrap">${h(c.notes)}</p>` : ''}`)
    + contactTripsCard(c.id)
    + `<div class="info"><div><b>More CRM sections arrive with their backend phases</b><p>Communication history, documents, tasks and automations for database contacts are connected in Phases 4–7. The full prototype CRM profile remains available for demo records under Contacts › Demo CRM records.</p></div></div>`;
}
/* ---------------- Staff accounts (live) ---------------- */
const SF: { items: any[] | null; err: string; loading: boolean } = { items: null, err: '', loading: false };
function sfLoad() { SF.loading = true; call<any>('GET', '/staff').then(r => { SF.items = r.items; SF.err = ''; }).catch(e => { SF.err = e instanceof ApiError ? e.message : 'Could not load staff'; }).finally(() => { SF.loading = false; if (S.pg === 'staff') draw(); }); }
function staffPage(): string {
  if (!can('staff.view')) return hd('Staff accounts') + emp('lock', 'Restricted for your role');
  if (!SF.items && !SF.loading && !SF.err) sfLoad();
  const manage = can('staff.manage');
  return hd('Staff accounts', '', 'Live accounts from the LTI database. Accounts are created by an administrator; deactivation takes effect on the person’s next request.')
    + (SF.err ? `<div class="err" role="alert">${h(SF.err)}</div>` : '')
    + (SF.items ? `<div class="cd" data-live="staff"><div class="tw"><table class="t2"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last sign-in</th>${manage ? '<th></th>' : ''}</tr></thead><tbody>${SF.items.map(s => `<tr><td>${h(s.fullName)}</td><td>${h(s.email)}</td><td>${h(s.roleName)}</td><td>${pill(s.isActive ? 'Active' : 'Deactivated', s.isActive ? 'Active' : 'Cancelled')}</td><td>${fmt(s.lastLoginAt)}</td>${manage ? `<td><div class="row" style="flex-wrap:nowrap">${s.id !== me.id ? `<button class="btn o s" onclick="sfToggle(${s.id},${s.isActive ? 0 : 1})">${s.isActive ? 'Deactivate' : 'Reactivate'}</button>` : ''}<button class="btn o s" onclick="sfReset(${s.id})">Reset link</button></div></td>` : ''}</tr>`).join('')}</tbody></table></div></div>` : '<p class="mu">Loading</p>')
    + `<p class="mu" style="font-size:12px">Role permissions are enforced by the server. The permissions matrix and role previews are under Staff & permissions.</p>`;
}
/* ---------------- Integration ---------------- */
function profileBlock(): string {
  const ini = me.fullName.split(/\s+/).map(x => x[0]).slice(0, 2).join('').toUpperCase();
  return `<div class="rel"><button class="pf" aria-label="Profile menu" onclick="mn('p')">${h(ini)}</button>${S.menu === 'p' ? `<div class="dd"><div class="mu">${h(me.fullName)} · ${h(ROLE_LABEL[me.role] || me.role)}</div><div class="mu">${h(me.email)}</div>${can('staff.view') ? `<a onclick="go('staff')">Staff accounts</a>` : ''}<a onclick="go('settings')">Settings</a><a onclick="ltiLogout()">Sign out</a></div>` : ''}</div>`;
}
function banner() {
  const m = document.getElementById('m'); if (!m || LIVE.has(S.pg) || X.role === 'Traveler') return;
  const note = pageNote(); if (note === 'live') return;
  const s = sec(S.pg); const d = document.createElement('div'); d.className = 'dm proto-note'; d.setAttribute('role', 'note');
  d.textContent = note || `Prototype module — sample data kept in this browser only and not saved to the database. Database connection planned for ${PHASE[s[0]] || 'a later phase'}.`;
  m.prepend(d);
  const bt = m.querySelector('.bnr .bt p'); if (bt) bt.textContent = 'You are signed in with your LTI account. Contacts, Staff accounts, Trips, Itineraries and the traveler portal are live database modules; all other modules still run on prototype sample data in this browser until their backend phase.';
}
export async function installAdapter(user: User) {
  me = user;
  X.role = ROLE_MAP[user.role] || 'Advisor'; X.phone && (X.phone.open = 0);
  const bo = N.find((n: any) => n[0] === 'bo'); const settings = bo[3][2][1]; if (!settings.some((x: any) => x[0] === 'staff')) settings.splice(1, 0, ['staff', 'Staff accounts']);
  ICN.staff = 'users'; ICN.lcontact = 'user'; ALIAS.lcontact = 'contacts';
  const legacyContacts = Pg.contacts; Pg.contacts = () => contactsPage(legacyContacts); Pg.lcontact = contactDetailPage; Pg.staff = staffPage;
  const origTop = w.topBar; w.topBar = function () { const html = origTop(); if (X.role === 'Traveler') return html.replace(/<button class="btn o s"[^>]*onclick="setRole\('Owner'\)"[^>]*>[^<]*<\/button>/, `<button class="btn o s" style="margin-left:8px" onclick="ltiLogout()">Sign out</button>`); const i = html.indexOf('<div class="rel"><button class="pf"'); return (i >= 0 ? html.slice(0, i) : html) + profileBlock(); };
  w.si = logout; w.ltiLogout = logout;
  w.setRole = (r: string) => { if (r !== X.role) toast('Your role comes from your account. Administrators change roles from the server.'); };
  w.addC = () => contactForm();
  w.lcNew = () => contactForm(); w.lcOpen = (id: number) => { LD.id = id; LD.err = ''; S.pg = 'lcontact'; S.menu = null; location.hash = 'lcontact-' + id; draw(); scrollTo(0, 0); };
  w.lcEdit = () => LD.data && contactForm(LD.data);
  w.lcDelete = () => { const c = LD.data; if (!c) return; dlg('Delete contact', `<p>Delete <b>${h(c.firstName)} ${h(c.lastName)}</b>? The record is removed from lists and kept in the audit history.</p>`, 'Delete', async () => { try { await call('DELETE', '/contacts/' + c.id); cl(); LC.data = null; toast('Contact deleted'); go('contacts'); } catch (e) { const er = document.getElementById('er'); if (er && e instanceof ApiError) er.textContent = e.message; } }); };
  w.lcSearch = (v: string) => { LC.q = v; LC.page = 1; clearTimeout(lcTimer); lcTimer = window.setTimeout(lcLoad, 250); };
  w.lcType = (v: string) => { LC.type = v; LC.page = 1; lcLoad(); };
  w.lcPage = (p: number) => { LC.page = p; lcLoad(); };
  w.sfToggle = async (id: number, active: number) => { try { await call('PATCH', '/staff/' + id, { isActive: !!active }); toast(active ? 'Account reactivated' : 'Account deactivated'); sfLoad(); } catch (e) { toast(e instanceof ApiError ? e.message : 'Failed'); } };
  w.sfReset = async (id: number) => { try { const r = await call<{ resetUrl: string }>('POST', `/staff/${id}/reset-link`); dlg('One-time password reset link', `<p>Give this link to the staff member through a trusted channel. It works once and expires in 60 minutes.</p><input readonly aria-label="Reset link" value="${h(r.resetUrl)}" onfocus="this.select()">`, null); } catch (e) { toast(e instanceof ApiError ? e.message : 'Failed'); } };
  const origDraw = w.draw; w.draw = function () { origDraw(); banner(); afterDraw(); };
  if (location.hash.startsWith('#/') || !location.hash) location.hash = 'dashboard';
  const fromHash = () => { const m = location.hash.match(/^#lcontact-(\d+)$/); if (m) { LD.id = Number(m[1]); LD.err = ''; S.pg = 'lcontact'; return true; } return false; };
  window.addEventListener('hashchange', () => { if (fromHash()) draw(); });
  if (!fromHash()) { const p = location.hash.slice(1); S.pg = Pg[p] ? p : 'dashboard'; }
  try { await installTrips(user, can); } catch (e) { toast('Trips could not be loaded: ' + (e instanceof ApiError ? e.message : 'network error')); }
  w.__LTI_LIVE = true; draw();
}
