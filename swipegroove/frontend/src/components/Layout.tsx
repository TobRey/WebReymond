import { ComponentChildren } from 'preact';
import { route, href } from '../lib/router';
import { t } from '../lib/i18n';
import { user, platform } from '../lib/state';
import { IHome, ICompass, IUsers, IChat, IUser, ISettings, IShield, ISparkle, IStar } from './Icons';
import { Toasts } from './ui';

function NavLink({ to, icon, label, badge, exact }: { to: string; icon: ComponentChildren; label: string; badge?: number; exact?: boolean }) {
  const p = route.value.path;
  const active = exact ? p === to : p === to || p.startsWith(to + '/');
  return (
    <a href={href(to)} class={'nav' + (active ? ' active' : '')} aria-current={active ? 'page' : undefined}>
      {icon}<span>{label}</span>
      {badge ? <span class="badge-dot" aria-label={String(badge)}>{badge > 99 ? '99+' : badge}</span> : null}
    </a>
  );
}

export function Layout({ children, width }: { children: ComponentChildren; width?: 'narrow' | 'wide' }) {
  const u = user.value;
  const msgBadge = (u?.unread_messages ?? 0);
  const profileBadge = (u?.unread_notifications ?? 0) + (u?.pending_friend_requests ?? 0);
  const links = [
    { to: '/', icon: <IHome />, label: t('nav.overview'), exact: true },
    { to: '/discover', icon: <ICompass />, label: t('nav.discover') },
    { to: '/community', icon: <IUsers />, label: t('nav.community') },
    { to: '/messages', icon: <IChat />, label: t('nav.messages'), badge: msgBadge },
    { to: '/profile', icon: <IUser />, label: t('nav.profile'), badge: profileBadge },
  ];
  return (
    <div class="app">
      <div class="bg-orbs" aria-hidden="true"><span /><span /><span /></div>
      <nav class="sidebar" aria-label={t('nav.main')}>
        <a class="brand" href={href('/')}><img src={href('/assets/logo.svg')} alt="" />SwipeGroove</a>
        {links.map((l) => <NavLink key={l.to} {...l} />)}
        <div class="divider" />
        <NavLink to="/covers" icon={<ISparkle />} label={t('nav.covers')} />
        <NavLink to="/premium" icon={<IStar />} label={t('nav.premium')} />
        <NavLink to="/settings" icon={<ISettings />} label={t('nav.settings')} />
        {u?.is_admin && <NavLink to="/admin" icon={<IShield />} label={t('nav.admin')} />}
        <div class="spacer" />
        <div class="tiny muted" style="padding:0 .5rem">{platform.value.operation_mode === 'closed_beta' ? t('nav.closed_beta') : ''} v{(window as unknown as { __SG__?: { version: string } }).__SG__?.version ?? ''}</div>
      </nav>
      <main class={'main' + (width ? ' ' + width : '')} id="main">{children}</main>
      <nav class="bottom-nav" aria-label={t('nav.main')}>
        {links.map((l) => <NavLink key={l.to} {...l} />)}
      </nav>
      <Toasts />
    </div>
  );
}

export function Bare({ children }: { children: ComponentChildren }) {
  return (
    <div class="app" style="display:block">
      <div class="bg-orbs" aria-hidden="true"><span /><span /><span /></div>
      {children}
      <Toasts />
    </div>
  );
}
