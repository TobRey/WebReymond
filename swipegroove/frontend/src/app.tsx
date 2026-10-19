import { useEffect } from 'preact/hooks';
import { route, match, navigate } from './lib/router';
import { user, setUser, platform } from './lib/state';
import { lang } from './lib/i18n';
import { api } from './lib/api';
import type { Platform } from './lib/types';
import { Login, Setup } from './pages/Login';
import { Welcome } from './pages/Welcome';
import { Legal } from './pages/Legal';
import { Library } from './pages/Overview';
import { Home, Explore } from './pages/Home';
import { Search } from './pages/Search';
import { PlaylistDetail } from './pages/PlaylistDetail';
import { EmptyCleanup } from './pages/EmptyCleanup';
import { Merge } from './pages/Merge';
import { Discover } from './pages/Discover';
import { PublishedPlaylist } from './pages/PublishedPlaylist';
import { Profile } from './pages/Profile';
import { Messages, Conversation } from './pages/Messages';
import { Notifications } from './pages/Notifications';
import { Settings } from './pages/Settings';
import { Premium } from './pages/Premium';
import { Covers } from './pages/Covers';
import { Admin } from './pages/Admin';
import { NotFound } from './pages/NotFound';

/** Zähler (Nachrichten, Benachrichtigungen) regelmässig und sparsam aktualisieren. */
function usePolling() {
  useEffect(() => {
    if (!user.value) return;
    let timer = 0;
    let stopped = false;
    const tick = async () => {
      if (stopped) return;
      if (document.visibilityState === 'visible') {
        try {
          const u = await api<{ unread_messages: number; unread_notifications: number; pending_friend_requests: number }>('/api/updates');
          if (user.value) setUser({ ...user.value, unread_messages: u.unread_messages, unread_notifications: u.unread_notifications, pending_friend_requests: u.pending_friend_requests });
        } catch { /* leise */ }
      }
      timer = window.setTimeout(tick, document.visibilityState === 'visible' ? 30000 : 120000);
    };
    timer = window.setTimeout(tick, 30000);
    const onVis = () => { if (document.visibilityState === 'visible') { window.clearTimeout(timer); tick(); } };
    document.addEventListener('visibilitychange', onVis);
    return () => { stopped = true; window.clearTimeout(timer); document.removeEventListener('visibilitychange', onVis); };
  }, [user.value?.id]);
}

export function App() {
  usePolling();
  const r = route.value;
  const u = user.value;
  const p = r.path;
  useEffect(() => { document.documentElement.lang = lang.value; }, [lang.value]);
  useEffect(() => {
    // Plattformstatus nach Rückkehr (z. B. Setup abgeschlossen) auffrischen
    if (!u) api<Platform>('/api/platform').then((pl) => { platform.value = pl; }).catch(() => undefined);
  }, [u?.id]);

  // Weiterleitungen zentral bestimmen (Hooks dürfen nicht bedingt aufgerufen werden)
  const isPublic = p.startsWith('/legal/') || p === '/setup';
  const guestOk = p === '/explore' || p === '/search' || !!match('/community/playlists/:id', p) || !!match('/u/:username', p);
  let redirectTo: string | null = null;
  if (!isPublic) {
    if (!u && p !== '/login' && p !== '/' && !guestOk) redirectTo = '/login?redirect=' + encodeURIComponent(p);
    else if (u && u.status === 'active' && !u.onboarding_done && p !== '/welcome') redirectTo = '/welcome';
    else if (u && u.status === 'active' && p === '/login') redirectTo = r.query.get('redirect')?.startsWith('/') ? r.query.get('redirect')! : '/';
    else if (u && (p === '/community' || p === '/explore')) redirectTo = p === '/community' ? '/search' : '/';
  }
  useEffect(() => { if (redirectTo) navigate(redirectTo, true); }, [redirectTo]);

  if (p.startsWith('/legal/')) {
    const page = p.slice(7);
    if (page === 'privacy' || page === 'terms' || page === 'imprint') return <Legal page={page} />;
    return <NotFound />;
  }
  if (p === '/setup') return <Setup />;
  if (redirectTo) return null;
  let m: Record<string, string> | null;
  if (!u || u.status !== 'active') {
    // Ohne Konto: nur stöbern (öffentliche Playlists und Profile)
    if (u && u.status !== 'active') return <Login />;
    if (p === '/explore') return <Explore />;
    if (p === '/search') return <Search />;
    if ((m = match('/community/playlists/:id', p))) return <PublishedPlaylist id={Number(m.id)} />;
    if ((m = match('/u/:username', p))) return <Profile username={m.username} self={false} />;
    return <Login />;
  }
  if (p === '/welcome') return <Welcome />;
  if (p === '/') return <Home />;
  if (p === '/search') return <Search />;
  if (p === '/library') return <Library />;
  if ((m = match('/playlists/:id', p))) return <PlaylistDetail id={m.id} />;
  if (p === '/playlists/tools/empty') return <EmptyCleanup />;
  if (p === '/playlists/tools/merge') return <Merge />;
  if (p === '/discover') return <Discover />;
  if ((m = match('/community/playlists/:id', p))) return <PublishedPlaylist id={Number(m.id)} />;
  if (p === '/profile') return <Profile username={u.username} self />;
  if ((m = match('/u/:username', p))) return <Profile username={m.username} self={m.username === u.username} />;
  if (p === '/messages') return <Messages />;
  if ((m = match('/messages/:id', p))) return <Conversation id={Number(m.id)} />;
  if (p === '/notifications') return <Notifications />;
  if (p === '/settings') return <Settings />;
  if (p === '/premium') return <Premium />;
  if (p === '/covers') return <Covers />;
  if (p === '/admin' || p.startsWith('/admin/')) return u.is_admin ? <Admin section={p.slice(7) || 'dashboard'} /> : <NotFound />;
  return <NotFound />;
}
