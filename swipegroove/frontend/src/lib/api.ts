import { boot, csrf, setUser, toast } from './state';
import { t } from './i18n';

export class ApiError extends Error {
  constructor(public status: number, public code: string, message: string, public extra: Record<string, unknown> = {}) {
    super(message);
  }
  get fields(): Record<string, string> {
    return (this.extra.fields as Record<string, string>) ?? {};
  }
}

export function url(path: string): string {
  return boot.basePath + path;
}

interface Opts { method?: string; body?: unknown; form?: FormData; query?: Record<string, string | number | undefined | null>; silent?: boolean; signal?: AbortSignal }

export async function api<T = Record<string, unknown>>(path: string, opts: Opts = {}): Promise<T> {
  const method = opts.method ?? (opts.body || opts.form ? 'POST' : 'GET');
  let target = url(path);
  if (opts.query) {
    const q = new URLSearchParams();
    for (const [k, v] of Object.entries(opts.query)) {
      if (v !== undefined && v !== null && v !== '') q.set(k, String(v));
    }
    const s = q.toString();
    if (s) target += (target.includes('?') ? '&' : '?') + s;
  }
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (csrf.value) headers['X-CSRF-Token'] = csrf.value;
  let body: BodyInit | undefined;
  if (opts.form) {
    body = opts.form;
  } else if (opts.body !== undefined) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(opts.body);
  }
  let res: Response;
  try {
    res = await fetch(target, { method, headers, body, credentials: 'same-origin', signal: opts.signal });
  } catch (e) {
    if ((e as Error).name === 'AbortError') throw e;
    throw new ApiError(0, 'error.network', t('error.network'));
  }
  if (res.status === 204) return {} as T;
  const text = await res.text();
  let data: Record<string, unknown> = {};
  try {
    data = text ? JSON.parse(text) : {};
  } catch {
    throw new ApiError(res.status, 'error.server', t('error.server'));
  }
  if (!res.ok) {
    const code = String(data.error ?? 'error.server');
    const message = typeof data.message === 'string' ? data.message : t(code);
    if (res.status === 401 && path !== '/api/me') {
      setUser(null);
    }
    throw new ApiError(res.status, code, message, data);
  }
  return data as T;
}

/** Zeigt die Fehlermeldung als Toast (ausser bei abgebrochenen Requests). */
export function showError(e: unknown): void {
  if (e instanceof ApiError) {
    toast(e.message, 'error');
  } else if ((e as Error)?.name === 'AbortError') {
    return;
  } else {
    toast(t('error.server'), 'error');
  }
}

export function uuid(): string {
  if (crypto.randomUUID) return crypto.randomUUID();
  const b = crypto.getRandomValues(new Uint8Array(16));
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  const h = Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}
