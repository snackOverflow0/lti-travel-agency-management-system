// Thin JSON client for the PHP API. Session cookie is HttpOnly; the CSRF token is kept in memory only (never in localStorage).
export class ApiError extends Error {
  constructor(public status: number, message: string, public fields: Record<string, string> = {}) { super(message); }
}
let csrf = '';
export const setCsrf = (t: string) => { csrf = t; };
export const getCsrf = () => csrf;
export async function api<T>(method: string, path: string, body?: unknown): Promise<T> {
  const res = await fetch('./api' + path, {
    method, credentials: 'same-origin',
    headers: { 'Accept': 'application/json', ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}), ...(method !== 'GET' ? { 'X-CSRF-Token': csrf } : {}) },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });
  let data: any = null;
  try { data = await res.json(); } catch { /* non-JSON */ }
  if (!res.ok) throw new ApiError(res.status, (data && data.error) || `Request failed (${res.status})`, (data && data.fields) || {});
  return data as T;
}
export interface User { id: number; email: string; fullName: string; role: string; permissions: string[] }
export interface Contact { id: number; firstName: string; lastName: string | null; email: string | null; phone: string | null; type: string; leadStatus: string | null;
  source: string | null; birthday: string | null; anniversary: string | null; passportExpiry: string | null; notes: string | null; assignedTo: number | null; assignedName: string | null;
  createdAt: string; updatedAt: string; version: number }
export const ROLE_LABEL: Record<string, string> = { owner: 'Company Owner', admin: 'Agency Administrator', supervisor: 'Team Supervisor', advisor: 'Travel Advisor', sales: 'Sales Staff', operations: 'Operations Staff', finance: 'Finance Staff', traveler: 'Traveler' };
export const TYPE_LABEL: Record<string, string> = { client: 'Client', lead: 'Lead', traveler: 'Traveler', supplier_contact: 'Supplier contact' };
