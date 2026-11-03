// Connects the Content hub's Destination guides and Task templates tabs, and the itinerary, photo, cruise,
// task, workflow and automation library pages, to the PHP library API (records migrated from Revision 27 included).
import { api, ApiError } from '../api';
const w = window as any;
let can: (p: string) => boolean = () => false;
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
const state = (c: Cache, empty: string, render: (d: any) => string) => c.error ? `<div class="err" role="alert">${h(c.error)} <button class="btn o s" onclick="lbRetry()">Retry</button></div>` : !c.data ? '<p class="mu">Loading</p>' : render(c.data) || emp('doc', empty, '');
const originTag = (t: any) => t.origin === 'revision27' ? ' <span class="tag" title="Migrated from the Revision 27 prototype; review before production use">Imported from Revision 27</span>' : '';
const manage = () => can('library.manage');
const TABS = ['Activities', 'Destination guides', 'Packing lists', 'Task templates', 'More libraries'];
const PRI: [string, string][] = [['low', 'Low'], ['normal', 'Normal'], ['high', 'High'], ['urgent', 'Urgent']];
/* ---------------- destination guides ---------------- */
function guidesView(): string { const c = load('guides', '/library/templates?kind=guide');
  return `<div class="row" style="margin-bottom:10px"><span style="flex:1"></span>${manage() ? '<button class="btn" onclick="lbGuide(0)">New guide</button>' : ''}</div>` + state(c, 'No destination guides', d => d.items.length ? `<div class="g g2" data-live="guides">${d.items.map((t: any) => `<section class="cd"><header><h2>${h(t.title)}${originTag(t)}</h2><div>${manage() ? `<button class="btn o s" onclick="lbGuide(${t.id})">Edit</button><button class="btn o s" onclick="lbArchive(${t.id},'guides')">Archive</button>` : ''}</div></header><div class="bd"><p class="mu">${h(t.body.destination || '')}</p>${(t.body.sections || []).map((s: any) => `<p><b>${h(s.title)}</b><br>${h(s.text)}</p>`).join('')}</div></section>`).join('')}</div>` : ''); }
w.lbGuide = async (id: number) => { let t: any = { title: '', body: { destination: '', sections: [{ title: '', text: '' }] } }; if (id) { try { t = (await call<any>('GET', '/library/templates/' + id)).template; } catch (e) { toast(msg(e)); return; } }
  const row = (s: any, i: number) => `<div class="cd" style="padding:8px;margin-bottom:6px" data-sec="${i}"><input class="gs-t" aria-label="Section ${i + 1} title" placeholder="Section title" value="${h(s.title)}"><textarea class="gs-x" aria-label="Section ${i + 1} text" rows="3">${h(s.text)}</textarea></div>`;
  dlgW(id ? 'Edit destination guide' : 'New destination guide', `<div class="fm"><label>Title</label><input id="gd1" value="${h(t.title)}"><label>Destination</label><input id="gd2" value="${h(t.body.destination || '')}"><label>Sections</label><div id="gdS">${(t.body.sections || []).map(row).join('')}</div><button class="btn o s" type="button" onclick="lbGuideAdd()">Add section</button></div>`, 'Save guide', async () => {
    const sections = [...document.querySelectorAll('#gdS [data-sec]')].map(x => ({ title: (x.querySelector('.gs-t') as HTMLInputElement).value.trim(), text: (x.querySelector('.gs-x') as HTMLTextAreaElement).value.trim() })).filter(s => s.title || s.text);
    const body = { kind: 'guide', title: val('gd1'), summary: val('gd2'), body: { ...t.body, destination: val('gd2'), sections } };
    try { await call(id ? 'PUT' : 'POST', '/library/templates' + (id ? '/' + id : ''), id ? { ...body, version: t.version } : body); cl(); invalidate('guides'); toast('Guide saved'); draw(); } catch (e) { errBox(msg(e)); } }, 720); };
w.lbGuideAdd = () => { const el = document.getElementById('gdS'); if (!el) return; const i = el.children.length; el.insertAdjacentHTML('beforeend', `<div class="cd" style="padding:8px;margin-bottom:6px" data-sec="${i}"><input class="gs-t" aria-label="Section ${i + 1} title" placeholder="Section title"><textarea class="gs-x" aria-label="Section ${i + 1} text" rows="3"></textarea></div>`); };
/* ---------------- task templates ---------------- */
function taskTplView(): string { const c = load('tasktpl', '/library/templates?kind=task');
  return `<div class="row" style="margin-bottom:10px"><span class="mu" style="font-size:12px;flex:1">Reusable tasks: “Create task” sets the due date from the template’s day offset.</span>${manage() ? '<button class="btn" onclick="lbTask(0)">New task template</button>' : ''}</div>`
    + state(c, 'No task templates', d => d.items.length ? `<div class="cd"><div class="tw"><table class="t2" data-live="task-templates"><thead><tr><th>Template</th><th>Due after</th><th>Priority</th><th></th></tr></thead><tbody>${d.items.map((t: any) => `<tr><td>${h(t.title)}${originTag(t)}</td><td>${h(t.body.dueInDays)} day${t.body.dueInDays === 1 ? '' : 's'}</td><td>${h((PRI.find(p => p[0] === t.body.priority) || ['', 'Normal'])[1])}</td>
      <td style="white-space:nowrap">${can('tasks.manage') ? `<button class="btn o s" onclick="lbUseTask(${t.id})">Create task</button>` : ''}${manage() ? `<button class="btn o s" onclick="lbTask(${t.id})">Edit</button><button class="btn o s" onclick="lbArchive(${t.id},'tasktpl')">Archive</button>` : ''}</td></tr>`).join('')}</tbody></table></div></div>` : ''); }
w.lbTask = async (id: number) => { let t: any = { title: '', body: { dueInDays: 1, priority: 'normal' } }; if (id) { try { t = (await call<any>('GET', '/library/templates/' + id)).template; } catch (e) { toast(msg(e)); return; } }
  dlg(id ? 'Edit task template' : 'New task template', `<label>Task</label><input id="tt1" value="${h(t.title)}"><label>Due after (days)</label><input id="tt2" type="number" min="0" max="365" value="${h(t.body.dueInDays)}"><label>Priority</label>${sel('tt3', PRI, t.body.priority || 'normal')}`, 'Save', async () => {
    const body = { kind: 'task', title: val('tt1'), body: { ...t.body, dueInDays: Number(val('tt2')) || 0, priority: val('tt3') } };
    try { await call(id ? 'PUT' : 'POST', '/library/templates' + (id ? '/' + id : ''), id ? { ...body, version: t.version } : body); cl(); invalidate('tasktpl'); toast('Task template saved'); draw(); } catch (e) { errBox(msg(e)); } }); };
w.lbUseTask = async (id: number) => { let t: any, staff: any[]; try { [t, staff] = await Promise.all([call<any>('GET', '/library/templates/' + id).then(r => r.template), call<any>('GET', '/contacts/staff-options').then(r => r.items)]); } catch (e) { toast(msg(e)); return; }
  const due = new Date(); due.setDate(due.getDate() + Number(t.body.dueInDays || 0)); const iso = `${due.getFullYear()}-${String(due.getMonth() + 1).padStart(2, '0')}-${String(due.getDate()).padStart(2, '0')}`;
  dlg('Create task from template', `<label>Task</label><input id="ut1" value="${h(t.title)}"><label>Assign to</label>${sel('ut2', staff.map((s: any) => [String(s.id), s.fullName]), '')}<label>Due date</label><input id="ut3" type="date" value="${iso}">`, 'Create task', async () => {
    try { await call('POST', '/tasks', { title: val('ut1'), assignedTo: Number(val('ut2')), dueDate: val('ut3'), priority: t.body.priority || 'normal' }); cl(); toast('Task created'); } catch (e) { errBox(msg(e)); } }); };
w.lbArchive = async (id: number, cache: string) => { try { await call('POST', `/library/templates/${id}/archive`); invalidate(cache); toast('Archived'); draw(); } catch (e) { toast(msg(e)); } };
/* ---------------- folder-based library pages ---------------- */
const PATH: Record<string, number | null> = {};
function folderBrowser(section: string, title: string, sub: string, items?: (folderId: number | null) => string): string {
  const c = load('f-' + section, '/library/folders?section=' + section); const cur = PATH[section] ?? null;
  return hd(title, manage() ? `<button class="btn o" onclick="lbFolder('${section}')">New folder</button>` : '', sub) + state(c, 'No folders', d => { const all = d.items; const here = all.filter((f: any) => (f.parentId ?? null) === cur);
    const crumbs: any[] = []; let x = cur; while (x) { const f = all.find((y: any) => y.id === x); if (!f) break; crumbs.unshift(f); x = f.parentId; }
    return `<div class="mu" style="margin-bottom:10px" data-live="breadcrumb"><a class="lk" onclick="lbCd('${section}',null)">${h(title)}</a>${crumbs.map(f => ` › <a class="lk" onclick="lbCd('${section}',${f.id})">${h(f.name)}</a>`).join('')}</div>`
      + (here.length ? `<div class="cd"><div class="tw"><table class="t2" data-live="folders-${section}"><thead><tr><th>Folder</th><th>Subfolders</th><th>Source</th></tr></thead><tbody>${here.map((f: any) => `<tr class="c" tabindex="0" onclick="lbCd('${section}',${f.id})"><td>${ic('folder', 14)} ${h(f.name)}</td><td>${all.filter((y: any) => y.parentId === f.id).length}</td><td style="font-size:12px">${f.origin === 'revision27' ? 'Imported from Revision 27' : 'Created in LTI'}</td></tr>`).join('')}</tbody></table></div></div>` : '<p class="mu">No subfolders here.</p>')
      + (items ? items(cur) : ''); }); }
w.lbCd = (section: string, id: number | null) => { PATH[section] = id; draw(); };
w.lbFolder = (section: string) => dlg('New folder', '<label>Name</label><input id="nf1">', 'Create folder', async () => {
  try { await call('POST', '/library/folders', { name: val('nf1'), parentId: PATH[section] ?? null, section }); cl(); invalidate('f-' + section); toast('Folder created'); draw(); } catch (e) { errBox(msg(e)); } });
function referencesPage(title: string, kindFilter: (b: any) => boolean, sub: string): string { const c = load('refs', '/library/templates?kind=workflow_reference');
  return hd(title, can('workflows.manage') ? `<button class="btn" onclick="wbOpen && wbOpen()">Build a workflow</button>` : '', sub)
    + `<p class="mu" style="font-size:12px">These are reference copies of the prototype’s templates. They do not run. Rebuild any you need in the workflow builder, where the server validates every trigger and action.</p>`
    + state(c, 'No references', d => { const L = d.items.filter((t: any) => kindFilter(t.body)); return L.length ? `<div class="cd"><div class="tw"><table class="t2" data-live="references"><thead><tr><th>Name</th><th>Type</th><th>Details</th><th></th></tr></thead><tbody>${L.map((t: any) => `<tr><td>${h(t.title)}${originTag(t)}</td><td style="font-size:12px">${h(t.body.kind)}</td><td style="font-size:12px">${h(t.summary || t.body.category || '')}</td><td><button class="btn o s" onclick="lbRef(${t.id})">View</button></td></tr>`).join('')}</tbody></table></div></div>` : ''; }); }
w.lbRef = async (id: number) => { let t: any; try { t = (await call<any>('GET', '/library/templates/' + id)).template; } catch (e) { toast(msg(e)); return; } const o = t.body.original || {};
  const steps = Array.isArray(o.steps) ? o.steps : Array.isArray(o.acts) ? o.acts : [];
  dlgW(h(t.title), `<p class="mu" style="font-size:12px">Reference only (${h(t.body.kind)}). Imported unchanged from Revision 27.</p>${o.desc ? `<p>${h(o.desc)}</p>` : ''}${o.trig ? `<div class="kv"><b>Trigger</b><span>${h(typeof o.trig === 'string' ? o.trig : JSON.stringify(o.trig))}</span></div>` : ''}${o.timing ? `<div class="kv"><b>Timing</b><span>${h(typeof o.timing === 'string' ? o.timing : JSON.stringify(o.timing))}</span></div>` : ''}
    ${steps.length ? `<h3 class="h3" style="font-size:13px">Steps</h3><ol style="font-size:13px">${steps.map((s: any) => `<li>${h(typeof s === 'string' ? s : s.n || s.name || s.t || s.type || JSON.stringify(s))}</li>`).join('')}</ol>` : ''}`, null, null, 640); };
function cruisePage(): string { const c = load('cruise', '/library/templates?kind=supplier_info');
  return hd('Cruises', '', 'Cruise products in the content library') + state(c, 'No cruises', d => d.items.length ? `<div class="cd"><div class="tw"><table class="t2" data-live="cruises"><thead><tr><th>Name</th><th>Type</th><th>Supplier</th></tr></thead><tbody>${d.items.map((t: any) => `<tr><td>${h(t.title)}${originTag(t)}</td><td>${h(t.body.type || '')}</td><td>${h(t.body.supplier || '')}</td></tr>`).join('')}</tbody></table></div></div>` : ''); }
w.lbRetry = () => { for (const k in C) if (C[k].error) { C[k].error = null; C[k].key = ''; } draw(); };
export const LIVE_LIBRARY = ['libit', 'libph', 'libcr', 'libta', 'libwf', 'libau', 'libt', 'libd'];
export function installLibrary(perms: (p: string) => boolean) {
  can = perms; const orig = Pg.content;
  Pg.content = () => { const k = tb('ct', TABS); if (k !== 'Destination guides' && k !== 'Task templates') return orig();
    return hd('Content hub', '', 'Reusable content for trips, emails, forms, tasks and workflows') + tabs('ct', TABS) + (k === 'Destination guides' ? guidesView() : taskTplView()); };
  Pg.libit = () => folderBrowser('itinerary', 'Itinerary templates', 'Folders imported from Revision 27', () => '<p class="mu" style="margin-top:10px">No itinerary templates existed in the prototype, only these folders. Build itineraries on a trip; saving them as reusable templates is not implemented yet.</p>');
  Pg.libph = () => folderBrowser('photos', 'Photos & image assets', 'Folder structure imported from Revision 27', () => '<p class="mu" style="margin-top:10px">No image files existed in the prototype (102 file names had no image data), so no photos were migrated. Upload images to a trip under Documents.</p>');
  Pg.libcr = cruisePage;
  Pg.libta = () => hd('Task templates', '', 'Reusable tasks') + taskTplView(); Pg.libt = Pg.libta;
  Pg.libd = () => hd('Destination guides', '', 'Destination knowledge for advisors and clients') + guidesView();
  Pg.libwf = () => referencesPage('Workflow templates', b => b.kind !== 'automation template', 'Reference copies of Revision 27 workflows');
  Pg.libau = () => referencesPage('Automation templates', b => b.kind === 'automation template', 'Reference copies of Revision 27 automation templates');
}
