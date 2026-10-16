import { ComponentChildren, JSX } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import { t } from '../lib/i18n';
import { toasts, type Toast } from '../lib/state';
import { initials } from '../lib/util';
import { ICheck, IInfo, IWarn, IX, ICrown } from './Icons';
import type { UserCard as UserCardT } from '../lib/types';
import { href } from '../lib/router';

export function Spinner({ small = false }: { small?: boolean }) {
  return <div class={'spinner' + (small ? ' sm' : '')} role="status" aria-label={t('common.loading')} />;
}

export function Loading({ text }: { text?: string }) {
  return (
    <div class="state" aria-busy="true">
      <Spinner />
      <p class="muted">{text ?? t('common.loading')}</p>
    </div>
  );
}

export function EmptyState({ icon, title, text, action }: { icon?: ComponentChildren; title: string; text?: string; action?: ComponentChildren }) {
  return (
    <div class="state">
      <div class="ico">{icon ?? <IInfo />}</div>
      <h3>{title}</h3>
      {text && <p>{text}</p>}
      {action}
    </div>
  );
}

export function ErrorState({ title, text, retry }: { title?: string; text?: string; retry?: () => void }) {
  return (
    <div class="state error">
      <div class="ico"><IWarn /></div>
      <h3>{title ?? t('common.error_title')}</h3>
      {text && <p>{text}</p>}
      {retry && <button class="btn" onClick={retry}>{t('common.retry')}</button>}
    </div>
  );
}

export function Notice({ kind = 'info', children }: { kind?: 'info' | 'warn' | 'danger' | 'ok'; children: ComponentChildren }) {
  return (
    <div class={'notice ' + kind} role={kind === 'danger' ? 'alert' : 'status'}>
      {kind === 'ok' ? <ICheck /> : kind === 'info' ? <IInfo /> : <IWarn />}
      <div>{children}</div>
    </div>
  );
}

export function Toasts() {
  return (
    <div class="toasts" aria-live="polite">
      {toasts.value.map((tt: Toast) => (
        <div key={tt.id} class={'toast ' + tt.kind}>
          {tt.kind === 'ok' ? <ICheck /> : tt.kind === 'error' ? <IWarn /> : <IInfo />}
          <span>{tt.text}</span>
        </div>
      ))}
    </div>
  );
}

export function Avatar({ user, size = '' }: { user: { display_name: string; avatar_url: string | null } | null; size?: '' | 'sm' | 'lg' | 'xl' }) {
  const cls = 'avatar' + (size ? ' ' + size : '');
  if (user?.avatar_url) return <div class={cls}><img src={user.avatar_url} alt="" loading="lazy" /></div>;
  return <div class={cls} aria-hidden="true">{initials(user?.display_name ?? '?')}</div>;
}

export function OwnerBadge({ small = false }: { small?: boolean }) {
  return <span class="admin-badge" title={t('profile.admin_badge')}><ICrown />{!small && t('profile.admin_badge')}</span>;
}

export function UserRow({ user, right, sub }: { user: UserCardT; right?: ComponentChildren; sub?: string }) {
  return (
    <div class="item">
      <a href={href('/u/' + user.username)} class="row grow" style="color:inherit;text-decoration:none;min-width:0">
        <Avatar user={user} />
        <div class="meta">
          <div class="title truncate">{user.display_name} {user.is_owner && <OwnerBadge small />}</div>
          <div class="sub truncate">@{user.username}{sub ? ' · ' + sub : ''}</div>
        </div>
      </a>
      {right && <div class="actions">{right}</div>}
    </div>
  );
}

export function Toggle({ label, desc, checked, onChange, disabled }: { label: string; desc?: string; checked: boolean; onChange: (v: boolean) => void; disabled?: boolean }) {
  return (
    <div class="toggle" onClick={() => !disabled && onChange(!checked)}>
      <div>
        <div class="t-label">{label}</div>
        {desc && <div class="t-desc">{desc}</div>}
      </div>
      <button type="button" role="switch" aria-checked={checked} aria-label={label} class="switch" disabled={disabled} onClick={(e) => { e.stopPropagation(); if (!disabled) onChange(!checked); }} />
    </div>
  );
}

export function Seg<T extends string>({ value, options, onChange }: { value: T; options: { value: T; label: string }[]; onChange: (v: T) => void }) {
  return (
    <div class="seg" role="tablist">
      {options.map((o) => (
        <button key={o.value} role="tab" aria-selected={o.value === value} class={o.value === value ? 'active' : ''} onClick={() => onChange(o.value)}>{o.label}</button>
      ))}
    </div>
  );
}

export function Tabs<T extends string>({ value, options, onChange }: { value: T; options: { value: T; label: string }[]; onChange: (v: T) => void }) {
  return (
    <div class="tabs" role="tablist">
      {options.map((o) => (
        <button key={o.value} role="tab" aria-selected={o.value === value} class={o.value === value ? 'active' : ''} onClick={() => onChange(o.value)}>{o.label}</button>
      ))}
    </div>
  );
}

export function Sheet({ title, onClose, children, wide, actions }: { title?: string; onClose: () => void; children: ComponentChildren; wide?: boolean; actions?: ComponentChildren }) {
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', onKey);
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const first = ref.current?.querySelector<HTMLElement>('input, textarea, select, button:not(.close)');
    first?.focus({ preventScroll: true });
    return () => { document.removeEventListener('keydown', onKey); document.body.style.overflow = prev; };
  }, []);
  return (
    <div class="overlay" onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}>
      <div class={'sheet' + (wide ? ' wide' : '')} role="dialog" aria-modal="true" aria-label={title} ref={ref}>
        <div class="grabber" />
        <div class="row between mb-1">
          {title ? <h2>{title}</h2> : <span />}
          <button class="btn icon ghost sm close" aria-label={t('common.close')} onClick={onClose}><IX /></button>
        </div>
        {children}
        {actions && <div class="actions">{actions}</div>}
      </div>
    </div>
  );
}

export function Confirm({ title, text, confirmLabel, danger, onConfirm, onClose, busy }: { title: string; text?: ComponentChildren; confirmLabel?: string; danger?: boolean; onConfirm: () => void; onClose: () => void; busy?: boolean }) {
  return (
    <Sheet title={title} onClose={onClose} actions={<>
      <button class="btn" onClick={onClose} disabled={busy}>{t('common.cancel')}</button>
      <button class={'btn ' + (danger ? 'danger' : 'primary')} onClick={onConfirm} disabled={busy}>{busy ? <Spinner small /> : (confirmLabel ?? t('common.confirm'))}</button>
    </>}>
      {text && <div class="muted">{text}</div>}
    </Sheet>
  );
}

export function Field({ label, hint, error, children }: { label: string; hint?: string; error?: string; children: ComponentChildren }) {
  return (
    <div class="field">
      <label>{label}</label>
      {children}
      {error ? <span class="err">{t(error)}</span> : hint ? <span class="hint">{hint}</span> : null}
    </div>
  );
}

export function Check({ on, onToggle, label }: { on: boolean; onToggle: () => void; label?: string }) {
  return (
    <button type="button" class={'check' + (on ? ' on' : '')} role="checkbox" aria-checked={on} aria-label={label ?? t('common.select')} onClick={(e) => { e.stopPropagation(); onToggle(); }}>
      {on && <ICheck />}
    </button>
  );
}

export function Menu({ button, items }: { button: ComponentChildren; items: { label: string; onClick: () => void; danger?: boolean; icon?: ComponentChildren }[] }) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!open) return;
    const onDoc = (e: MouseEvent) => { if (!ref.current?.contains(e.target as Node)) setOpen(false); };
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('click', onDoc);
    document.addEventListener('keydown', onKey);
    return () => { document.removeEventListener('click', onDoc); document.removeEventListener('keydown', onKey); };
  }, [open]);
  return (
    <div class="rel" ref={ref}>
      <div onClick={(e) => { e.stopPropagation(); setOpen(!open); }}>{button}</div>
      {open && (
        <div class="menu" role="menu">
          {items.map((it, i) => (
            <button key={i} role="menuitem" class={it.danger ? 'danger' : ''} onClick={() => { setOpen(false); it.onClick(); }}>{it.icon}{it.label}</button>
          ))}
        </div>
      )}
    </div>
  );
}

export function TopBar({ title, back, right, children }: { title?: string; back?: string | (() => void); right?: ComponentChildren; children?: ComponentChildren }) {
  return (
    <div class="topbar">
      {back && (typeof back === 'string'
        ? <a class="btn icon ghost" href={href(back)} aria-label={t('common.back')}><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:22px;height:22px"><path d="M15 5l-7 7 7 7" /></svg></a>
        : <button class="btn icon ghost" onClick={back} aria-label={t('common.back')}><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:22px;height:22px"><path d="M15 5l-7 7 7 7" /></svg></button>)}
      {title && <h1 class="grow truncate">{title}</h1>}
      {children}
      {right}
    </div>
  );
}

/** Bildzuschnitt (Canvas, Touch & Maus), gibt relative Crop-Werte zurück. */
export function Cropper({ src, aspect, onChange }: { src: string; aspect: number; onChange: (crop: { x: number; y: number; w: number; h: number }) => void }) {
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const [img, setImg] = useState<HTMLImageElement | null>(null);
  const [frame, setFrame] = useState({ x: 0, y: 0, w: 1, h: 1 });
  const drag = useRef<{ sx: number; sy: number; fx: number; fy: number } | null>(null);
  useEffect(() => {
    const i = new Image();
    i.onload = () => {
      setImg(i);
      const ir = i.width / i.height;
      let w = 1, h = 1;
      if (ir > aspect) w = aspect / ir; else h = ir / aspect;
      const f = { x: (1 - w) / 2, y: (1 - h) / 2, w, h };
      setFrame(f);
      onChange(f);
    };
    i.src = src;
  }, [src, aspect]);
  useEffect(() => {
    const c = canvasRef.current;
    if (!c || !img) return;
    const maxW = 420;
    const scale = Math.min(1, maxW / img.width);
    c.width = Math.round(img.width * scale);
    c.height = Math.round(img.height * scale);
    c.getContext('2d')?.drawImage(img, 0, 0, c.width, c.height);
  }, [img]);
  const move = (dx: number, dy: number) => {
    const c = canvasRef.current;
    if (!c || !drag.current) return;
    const rect = c.getBoundingClientRect();
    const nx = Math.max(0, Math.min(1 - frame.w, drag.current.fx + dx / rect.width));
    const ny = Math.max(0, Math.min(1 - frame.h, drag.current.fy + dy / rect.height));
    const f = { ...frame, x: nx, y: ny };
    setFrame(f);
    onChange(f);
  };
  const zoom = (delta: number) => {
    const w = Math.max(0.2, Math.min(1, frame.w * (1 + delta)));
    const ir = img ? img.width / img.height : 1;
    const h = Math.min(1, w * ir / aspect);
    const ww = h * aspect / ir;
    const f = { w: ww, h, x: Math.max(0, Math.min(1 - ww, frame.x + (frame.w - ww) / 2)), y: Math.max(0, Math.min(1 - h, frame.y + (frame.h - h) / 2)) };
    setFrame(f);
    onChange(f);
  };
  return (
    <div class="col">
      <div class="cropper"
        onPointerDown={(e) => { (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId); drag.current = { sx: e.clientX, sy: e.clientY, fx: frame.x, fy: frame.y }; }}
        onPointerMove={(e) => { if (drag.current) move(e.clientX - drag.current.sx, e.clientY - drag.current.sy); }}
        onPointerUp={() => { drag.current = null; }}
        onPointerCancel={() => { drag.current = null; }}>
        <canvas ref={canvasRef} />
        <div class="frame" style={{ left: frame.x * 100 + '%', top: frame.y * 100 + '%', width: frame.w * 100 + '%', height: frame.h * 100 + '%' }} />
      </div>
      <div class="row center gap-1">
        <button class="btn sm" type="button" onClick={() => zoom(-0.15)}>{t('image.zoom_in')}</button>
        <button class="btn sm" type="button" onClick={() => zoom(0.15)}>{t('image.zoom_out')}</button>
        <span class="tiny muted">{t('image.drag_hint')}</span>
      </div>
    </div>
  );
}

export function LinkButton(props: JSX.HTMLAttributes<HTMLAnchorElement> & { to: string }) {
  const { to, children, ...rest } = props;
  return <a href={href(to)} {...rest}>{children}</a>;
}
