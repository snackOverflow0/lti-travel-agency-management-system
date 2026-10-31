import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import './styles/lti.css';
import { AuthProvider } from './auth';
import { App } from './App';
createRoot(document.getElementById('root')!).render(<StrictMode><AuthProvider><App /></AuthProvider></StrictMode>);
