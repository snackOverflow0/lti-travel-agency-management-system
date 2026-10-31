import { ReactNode, useEffect, useRef } from 'react';
import { Icon } from '../icons';
export function Header({ title, sub, actions }: { title: ReactNode; sub?: ReactNode; actions?: ReactNode }) {
  return <div className="hd"><div><h1>{title}</h1>{sub && <p>{sub}</p>}</div><div className="row">{actions}</div></div>;
}
export function Empty({ icon, title, text, children }: { icon: string; title: string; text?: string; children?: ReactNode }) {
  return <div className="em2"><Icon name={icon} size={18} /><b>{title}</b>{text && <p>{text}</p>}{children}</div>;
}
export function Dialog({ title, children, onClose, onSubmit, submitLabel, busy, error }: { title: string; children: ReactNode; onClose: () => void; onSubmit?: () => void; submitLabel?: string; busy?: boolean; error?: string }) {
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => { const k = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); }; window.addEventListener('keydown', k); ref.current?.querySelector<HTMLElement>('input,select,textarea')?.focus(); return () => window.removeEventListener('keydown', k); }, [onClose]);
  return (<div className="mo" onMouseDown={e => { if (e.target === e.currentTarget) onClose(); }}>
    <div role="dialog" aria-modal="true" aria-label={title} className="fm" ref={ref} style={{ maxWidth: 640 }}>
      <h2 style={{ fontSize: 15 }}>{title}</h2>
      <form onSubmit={e => { e.preventDefault(); onSubmit && onSubmit(); }}>{children}
        <div className="err" role="alert">{error}</div>
        <div className="row" style={{ marginTop: 14, justifyContent: 'flex-end' }}>
          <button type="button" className="btn o" onClick={onClose}>Cancel</button>
          {onSubmit && <button type="submit" className="btn g1" disabled={busy}>{busy ? 'Saving' : submitLabel || 'Save'}</button>}
        </div></form></div></div>);
}
export function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
  return <div><label>{label}</label>{children}{error && <div className="err" style={{ minHeight: 0 }}>{error}</div>}</div>;
}
export function Toast({ text }: { text: string }) { return text ? <div className="toast" role="status">{text}</div> : null; }
export const fmtDate = (s: string | null) => { if (!s) return '—'; const d = new Date(s.length === 10 ? s + 'T00:00:00Z' : s.replace(' ', 'T') + 'Z'); return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', timeZone: s.length === 10 ? 'UTC' : undefined }); };
