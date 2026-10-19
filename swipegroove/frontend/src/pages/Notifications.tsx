import { useEffect, useState } from 'preact/hooks';
import { Layout } from '../components/Layout';
import { api, showError } from '../lib/api';
import { t, timeAgo } from '../lib/i18n';
import { setUser, user } from '../lib/state';
import { href } from '../lib/router';
import type { Notification, UserCard } from '../lib/types';
import { Avatar, EmptyState, Loading, TopBar, UserRow } from '../components/ui';
import { IBell, IX } from '../components/Icons';

export function Notifications() {
  const [items, setItems] = useState<Notification[] | null>(null);
  const [cursor, setCursor] = useState<number | null>(null);
  const [reqs, setReqs] = useState<{ incoming: UserCard[]; outgoing: UserCard[] } | null>(null);
  const load = async (before?: number) => {
    try {
      const r = await api<{ notifications: Notification[]; next_cursor: number | null }>('/api/notifications', { query: { before } });
      setItems(before ? [...(items ?? []), ...r.notifications] : r.notifications);
      setCursor(r.next_cursor);
      if (!before) { await api('/api/notifications/read', { body: {} }); if (user.value) setUser({ ...user.value, unread_notifications: 0 }); }
    } catch (e) { showError(e); setItems([]); }
  };
  const loadReqs = () => api<{ incoming: UserCard[]; outgoing: UserCard[] }>('/api/friends/requests').then(setReqs).catch(() => undefined);
  useEffect(() => { load(); loadReqs(); }, []);
  const respond = async (u: UserCard, accept: boolean) => {
    try { await api('/api/users/' + u.username + (accept ? '/friend-accept' : '/friend-decline'), { body: {} }); loadReqs(); if (user.value) setUser({ ...user.value, pending_friend_requests: Math.max(0, user.value.pending_friend_requests - 1) }); } catch (e) { showError(e); }
  };
  const link = (n: Notification): string => {
    if (n.ref_type === 'playlist' && n.ref_id) return '/community/playlists/' + n.ref_id;
    if (n.ref_type === 'conversation' && n.ref_id) return '/messages/' + n.ref_id;
    if (n.actor) return '/u/' + n.actor.username;
    return '/notifications';
  };
  return (
    <Layout width="narrow">
      <TopBar back="/" title={t('nav.notifications')} />
      {reqs && reqs.incoming.length > 0 && (
        <div class="mb-1">
          <div class="section-label">{t('notif.friend_requests')}</div>
          <div class="list">{reqs.incoming.map((u) => <UserRow key={u.id} user={u} right={<><button class="btn sm primary" onClick={() => respond(u, true)}>{t('profile.accept')}</button><button class="icon-btn sm" aria-label={t('profile.decline')} onClick={() => respond(u, false)}><IX /></button></>} />)}</div>
          <div class="section-label">{t('notif.recent')}</div>
        </div>
      )}
      {items === null ? <Loading /> : items.length === 0 ? <EmptyState icon={<IBell />} title={t('notif.empty')} /> : (
        <div class="list">
          {items.map((n) => (
            <a key={n.id} class={'item clickable' + (n.read ? '' : ' selected')} href={href(link(n))} style="color:inherit;text-decoration:none">
              <Avatar user={n.actor} size="sm" />
              <div class="meta">
                <div class="small"><strong>{n.actor?.display_name ?? 'SwipeGroove'}</strong> {t('notif.' + n.type, { name: n.data?.name ?? '' })}{n.data?.excerpt ? <span class="muted"> „{n.data.excerpt}“</span> : null}</div>
                <div class="meta-mono">{timeAgo(n.created_at)}</div>
              </div>
            </a>
          ))}
          {cursor && <div class="row center mt-1"><button class="btn outline sm" onClick={() => load(cursor)}>{t('common.load_more')}</button></div>}
        </div>
      )}
    </Layout>
  );
}
