// Last line of defence: every write of the prototype's sample-data store passes through here.
// Whatever path produced it, nothing that belongs to the database is allowed into browser storage.
const DB_KEY = /^(d|dc|dd|df|dl|dp|dr|ds)\d+$/;
function markers(): string[] {
  const X0 = (window as any).S && (window as any).S.X; if (!X0) return [];
  const out = new Set<string>();
  (X0.bookings || []).filter((b: any) => b && b.db).forEach((b: any) => { if (b.ref) out.add(b.ref); if (b.conf) out.add(b.conf); if (b.supplier) out.add(b.supplier); });
  Object.entries(X0.supx || {}).forEach(([name, v]: any) => { if (v && v._db) out.add(name); });
  (X0.docs || []).filter((d: any) => d && d.db).forEach((d: any) => { if (d.n) out.add(d.n); });
  return Array.from(out).filter(m => m && m.length >= 4).map(m => JSON.stringify(m));
}
function scrub(node: any, marks: string[], depth = 0): any {
  if (depth > 40 || !node || typeof node !== 'object') return node;
  if (Array.isArray(node)) return node.filter(el => !(el && typeof el === 'object' && (el.db || (marks.length && marks.some(m => JSON.stringify(el).includes(m)))))).map(el => scrub(el, marks, depth + 1));
  const o: any = {};
  for (const [k, v] of Object.entries(node)) {
    if (DB_KEY.test(k)) continue;
    if (v && typeof v === 'object' && (v as any)._db) continue;
    if (typeof v === 'string' && (DB_KEY.test(v) || marks.includes(JSON.stringify(v)))) { o[k] = ''; continue; }
    o[k] = scrub(v, marks, depth + 1);
  }
  return o;
}
export function installStorageGuard(storeKey: string) {
  const w = window as any; if (w.__LTI_GUARD) return; w.__LTI_GUARD = true;
  const orig = Storage.prototype.setItem;
  Storage.prototype.setItem = function (key: string, value: string) {
    if (this === window.localStorage && key === storeKey) {
      try { const m = markers(); value = JSON.stringify(scrub(JSON.parse(value), m)); } catch { return; }   // never write an unscrubbed payload
    }
    return orig.call(this, key, value);
  };
}
