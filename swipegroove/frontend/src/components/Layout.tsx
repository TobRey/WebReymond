import { ComponentChildren } from 'preact';
import { useState } from 'preact/hooks';
import { route, href, navigate } from '../lib/router';
import { t } from '../lib/i18n';
import { user } from '../lib/state';
import { now, playing, progress, togglePause, stop, loadingKey } from '../lib/player';
import { initials } from '../lib/util';
import { IHome, IHomeFill, ISwipe, ISwipeFill, IChat, IChatFill, IPlus, ISearch, IBell, ILibrary, ISparkle, IStar, ISettings, IShield, IMerge, IPlayFill, IPauseFill, IX, ISpotify, IGlobe } from './Icons';
import { CoverImg, Sheet, Spinner, Toasts } from './ui';
import { CreatePlaylist } from '../pages/Overview';

function isActive(to: string, exact?: boolean): boolean {
  const p = route.value.path;
  return exact ? p === to : p === to || p.startsWith(to + '/');
}

function Me() {
  const u = user.value;
  return <span class="me">{u?.avatar_url ? <img src={u.avatar_url} alt="" /> : initials(u?.display_name ?? '?')}</span>;
}

export function MiniPlayer() {
  const n = now.value;
  if (!n) return null;
  const pct = progress.value.dur > 0 ? Math.min(100, (progress.value.pos / progress.value.dur) * 100) : 0;
  return (
    <div class="player" role="region" aria-label={t('player.label')}>
      <span class="art"><CoverImg src={n.image} id={n.key} /></span>
      <div class="grow">
        <div class="t truncate">{n.title}</div>
        <div class="a truncate">{n.subtitle}</div>
      </div>
      <button class="pbtn" onClick={togglePause} aria-label={playing.value ? t('player.pause') : t('player.play')}>{loadingKey.value ? <Spinner small /> : playing.value ? <IPauseFill /> : <IPlayFill />}</button>
      <button class="xbtn" onClick={stop} aria-label={t('common.close')}><IX /></button>
      <div class="bar" aria-hidden="true"><i style={{ width: pct + '%' }} /></div>
    </div>
  );
}

export function CreateSheet({ onClose }: { onClose: () => void }) {
  const [newPl, setNewPl] = useState(false);
  if (newPl) return <CreatePlaylist onClose={onClose} onCreated={(p) => { onClose(); navigate('/playlists/' + p.id); }} />;
  const go = (to: string) => { onClose(); navigate(to); };
  return (
    <Sheet title={t('create.title')} onClose={onClose}>
      <div class="action-list">
        <button onClick={() => setNewPl(true)}><span class="ai c3"><IPlus /></span><span>{t('create.playlist')}<span class="sub">{t('create.playlist_sub')}</span></span></button>
        <button onClick={() => go('/library?filter=own')}><span class="ai c1"><IGlobe /></span><span>{t('create.publish')}<span class="sub">{t('create.publish_sub')}</span></span></button>
        <button onClick={() => go('/discover')}><span class="ai c2"><ISwipe /></span><span>{t('create.swipe')}<span class="sub">{t('create.swipe_sub')}</span></span></button>
        <button onClick={() => go('/covers')}><span class="ai c4"><ISparkle /></span><span>{t('create.cover')}<span class="sub">{t('create.cover_sub')}</span></span></button>
        <button onClick={() => go('/playlists/tools/merge')}><span class="ai"><IMerge /></span><span>{t('create.merge')}</span></button>
      </div>
    </Sheet>
  );
}

export function GuestBar() {
  return (
    <div class="guest-bar">
      <span class="grow">{t('guest.bar')}</span>
      <a class="btn sm primary" href={href('/login')}>{t('guest.login')}</a>
    </div>
  );
}

export function Layout({ children, width }: { children: ComponentChildren; width?: 'narrow' | 'wide' }) {
  const u = user.value;
  const [create, setCreate] = useState(false);
  const msgs = u?.unread_messages ?? 0;
  const notes = (u?.unread_notifications ?? 0) + (u?.pending_friend_requests ?? 0);
  const home = isActive('/', true) || isActive('/explore');
  const swipe = isActive('/discover');
  const chat = isActive('/messages');
  const prof = isActive('/profile') || (u ? isActive('/u/' + u.username) : false);
  return (
    <div class="app">
      <nav class="rail" aria-label={t('nav.main')}>
        <a class="wordmark" href={href(u ? '/' : '/explore')}>swipe<b>groove</b></a>
        {u ? (<>
          <a href={href('/')} class={home ? 'active' : ''}>{home ? <IHomeFill /> : <IHome />}{t('nav.home')}</a>
          <a href={href('/search')} class={isActive('/search') ? 'active' : ''}><ISearch />{t('nav.search')}</a>
          <a href={href('/discover')} class={swipe ? 'active' : ''}>{swipe ? <ISwipeFill /> : <ISwipe />}{t('nav.swipe')}</a>
          <a href={href('/messages')} class={chat ? 'active' : ''}>{chat ? <IChatFill /> : <IChat />}{t('nav.messages')}{msgs > 0 && <span class="count-badge">{msgs > 99 ? '99+' : msgs}</span>}</a>
          <a href={href('/notifications')} class={isActive('/notifications') ? 'active' : ''}><IBell />{t('nav.notifications')}{notes > 0 && <span class="count-badge">{notes > 99 ? '99+' : notes}</span>}</a>
          <a href={href('/library')} class={isActive('/library') || isActive('/playlists') ? 'active' : ''}><ILibrary />{t('nav.library')}</a>
          <a href={href('/profile')} class={prof ? 'active' : ''}><Me />{t('nav.profile')}</a>
          <button class="nav create-btn" onClick={() => setCreate(true)}><IPlus />{t('nav.create')}</button>
          <div class="spacer" />
          <a href={href('/covers')} class={isActive('/covers') ? 'active' : ''}><ISparkle />{t('nav.covers')}</a>
          <a href={href('/premium')} class={isActive('/premium') ? 'active' : ''}><IStar />{t('nav.premium')}</a>
          <a href={href('/settings')} class={isActive('/settings') ? 'active' : ''}><ISettings />{t('nav.settings')}</a>
          {u.is_admin && <a href={href('/admin')} class={isActive('/admin') ? 'active' : ''}><IShield />{t('nav.admin')}</a>}
        </>) : (<>
          <a href={href('/explore')} class={home ? 'active' : ''}>{home ? <IHomeFill /> : <IHome />}{t('nav.home')}</a>
          <a href={href('/search')} class={isActive('/search') ? 'active' : ''}><ISearch />{t('nav.search')}</a>
          <div class="spacer" />
          <a href={href('/login')} class="create-btn"><ISpotify />{t('guest.login')}</a>
        </>)}
      </nav>
      <main class={'main' + (width ? ' ' + width : '') + (now.value ? ' has-player' : '')} id="main">{children}</main>
      <MiniPlayer />
      {u ? (
        <nav class="bottom-nav" aria-label={t('nav.main')}>
          <a href={href('/')} class={home ? 'active' : ''} aria-label={t('nav.home')} aria-current={home ? 'page' : undefined}>{home ? <IHomeFill /> : <IHome />}</a>
          <a href={href('/discover')} class={swipe ? 'active' : ''} aria-label={t('nav.swipe')} aria-current={swipe ? 'page' : undefined}>{swipe ? <ISwipeFill /> : <ISwipe />}</a>
          <a href="#create" class="create" aria-label={t('nav.create')} onClick={(e) => { e.preventDefault(); setCreate(true); }}><IPlus /></a>
          <a href={href('/messages')} class={chat ? 'active' : ''} aria-label={t('nav.messages')} aria-current={chat ? 'page' : undefined}>{chat ? <IChatFill /> : <IChat />}{msgs > 0 && <span class="count-badge">{msgs > 9 ? '9+' : msgs}</span>}</a>
          <a href={href('/profile')} class={prof ? 'active' : ''} aria-label={t('nav.profile')} aria-current={prof ? 'page' : undefined}><Me /></a>
        </nav>
      ) : <GuestBar />}
      {create && <CreateSheet onClose={() => setCreate(false)} />}
      <Toasts />
    </div>
  );
}

export function Bare({ children }: { children: ComponentChildren }) {
  return (
    <div>
      {children}
      <Toasts />
    </div>
  );
}

