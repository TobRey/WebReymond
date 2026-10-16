export function initials(name: string): string {
  return name.trim().split(/\s+/).slice(0, 2).map((p) => p[0]?.toUpperCase() ?? '').join('') || '?';
}

export function clamp(v: number, min: number, max: number): number {
  return Math.max(min, Math.min(max, v));
}

export function debounce<T extends (...args: never[]) => void>(fn: T, ms: number): T {
  let h = 0;
  return ((...args: Parameters<T>) => {
    window.clearTimeout(h);
    h = window.setTimeout(() => fn(...args), ms);
  }) as T;
}

export function prefersReducedMotion(): boolean {
  return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
}

export function readFileAsDataUrl(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const r = new FileReader();
    r.onload = () => resolve(String(r.result));
    r.onerror = () => reject(r.error);
    r.readAsDataURL(file);
  });
}

export function artistsText(a: string[] | undefined): string {
  return (a ?? []).join(', ');
}

export function money(chf: string): string {
  return 'CHF ' + chf;
}
