import { useState } from 'react';
import { api, ApiError } from '../api';
import { navigate } from '../route';
export function Reset({ token }: { token: string }) {
  const [pw, setPw] = useState(''); const [pw2, setPw2] = useState(''); const [msg, setMsg] = useState(''); const [err, setErr] = useState('');
  const submit = async (e: React.FormEvent) => { e.preventDefault(); setErr(''); if (pw !== pw2) return setErr('Passwords do not match');
    try { await api('POST', '/auth/reset', { token, password: pw }); setMsg('Password updated. You can sign in now.'); } catch (x) { setErr(x instanceof ApiError ? x.message : 'Reset failed'); } };
  return (<div style={{ minHeight: '100vh', display: 'grid', placeItems: 'center', padding: 16 }}><form className="cd fm" style={{ width: 'min(400px,100%)', padding: 24 }} onSubmit={submit}>
    <h1 style={{ fontSize: 20 }}>Choose a new password</h1>
    {msg ? <><p>{msg}</p><button type="button" className="btn" onClick={() => navigate('/login')}>Go to sign in</button></> : <>
      <label htmlFor="p1">New password (12+ characters)</label><input id="p1" type="password" autoComplete="new-password" value={pw} onChange={e => setPw(e.target.value)} required minLength={12} />
      <label htmlFor="p2">Confirm password</label><input id="p2" type="password" autoComplete="new-password" value={pw2} onChange={e => setPw2(e.target.value)} required />
      <div className="err" role="alert">{err}</div><button className="btn" style={{ width: '100%' }}>Update password</button></>}
  </form></div>);
}
