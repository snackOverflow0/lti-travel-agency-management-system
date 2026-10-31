import { useEffect, useState } from 'react';
// Hash routing keeps deployment to a static folder on shared hosting trivial (no rewrite rules needed).
export function useRoute(): { path: string; query: URLSearchParams } {
  const get = () => { const h = location.hash.replace(/^#/, '') || '/contacts'; const [p, q] = h.split('?'); return { path: p, query: new URLSearchParams(q || '') }; };
  const [r, setR] = useState(get);
  useEffect(() => { const f = () => setR(get()); window.addEventListener('hashchange', f); return () => window.removeEventListener('hashchange', f); }, []);
  return r;
}
export const navigate = (p: string) => { location.hash = '#' + p; };
