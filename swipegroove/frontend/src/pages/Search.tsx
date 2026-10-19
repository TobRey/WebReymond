import { useEffect, useRef, useState } from 'preact/hooks';
import { Layout } from '../components/Layout';
import { api, showError } from '../lib/api';
import { t } from '../lib/i18n';
import { route } from '../lib/router';
import type { PublishedPlaylist, UserCard } from '../lib/types';
import { EmptyState, Loading, Spinner, TopBar, UserRow } from '../components/ui';
import { ISearch, IX } from '../components/Icons';
import { PlaylistTile } from './Community';
import type { FeedItem } from './Home';

type Kind = 'all' | 'people' | 'playlists';

export function Search() {
  const [q, setQ] = useState(route.value.query.get('q') ?? '');
  const [kind, setKind] = useState<Kind>('all');
  const [res, setRes] = useState<{ users: UserCard[]; playlists: PublishedPlaylist[] } | null>(null);
  const [busy, setBusy] = useState(false);
  const [trending, setTrending] = useState<PublishedPlaylist[] | null>(null);
  const input = useRef<HTMLInputElement>(null);
  useEffect(() => { input.current?.focus({ preventScroll: true }); }, []);
  useEffect(() => {
    api<{ items: FeedItem[] }>('/api/community/feed').then((r) => {
      const seen = new Set<number>();
      setTrending(r.items.map((i) => i.playlist).filter((p) => (seen.has(p.id) ? false : (seen.add(p.id), true))));
    }).catch(() => setTrending([]));
  }, []);
  useEffect(() => {
    const s = q.trim();
    if (s.length < 2) { setRes(null); return; }
    setBusy(true);
    const h = window.setTimeout(() => {
      api<{ users: UserCard[]; playlists: PublishedPlaylist[] }>('/api/community/search', { query: { q: s } })
        .then(setRes).catch((e) => { showError(e); setRes({ users: [], playlists: [] }); }).finally(() => setBusy(false));
    }, 280);
    return () => window.clearTimeout(h);
  }, [q]);
  const showPeople = res && kind !== 'playlists' && res.users.length > 0;
  const showLists = res && kind !== 'people' && res.playlists.length > 0;
  return (
    <Layout width="wide">
      <TopBar>
        <div class="search grow" style="margin-right:8px">
          <ISearch />
          <input ref={input} class="input" type="search" placeholder={t('search.placeholder')} value={q} onInput={(e) => setQ((e.target as HTMLInputElement).value)} aria-label={t('nav.search')} />
        </div>
        {q && <button class="icon-btn" aria-label={t('common.reset')} onClick={() => { setQ(''); input.current?.focus(); }}><IX /></button>}
      </TopBar>
      {res === null ? (
        <>
          <div class="section-label">{t('search.trending')}</div>
          <div class="pad">{trending === null ? <Loading /> : trending.length === 0 ? <EmptyState title={t('community.empty_title')} /> : <div class="grid">{trending.map((p) => <PlaylistTile key={p.id} p={p} />)}</div>}</div>
        </>
      ) : (
        <>
          <div class="pad mb-1">
            <div class="seg" role="tablist">
              {(['all', 'people', 'playlists'] as Kind[]).map((k) => <button key={k} role="tab" aria-selected={kind === k} class={kind === k ? 'active' : ''} onClick={() => setKind(k)}>{t('search.k_' + k)}</button>)}
              {busy && <Spinner small />}
            </div>
          </div>
          {!showPeople && !showLists && !busy && <EmptyState icon={<ISearch />} title={t('common.no_results')} />}
          {showPeople && (<>
            {kind === 'all' && <div class="section-label">{t('search.k_people')}</div>}
            <div class="list">{(kind === 'all' ? res!.users.slice(0, 5) : res!.users).map((u) => <UserRow key={u.id} user={u} />)}</div>
          </>)}
          {showLists && (<>
            {kind === 'all' && <div class="section-label">{t('search.k_playlists')}</div>}
            <div class="pad"><div class="grid">{res!.playlists.map((p) => <PlaylistTile key={p.id} p={p} />)}</div></div>
          </>)}
        </>
      )}
    </Layout>
  );
}
