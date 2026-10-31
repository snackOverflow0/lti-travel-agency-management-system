import { useEffect, useState } from 'react';
import { api, ApiError, Contact } from '../api';
import { Dialog, Field } from '../components/ui';
import { useAuth } from '../auth';
type Form = Record<string, string>;
const EMPTY: Form = { firstName: '', lastName: '', email: '', phone: '', type: 'client', leadStatus: '', source: '', birthday: '', anniversary: '', passportExpiry: '', notes: '', assignedTo: '' };
export function ContactForm({ initial, onClose, onSaved }: { initial?: Contact; onClose: () => void; onSaved: (c: Contact) => void }) {
  const { can, user } = useAuth();
  const [f, setF] = useState<Form>(() => initial ? Object.fromEntries(Object.keys(EMPTY).map(k => [k, String((initial as any)[k] ?? '')])) : { ...EMPTY, assignedTo: String(user!.id) });
  const [errs, setErrs] = useState<Record<string, string>>({}); const [err, setErr] = useState(''); const [busy, setBusy] = useState(false);
  const [staff, setStaff] = useState<{ id: number; fullName: string }[]>([]);
  useEffect(() => { api<{ items: { id: number; fullName: string }[] }>('GET', '/contacts/staff-options').then(r => setStaff(r.items)).catch(() => {}); }, []);
  const set = (k: string) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => setF({ ...f, [k]: e.target.value });
  const save = async (allowDuplicate = false) => {
    setBusy(true); setErr(''); setErrs({});
    const body: Record<string, unknown> = { ...f, assignedTo: f.assignedTo ? Number(f.assignedTo) : null, allowDuplicate };
    if (initial) body.version = initial.version;
    try { const r = await api<{ contact: Contact }>(initial ? 'PUT' : 'POST', initial ? `/contacts/${initial.id}` : '/contacts', body); onSaved(r.contact); }
    catch (x) { if (x instanceof ApiError) { setErr(x.message); setErrs(x.fields); if (x.status === 409 && x.fields.email && confirm('A contact with this email already exists. Create anyway?')) return save(true); } else setErr('Could not save'); }
    finally { setBusy(false); } };
  return (<Dialog title={initial ? 'Edit contact' : 'New contact'} onClose={onClose} onSubmit={() => save()} submitLabel={initial ? 'Save changes' : 'Create contact'} busy={busy} error={err}>
    <div className="g g2">
      <Field label="First name" error={errs.firstName}><input name="firstName" value={f.firstName} onChange={set('firstName')} required maxLength={100} /></Field>
      <Field label="Last name" error={errs.lastName}><input name="lastName" value={f.lastName} onChange={set('lastName')} maxLength={100} /></Field>
      <Field label="Email" error={errs.email}><input name="email" type="email" value={f.email} onChange={set('email')} /></Field>
      <Field label="Phone" error={errs.phone}><input name="phone" type="tel" value={f.phone} onChange={set('phone')} /></Field>
      <Field label="Type" error={errs.type}><select name="type" value={f.type} onChange={set('type')}><option value="client">Client</option><option value="lead">Lead</option><option value="traveler">Traveler</option><option value="supplier_contact">Supplier contact</option></select></Field>
      <Field label="Lead status" error={errs.leadStatus}><select name="leadStatus" value={f.leadStatus} onChange={set('leadStatus')}><option value="">—</option>{['new','contacted','qualified','quoted','won','lost'].map(s => <option key={s} value={s}>{s[0].toUpperCase() + s.slice(1)}</option>)}</select></Field>
      <Field label="Source" error={errs.source}><input name="source" value={f.source} onChange={set('source')} /></Field>
      <Field label="Assigned to" error={errs.assignedTo}><select name="assignedTo" value={f.assignedTo} onChange={set('assignedTo')} disabled={!can('contacts.assign')}><option value="">Unassigned</option>{staff.map(s => <option key={s.id} value={s.id}>{s.fullName}</option>)}</select></Field>
      <Field label="Birthday" error={errs.birthday}><input name="birthday" type="date" value={f.birthday} onChange={set('birthday')} /></Field>
      <Field label="Anniversary" error={errs.anniversary}><input name="anniversary" type="date" value={f.anniversary} onChange={set('anniversary')} /></Field>
      <Field label="Passport expiration" error={errs.passportExpiry}><input name="passportExpiry" type="date" value={f.passportExpiry} onChange={set('passportExpiry')} /></Field>
    </div>
    <Field label="Notes" error={errs.notes}><textarea name="notes" rows={3} value={f.notes} onChange={set('notes')} /></Field>
  </Dialog>);
}
