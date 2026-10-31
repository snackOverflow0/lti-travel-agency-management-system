import { useEffect, useState } from 'react';
import { api, ApiError } from '../api';
import { Dialog, Header, fmtDate } from '../components/ui';
import { useAuth } from '../auth';
interface S { id: number; email: string; fullName: string; role: string; roleName: string; isActive: boolean; lastLoginAt: string | null }
export function Staff() {
  const { can, user } = useAuth(); const [items, setItems] = useState<S[]>([]); const [err, setErr] = useState(''); const [link, setLink] = useState('');
  const load = () => api<{ items: S[] }>('GET', '/staff').then(r => setItems(r.items)).catch(x => setErr(x instanceof ApiError ? x.message : 'Could not load staff'));
  useEffect(() => { load(); }, []);
  const toggle = async (s: S) => { try { await api('PATCH', '/staff/' + s.id, { isActive: !s.isActive }); load(); } catch (x) { setErr(x instanceof ApiError ? x.message : 'Failed'); } };
  const reset = async (s: S) => { try { const r = await api<{ resetUrl: string }>('POST', `/staff/${s.id}/reset-link`); setLink(r.resetUrl); } catch (x) { setErr(x instanceof ApiError ? x.message : 'Failed'); } };
  return (<>
    <Header title="Staff accounts" sub="Accounts are created by an administrator. Deactivation takes effect on the person's next request." />
    {err && <div className="err" role="alert">{err}</div>}
    <div className="cd"><div className="tw"><table className="t2"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last sign-in</th>{can('staff.manage') && <th></th>}</tr></thead><tbody>
      {items.map(s => <tr key={s.id}><td>{s.fullName}</td><td>{s.email}</td><td>{s.roleName}</td><td><span className={'pl ' + (s.isActive ? 'Active' : 'Cancelled')}>{s.isActive ? 'Active' : 'Deactivated'}</span></td><td>{fmtDate(s.lastLoginAt)}</td>
        {can('staff.manage') && <td><div className="row" style={{ flexWrap: 'nowrap' }}>{s.id !== user!.id && <button className="btn o s" onClick={() => toggle(s)}>{s.isActive ? 'Deactivate' : 'Reactivate'}</button>}<button className="btn o s" onClick={() => reset(s)}>Reset link</button></div></td>}</tr>)}
    </tbody></table></div></div>
    {link && <Dialog title="One-time password reset link" onClose={() => setLink('')}><p>Give this link to the staff member through a trusted channel. It works once and expires in 60 minutes.</p><input readOnly value={link} aria-label="Reset link" onFocus={e => e.target.select()} /></Dialog>}
  </>);
}
