import { useState } from 'react';
import { useAuth } from '../auth';
import { ApiError } from '../api';
import { navigate } from '../route';
export function Login() {
  const { login } = useAuth(); const [email, setEmail] = useState(''); const [pw, setPw] = useState(''); const [err, setErr] = useState(''); const [busy, setBusy] = useState(false);
  const submit = async (e: React.FormEvent) => { e.preventDefault(); setErr(''); setBusy(true);
    try { await login(email, pw); navigate('/contacts'); } catch (x) { setErr(x instanceof ApiError ? x.message : 'Sign-in failed'); } finally { setBusy(false); } };
  return (<div style={{ minHeight: '100vh', display: 'grid', placeItems: 'center', padding: 16 }}>
    <form className="cd fm" onSubmit={submit} style={{ width: 'min(400px, 100%)', padding: 24 }} aria-label="Sign in">
      <img src="./logo.png" alt="Luxury Travel Institute logo" style={{ height: 56, display: 'block', margin: '0 auto 12px' }} />
      <h1 style={{ fontSize: 20, textAlign: 'center' }}>Sign in to LTI</h1>
      <label htmlFor="em">Email</label><input id="em" type="email" autoComplete="username" value={email} onChange={e => setEmail(e.target.value)} required />
      <label htmlFor="pw">Password</label><input id="pw" type="password" autoComplete="current-password" value={pw} onChange={e => setPw(e.target.value)} required />
      <div className="err" role="alert">{err}</div>
      <button className="btn" style={{ width: '100%', marginTop: 8 }} disabled={busy}>{busy ? 'Signing in' : 'Sign in'}</button>
      <p className="mu" style={{ fontSize: 12, marginTop: 12 }}>Forgot your password? Ask your administrator for a one-time reset link.</p>
    </form></div>);
}
