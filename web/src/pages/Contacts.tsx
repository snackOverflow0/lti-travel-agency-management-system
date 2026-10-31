import { useEffect, useState } from 'react';
import { api, ApiError, Contact, TYPE_LABEL } from '../api';
import { Empty, Header, Toast } from '../components/ui';
import { Icon } from '../icons';
import { useAuth } from '../auth';
import { navigate } from '../route';
import { ContactForm } from './ContactForm';
export function Contacts() {
  const { can } = useAuth();
  const [q, setQ] = useState(''); const [type, setType] = useState(''); const [page, setPage] = useState(1);
  const [data, setData] = useState<{ items: Contact[]; total: number; perPage: number } | null>(null); const [err, setErr] = useState('');
  const [adding, setAdding] = useState(false); const [toast, setToast] = useState('');
  const load = () => { const p = new URLSearchParams({ q, type, page: String(page), perPage: '25' });
    api<{ items: Contact[]; total: number; perPage: number }>('GET', '/contacts?' + p).then(setData).catch(x => setErr(x instanceof ApiError ? x.message : 'Could not load contacts')); };
  useEffect(() => { const t = setTimeout(load, 200); return () => clearTimeout(t); }, [q, type, page]);
  const pages = data ? Math.max(1, Math.ceil(data.total / data.perPage)) : 1;
  return (<>
    <Header title="Contacts" sub={data ? `${data.total} contact${data.total === 1 ? '' : 's'}` : undefined}
      actions={can('contacts.create') && <button className="btn" onClick={() => setAdding(true)}>New contact</button>} />
    <div className="row" style={{ marginBottom: 12 }}>
      <div className="si2"><span><Icon name="search" /></span><input aria-label="Search contacts" placeholder="Search name, email or phone" value={q} onChange={e => { setQ(e.target.value); setPage(1); }} /></div>
      <select aria-label="Contact type" style={{ width: 'auto' }} value={type} onChange={e => { setType(e.target.value); setPage(1); }}><option value="">All types</option>{Object.entries(TYPE_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>
    </div>
    {err && <div className="err" role="alert">{err}</div>}
    {data && (data.items.length ? <div className="cd"><div className="tw"><table className="t2"><thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Type</th><th>Assigned to</th></tr></thead><tbody>
      {data.items.map(c => <tr key={c.id} className="c" tabIndex={0} onClick={() => navigate('/contacts/' + c.id)} onKeyDown={e => { if (e.key === 'Enter') navigate('/contacts/' + c.id); }}>
        <td><span className="lk">{c.firstName} {c.lastName}</span></td><td>{c.email || '—'}</td><td>{c.phone || '—'}</td><td><span className="tag">{TYPE_LABEL[c.type]}</span></td><td>{c.assignedName || 'Unassigned'}</td></tr>)}
    </tbody></table></div></div> : <Empty icon="users" title="No contacts found" text={q || type ? 'Try a different search or filter.' : 'Create your first contact.'} />)}
    {data && pages > 1 && <div className="pgx"><span className="mu">Page {page} of {pages}</span><div className="row"><button className="btn o s" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</button><button className="btn o s" disabled={page >= pages} onClick={() => setPage(page + 1)}>Next</button></div></div>}
    {adding && <ContactForm onClose={() => setAdding(false)} onSaved={c => { setAdding(false); setToast('Contact saved'); navigate('/contacts/' + c.id); }} />}
    <Toast text={toast} />
  </>);
}
