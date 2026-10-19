import { ComponentChildren } from 'preact';
import { useState } from 'preact/hooks';
import { route, href, navigate } from '../lib/router';
import { t } from '../lib/i18n';
import { user } from '../lib/state';
import { now, playing, progress, togglePause, stop, loadingKey, seekTo, skipBy, tapTempo, bpm, beatEpoch } from '../lib/player';
import { initials } from '../lib/util';
import { IHome, IHomeFill, ISwipe, ISwipeFill, IChat, IChatFill, IPlus, ISearch, IBell, ILibrary, ISparkle, IStar, ISettings, IShield, IMerge, IPlayFill, IPauseFill, IX, IBack15, IFwd15, ISpotify, IGlobe } from './Icons';
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

function fmt(ms: number): string {
  const s = Math.max(0, Math.floor(ms / 1000));
  return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
}

export function MiniPlayer() {
  const n = now.value;
  const [drag, setDrag] = useState<number | null>(null);
  if (!n) return null;
  const { pos, dur } = progress.value;
  const shown = drag ?? pos;
  return (
    <div class="player" role="region" aria-label={t('player.label')}>
      <div class="prow">
        <button class="art" onClick={tapTempo} aria-label={t('player.tap_tempo', { bpm: bpm.value })} title={t('player.tap_tempo', { bpm: bpm.value })}>
          <CoverImg src={n.image} id={n.key} />
          {playing.value && <span class="eq" aria-hidden="true"><i /><i /><i /></span>}
        </button>
        <div class="grow" style="min-width:0">
          <div class="t truncate">{n.title}</div>
          <div class="a truncate">{n.subtitle}</div>
        </div>
        <button class="xbtn" onClick={() => skipBy(-15000)} aria-label={t('player.back15')}><IBack15 /></button>
        <button class="pbtn" onClick={togglePause} aria-label={playing.value ? t('player.pause') : t('player.play')}>{loadingKey.value ? <Spinner small /> : playing.value ? <IPauseFill /> : <IPlayFill />}</button>
        <button class="xbtn" onClick={() => skipBy(15000)} aria-label={t('player.fwd15')}><IFwd15 /></button>
        <button class="xbtn" onClick={stop} aria-label={t('common.close')}><IX /></button>
      </div>
      <div class="seekrow">
        <span class="time">{fmt(shown)}</span>
        <input type="range" class="seek" min={0} max={Math.max(1, dur)} step={1000} value={Math.min(shown, Math.max(1, dur))} disabled={dur <= 0}
          aria-label={t('player.seek')} aria-valuetext={fmt(shown) + ' / ' + fmt(dur)}
          style={{ '--p': (dur > 0 ? (shown / dur) * 100 : 0) + '%' }}
          onInput={(e) => setDrag(Number((e.target as HTMLInputElement).value))}
          onChange={(e) => { const v = Number((e.target as HTMLInputElement).value); setDrag(null); seekTo(v); }} />
        <span class="time">{dur > 0 ? fmt(dur) : '–:––'}</span>
      </div>
    </div>
  );
}

/** Animierter Hintergrund, der im eingestellten Tempo pulsiert, solange Musik läuft. */
export function GrooveLayer() {
  if (!now.value || !playing.value) return null;
  return <div key={beatEpoch.value} class="groove" aria-hidden="true" style={{ '--beat': (60 / bpm.value).toFixed(3) + 's' }}><span /><span /><span /></div>;
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
    <div class={'app' + (now.value && playing.value ? ' grooving' : '')} style={now.value && playing.value ? { '--beat': (60 / bpm.value).toFixed(3) + 's' } : undefined}>
      <GrooveLayer />
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

