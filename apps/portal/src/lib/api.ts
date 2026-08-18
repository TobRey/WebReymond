/**
 * Schmaler Zugang zur WebReymond-API.
 *
 * `credentials: 'include'` ist entscheidend: Ohne diese Angabe schickt der
 * Browser den Sitzungs-Cookie nicht mit, und man wäre nach jedem Klick
 * wieder abgemeldet.
 */
const API_URL = process.env['NEXT_PUBLIC_API_URL'] ?? 'http://localhost:3001';

export interface ApiResult<T> {
  ok: boolean;
  status: number;
  data?: T;
  /** Meldung der API – bewusst allgemein gehalten, verrät keine Interna. */
  message?: string;
}

async function request<T>(path: string, init: RequestInit = {}): Promise<ApiResult<T>> {
  try {
    const response = await fetch(`${API_URL}${path}`, {
      ...init,
      credentials: 'include',
      headers: { 'content-type': 'application/json', ...init.headers },
    });

    const text = await response.text();
    const body: unknown = text ? JSON.parse(text) : undefined;

    if (!response.ok) {
      const message =
        (body as { message?: string; error?: { message?: string } } | undefined)?.message ??
        (body as { error?: { message?: string } } | undefined)?.error?.message;
      return { ok: false, status: response.status, ...(message ? { message } : {}) };
    }

    return { ok: true, status: response.status, data: body as T };
  } catch {
    // Netzwerkfehler: keine technischen Details nach aussen geben.
    return { ok: false, status: 0 };
  }
}

export interface Me {
  id: string;
  email: string;
  name: string;
  role: 'user' | 'customer' | 'admin';
}

export const api = {
  me: () => request<Me>('/v1/me'),

  signUp: (input: { name: string; email: string; password: string }) =>
    request('/api/auth/sign-up/email', { method: 'POST', body: JSON.stringify(input) }),

  signIn: (input: { email: string; password: string }) =>
    request('/api/auth/sign-in/email', { method: 'POST', body: JSON.stringify(input) }),

  signOut: () => request('/api/auth/sign-out', { method: 'POST', body: '{}' }),

  requestPasswordReset: (input: { email: string; redirectTo: string }) =>
    request('/api/auth/request-password-reset', { method: 'POST', body: JSON.stringify(input) }),

  resetPassword: (input: { newPassword: string; token: string }) =>
    request('/api/auth/reset-password', { method: 'POST', body: JSON.stringify(input) }),
};
