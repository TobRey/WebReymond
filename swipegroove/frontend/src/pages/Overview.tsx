import { useEffect, useMemo, useState } from 'preact/hooks';
import { Layout } from '../components/Layout';
import { api, ApiError, showError } from '../lib/api';
import { t } from '../lib/i18n';
import { user, toast } from '../lib/state';
import { href, navigate } from '../lib/router';
import type { Playlist } from '../lib/types';
import { EmptyState, ErrorState, Field, Loading, Notice, Seg, Sheet, Spinner, TopBar, Toggle } from '../components/ui';
import { IGrid, IList, IMusic, IPlus, IRefresh, ISearch, ISpotify, ITrash, IMerge, IGlobe, IEdit } from '../components/Icons';

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

export function Overview() {
  const [lists, setLists] = useState<Playlist[] | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [filter, setFilter] = useState<Filter>('all');
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
  useEffect(() => { localStorage.setItem('sg_view', view); }, [view]);

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

  const u = user.value!;
  const emptyCount = (lists ?? []).filter((p) => p.track_count === 0).length;

  return (
    <Layout width="wide">
      <TopBar title={t('overview.title')} right={<>
        <button class="btn icon ghost" aria-label={t('common.refresh')} onClick={() => load(true)} disabled={refreshing}>{refreshing ? <Spinner small /> : <IRefresh />}</button>
        <button class="btn primary sm" onClick={() => setCreate(true)}><IPlus />{t('playlist.create_short')}</button>
      </>} />
      <SpotifyBanner />
      <div class="row wrap gap-1 mt-2">
        <div class="search grow" style="min-width:200px"><ISearch /><input class="input" placeholder={t('overview.search')} value={q} onInput={(e) => setQ((e.target as HTMLInputElement).value)} /></div>
        <div class="seg" role="group" aria-label={t('overview.view')}>
          <button class={view === 'grid' ? 'active' : ''} aria-label={t('overview.view_grid')} onClick={() => setView('grid')}><IGrid style="width:18px;height:18px" /></button>
          <button class={view === 'list' ? 'active' : ''} aria-label={t('overview.view_list')} onClick={() => setView('list')}><IList style="width:18px;height:18px" /></button>
        </div>
      </div>
      <div class="row wrap gap-1 mt-1">
        <Seg value={filter} onChange={setFilter} options={[
          { value: 'all', label: t('overview.f_all') }, { value: 'own', label: t('overview.f_own') }, { value: 'editable', label: t('overview.f_editable') },
          { value: 'empty', label: t('overview.f_empty') + (emptyCount ? ` (${emptyCount})` : '') }, { value: 'published', label: t('overview.f_published') },
        ]} />
        <select class="input" style="width:auto;min-height:40px" value={sort} onChange={(e) => setSort((e.target as HTMLSelectElement).value as Sort)} aria-label={t('overview.sort')}>
          <option value="name">{t('overview.sort_name')}</option>
          <option value="tracks">{t('overview.sort_tracks')}</option>
          <option value="tracks_asc">{t('overview.sort_tracks_asc')}</option>
        </select>
      </div>
      <div class="row wrap gap-1 mt-2">
        <a class="btn sm" href={href('/playlists/tools/empty')}><ITrash />{t('overview.tool_empty')}</a>
        <a class="btn sm" href={href('/playlists/tools/merge')}><IMerge />{t('overview.tool_merge')}</a>
      </div>
      <div class="mt-2">
        {error ? (
          error.code === 'spotify.not_connected' || error.code === 'spotify.revoked'
            ? <EmptyState icon={<ISpotify />} title={t('spotify.connect_title')} text={error.message} action={<a class="btn primary" href={href('/auth/spotify/start?purpose=reconnect')}>{t('spotify.reconnect')}</a>} />
            : <ErrorState text={error.message} retry={() => load(true)} />
        ) : lists === null ? <Loading text={t('overview.loading')} /> : filtered.length === 0 ? (
          <EmptyState icon={<IMusic />} title={lists.length === 0 ? t('overview.empty_title') : t('overview.no_match')} text={lists.length === 0 ? t('overview.empty_text') : undefined}
            action={lists.length === 0 ? <button class="btn primary" onClick={() => setCreate(true)}><IPlus />{t('playlist.create')}</button> : undefined} />
        ) : view === 'grid' ? (
          <div class="grid">
            {filtered.map((p) => (
              <div key={p.id} class="pl-card" role="link" tabIndex={0} onClick={() => navigate('/playlists/' + p.id)} onKeyDown={(e) => { if (e.key === 'Enter') navigate('/playlists/' + p.id); }}>
                {p.image_url ? <img class="cover" src={p.image_url} alt="" loading="lazy" /> : <div class="cover placeholder"><IMusic /></div>}
                <div class="body">
                  <div class="name truncate">{p.name}</div>
                  <div class="sub truncate">{t('playlist.n_songs', { n: p.track_count })}{!p.is_owner && p.owner_name ? ' · ' + p.owner_name : ''}</div>
                  <div class="tags">
                    {p.is_published && <span class="chip accent"><IGlobe style="width:12px;height:12px" />{t('playlist.published')}</span>}
                    {!p.can_edit && <span class="chip">{t('playlist.read_only')}</span>}
                    {p.is_collaborative && <span class="chip">{t('playlist.collab')}</span>}
                  </div>
                </div>
              </div>
            ))}
          </div>
        ) : (
          <div class="list">
            {filtered.map((p) => (
              <a key={p.id} class="item clickable" href={href('/playlists/' + p.id)} style="color:inherit;text-decoration:none">
                {p.image_url ? <img class="thumb" src={p.image_url} alt="" loading="lazy" /> : <div class="thumb" />}
                <div class="meta">
                  <div class="title truncate">{p.name}</div>
                  <div class="sub truncate">{t('playlist.n_songs', { n: p.track_count })} · {p.is_owner ? t('playlist.yours') : p.owner_name}{p.can_edit ? '' : ' · ' + t('playlist.read_only')}</div>
                </div>
                <div class="actions">{p.is_published && <span class="chip accent"><IGlobe style="width:12px;height:12px" /></span>}{p.can_edit && <IEdit style="width:16px;height:16px;color:var(--text-3)" />}</div>
              </a>
            ))}
          </div>
        )}
      </div>
      {create && <CreatePlaylist onClose={() => setCreate(false)} onCreated={(p) => { setCreate(false); setLists([...(lists ?? []), p]); navigate('/playlists/' + p.id); }} />}
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
