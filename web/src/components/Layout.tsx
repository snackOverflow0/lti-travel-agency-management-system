import { ReactNode, useState } from 'react';
import { Icon } from '../icons';
import { useAuth } from '../auth';
import { ROLE_LABEL } from '../api';
import { navigate } from '../route';
const initials = (n: string) => n.split(/\s+/).map(x => x[0]).slice(0, 2).join('').toUpperCase();
export function Layout({ section, children }: { section: 'contacts' | 'staff'; children: ReactNode }) {
  const { user, logout, can } = useAuth(); const [menu, setMenu] = useState(false); const [drawer, setDrawer] = useState(false);
  const nav = [{ k: 'contacts', l: 'Contacts', to: '/contacts', show: can('contacts.view') }, { k: 'staff', l: 'Staff', to: '/staff', show: can('staff.view') }].filter(x => x.show);
  return (<>
    <div className="top" id="top">
      <button className="ib hb" aria-label="Open menu" onClick={() => setDrawer(true)}><Icon name="menu" size={18} /></button>
      <div className="lg"><img src="./logo.png" alt="Luxury Travel Institute logo" /></div>
      <nav className="tn" aria-label="Main">{nav.map(n => <a key={n.k} href={'#' + n.to} className={section === n.k ? 'on' : ''}>{n.l}</a>)}</nav>
      <div className="rel" style={{ marginLeft: 'auto' }}>
        <button className="pf" aria-label="Profile menu" aria-expanded={menu} onClick={() => setMenu(!menu)}>{initials(user!.fullName)}</button>
        {menu && <div className="dd"><div className="mu">{user!.fullName} · {ROLE_LABEL[user!.role] || user!.role}</div><a role="button" tabIndex={0} onClick={() => logout()}>Sign out</a></div>}
      </div>
    </div>
    <div className="lay">
      <aside className="sb" id="sb"><h4>{section === 'staff' ? 'Settings' : 'Contacts'}</h4>
        {section === 'contacts' ? <a className="on" href="#/contacts"><Icon name="users" /><span>Contacts</span></a> : <a className="on" href="#/staff"><Icon name="users" /><span>Staff accounts</span></a>}
      </aside>
      <main id="m">{children}</main>
    </div>
    {drawer && <div id="ov"><div className="mo" onMouseDown={e => { if (e.target === e.currentTarget) setDrawer(false); }}><div role="dialog" aria-label="Menu" className="fm">
      {nav.map(n => <p key={n.k}><a className="lk" onClick={() => { setDrawer(false); navigate(n.to); }}>{n.l}</a></p>)}
      <p><a className="lk" onClick={() => logout()}>Sign out</a></p></div></div></div>}
  </>);
}
