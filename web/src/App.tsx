import { useEffect } from 'react';
import { useAuth } from './auth';
import { navigate, useRoute } from './route';
import { Login } from './pages/Login';
import { Reset } from './pages/Reset';
import { PublicForm } from './pages/PublicForm';
import { bootPrototype } from './prototype/host';
export function App() {
  const { user, ready } = useAuth(); const { path, query } = useRoute();
  const isPublic = path.startsWith('/f/');
  useEffect(() => { if (ready && !user && path !== '/login' && path !== '/reset' && !isPublic) navigate('/login'); }, [ready, user, path]);
  useEffect(() => { if (ready && user && path !== '/reset' && !isPublic) bootPrototype(user); }, [ready, user, path]);
  if (!ready) return <p className="mu" style={{ padding: 24 }}>Loading</p>;
  if (isPublic) return <PublicForm token={path.slice(3)} />;
  if (path === '/reset') return <Reset token={query.get('token') || ''} />;
  if (!user) return <Login />;
  return null; // the restored Revision 27 interface renders outside React (see prototype/host.ts)
}
