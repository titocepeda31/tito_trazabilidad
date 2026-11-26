export const API_BASE = (import.meta.env.VITE_API_BASE_URL || '').replace(/\/+$/, '');

export const TERMINAL_KEY='tito_pos_terminal_token_v1';
export class ApiError extends Error {
  constructor(message, status = 0, field = null) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.field = field;
  }
}
export async function api(path, { token, method = 'GET', body, signal } = {}) {
  if (!API_BASE) throw new ApiError('Configura VITE_API_BASE_URL para la API independiente.', 0);
  let response;
  try {
    response = await fetch(`${API_BASE}${path}`, {
      method,
      headers: { 'Accept': 'application/json', ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}), ...(token ? { Authorization: `Bearer ${token}` } : {}), ...(token&&localStorage.getItem(TERMINAL_KEY) ? { 'X-Tito-Terminal': localStorage.getItem(TERMINAL_KEY) } : {}) },
      ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
      cache: 'no-store',
      signal
    });
  } catch (error) {
    if (error?.name === 'AbortError') throw error;
    throw new ApiError('No se pudo conectar con la API. Revisa Internet o la configuración CORS.', 0);
  }
  const text = await response.text();
  let data = {};
  try { data = text ? JSON.parse(text) : {}; } catch { throw new ApiError(`La API respondió algo inesperado (HTTP ${response.status}).`, response.status); }
  if (!response.ok) throw new ApiError(data.error || `Error HTTP ${response.status}`, response.status, typeof data.field==='string'?data.field:null);
  return data;
}
export const pesos = (n) => new Intl.NumberFormat('es-CL', { style: 'currency', currency: 'CLP', maximumFractionDigits: 0 }).format(Number(n) || 0);
export const qtyFormat = (n) => new Intl.NumberFormat('es-CL', { maximumFractionDigits: 3 }).format(Number(n) || 0);
export const newId = () => crypto.randomUUID();
