import { User } from '../api';
import { installAdapter } from './adapter';
// Builds the Revision 27 page skeleton outside React's tree and loads the prototype as a classic script (globals + inline handlers).
export function bootPrototype(user: User): void {
  const w = window as any; if (w.__LTI_BOOTED) return; w.__LTI_BOOTED = true;
  if (location.hash.startsWith('#/') || !location.hash) location.hash = 'dashboard';
  const host = document.createElement('div'); host.id = 'lti-app';
  host.innerHTML = '<div class="top" id="top"></div><div class="lay"><aside class="sb" id="sb"></aside><main id="m"></main></div><div id="ov"></div>';
  document.body.append(...Array.from(host.childNodes));
  const s = document.createElement('script'); s.src = './prototype/lti-prototype.js?v=' + __LTI_PROTO_VERSION__;
  s.onload = () => { const t = setInterval(() => { if (w.__LTI_READY) { clearInterval(t); installAdapter(user); } }, 20); };
  s.onerror = () => { document.getElementById('m')!.innerHTML = '<div class="err">The LTI interface could not be loaded. Check that prototype/lti-prototype.js was deployed.</div>'; };
  document.body.append(s);
}
