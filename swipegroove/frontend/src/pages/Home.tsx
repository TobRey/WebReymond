import { useEffect, useState } from 'preact/hooks';
import { Layout, CreateSheet } from '../components/Layout';
import { api, showError } from '../lib/api';
import { t, timeAgo } from '../lib/i18n';
import { user, toast } from '../lib/state';
import { href, navigate, route } from '../lib/router';
import { now, playing, loadingKey, playPlaylist, playTracks, togglePause } from '../lib/player';
import type { PublishedPlaylist, UserCard } from '../lib/types';
import { Avatar, CoverImg, EmptyState, Loading, Menu, OwnerBadge, Spinner } from '../components/ui';
import { IBell, IChat, IExternal, IFlag, IHeart, IHeartFill, ILink, IMore, IPauseFill, IPlayFill, IPlus, ISearch, ISend, IUsers } from '../components/Icons';
import { ReportSheet, ShareSheet } from './PublishedPlaylist';

export interface FeedItem { id: number; type: string; created_at: string; actor: UserCard; playlist: PublishedPlaylist }

const trackCache = new Map<number, string[]>();

/** Spielt eine veröffentlichte Playlist auf der Seite ab (Gäste werden zur Anmeldung geleitet). */
export async function playPublished(p: PublishedPlaylist): Promise<void> {
  if (!user.value) { navigate('/login?redirect=' + encodeURIComponent('/community/playlists/' + p.id)); return; }
  const key = 'pl:' + p.id;
  if (now.value?.key === key) { await togglePause(); return; }
  let ids = trackCache.get(p.id) ?? (p.tracks ? p.tracks.map((x) => x.id ?? '').filter(Boolean) : undefined);
  if (!ids) {
    try {
      const full = await api<PublishedPlaylist>('/api/community/playlists/' + p.id);
      ids = (full.tracks ?? []).map((x) => x.id ?? '').filter(Boolean);
    } catch (e) { showError(e); return; }
  }
  trackCache.set(p.id, ids);
  const meta = { title: p.name, subtitle: p.owner.display_name, image: p.image_url };
  // Ohne gespeicherte Songliste: ganze Playlist direkt bei Spotify ab dem ersten Song abspielen
  if (ids.length === 0) await playPlaylist(key, p.spotify_playlist_id, meta, 0);
  else await playTracks(key, ids, meta);
}

export function PlayButton({ p, class: cls = 'play' }: { p: PublishedPlaylist; class?: string }) {
  const key = 'pl:' + p.id;
  const active = now.value?.key === key && playing.value;
  return (
    <button class={cls + (active ? ' on' : '')} aria-label={active ? t('player.pause') : t('player.play')} onClick={(e) => { e.preventDefault(); e.stopPropagation(); playPublished(p); }}>
      {loadingKey.value === key ? <Spinner small /> : active ? <IPauseFill /> : <IPlayFill />}
    </button>
  );
}

export function requireLogin(): boolean {
  if (user.value) return true;
  toast(t('guest.need_login'), 'info');
  navigate('/login?redirect=' + encodeURIComponent(route.value.path));
  return false;
}

export function Post({ item, onChange }: { item: FeedItem; onChange: (p: PublishedPlaylist) => void }) {
  const p = item.playlist;
  const [share, setShare] = useState(false);
  const [report, setReport] = useState(false);
  const [pop, setPop] = useState(false);
  const like = async () => {
    if (!requireLogin()) return;
    setPop(true); window.setTimeout(() => setPop(false), 400);
    const optimistic = { ...p, liked: !p.liked, like_count: p.like_count + (p.liked ? -1 : 1) };
    onChange(optimistic);
    try { onChange(await api<PublishedPlaylist>('/api/community/playlists/' + p.id + '/like', { method: p.liked ? 'DELETE' : 'POST', body: {} })); } catch (e) { onChange(p); showError(e); }
  };
  const isSelf = item.actor.id === p.owner.id;
  return (
    <article class="post">
      <div class="post-head">
        <a class="who" href={href('/u/' + item.actor.username)}>
          <Avatar user={item.actor} size="sm" />
          <div class="grow">
            <div class="name truncate">{item.actor.display_name}{item.actor.is_owner && <OwnerBadge small />}</div>
            <div class="meta-mono truncate">{t('feed.act_' + item.type)}{!isSelf ? ' · @' + p.owner.username : ''} · {timeAgo(item.created_at)}</div>
          </div>
        </a>
        <Menu button={<button class="icon-btn sm" aria-label={t('common.more')}><IMore /></button>} items={[
          { label: t('common.share'), icon: <ILink />, onClick: () => setShare(true) },
          { label: t('playlist.open_spotify'), icon: <IExternal />, onClick: () => window.open(p.spotify_url, '_blank', 'noopener') },
          ...(user.value && !p.is_owner ? [{ label: t('report.title'), icon: <IFlag />, danger: true, onClick: () => setReport(true) }] : []),
        ]} />
      </div>
      <a class="post-media" href={href('/community/playlists/' + p.id)} onDblClick={(e) => { e.preventDefault(); if (!p.liked) like(); }}>
        <CoverImg src={p.image_url} id={p.id} />
        <div class="post-cap">
          <div class="t">{p.name}</div>
          <div class="s">{t('playlist.n_songs', { n: p.track_count })}</div>
        </div>
        <PlayButton p={p} />
      </a>
      <div class="post-actions">
        <button class={'icon-btn' + (p.liked ? ' liked' : '') + (pop ? ' pop' : '')} aria-pressed={p.liked} aria-label={t('community.like')} onClick={like}>{p.liked ? <IHeartFill /> : <IHeart />}</button>
        <span class="count">{p.like_count}</span>
        <a class="icon-btn" href={href('/community/playlists/' + p.id + '#comments')} aria-label={t('community.comments')}><IChat /></a>
        <span class="count">{p.comment_count}</span>
        <button class="icon-btn" aria-label={t('common.share')} onClick={() => setShare(true)}><ISend /></button>
      </div>
      {p.description && <p class="post-caption truncate"><b>{p.owner.username}</b> {p.description}</p>}
      {share && <ShareSheet type="playlist" refId={String(p.id)} label={p.name} onClose={() => setShare(false)} />}
      {report && <ReportSheet type="playlist" id={p.id} onClose={() => setReport(false)} />}
    </article>
  );
}

function useFeed(scope: 'following' | 'all') {
  const [items, setItems] = useState<FeedItem[] | null>(null);
  const [cursor, setCursor] = useState<number | null>(null);
  const [loading, setLoading] = useState(false);
  const load = async (before?: number) => {
    setLoading(true);
    try {
      const r = await api<{ items: FeedItem[]; next_cursor: number | null }>('/api/community/feed', { query: { before, scope: scope === 'following' ? 'following' : undefined } });
      // Eine Playlist erscheint nur einmal im Feed (neueste Aktivität zählt)
      setItems((cur) => {
        const all = before ? [...(cur ?? []), ...r.items] : r.items;
        const seen = new Set<number>();
        return all.filter((it) => (seen.has(it.playlist.id) ? false : (seen.add(it.playlist.id), true)));
      });
      setCursor(r.next_cursor);
    } catch (e) { showError(e); setItems((cur) => cur ?? []); } finally { setLoading(false); }
  };
  useEffect(() => { setItems(null); load(); }, [scope, user.value?.id]);
  const update = (p: PublishedPlaylist) => setItems((cur) => (cur ?? []).map((it) => (it.playlist.id === p.id ? { ...it, playlist: { ...it.playlist, ...p, tracks: it.playlist.tracks } } : it)));
  return { items, cursor, loading, load, update };
}

function Posts({ feed }: { feed: ReturnType<typeof useFeed> }) {
  if (feed.items === null) return <Loading />;
  return (
    <div>
      {feed.items.map((it) => <Post key={it.id} item={it} onChange={feed.update} />)}
      {feed.cursor && <div class="row center pad mt-1"><button class="btn outline" disabled={feed.loading} onClick={() => feed.load(feed.cursor!)}>{feed.loading ? <Spinner small /> : t('common.load_more')}</button></div>}
    </div>
  );
}

function Stories({ items, onCreate }: { items: FeedItem[]; onCreate: () => void }) {
  const me = user.value!;
  const seen = new Set<number>([me.id]);
  const people: UserCard[] = [];
  for (const it of items) {
    if (!seen.has(it.actor.id)) { seen.add(it.actor.id); people.push(it.actor); }
    if (people.length >= 15) break;
  }
  return (
    <div class="hscroll stories" aria-label={t('feed.stories')}>
      <button class="story" onClick={onCreate}>
        <span class="ring add"><Avatar user={me} /><span class="plus"><IPlus /></span></span>
        <span class="n">{t('feed.you')}</span>
      </button>
      {people.map((u) => (
        <a key={u.id} class="story" href={href('/u/' + u.username)}>
          <span class="ring"><Avatar user={u} /></span>
          <span class="n">{u.username}</span>
        </a>
      ))}
      <a class="story" href={href('/search')}>
        <span class="ring seen"><span class="avatar" style="border:3px solid var(--bg)"><ISearch style="width:22px;height:22px" /></span></span>
        <span class="n">{t('feed.find')}</span>
      </a>
    </div>
  );
}

export function Home() {
  const u = user.value!;
  const [tab, setTab] = useState<'following' | 'all'>(() => (localStorage.getItem('sg_home') as 'following' | 'all') || 'following');
  const [create, setCreate] = useState(false);
  const feed = useFeed('all');
  const friends = useFeed('following');
  useEffect(() => { try { localStorage.setItem('sg_home', tab); } catch { /* egal */ } }, [tab]);
  const notes = u.unread_notifications + u.pending_friend_requests;
  const activeFeed = tab === 'following' ? friends : feed;
  return (
    <Layout>
      <header class="topbar hide-desktop">
        <a class="wordmark grow" href={href('/')}>swipe<b>groove</b></a>
        <a class="icon-btn" href={href('/search')} aria-label={t('nav.search')}><ISearch /></a>
        <a class="icon-btn" href={href('/notifications')} aria-label={t('nav.notifications')}><IBell />{notes > 0 && <span class="dot" />}</a>
      </header>
      {friends.items && <Stories items={friends.items} onCreate={() => setCreate(true)} />}
      <div class="head-tabs pad" role="tablist">
        <button role="tab" aria-selected={tab === 'following'} class={tab === 'following' ? 'active' : ''} onClick={() => setTab('following')}>{t('feed.friends')}</button>
        <button role="tab" aria-selected={tab === 'all'} class={tab === 'all' ? 'active' : ''} onClick={() => setTab('all')}>{t('feed.explore')}</button>
      </div>
      {tab === 'following' && friends.items && friends.items.length === 0 ? (
        <>
          <EmptyState icon={<IUsers />} title={t('feed.empty_title')} text={t('feed.empty_text')} action={<a class="btn primary" href={href('/search')}><ISearch />{t('feed.find_people')}</a>} />
          <div class="section-label">{t('feed.suggested')}<button onClick={() => setTab('all')}>{t('feed.show_all')}</button></div>
          <Posts feed={feed} />
        </>
      ) : <Posts feed={activeFeed} />}
      {activeFeed.items && activeFeed.items.length === 0 && tab === 'all' && <EmptyState title={t('community.empty_title')} text={t('community.empty_text')} />}
      {create && <CreateSheet onClose={() => setCreate(false)} />}
    </Layout>
  );
}

/** Öffentliche Ansicht ohne Konto: veröffentlichte Playlists durchstöbern. */
export function Explore() {
  const feed = useFeed('all');
  return (
    <Layout>
      <header class="topbar">
        <a class="wordmark grow" href={href(user.value ? '/' : '/explore')}>swipe<b>groove</b></a>
        <a class="icon-btn" href={href('/search')} aria-label={t('nav.search')}><ISearch /></a>
      </header>
      {feed.items && feed.items.length === 0 ? <EmptyState title={t('community.empty_title')} text={t('community.empty_text')} /> : <Posts feed={feed} />}
    </Layout>
  );
}
