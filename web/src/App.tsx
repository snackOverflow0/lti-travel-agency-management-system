import { useEffect } from 'react';
import { useAuth } from './auth';
import { navigate, useRoute } from './route';
import { Layout } from './components/Layout';
import { Login } from './pages/Login';
import { Contacts } from './pages/Contacts';
import { ContactDetail } from './pages/ContactDetail';
import { Staff } from './pages/Staff';
import { Reset } from './pages/Reset';
import { Empty, Header } from './components/ui';
export function App() {
  const { user, ready, can } = useAuth(); const { path, query } = useRoute();
  useEffect(() => { if (ready && !user && path !== '/login' && path !== '/reset') navigate('/login'); if (ready && user && path === '/login') navigate('/contacts'); }, [ready, user, path]);
  if (!ready) return <p className="mu" style={{ padding: 24 }}>Loading</p>;
  if (path === '/reset') return <Reset token={query.get('token') || ''} />;
  if (!user) return <Login />;
  if (user.role === 'traveler') return <div style={{ padding: 24 }}><Header title="Traveler portal" sub="The traveler portal is delivered in Phase 3." /></div>;
  const m = path.match(/^\/contacts\/(\d+)$/);
  if (m) return <Layout section="contacts"><ContactDetail id={Number(m[1])} /></Layout>;
  if (path === '/staff') return <Layout section="staff">{can('staff.view') ? <Staff /> : <Empty icon="lock" title="Restricted for your role" />}</Layout>;
  return <Layout section="contacts">{can('contacts.view') ? <Contacts /> : <Empty icon="lock" title="Restricted for your role" />}</Layout>;
}
