import { useEffect, useState } from 'react';
import { api, ApiError, Contact, TYPE_LABEL } from '../api';
import { Dialog, Empty, Header, fmtDate } from '../components/ui';
import { useAuth } from '../auth';
import { navigate } from '../route';
import { ContactForm } from './ContactForm';
export function ContactDetail({ id }: { id: number }) {
  const { can } = useAuth(); const [c, setC] = useState<Contact | null>(null); const [err, setErr] = useState(''); const [edit, setEdit] = useState(false); const [del, setDel] = useState(false);
  useEffect(() => { setC(null); setErr(''); api<{ contact: Contact }>('GET', '/contacts/' + id).then(r => setC(r.contact)).catch(x => setErr(x instanceof ApiError ? x.message : 'Could not load contact')); }, [id]);
  if (err) return <><Header title="Contact" actions={<button className="btn o" onClick={() => navigate('/contacts')}>Back to contacts</button>} /><Empty icon="users" title={err} /></>;
  if (!c) return <p className="mu">Loading</p>;
  const rows: [string, string][] = [['Email', c.email || '—'], ['Phone', c.phone || '—'], ['Type', TYPE_LABEL[c.type]], ['Lead status', c.leadStatus || '—'], ['Source', c.source || '—'], ['Assigned to', c.assignedName || 'Unassigned'],
    ['Birthday', fmtDate(c.birthday)], ['Anniversary', fmtDate(c.anniversary)], ['Passport expiration', fmtDate(c.passportExpiry)], ['Created', fmtDate(c.createdAt)], ['Last updated', fmtDate(c.updatedAt)]];
  return (<>
    <Header title={`${c.firstName} ${c.lastName || ''}`.trim()} sub={TYPE_LABEL[c.type]} actions={<>
      <button className="btn o" onClick={() => navigate('/contacts')}>Back to contacts</button>
      {c.phone && <a className="btn o" href={'tel:' + c.phone.replace(/[^0-9+]/g, '')}>Call</a>}
      {can('contacts.edit') && <button className="btn o" onClick={() => setEdit(true)}>Edit</button>}
      {can('contacts.delete') && <button className="btn o" onClick={() => setDel(true)}>Delete</button>}</>} />
    <section className="cd"><header><h2>Details</h2></header><div className="bd"><div className="g g2">{rows.map(([k, v]) => <div key={k} className="kv"><b>{k}</b><span data-field={k}>{v}</span></div>)}</div>
      {c.notes && <><h3 className="h3" style={{ fontSize: 13, marginTop: 12 }}>Notes</h3><p style={{ whiteSpace: 'pre-wrap' }}>{c.notes}</p></>}</div></section>
    {edit && <ContactForm initial={c} onClose={() => setEdit(false)} onSaved={n => { setC(n); setEdit(false); }} />}
    {del && <Dialog title="Delete contact" onClose={() => setDel(false)} submitLabel="Delete" onSubmit={async () => { await api('DELETE', '/contacts/' + c.id); navigate('/contacts'); }}><p>Delete {c.firstName} {c.lastName}? The record is kept in the audit history.</p></Dialog>}
  </>);
}
