// Phase 9: settings, tags, booking controls, roles and permissions, terms, travelers, support requests, integrations status,
// plus accurate handling of every remaining prototype page (routed to its live module, or labelled not available).
import { api, ApiError } from '../api';
const w = window as any;
let can: (p: string) => boolean = () => false; let ROLE = '';
const h = (s: unknown) => esc(s == null ? '' : s);
const msg = (e: unknown) => e instanceof ApiError ? e.message + (e.fields && Object.keys(e.fields).length ? ': ' + Object.values(e.fields).join('; ') : '') : 'Request failed';
const errBox = (m: string) => { const e = document.getElementById('er'); if (e) e.textContent = m; else toast(m); };
async function call<T>(m: string, p: string, b?: unknown): Promise<T> { try { return await api<T>(m, p, b); } catch (e) { if (e instanceof ApiError && e.status === 401) { toast('Your session has ended. Please sign in again.'); setTimeout(() => { location.hash = '#/login'; location.reload(); }, 900); } throw e; } }
const dialogOpen = () => !!(document.getElementById('ov') && document.getElementById('ov')!.innerHTML.trim());
const redraw = () => { if (!dialogOpen()) draw(); };
type Cache = { data: any; loading: boolean; error: string | null; key: string };
const C: Record<string, Cache> = {};
function load(name: string, path: string): Cache { const c = C[name] || (C[name] = { data: null, loading: false, error: null, key: '' }); if (c.key !== path) { c.key = path; c.data = null; c.error = null; c.loading = false; }
  if (!c.data && !c.loading && !c.error) { c.loading = true; call<any>('GET', path).then(r => { if (c.key === path) c.data = r; }).catch(e => { if (c.key === path) c.error = msg(e); }).finally(() => { c.loading = false; redraw(); }); } return c; }
const invalidate = (...n: string[]) => n.forEach(k => { if (C[k]) { C[k].data = null; C[k].error = null; C[k].key = ''; } });
const state = (c: Cache, render: (d: any) => string) => c.error ? `<div class="err" role="alert">${h(c.error)} <button class="btn o s" onclick="adRetry()">Retry</button></div>` : !c.data ? '<p class="mu">Loading</p>' : render(c.data);
const unavailable = (title: string, why: string, alt = '') => hd(title, '', 'Not available') + emp('lock', `${title} is not available`, why) + alt;
const go2 = (pg: string, tab?: [string, string]) => () => { if (tab) S.tab[tab[0]] = tab[1]; setTimeout(() => go(pg), 0); return '<p class="mu">Opening…</p>'; };
/* ---------------- settings ---------------- */
const field = (id: string, label: string, v: string, extra = '') => `<label for="${id}">${label}</label><input id="${id}" value="${h(v)}" ${extra}>`;
function agencyForm(d: any, ro: boolean): string { const a = d.agency; return `<div class="fm" data-live="agency">${field('ag1', 'Agency name', a.name, ro ? 'disabled' : '')}${field('ag2', 'Email', a.email, (ro ? 'disabled ' : '') + 'type="email" placeholder="Not set"')}${field('ag3', 'Phone', a.phone, (ro ? 'disabled ' : '') + 'placeholder="Not set"')}
  <label for="ag4">Address</label><textarea id="ag4" rows="2" ${ro ? 'disabled' : ''}>${h(a.address)}</textarea>${field('ag5', 'Website', a.website, (ro ? 'disabled ' : '') + 'placeholder="https://"')}${field('ag6', 'Brand colour', a.brandColor, ro ? 'disabled' : '')}
  ${ro ? '<p class="mu" style="font-size:12px">Only administrators can change agency details.</p>' : '<button class="btn" onclick="adSave(\'agency\')">Save agency details</button>'}</div>`; }
function regionalForm(d: any, ro: boolean): string { const r = d.regional; return `<div class="fm" data-live="regional">${field('rg1', 'Agency time zone (used for due dates, reminders and reports)', r.timezone, ro ? 'disabled' : '')}
  <label>Date format</label>${sel('rg2', d.options.dateFormats.map((x: string) => [x, x]), r.dateFormat, ro ? 'disabled' : '')}<label>Week starts on</label>${sel('rg3', [['Sun', 'Sunday'], ['Mon', 'Monday']], r.weekStart, ro ? 'disabled' : '')}
  <label>Default country for phone numbers</label>${sel('rg4', d.options.phoneCountries.map((x: string) => [x, x]), r.defaultPhoneCountry, ro ? 'disabled' : '')}<p class="mu" style="font-size:12px">Reporting currency: ${h(r.reportingCurrency)} (Finance settings).</p>
  ${ro ? '' : '<button class="btn" onclick="adSave(\'regional\')">Save regional settings</button>'}</div>`; }
function emailForm(d: any, ro: boolean): string { const t = d.email.transport; return `<div class="fm" data-live="email-settings"><p style="font-size:13px">${t.configured ? `Outgoing mail: ${h(t.host)} as ${h(t.fromEmail)} (up to ${h(t.maxPerHour)} per hour).` : '<b>Outgoing mail is not configured on this server.</b> Messages cannot be sent until an SMTP mailbox is set in the server configuration.'}</p>
  <label for="em1s">Default email signature</label><textarea id="em1s" rows="5" ${ro ? 'disabled' : ''}>${h(d.email.signature)}</textarea>${ro ? '' : '<button class="btn" onclick="adSave(\'email\')">Save signature</button>'}</div>`; }
function bookingForm(d: any, ro: boolean): string { return `<div class="fm" data-live="booking-controls"><p style="font-size:13px">Fields required before a booking can be marked <b>confirmed</b>. The server enforces these for every user.</p>
  ${Object.entries(d.bookingFieldOptions).map(([k, l]) => `<label class="ck" style="font-size:13px"><input type="checkbox" class="bc" value="${k}" ${d.bookingRequired.includes(k) ? 'checked' : ''} ${ro ? 'disabled' : ''}> ${h(l)}</label>`).join('')}
  ${ro ? '' : '<button class="btn" onclick="adSave(\'booking\')">Save booking controls</button>'}</div>`; }
function termsForm(d: any, ro: boolean): string { const t = d.terms; return `<div class="fm" data-live="terms"><p style="font-size:13px">${t.publishedAt ? `Published ${h(t.publishedAt)} UTC — shown to travelers in the client portal.` : 'Not published — travelers do not see terms.'}</p>
  <label for="tm1">Agency terms and conditions</label><textarea id="tm1" rows="12" ${ro ? 'disabled' : ''} placeholder="Write your agency's own terms. Have them reviewed by your legal adviser before publishing.">${h(t.text)}</textarea>
  ${ro ? '' : `<div class="row" style="gap:6px"><button class="btn o" onclick="adSave('terms')">Save draft</button>${t.publishedAt ? '<button class="btn o" onclick="adTerms(false)">Unpublish</button>' : '<button class="btn" onclick="adTerms(true)">Publish to client portal</button>'}</div>`}</div>`; }
const SET_TABS = ['Agency & branding', 'Regional', 'Email', 'Booking controls', 'Tags', 'Terms', 'Other'];
function settingsPage(): string { const c = load('settings', '/settings'); const k = tb('set9', SET_TABS);
  return hd('Settings', '', 'Agency configuration stored in the database') + tabs('set9', SET_TABS) + (k === 'Tags' ? tagsBody() : state(c, d => { const ro = !d.canEdit;
    if (k === 'Agency & branding') return agencyForm(d, ro); if (k === 'Regional') return regionalForm(d, ro); if (k === 'Email') return emailForm(d, ro); if (k === 'Booking controls') return bookingForm(d, ro); if (k === 'Terms') return termsForm(d, ro);
    return emp('lock', 'Other prototype settings are not available', 'Personal profile is managed under Staff accounts; notification channels, calendar sync, AI tone and automation defaults from the prototype have no backend. Workflows are configured under Automations.'); })); }
w.adSave = async (sec: string) => { const v = (id: string) => (document.getElementById(id) as HTMLInputElement | null)?.value ?? '';
  const body: any = sec === 'agency' ? { name: v('ag1'), email: v('ag2'), phone: v('ag3'), address: v('ag4'), website: v('ag5'), brandColor: v('ag6') } : sec === 'regional' ? { timezone: v('rg1'), dateFormat: v('rg2'), weekStart: v('rg3'), defaultPhoneCountry: v('rg4') }
    : sec === 'email' ? { signature: v('em1s') } : sec === 'booking' ? { required: [...document.querySelectorAll('.bc:checked')].map(x => (x as HTMLInputElement).value) } : { text: v('tm1') };
  try { C.settings = { data: await call<any>('PUT', '/settings/' + sec, body), loading: false, error: null, key: '/settings' }; toast('Saved'); draw(); } catch (e) { toast(msg(e)); } };
w.adTerms = async (pub: boolean) => { try { C.settings = { data: await call<any>('PUT', '/settings/terms', { text: (document.getElementById('tm1') as HTMLTextAreaElement).value, publish: pub }), loading: false, error: null, key: '/settings' }; toast(pub ? 'Terms published to the client portal' : 'Terms unpublished'); draw(); } catch (e) { toast(msg(e)); } };
/* ---------------- tags ---------------- */
function tagsBody(): string { const c = load('tags', '/admin/tags'); const ed = can('staff.manage');
  return state(c, d => `${ed ? `<div class="row" style="gap:6px;margin-bottom:10px"><input id="tg1" aria-label="New tag" placeholder="New tag" style="width:220px"><button class="btn" onclick="adTagNew()">Create tag</button></div>` : ''}
    <div class="cd"><div class="tw"><table class="t2" data-live="tags"><thead><tr><th>Tag</th><th>Contacts</th><th>Trips</th><th>Source</th><th></th></tr></thead><tbody>${d.items.map((t: any) => `<tr><td>${h(t.name)}</td><td>${t.contacts}</td><td>${t.trips}</td><td style="font-size:12px">${t.origin === 'revision27' ? 'Imported from Revision 27' : 'Created in LTI'}</td>
    <td style="white-space:nowrap">${ed ? `<button class="btn o s" onclick="adTagRename(${t.id},'${h(t.name).replace(/'/g, '&#39;')}')">Rename</button><button class="btn o s" onclick="adTagDel(${t.id})">Delete</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="5" class="mu">No tags yet.</td></tr>'}</tbody></table></div></div>`); }
w.adTagNew = async () => { try { C.tags = { data: await call<any>('POST', '/admin/tags', { name: val('tg1') }), loading: false, error: null, key: '/admin/tags' }; toast('Tag created'); draw(); } catch (e) { toast(msg(e)); } };
w.adTagRename = (id: number, cur: string) => dlg('Rename tag', `<label>Name</label><input id="tg2" value="${cur}">`, 'Rename', async () => { try { C.tags = { data: await call<any>('PUT', '/admin/tags/' + id, { name: val('tg2') }), loading: false, error: null, key: '/admin/tags' }; cl(); draw(); } catch (e) { errBox(msg(e)); } });
w.adTagDel = async (id: number) => { try { C.tags = { data: await call<any>('DELETE', '/admin/tags/' + id), loading: false, error: null, key: '/admin/tags' }; toast('Tag deleted'); draw(); } catch (e) { toast(msg(e)); } };
/* ---------------- roles and permissions ---------------- */
function rolesPage(): string { if (!can('staff.manage')) return hd('Staff & permissions') + emp('lock', 'Restricted for your role', 'Only administrators can view role permissions.'); const c = load('roles', '/admin/roles');
  return hd('Staff & permissions', `<button class="btn o" onclick="go('staff')">Staff accounts</button>`, 'Permissions are enforced by the server for every request') + state(c, d => `<p class="mu" style="font-size:12px">${d.canEdit ? 'As an owner you can change role permissions. The owner role is locked; client accounts can only use the portal. Every change is recorded in the audit log.' : 'Only an owner can change role permissions.'}</p>
    <div class="tw"><table class="t2" data-live="roles"><thead><tr><th>Permission</th>${d.roles.map((r: any) => `<th style="text-align:center">${h(r.name)}<div class="mu" style="font-size:11px">${r.users} active</div></th>`).join('')}</tr></thead><tbody>${d.permissions.map((p: any) => `<tr><td style="font-size:12px"><b>${h(p.code)}</b><br><span class="mu">${h(p.description || '')}</span></td>${d.roles.map((r: any) => `<td style="text-align:center"><input type="checkbox" aria-label="${h(r.name)}: ${h(p.code)}" data-role="${h(r.code)}" data-perm="${h(p.code)}" ${r.permissions.includes(p.code) ? 'checked' : ''} ${!d.canEdit || r.locked ? 'disabled' : ''}></td>`).join('')}</tr>`).join('')}</tbody></table></div>
    ${d.canEdit ? `<div class="row" style="gap:6px;margin-top:10px">${d.roles.filter((r: any) => !r.locked).map((r: any) => `<button class="btn o s" onclick="adRoleSave('${h(r.code)}')">Save ${h(r.name)}</button>`).join('')}</div>` : ''}`); }
w.adRoleSave = async (code: string) => { const perms = [...document.querySelectorAll(`input[data-role="${code}"]:checked`)].map(x => (x as HTMLElement).dataset.perm);
  try { C.roles = { data: await call<any>('PUT', `/admin/roles/${code}/permissions`, { permissions: perms }), loading: false, error: null, key: '/admin/roles' }; toast('Permissions saved'); draw(); } catch (e) { toast(msg(e)); invalidate('roles'); draw(); } };
/* ---------------- travelers ---------------- */
let TQ = '';
function travelersPage(): string { const c = load('trav', '/travelers' + (TQ ? '?q=' + encodeURIComponent(TQ) : ''));
  return hd('Travelers', '', 'Everyone traveling on trips you can access') + `<input aria-label="Search travelers" placeholder="Search name or email" value="${h(TQ)}" style="width:240px;margin-bottom:10px" onchange="adTQ(this.value)">`
    + state(c, d => d.items.length ? `<div class="cd"><div class="tw"><table class="t2" data-live="travelers"><thead><tr><th>Traveler</th><th>Trips</th><th>Phone</th><th>Passport (number not stored)</th></tr></thead><tbody>${d.items.map((t: any) => `<tr><td><a class="lk" onclick="lcOpen(${t.contactId})">${h(t.name)}</a><div class="mu" style="font-size:11px">${h(t.email || '')}</div></td>
      <td style="font-size:12px">${t.trips.map((x: any) => `<a class="lk" onclick="S.cur='d${x.id}';S.tsec='Overview';go('trip')">${h(x.name)}</a> <span class="mu">(${h(x.permission)})</span>`).join('<br>')}</td><td>${h(t.phone || '—')}</td>
      <td style="font-size:12px">${t.passportCountry ? 'Country ' + h(t.passportCountry) : 'Not recorded'}${t.passportExpiry ? `<br>${t.passportExpiresSoon ? pill('Expires ' + h(t.passportExpiry), 'Overdue') : 'Expires ' + h(t.passportExpiry)}` : ''}</td></tr>`).join('')}</tbody></table></div></div>` : emp('user', 'No travelers', 'Add travelers to a trip to see them here.')); }
w.adTQ = (v: string) => { TQ = v; draw(); };
/* ---------------- support, integrations ---------------- */
function supportPage(): string { return hd('Contact support', '', 'Requests go to an agency administrator as a task') + `<div class="fm" style="max-width:560px" data-live="support"><label>Category</label>${sel('sp1', ['Platform question', 'Bug report', 'Access / permissions', 'Integration request', 'Other'].map(x => [x, x]), 'Platform question')}
  <label for="sp2">Subject</label><input id="sp2"><label for="sp3">Details</label><textarea id="sp3" rows="6"></textarea><button class="btn" onclick="adSupport()">Send request</button></div>`; }
w.adSupport = async () => { try { await call('POST', '/support-requests', { category: val('sp1'), subject: val('sp2'), details: val('sp3') }); toast('Request sent — an administrator has a new task'); (document.getElementById('sp2') as HTMLInputElement).value = ''; (document.getElementById('sp3') as HTMLTextAreaElement).value = ''; } catch (e) { toast(msg(e)); } };
function integrationsPage(): string { const s = can('jobs.view') ? load('jobs', '/jobs/status') : null; const st = load('settings', '/settings');
  const row = (n: string, status: string, kind: string, detail: string) => `<tr><td><b>${h(n)}</b></td><td>${pill(status, kind)}</td><td style="font-size:12px;white-space:normal">${detail}</td></tr>`;
  return hd('Integrations', '', 'Actual status of external connections') + state(st, d => { const t = d.email.transport; const sch = s && s.data;
    return `<div class="cd"><div class="tw"><table class="t2" data-live="integrations"><tbody>${row('Outgoing email (SMTP)', t.configured ? 'Configured' : 'Not configured', t.configured ? 'Active' : 'Cancelled', t.configured ? `Sending via ${h(t.host)}. Delivery to inboxes is not tracked.` : 'Set an SMTP mailbox in the server configuration (hosting mailbox; no paid service needed).')}
      ${row('Scheduler (cron)', sch ? (sch.schedulerHealthy ? 'Running' : 'Not running') : 'Unknown', sch && sch.schedulerHealthy ? 'Active' : 'Cancelled', sch ? h(sch.message) : 'Visible to administrators.')}
      ${row('Telephony (phone calls in the browser)', 'Unavailable', 'Cancelled', 'No telephony provider. Calls use your phone app (tel: links) and are logged manually.')}
      ${row('Inbound email (IMAP)', 'Not connected', 'Cancelled', 'Requires confirmed IMAP access on the hosting plan.')}
      ${row('Calendar sync (Google / Outlook)', 'Not available', 'Cancelled', 'Not implemented; would need provider API credentials.')}
      ${row('Tern, payment processors, other services', 'Not available', 'Cancelled', 'Not implemented; no paid services are used.')}</tbody></table></div></div>`; }); }
w.adRetry = () => { for (const k in C) if (C[k].error) { C[k].error = null; C[k].key = ''; } draw(); };
export const LIVE_ADMIN = ['agency', 'settings', 'tags', 'controls', 'roles', 'team', 'terms', 'trav', 'hsup', 'integ', 'ai', 'scheduling', 'libca', 'libch', 'rpcl', 'rpq', 'tpl', 'tact', 'library', 'libem', 'libfo', 'libsu', 'comm', 'client', 'preview', 'rpc', 'rpview', 'help'];
export function installAdmin(perms: (p: string) => boolean, role: string) {
  can = perms; ROLE = role; void ROLE;
  Pg.settings = settingsPage;
  Pg.agency = () => hd('Agency details', can('staff.manage') ? `<button class="btn o" onclick="S.tab.set9='Agency & branding';go('settings')">All settings</button>` : '', 'Company profile used on invoices, emails and the client portal') + state(load('settings', '/settings'), d => agencyForm(d, !d.canEdit));
  Pg.tags = () => hd('Agency tags', '', 'Tags for contacts and trips') + tagsBody();
  Pg.controls = () => hd('Agency controls', '', 'Booking requirements enforced by the server') + state(load('settings', '/settings'), d => bookingForm(d, !d.canEdit));
  Pg.terms = () => hd('Terms & conditions', '', 'Shown to travelers in the client portal when published') + state(load('settings', '/settings'), d => termsForm(d, !d.canEdit));
  Pg.roles = rolesPage; Pg.team = rolesPage; Pg.trav = travelersPage; Pg.hsup = supportPage; Pg.integ = integrationsPage;
  Pg.ai = () => unavailable('AI workspace', 'LTI has no AI service. The prototype’s assistant was a scripted demo; a real one would need a paid AI provider, which is outside the zero-cost scope.');
  Pg.scheduling = () => unavailable('Scheduling', 'Client self-booking links and advisor availability are not implemented. Use Tasks, Calendar and callbacks to schedule follow-ups.', `<p style="text-align:center"><button class="btn o" onclick="go('calendar')">Open calendar</button></p>`);
  Pg.libca = () => unavailable('Campaigns', 'Bulk email campaigns are not implemented. Individual messages can be sent from the Outbox.');
  Pg.libch = () => unavailable('Chat prompts', 'There is no AI chat service, so saved prompts have no use.');
  Pg.rpcl = () => unavailable('Collections', 'Report collections belong to the report builder, which is not implemented. Use Reports for live data.', `<p style="text-align:center"><button class="btn o" onclick="go('reporting')">Open reports</button></p>`);
  Pg.rpq = () => unavailable('Saved queries', 'Saved queries belong to the report builder, which is not implemented. Use Reports for live data.', `<p style="text-align:center"><button class="btn o" onclick="go('reporting')">Open reports</button></p>`);
  Pg.tpl = () => unavailable('Itinerary template', 'Reusable itinerary templates are not implemented, and none existed in the prototype. Build itineraries on a trip.');
  Pg.tact = Pg.tpl;
  Pg.library = go2('content', ['ct', 'Activities']); Pg.libem = go2('emt'); Pg.libfo = go2('forms'); Pg.libsu = go2('suppliers'); Pg.comm = go2('commission'); Pg.client = go2('contacts'); Pg.preview = go2('portal');
  const helpOrig = Pg.help; Pg.help = () => `<div class="proto-info" role="note" style="font-size:12px;margin-bottom:8px;color:var(--mu,#666)">Static help articles written for the approved design. They contain no business data; where an article describes a feature listed as not available, that feature is not part of this release.</div>` + helpOrig();
  const rep = Pg.reporting; Pg.reporting = () => { const k = tb('rpt', ['Reports', 'Report builder', 'AI assist']); if (k === 'Reports') return rep();
    return tabs('rpt', ['Reports', 'Report builder', 'AI assist']) + unavailable(k, k === 'Report builder' ? 'The custom report builder is not implemented. The Reports tab provides nine live reports from the database.' : 'There is no AI service.'); };
}
