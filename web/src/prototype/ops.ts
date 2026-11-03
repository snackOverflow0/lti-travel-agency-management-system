// Phase 8.1: connects Tasks, Calendar, Notifications and the top-bar notification bell to the PHP API.
import { api, ApiError } from '../api';
const w = window as any;
let can: (p: string) => boolean = () => false; let ME = 0;
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
const state = (c: Cache, empty: string, render: (d: any) => string) => c.error ? `<div class="err" role="alert">${h(c.error)} <button class="btn o s" onclick="opRetry()">Retry</button></div>` : !c.data ? '<p class="mu">Loading</p>' : render(c.data) || emp('list', empty, '');
const PRI: [string, string][] = [['low', 'Low'], ['normal', 'Normal'], ['high', 'High'], ['urgent', 'Urgent']];
const STAT: [string, string][] = [['todo', 'To do'], ['in_progress', 'In progress'], ['blocked', 'Blocked'], ['completed', 'Completed'], ['archived', 'Archived']];
const lbl = (l: [string, string][], v: string) => (l.find(x => x[0] === v) || [v, v])[1];
let STAFF: any[] | null = null; let PICK: any = null;
async function staff() { if (!STAFF) STAFF = (await call<any>('GET', '/contacts/staff-options')).items; return STAFF!; }
async function picks() { if (!PICK) { const [c, t] = await Promise.all([call<any>('GET', '/contacts?perPage=100'), call<any>('GET', '/trips?type=all&perPage=200')]); PICK = { contacts: c.items, trips: t.items }; } return PICK; }
/* ---------------- tasks ---------------- */
const TF: any = { assignedTo: '', priority: '', q: '' };
function tasksPage(): string {
  const tl = ['Incomplete', 'Overdue', 'Completed', 'All']; const k = tb('tk2', tl);
  const p: Record<string, string> = { perPage: '200', ...Object.fromEntries(Object.entries(TF).filter(([, v]) => v)) as any }; if (k === 'Incomplete') p.status = 'open'; if (k === 'Overdue') p.overdue = '1'; if (k === 'Completed') p.status = 'completed';
  const c = load('tasks', '/tasks?' + new URLSearchParams(p)); if (!STAFF) staff().then(redraw).catch(() => {});
  const filt = `<div class="row" style="gap:6px;margin-bottom:10px;flex-wrap:wrap">${sel('tf1', [['', 'Anyone'], ['me', 'Assigned to me'], ...(STAFF || []).map((s: any) => [String(s.id), s.fullName])], TF.assignedTo, 'style="width:auto" aria-label="Assignee" onchange="opTF(\'assignedTo\',this.value)"')}${sel('tf2', [['', 'Any priority'], ...PRI], TF.priority, 'style="width:auto" aria-label="Priority" onchange="opTF(\'priority\',this.value)"')}<input aria-label="Search tasks" placeholder="Search" value="${h(TF.q)}" style="width:200px" onchange="opTF('q',this.value)"></div>`;
  return hd('Tasks', can('tasks.manage') ? `<button class="btn" onclick="opTask()">New task</button>` : '', 'Assigned work, follow-ups and callbacks') + tabs('tk2', tl) + filt + state(c, k === 'Completed' ? 'No completed tasks' : 'No tasks here', d => d.items.length ? `<div class="cd"><div class="tw"><table class="t2" data-live="tasks"><thead><tr><th style="width:34px"></th><th>Task</th><th>Due</th><th>Priority</th><th>Status</th><th>Assignee</th><th>Related</th></tr></thead><tbody>${d.items.map((t: any) => `<tr>
    <td>${t.status === 'archived' ? '' : `<input type="checkbox" aria-label="${t.status === 'completed' ? 'Reopen' : 'Complete'} ${h(t.title)}" ${t.status === 'completed' ? 'checked' : ''} onclick="opToggle(${t.id},${t.status === 'completed'})">`}</td>
    <td><a class="lk" onclick="opTaskView(${t.id})">${h(t.title)}</a>${t.source && t.source !== 'Manual' ? ` <span class="mu" style="font-size:11px">${h(t.source)}</span>` : ''}</td><td>${t.overdue ? pill('Overdue ' + h(t.dueDate), 'Overdue') : h(t.dueDate || '')}</td><td>${h(lbl(PRI, t.priority))}</td><td>${h(lbl(STAT, t.status))}</td><td>${h(t.assigneeName || 'Unassigned')}</td>
    <td style="font-size:12px">${[t.contact, t.trip, t.booking, t.invoice].filter(Boolean).map((x: any) => h(x.label)).join(' · ')}</td></tr>`).join('')}</tbody></table></div></div>` : ''); }
async function taskDialog(cur?: any) { let s: any[], p: any; try { [s, p] = await Promise.all([staff(), picks()]); } catch (e) { toast(msg(e)); return; }
  dlgW(cur ? 'Edit task' : 'New task', `<div class="fm"><label>Title</label><input id="tk1" value="${h(cur?.title ?? '')}"><label>Description</label><textarea id="tk2" rows="3">${h(cur?.description ?? '')}</textarea><div class="g g2">
    <div><label>Assignee</label>${sel('tk3', [['', 'Unassigned'], ...s.map((x: any) => [String(x.id), x.fullName])], String(cur ? (cur.assignedTo ?? '') : ME))}</div><div><label>Due date</label><input id="tk4" type="date" value="${h(cur?.dueDate ?? '')}"></div>
    <div><label>Priority</label>${sel('tk5', PRI, cur?.priority || 'normal')}</div><div><label>Status</label>${sel('tk6', STAT.slice(0, 3), cur && ['todo', 'in_progress', 'blocked'].includes(cur.status) ? cur.status : 'todo')}</div>
    <div><label>Contact</label>${sel('tk7', [['', '—'], ...p.contacts.map((c: any) => [String(c.id), `${c.firstName} ${c.lastName || ''}`.trim()])], String(cur?.contact?.id ?? ''))}</div><div><label>Trip</label>${sel('tk8', [['', '—'], ...p.trips.map((t: any) => [String(t.id), t.name])], String(cur?.trip?.id ?? ''))}</div></div></div>`, cur ? 'Save' : 'Create task', async () => {
    const b: any = { title: val('tk1'), description: val('tk2'), assignedTo: val('tk3') ? Number(val('tk3')) : null, dueDate: val('tk4') || null, priority: val('tk5'), status: val('tk6'), contactId: val('tk7') ? Number(val('tk7')) : null, tripId: val('tk8') ? Number(val('tk8')) : null };
    if (cur) b.version = cur.version; try { const r = await call<any>(cur ? 'PUT' : 'POST', '/tasks' + (cur ? '/' + cur.id : ''), b); cl(); invalidate('tasks', 'cal'); toast(cur ? 'Task saved' : 'Task created'); draw(); if (cur) w.opTaskView(r.task.id); } catch (e) { errBox(msg(e)); } }, 680); }
w.opTask = () => taskDialog();
w.opTaskView = async (id: number) => { let t: any; try { t = (await call<any>('GET', '/tasks/' + id)).task; } catch (e) { toast(msg(e)); return; } cl();
  const act = t.status === 'archived' ? '' : `${t.status === 'completed' ? `<button class="btn o" onclick="opToggle(${id},true,1)">Reopen</button>` : `<button class="btn" onclick="opToggle(${id},false,1)">Mark complete</button><button class="btn o" onclick="opTaskEdit(${id})">Edit</button>`}<button class="btn o" onclick="opArchive(${id})">Archive</button>`;
  dlgW(h(t.title), `<div class="g g2"><div class="kv"><b>Status</b><span data-field="status">${h(lbl(STAT, t.status))}</span></div><div class="kv"><b>Priority</b><span>${h(lbl(PRI, t.priority))}</span></div><div class="kv"><b>Due</b><span>${t.overdue ? pill('Overdue ' + h(t.dueDate), 'Overdue') : h(t.dueDate || '—')}</span></div><div class="kv"><b>Assignee</b><span>${h(t.assigneeName || 'Unassigned')}</span></div>
    <div class="kv"><b>Contact</b><span>${t.contact ? (t.contact.id ? `<a class="lk" onclick="cl();lcOpen(${t.contact.id})">${h(t.contact.label)}</a>` : h(t.contact.label)) : '—'}</span></div><div class="kv"><b>Trip</b><span>${t.trip ? (t.trip.id ? `<a class="lk" onclick="cl();S.cur='d${t.trip.id}';S.tsec='Overview';go('trip')">${h(t.trip.label)}</a>` : h(t.trip.label)) : '—'}</span></div><div class="kv"><b>Source</b><span>${h(t.source || 'Manual')}</span></div><div class="kv"><b>Created by</b><span>${h(t.createdBy || '')}</span></div></div>
    ${t.description ? `<p style="white-space:pre-wrap;margin-top:8px">${h(t.description)}</p>` : ''}<div class="row" style="gap:6px;margin:10px 0;flex-wrap:wrap">${act}</div>
    <h3 class="h3" style="font-size:13px">Comments</h3>${t.comments.map((c: any) => `<div class="ts" style="font-size:13px"><span style="flex:1;white-space:pre-wrap">${h(c.body)}</span><span class="mu">${h(c.by)} · ${h(String(c.at).slice(0, 16))}</span></div>`).join('') || '<p class="mu">No comments.</p>'}
    <div class="fm"><textarea id="tkc" rows="2" aria-label="New comment" placeholder="Add a comment"></textarea><button class="btn o s" onclick="opComment(${id})">Add comment</button></div>
    <h3 class="h3" style="font-size:13px;margin-top:10px">History</h3>${t.history.map((x: any) => `<div class="mu" style="font-size:12px">${h(String(x.at).slice(0, 16))} · ${h(x.by || 'System')} · ${h(x.field.replace('_', ' '))}: ${h(x.from ?? '—')} → ${h(x.to ?? '—')}</div>`).join('') || '<p class="mu">No changes yet.</p>'}`, null, null, 680); };
w.opTaskEdit = async (id: number) => { try { const t = (await call<any>('GET', '/tasks/' + id)).task; cl(); taskDialog(t); } catch (e) { toast(msg(e)); } };
w.opToggle = async (id: number, done: boolean, reopenView?: number) => { try { await call('POST', `/tasks/${id}/${done ? 'reopen' : 'complete'}`); invalidate('tasks', 'cal'); toast(done ? 'Task reopened' : 'Task completed'); if (reopenView) w.opTaskView(id); else draw(); } catch (e) { toast(msg(e)); } };
w.opArchive = async (id: number) => { try { await call('POST', `/tasks/${id}/archive`); cl(); invalidate('tasks', 'cal'); toast('Task archived'); draw(); } catch (e) { errBox(msg(e)); } };
w.opComment = async (id: number) => { const b = val('tkc'); if (!b) return; try { await call('POST', `/tasks/${id}/comments`, { body: b }); w.opTaskView(id); } catch (e) { errBox(msg(e)); } };
w.opTF = (k: string, v: string) => { TF[k] = v; draw(); };
/* ---------------- calendar ---------------- */
let CAL = (() => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`; })();
function calendarPage(): string {
  const [y, m] = CAL.split('-').map(Number); const first = new Date(y, m - 1, 1), days = new Date(y, m, 0).getDate(), pad = (first.getDay() + 6) % 7;
  const tk = load('cal', '/tasks?perPage=200&status=open'), tr = load('caltrips', '/trips?type=all&perPage=200'), cb = load('calcb', '/callbacks?status=pending');
  if (tk.error || tr.error || cb.error) return hd('Calendar') + `<div class="err" role="alert">${h(tk.error || tr.error || cb.error)} <button class="btn o s" onclick="opRetry()">Retry</button></div>`;
  if (!tk.data || !tr.data || !cb.data) return hd('Calendar') + '<p class="mu">Loading</p>';
  const ev: Record<string, string[]> = {}; const add = (d: string | null, html: string) => { if (d && d.startsWith(CAL)) (ev[d.slice(0, 10)] = ev[d.slice(0, 10)] || []).push(html); };
  tk.data.items.forEach((t: any) => add(t.dueDate, `<a class="cev" style="border-left:3px solid var(--gd)" onclick="opTaskView(${t.id})">${h(t.title)}</a>`));
  tr.data.items.forEach((t: any) => add(t.startDate, `<a class="cev" style="border-left:3px solid #2c6e9b" onclick="S.cur='d${t.id}';S.tsec='Overview';go('trip')">Departs: ${h(t.name)}</a>`));
  cb.data.items.forEach((c: any) => add(c.scheduledAtLocal, `<a class="cev" style="border-left:3px solid #8a5a00" onclick="go('callhist')">Call back ${h(c.contactName)} ${h(String(c.scheduledAtLocal).slice(11))}</a>`));
  const cells: string[] = []; for (let i = 0; i < pad; i++) cells.push('<div class="cal-d mu"></div>');
  for (let d = 1; d <= days; d++) { const key = `${CAL}-${String(d).padStart(2, '0')}`; cells.push(`<div class="cal-d" data-date="${key}" style="min-height:84px;border:1px solid var(--ln,#e3e3e3);padding:4px;font-size:12px;overflow:hidden"><b>${d}</b>${(ev[key] || []).map(x => `<div style="margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${x}</div>`).join('')}</div>`); }
  const label = first.toLocaleString(undefined, { month: 'long', year: 'numeric' });
  return hd('Calendar', `<button class="btn o" aria-label="Previous month" onclick="opCal(-1)">Previous</button><button class="btn o" onclick="opCal(0)">Today</button><button class="btn o" aria-label="Next month" onclick="opCal(1)">Next</button>`, 'Task due dates, trip departures and scheduled callbacks')
    + `<h2 style="font-size:16px;margin:0 0 8px">${h(label)}</h2><div class="tw"><div data-live="calendar" style="display:grid;grid-template-columns:repeat(7,minmax(90px,1fr));gap:2px;min-width:640px">${['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map(x => `<div class="mu" style="font-size:11px;padding:2px 4px">${x}</div>`).join('')}${cells.join('')}</div></div>`; }
w.opCal = (d: number) => { if (!d) { const n = new Date(); CAL = `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, '0')}`; } else { const [y, m] = CAL.split('-').map(Number); const n = new Date(y, m - 1 + d, 1); CAL = `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, '0')}`; } draw(); };
/* ---------------- notifications (page and top-bar bell) ---------------- */
const SERVER_READ = new Map<number, boolean>();
async function syncNotifs() { try { const r = await call<any>('GET', '/notifications'); SERVER_READ.clear(); r.items.forEach((n: any) => SERVER_READ.set(n.id, n.read));
  X.notifs = r.items.map((n: any) => ({ id: 's' + n.id, sid: n.id, at: String(n.createdAt).slice(0, 16), txt: n.message, ref: '', lvl: 'info', read: n.read ? 1 : 0 })); refreshBell(); } catch { /* keep last state */ } }
/** Background sync only refreshes the top bar: a full page redraw here would wipe text a user is typing elsewhere. */
function refreshBell() { // update only the badge and label in place: replacing the top bar would destroy the element a keyboard user has focused
  const btn = document.querySelector('#top button[aria-label^="Notifications"]') as HTMLElement | null; if (!btn) return; const nu = (X.notifs || []).filter((n: any) => !n.read).length;
  btn.setAttribute('aria-label', 'Notifications' + (nu ? ', ' + nu + ' unread' : '')); const b = btn.querySelector('.nbadge'); if (!nu) { if (b) b.remove(); return; }
  if (b) b.textContent = nu > 9 ? '9+' : String(nu); else { const sp = document.createElement('span'); sp.className = 'nbadge'; sp.textContent = nu > 9 ? '9+' : String(nu); btn.append(sp); } }
/** The restored bell marks items read in memory; persist those changes. */
function pushReads() { const changed = (X.notifs || []).filter((n: any) => n.sid && !!n.read !== SERVER_READ.get(n.sid)); if (!changed.length) return;
  changed.forEach((n: any) => SERVER_READ.set(n.sid, !!n.read)); const allRead = (X.notifs || []).every((n: any) => n.read);
  (allRead && changed.length > 1 ? call('POST', '/notifications/read-all') : Promise.all(changed.map((n: any) => call('POST', `/notifications/${n.sid}/${n.read ? 'read' : 'unread'}`)))).catch(() => syncNotifs()); }
const NF: any = { unread: '', category: '' };
function notificationsPage(): string { const c = load('notifs', '/notifications' + (NF.unread ? '?unread=1' : '') + (NF.category ? (NF.unread ? '&' : '?') + 'category=' + NF.category : ''));
  return hd('Notifications', `<button class="btn o" onclick="opNotif(0,'read-all')">Mark all read</button>`) + `<div class="seg" style="margin-bottom:12px">${[['', 'All'], ['1', 'Unread']].map(x => `<button class="${NF.unread === x[0] ? 'on' : ''}" onclick="opNF('unread','${x[0]}')">${x[1]}</button>`).join('')}</div>`
    + state(c, NF.unread ? 'No unread notifications' : 'No notifications yet', d => d.items.length ? cd(`${d.unread} unread`, `<div data-live="notifications">${d.items.map((n: any) => `<div class="ts" style="font-size:13px"><span style="flex:1">${n.read ? '' : '<b style="color:var(--gd)">● </b>'}<a class="lk" onclick="opNotifOpen(${n.id},'${h(n.refType || '')}',${n.refId || 0})">${h(n.message)}</a><div class="mu" style="font-size:11px">${h(String(n.createdAt).slice(0, 16))} · ${h(String(n.category).replace(/_/g, ' '))}</div></span>
      <button class="btn o s" onclick="opNotif(${n.id},'${n.read ? 'unread' : 'read'}')">${n.read ? 'Mark unread' : 'Mark read'}</button><button class="btn o s" aria-label="Dismiss notification" onclick="opNotif(${n.id},'dismiss')">Dismiss</button></div>`).join('')}</div>`) : ''); }
w.opNF = (k: string, v: string) => { NF[k] = v; draw(); };
w.opNotif = async (id: number, a: string) => { try { await call('POST', a === 'read-all' ? '/notifications/read-all' : `/notifications/${id}/${a}`); invalidate('notifs'); await syncNotifs(); draw(); } catch (e) { toast(msg(e)); } };
w.opNotifOpen = async (id: number, type: string, ref: number) => { await call('POST', `/notifications/${id}/read`).catch(() => {}); invalidate('notifs'); syncNotifs();
  if (type === 'task' && ref) w.opTaskView(ref); else if (type === 'trip' && ref) { S.cur = 'd' + ref; S.tsec = 'Overview'; go('trip'); } else if (type === 'contact' && ref) w.lcOpen(ref); else if (type === 'invoice' && ref && w.fiOpen) w.fiOpen('invoice', ref); else draw(); };
w.opRetry = () => { for (const k in C) if (C[k].error) { C[k].error = null; C[k].key = ''; } draw(); };
export const LIVE_OPS = ['tasks', 'calendar', 'notifications'];
export function installOps(perms: (p: string) => boolean, userId: number) {
  can = perms; ME = userId; w.notify = () => {}; X.notifs = [];
  Pg.tasks = tasksPage; Pg.calendar = calendarPage; Pg.notifications = notificationsPage;
  const d0 = w.draw; w.draw = function (...a: any[]) { const r = d0.apply(this, a); try { pushReads(); } catch { /* ignore */ } return r; };
  syncNotifs(); setInterval(syncNotifs, 30000);
}
