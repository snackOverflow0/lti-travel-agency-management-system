import { createContext, useContext, useEffect, useState, ReactNode } from 'react';
import { api, setCsrf, User } from './api';
interface AuthState { user: User | null; ready: boolean; login: (e: string, p: string) => Promise<void>; logout: () => Promise<void>; can: (p: string) => boolean }
const Ctx = createContext<AuthState | null>(null);
export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null); const [ready, setReady] = useState(false);
  useEffect(() => { api<{ user: User; csrf: string }>('GET', '/auth/me').then(r => { setCsrf(r.csrf); setUser(r.user); }).catch(() => setUser(null)).finally(() => setReady(true)); }, []);
  const login = async (email: string, password: string) => { const r = await api<{ user: User; csrf: string }>('POST', '/auth/login', { email, password }); setCsrf(r.csrf); setUser(r.user); };
  const logout = async () => { try { await api('POST', '/auth/logout'); } finally { setUser(null); setCsrf(''); location.hash = '#/login'; } };
  const can = (p: string) => !!user && user.permissions.includes(p);
  return <Ctx.Provider value={{ user, ready, login, logout, can }}>{children}</Ctx.Provider>;
}
export const useAuth = () => { const c = useContext(Ctx); if (!c) throw new Error('AuthProvider missing'); return c; };
