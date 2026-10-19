import { signal } from '@preact/signals';
import { boot } from './state';

export interface Route { path: string; params: Record<string, string>; query: URLSearchParams }

function parse(): Route {
  let p = location.pathname;
  if (boot.basePath && p.startsWith(boot.basePath)) p = p.slice(boot.basePath.length);
  if (!p.startsWith('/')) p = '/' + p;
  if (p.length > 1) p = p.replace(/\/+$/, '') || '/';
  return { path: p, params: {}, query: new URLSearchParams(location.search) };
}

export const route = signal<Route>(parse());

let depth = 0;

export function navigate(path: string, replace = false): void {
  const target = boot.basePath + path;
  if (replace) history.replaceState(null, '', target);
  else { history.pushState(null, '', target); depth++; }
  route.value = parse();
  window.scrollTo({ top: 0 });
}

window.addEventListener('popstate', () => { depth = Math.max(0, depth - 1); route.value = parse(); });

/** Zurück innerhalb der App, sonst zur Ausweichseite. */
export function goBack(fallback: string): void {
  if (depth > 0) history.back();
  else navigate(fallback, true);
}

// Interne Links abfangen
document.addEventListener('click', (e) => {
  const a = (e.target as HTMLElement).closest?.('a[href]') as HTMLAnchorElement | null;
  if (!a || a.target === '_blank' || a.hasAttribute('download') || e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
  const href = a.getAttribute('href') || '';
  if (/^https?:\/\//.test(href) || href.startsWith('mailto:')) return;
  if (!href.startsWith(boot.basePath + '/') && href !== boot.basePath) return;
  // Server-seitige Routen nicht abfangen
  const rel = href.slice(boot.basePath.length);
  if (rel.startsWith('/auth/') || rel.startsWith('/api/') || rel.startsWith('/media/') || rel.startsWith('/install') || rel.startsWith('/uploads/')) return;
  e.preventDefault();
  navigate(rel);
});

/** Einfacher Mustervergleich: '/users/:username' */
export function match(pattern: string, path: string): Record<string, string> | null {
  const a = pattern.split('/');
  const b = path.split('/');
  if (a.length !== b.length) return null;
  const params: Record<string, string> = {};
  for (let i = 0; i < a.length; i++) {
    if (a[i].startsWith(':')) params[a[i].slice(1)] = decodeURIComponent(b[i]);
    else if (a[i] !== b[i]) return null;
  }
  return params;
}

export function href(path: string): string {
  return boot.basePath + path;
}
