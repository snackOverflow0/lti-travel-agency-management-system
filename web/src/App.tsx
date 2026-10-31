import { useEffect } from 'react';
import { useAuth } from './auth';
import { navigate, useRoute } from './route';
import { Login } from './pages/Login';
import { Reset } from './pages/Reset';
import { bootPrototype } from './prototype/host';
export function App() {
  const { user, ready } = useAuth(); const { path, query } = useRoute();
  useEffect(() => { if (ready && !user && path !== '/login' && path !== '/reset') navigate('/login'); }, [ready, user, path]);
  useEffect(() => { if (ready && user && path !== '/reset') bootPrototype(user); }, [ready, user, path]);
  if (!ready) return <p className="mu" style={{ padding: 24 }}>Loading</p>;
  if (path === '/reset') return <Reset token={query.get('token') || ''} />;
  if (!user) return <Login />;
  return null; // the restored Revision 27 interface renders outside React (see prototype/host.ts)
}
