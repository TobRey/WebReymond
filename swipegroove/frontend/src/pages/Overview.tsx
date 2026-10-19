import { useEffect, useMemo, useState } from 'preact/hooks';
import { Layout } from '../components/Layout';
import { api, ApiError, showError } from '../lib/api';
import { t } from '../lib/i18n';
import { user, toast } from '../lib/state';
import { href, navigate, route } from '../lib/router';
import type { Playlist } from '../lib/types';
import { CoverImg, EmptyState, ErrorState, Field, Loading, Menu, Notice, Sheet, Spinner, TopBar, Toggle, phClass } from '../components/ui';
import { IGrid, IList, IMusic, IPlus, IRefresh, ISearch, ISpotify, ITrash, IMerge, IGlobe, ILock, ISort } from '../components/Icons';

type Filter = 'all' | 'own' | 'editable' | 'empty' | 'published';
type Sort = 'name' | 'tracks' | 'tracks_asc';

export function SpotifyBanner() {
  const u = user.value!;
  if (u.spotify.status === 'connected') {
    if (u.spotify.reauth_due_at && new Date(u.spotify.reauth_due_at).getTime() - Date.now() < 14 * 86400000) {
      return <Notice kind="warn">{t('spotify.reauth_soon')} <a href={href('/auth/spotify/start?purpose=reconnect')}>{t('spotify.reconnect')}</a></Notice>;
    }
    return null;
  }
  return (
    <Notice kind="danger">
      {u.spotify.status === 'revoked' ? t('spotify.revoked') : t('spotify.not_connected')}{' '}
      <a href={href('/auth/spotify/start?purpose=reconnect')}>{t('spotify.reconnect')}</a>
    </Notice>
  );
}

/** Spotify-Bibliothek (Playlist-Manager) – eigene Seite und Tab im eigenen Profil. */
export function LibraryContent({ embedded = false }: { embedded?: boolean }) {
  const [lists, setLists] = useState<Playlist[] | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [filter, setFilter] = useState<Filter>(() => (route.value.query.get('filter') as Filter) || (localStorage.getItem('sg_filter') as Filter) || 'own');
  const [sort, setSort] = useState<Sort>('name');
  const [q, setQ] = useState('');
  const [view, setView] = useState<'grid' | 'list'>(() => (localStorage.getItem('sg_view') as 'grid' | 'list') || 'grid');
  const [refreshing, setRefreshing] = useState(false);
  const [create, setCreate] = useState(false);

  const load = async (refresh = false) => {
    setError(null);
    if (refresh) setRefreshing(true);
    try {
      const r = await api<{ playlists: Playlist[] }>('/api/playlists', { query: refresh ? { refresh: 1 } : undefined });
      setLists(r.playlists);
    } catch (e) {
      if (e instanceof ApiError) setError(e); else showError(e);
    } finally {
      setRefreshing(false);
    }
  };
  useEffect(() => { load(); }, []);
  useEffect(() => { try { localStorage.setItem('sg_view', view); localStorage.setItem('sg_filter', filter); } catch { /* egal */ } }, [view, filter]);

  const filtered = useMemo(() => {
    let l = lists ?? [];
    if (filter === 'own') l = l.filter((p) => p.is_owner);
    if (filter === 'editable') l = l.filter((p) => p.can_edit);
    if (filter === 'empty') l = l.filter((p) => p.track_count === 0);
    if (filter === 'published') l = l.filter((p) => p.is_published);
    if (q) l = l.filter((p) => p.name.toLowerCase().includes(q.toLowerCase()) || (p.owner_name ?? '').toLowerCase().includes(q.toLowerCase()));
    l = [...l].sort((a, b) => sort === 'name' ? a.name.localeCompare(b.name) : sort === 'tracks' ? b.track_count - a.track_count : a.track_count - b.track_count);
    return l;
  }, [lists, filter, sort, q]);

  const emptyCount = (lists ?? []).filter((p) => p.track_count === 0).length;
  const sortLabel = { name: t('overview.sort_name'), tracks: t('overview.sort_tracks'), tracks_asc: t('overview.sort_tracks_asc') };

  return (
    <div class={embedded ? 'mt-1' : ''}>
      <div class="pad"><SpotifyBanner /></div>
      <div class="row gap-1 pad mt-1">
        <div class="search grow"><ISearch /><input class="input" placeholder={t('overview.search')} value={q} onInput={(e) => setQ((e.target as HTMLInputElement).value)} /></div>
        <Menu button={<button class="icon-btn" aria-label={t('overview.sort') + ': ' + sortLabel[sort]}><ISort /></button>} items={(['name', 'tracks', 'tracks_asc'] as Sort[]).map((k) => ({ label: (sort === k ? '✓ ' : '') + sortLabel[k], onClick: () => setSort(k) }))} />
        <button class="icon-btn" aria-label={view === 'grid' ? t('overview.view_list') : t('overview.view_grid')} onClick={() => setView(view === 'grid' ? 'list' : 'grid')}>{view === 'grid' ? <IList /> : <IGrid />}</button>
        <button class="icon-btn" aria-label={t('common.refresh')} onClick={() => load(true)} disabled={refreshing}>{refreshing ? <Spinner small /> : <IRefresh />}</button>
      </div>
      <div class="hscroll pad mt-1" style="padding-top:2px;padding-bottom:4px">
        <div class="seg" role="tablist">
          {([['own', t('overview.f_own')], ['all', t('overview.f_all')], ['editable', t('overview.f_editable')], ['published', t('overview.f_published')], ['empty', t('overview.f_empty') + (emptyCount ? ' ' + emptyCount : '')]] as [Filter, string][]).map(([v, l]) => (
            <button key={v} role="tab" aria-selected={filter === v} class={filter === v ? 'active' : ''} onClick={() => setFilter(v)}>{l}</button>
          ))}
          <a class="btn sm ghost" href={href('/playlists/tools/empty')} style="height:36px"><ITrash />{t('overview.tool_empty_short')}</a>
          <a class="btn sm ghost" href={href('/playlists/tools/merge')} style="height:36px"><IMerge />{t('overview.tool_merge_short')}</a>
        </div>
      </div>
      <div class="mt-1">
        {error ? (
          error.code === 'spotify.not_connected' || error.code === 'spotify.revoked'
            ? <EmptyState icon={<ISpotify />} title={t('spotify.connect_title')} text={error.message} action={<a class="btn primary" href={href('/auth/spotify/start?purpose=reconnect')}>{t('spotify.reconnect')}</a>} />
            : <ErrorState text={error.message} retry={() => load(true)} />
        ) : lists === null ? <Loading text={t('overview.loading')} /> : filtered.length === 0 ? (
          <EmptyState icon={<IMusic />} title={lists.length === 0 ? t('overview.empty_title') : t('overview.no_match')}
            action={lists.length === 0 ? <button class="btn primary" onClick={() => setCreate(true)}><IPlus />{t('playlist.create')}</button> : undefined} />
        ) : view === 'grid' ? (
          <div class="grid pad">
            {filtered.map((p) => (
              <a key={p.id} class="tile" href={href('/playlists/' + p.id)}>
                <div class="art">
                  <CoverImg src={p.image_url} id={p.id} />
                  {p.is_published && <span class="badge chip accent" title={t('playlist.published')}><IGlobe /></span>}
                </div>
                <div class="name truncate">{p.name}</div>
                <div class="sub"><span class="truncate">{t('playlist.n_songs', { n: p.track_count })}{!p.is_owner && p.owner_name ? ' · ' + p.owner_name : ''}</span>{!p.can_edit && <ILock />}</div>
              </a>
            ))}
          </div>
        ) : (
          <div class="list">
            {filtered.map((p) => (
              <a key={p.id} class="item" href={href('/playlists/' + p.id)}>
                {p.image_url ? <img class="thumb" src={p.image_url} alt="" loading="lazy" /> : <div class={'thumb ' + phClass(p.id)} />}
                <div class="meta">
                  <div class="title truncate">{p.name}</div>
                  <div class="sub truncate">{t('playlist.n_songs', { n: p.track_count })} · {p.is_owner ? t('playlist.yours') : p.owner_name}</div>
                </div>
                <div class="actions">{p.is_published && <IGlobe style="width:16px;height:16px;color:var(--accent)" />}{!p.can_edit && <ILock style="width:15px;height:15px;color:var(--text-4)" />}</div>
              </a>
            ))}
          </div>
        )}
      </div>
      {!embedded && <button class="fab" aria-label={t('playlist.create')} onClick={() => setCreate(true)}><IPlus /></button>}
      {create && <CreatePlaylist onClose={() => setCreate(false)} onCreated={(p) => { setCreate(false); setLists([...(lists ?? []), p]); navigate('/playlists/' + p.id); }} />}
    </div>
  );
}

export function Library() {
  return (
    <Layout width="wide">
      <TopBar title={t('nav.library')} back="/profile" />
      <LibraryContent />
    </Layout>
  );
}

export function CreatePlaylist({ onClose, onCreated }: { onClose: () => void; onCreated: (p: Playlist) => void }) {
  const [name, setName] = useState('');
  const [desc, setDesc] = useState('');
  const [pub, setPub] = useState(false);
  const [busy, setBusy] = useState(false);
  const submit = async (e: Event) => {
    e.preventDefault();
    setBusy(true);
    try {
      const p = await api<Playlist>('/api/playlists', { body: { name, description: desc, public: pub } });
      toast(t('playlist.created'), 'ok');
      onCreated(p);
    } catch (err) { showError(err); } finally { setBusy(false); }
  };
  return (
    <Sheet title={t('playlist.create')} onClose={onClose}>
      <form class="col" onSubmit={submit}>
        <Field label={t('playlist.name')}><input class="input" value={name} onInput={(e) => setName((e.target as HTMLInputElement).value)} maxLength={100} required /></Field>
        <Field label={t('playlist.description')}><textarea class="input" value={desc} onInput={(e) => setDesc((e.target as HTMLTextAreaElement).value)} maxLength={300} /></Field>
        <Toggle label={t('playlist.spotify_public')} desc={t('playlist.spotify_public_hint')} checked={pub} onChange={setPub} />
        <button class="btn primary block" type="submit" disabled={busy || !name.trim()}>{busy ? <Spinner small /> : t('playlist.create')}</button>
      </form>
    </Sheet>
  );
}
