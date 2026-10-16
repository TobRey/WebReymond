import { useEffect, useState } from 'preact/hooks';
import { Layout } from '../components/Layout';
import { api, showError } from '../lib/api';
import { t, timeAgo } from '../lib/i18n';
import { href } from '../lib/router';
import type { PublishedPlaylist, UserCard } from '../lib/types';
import { Avatar, EmptyState, Loading, OwnerBadge, Tabs, UserRow } from '../components/ui';
import { IHeart, IMusic, ISearch, IUsers, IChat } from '../components/Icons';

interface FeedItem { id: number; type: string; created_at: string; actor: UserCard; playlist: PublishedPlaylist }

export function PlaylistTile({ p }: { p: PublishedPlaylist }) {
  return (
    <a class="pl-card" href={href('/community/playlists/' + p.id)} style="color:inherit;text-decoration:none">
      {p.image_url ? <img class="cover" src={p.image_url} alt="" loading="lazy" /> : <div class="cover placeholder"><IMusic /></div>}
      <div class="body">
        <div class="name truncate">{p.name}</div>
        <div class="sub truncate">{p.owner.display_name} · {t('playlist.n_songs', { n: p.track_count })}</div>
        <div class="tags"><span class="chip"><IHeart style="width:12px;height:12px" />{p.like_count}</span><span class="chip"><IChat style="width:12px;height:12px" />{p.comment_count}</span></div>
      </div>
    </a>
  );
}

export function Community() {
  const [tab, setTab] = useState<'feed' | 'people'>('feed');
  const [items, setItems] = useState<FeedItem[] | null>(null);
  const [cursor, setCursor] = useState<number | null>(null);
  const [loading, setLoading] = useState(false);
  const [q, setQ] = useState('');
  const [users, setUsers] = useState<UserCard[] | null>(null);
  const load = async (before?: number) => {
    setLoading(true);
    try {
      const r = await api<{ items: FeedItem[]; next_cursor: number | null }>('/api/community/feed', { query: { before } });
      setItems(before ? [...(items ?? []), ...r.items] : r.items);
      setCursor(r.next_cursor);
    } catch (e) { showError(e); setItems([]); } finally { setLoading(false); }
  };
  useEffect(() => { load(); }, []);
  useEffect(() => {
    if (q.trim().length < 2) { setUsers(null); return; }
    const h = window.setTimeout(() => api<{ users: UserCard[] }>('/api/users/search', { query: { q } }).then((r) => setUsers(r.users)).catch(() => setUsers([])), 300);
    return () => window.clearTimeout(h);
  }, [q]);
  return (
    <Layout width="wide">
      <div class="topbar"><h1 class="grow">{t('community.title')}</h1></div>
      <Tabs value={tab} onChange={setTab} options={[{ value: 'feed', label: t('community.feed') }, { value: 'people', label: t('community.people') }]} />
      {tab === 'people' ? (
        <div class="mt-2">
          <div class="search"><ISearch /><input class="input" placeholder={t('community.search_users')} value={q} onInput={(e) => setQ((e.target as HTMLInputElement).value)} autoFocus /></div>
          <div class="list mt-2">
            {users === null ? <p class="muted small" style="text-align:center;padding:1rem">{t('community.search_hint')}</p> : users.length === 0 ? <EmptyState icon={<IUsers />} title={t('common.no_results')} /> : users.map((u) => <UserRow key={u.id} user={u} />)}
          </div>
        </div>
      ) : (
        <div class="mt-2">
          {items === null ? <Loading /> : items.length === 0 ? <EmptyState icon={<IUsers />} title={t('community.empty_title')} text={t('community.empty_text')} /> : (
            <div class="list">
              {items.map((it) => (
                <div key={it.id} class="card flat col gap-1">
                  <div class="row small">
                    <a href={href('/u/' + it.actor.username)} class="row gap-1" style="color:inherit;text-decoration:none"><Avatar user={it.actor} size="sm" /><strong>{it.actor.display_name}</strong>{it.actor.is_owner && <OwnerBadge small />}</a>
                    <span class="muted">{t('community.act_' + it.type)}</span>
                    <span class="muted tiny" style="margin-left:auto">{timeAgo(it.created_at)}</span>
                  </div>
                  <a class="item clickable" href={href('/community/playlists/' + it.playlist.id)} style="color:inherit;text-decoration:none">
                    {it.playlist.image_url ? <img class="thumb lg" src={it.playlist.image_url} alt="" loading="lazy" /> : <div class="thumb lg" />}
                    <div class="meta">
                      <div class="title truncate">{it.playlist.name}</div>
                      <div class="sub truncate">{it.playlist.owner.display_name} · {t('playlist.n_songs', { n: it.playlist.track_count })}</div>
                      <div class="row gap-1 mt-1"><span class="chip"><IHeart style="width:12px;height:12px" />{it.playlist.like_count}</span><span class="chip"><IChat style="width:12px;height:12px" />{it.playlist.comment_count}</span></div>
                    </div>
                  </a>
                </div>
              ))}
              {cursor && <button class="btn" disabled={loading} onClick={() => load(cursor)}>{t('common.load_more')}</button>}
            </div>
          )}
        </div>
      )}
    </Layout>
  );
}
