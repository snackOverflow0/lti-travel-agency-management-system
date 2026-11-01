import { useEffect, useState } from 'react';
// Public form page (#/f/<token>): no sign-in; only published forms; answers are validated again on the server.
interface Field { key: string; label: string; type: string; required: boolean; options: string[]; showIf: string; step: number }
export function PublicForm({ token }: { token: string }) {
  const [form, setForm] = useState<{ name: string; steps: string[]; fields: Field[] } | null>(null); const [err, setErr] = useState(''); const [fieldErr, setFieldErr] = useState<Record<string, string>>({});
  const [ans, setAns] = useState<Record<string, string | boolean>>({}); const [done, setDone] = useState(false); const [busy, setBusy] = useState(false); const [hp, setHp] = useState('');
  useEffect(() => { fetch('./api/public/forms/' + token).then(async r => { const d = await r.json(); if (!r.ok) throw new Error(d.error || 'This form is not available'); setForm(d.form); }).catch(e => setErr(e.message)); }, [token]);
  const visible = (f: Field) => { if (!f.showIf) return true; const [k, v] = f.showIf.split('='); return String(ans[k] ?? '') === v; };
  const submit = async (e: React.FormEvent) => { e.preventDefault(); setBusy(true); setErr(''); setFieldErr({});
    try { const r = await fetch('./api/public/forms/' + token + '/submit', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ answers: ans, website: hp }) }); const d = await r.json().catch(() => ({}));
      if (!r.ok) { setErr(d.error || 'Submission failed'); setFieldErr(d.fields || {}); } else setDone(true); } catch { setErr('Submission failed. Check your connection.'); } finally { setBusy(false); } };
  const set = (k: string, v: string | boolean) => setAns({ ...ans, [k]: v });
  return (<div style={{ minHeight: '100vh', display: 'grid', placeItems: 'start center', padding: 16 }}><form className="cd fm" onSubmit={submit} style={{ width: 'min(640px,100%)', padding: 24, marginTop: 24 }} aria-label="Public form">
    <img src="./logo.png" alt="Luxury Travel Institute logo" style={{ height: 52, display: 'block', margin: '0 auto 10px' }} />
    {!form && !err && <p className="mu">Loading</p>}
    {err && !form && <><h1 style={{ fontSize: 20 }}>Form unavailable</h1><p>{err}</p></>}
    {form && done && <><h1 style={{ fontSize: 20 }}>Thank you</h1><p>Your response has been sent to Luxury Travel Institute.</p></>}
    {form && !done && <>
      <h1 style={{ fontSize: 20 }}>{form.name}</h1>
      {form.steps.map((s, i) => { const fs = form.fields.filter(f => (f.step || 1) === i + 1 && visible(f)); return fs.length ? <section key={i}><h2 className="h3" style={{ fontSize: 14, marginTop: 14 }}>{s}</h2>
        {fs.map(f => <div key={f.key}><label htmlFor={'pf_' + f.key}>{f.label}{f.required ? ' *' : ''}</label>
          {f.type === 'textarea' ? <textarea id={'pf_' + f.key} rows={3} value={String(ans[f.key] ?? '')} onChange={e => set(f.key, e.target.value)} />
            : f.type === 'select' ? <select id={'pf_' + f.key} value={String(ans[f.key] ?? '')} onChange={e => set(f.key, e.target.value)}><option value="">—</option>{f.options.map(o => <option key={o}>{o}</option>)}</select>
            : f.type === 'radio' ? <div role="radiogroup" aria-label={f.label} className="row">{f.options.map(o => <label key={o} className="ck" style={{ fontSize: 13 }}><input type="radio" name={'pf_' + f.key} checked={ans[f.key] === o} onChange={() => set(f.key, o)} /> {o}</label>)}</div>
            : f.type === 'checkbox' ? (f.options.length ? <div className="row">{f.options.map(o => { const cur = String(ans[f.key] || '').split(', ').filter(Boolean); return <label key={o} className="ck" style={{ fontSize: 13 }}><input type="checkbox" checked={cur.includes(o)} onChange={e => set(f.key, (e.target.checked ? [...cur, o] : cur.filter(x => x !== o)).join(', '))} /> {o}</label>; })}</div>
              : <label className="ck" style={{ fontSize: 13 }}><input id={'pf_' + f.key} type="checkbox" checked={ans[f.key] === true} onChange={e => set(f.key, e.target.checked)} /> Yes</label>)
            : <input id={'pf_' + f.key} type={f.type === 'email' ? 'email' : f.type === 'date' ? 'date' : f.type === 'number' ? 'number' : f.type === 'phone' ? 'tel' : 'text'} value={String(ans[f.key] ?? '')} onChange={e => set(f.key, e.target.value)} />}
          {fieldErr[f.key] && <div className="err" style={{ minHeight: 0 }}>{fieldErr[f.key]}</div>}</div>)}</section> : null; })}
      <input aria-hidden="true" tabIndex={-1} autoComplete="off" value={hp} onChange={e => setHp(e.target.value)} name="website" style={{ position: 'absolute', left: -9999, width: 1, height: 1 }} />
      <div className="err" role="alert">{err}</div><button className="btn" disabled={busy} style={{ marginTop: 8 }}>{busy ? 'Sending' : 'Submit'}</button>
      <p className="mu" style={{ fontSize: 12, marginTop: 10 }}>Your answers are sent securely to Luxury Travel Institute and used only to plan your travel.</p></>}
  </form></div>);
}
