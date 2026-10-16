import { useEffect, useRef, useState } from 'preact/hooks';
import { Layout } from '../components/Layout';
import { api, ApiError, showError, uuid } from '../lib/api';
import { t } from '../lib/i18n';
import { platform, toast, user } from '../lib/state';
import { route, href } from '../lib/router';
import type { Card, Playlist } from '../lib/types';
import { EmptyState, ErrorState, Loading, Notice, Seg, Sheet, Spinner, TopBar, Field } from '../components/ui';
import { PlaylistPicker } from '../components/PlaylistPicker';
import { IExternal, IHeart, IMusic, IPause, IPlay, ISkip, IUndo, IX, IFilter, ISearch, IRefresh } from '../components/Icons';
import { artistsText, prefersReducedMotion } from '../lib/util';
import { SpotifyBanner } from './Overview';

type Mode = 'match' | 'discover' | 'custom';
interface Filters { q: string; artists: { id: string; name: string }[]; genres: string[]; years: string }
interface FeedResp { cards: Card[]; target: Playlist; exhausted: boolean; reason: string | null; method: string }
interface HistoryEntry { card: Card; actionId: string; action: 'add' | 'hide' | 'skip' }

export function Discover() {
  const [target, setTarget] = useState<Playlist | null>(null);
  const [pick, setPick] = useState(false);
  const [mode, setMode] = useState<Mode>(() => (localStorage.getItem('sg_mode') as Mode) || 'match');
  const [filters, setFilters] = useState<Filters>({ q: '', artists: [], genres: [], years: '' });
  const [showFilters, setShowFilters] = useState(false);
  const [cards, setCards] = useState<Card[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [exhausted, setExhausted] = useState<string | null>(null);
  const [history, setHistory] = useState<HistoryEntry[]>([]);
  const [fly, setFly] = useState<'left' | 'right' | null>(null);
  const [drag, setDrag] = useState({ dx: 0, dy: 0, active: false });
  const [undoing, setUndoing] = useState(false);
  const [playback, setPlayback] = useState<{ enabled: boolean; playing: boolean; device: string | null; error: string | null }>({ enabled: false, playing: false, device: null, error: null });
  const start = useRef<{ x: number; y: number } | null>(null);
  const busy = useRef(false);
  const seen = useRef<Set<string>>(new Set());
  const u = user.value!;
  const playbackAvailable = platform.value.playback_mode === 'connect' && u.playback_enabled;

  // Ziel aus Query (?target=) vorbelegen
  useEffect(() => {
    const q = route.value.query.get('target') || localStorage.getItem('sg_target');
    if (!q) return;
    api<{ targets: Playlist[] }>('/api/swipe/targets').then((r) => { const p = r.targets.find((x) => x.id === q); if (p) setTarget(p); }).catch(() => undefined);
  }, []);
  useEffect(() => { localStorage.setItem('sg_mode', mode); }, [mode]);

  const fetchCards = async (reset = false) => {
    if (!target) return;
    setLoading(true);
    setError(null);
    try {
      const r = await api<FeedResp>('/api/swipe/feed', { query: { playlist_id: target.id, mode, filters: mode === 'custom' ? JSON.stringify(filters) : undefined } });
      const fresh = r.cards.filter((c) => !seen.current.has(c.id));
      fresh.forEach((c) => seen.current.add(c.id));
      setCards((prev) => (reset ? fresh : [...prev, ...fresh]));
      setExhausted(fresh.length === 0 && r.exhausted ? (r.reason ?? 'exhausted') : fresh.length === 0 && (reset || cards.length === 0) ? 'exhausted' : null);
    } catch (e) {
      if (e instanceof ApiError) setError(e); else showError(e);
    } finally { setLoading(false); }
  };

  useEffect(() => {
    if (!target) return;
    seen.current = new Set();
    setCards([]); setHistory([]); setExhausted(null);
    fetchCards(true);
  }, [target?.id, mode, mode === 'custom' ? JSON.stringify(filters) : '']);

  useEffect(() => { if (target && cards.length > 0 && cards.length < 3 && !loading && !exhausted) fetchCards(false); }, [cards.length]);

  // Wiedergabe über Spotify Connect beim Kartenwechsel
  const current = cards[0];
  useEffect(() => {
    if (!current || !playback.enabled || !playbackAvailable) return;
    api('/api/playback/play', { body: { track_id: current.id, device_id: playback.device } }).then(() => setPlayback((p) => ({ ...p, playing: true, error: null }))).catch((e) => {
      setPlayback((p) => ({ ...p, playing: false, error: (e as Error).message, enabled: e instanceof ApiError && e.status === 403 ? false : p.enabled }));
    });
  }, [current?.id, playback.enabled]);

  const act = async (action: 'add' | 'hide' | 'skip') => {
    const card = cards[0];
    if (!card || !target || busy.current) return;
    busy.current = true;
    if (!prefersReducedMotion() && action !== 'skip') {
      setFly(action === 'add' ? 'right' : 'left');
      await new Promise((r) => setTimeout(r, 260));
    }
    const actionId = uuid();
    setCards((c) => c.slice(1));
    setFly(null);
    setDrag({ dx: 0, dy: 0, active: false });
    try {
      const r = await api<{ result: string; status: string }>('/api/swipe/action', { body: { action_id: actionId, playlist_id: target.id, track_id: card.id, action } });
      if (r.status === 'done' || r.result === 'duplicate') {
        setHistory((h) => [...h.slice(-19), { card, actionId, action }]);
        if (action === 'add') toast(t('swipe.added_to', { name: target.name }), 'ok', 1600);
      }
    } catch (e) {
      if (e instanceof ApiError && e.status === 409) {
        toast(e.message, 'info');
      } else {
        showError(e);
        // Karte zurücklegen, damit nichts verloren geht
        setCards((c) => [card, ...c]);
      }
    } finally { busy.current = false; }
  };

  const undo = async () => {
    const last = history[history.length - 1];
    if (!last || !target || undoing) return;
    setUndoing(true);
    try {
      const r = await api<{ undone: string; card: Card | null }>('/api/swipe/undo', { body: { playlist_id: target.id, action_id: last.actionId } });
      setHistory((h) => h.slice(0, -1));
      setCards((c) => [r.card ?? last.card, ...c.filter((x) => x.id !== last.card.id)]);
      toast(t('swipe.undone_' + r.undone), 'ok', 1500);
    } catch (e) { showError(e); } finally { setUndoing(false); }
  };

  const onPointerDown = (e: PointerEvent) => { if (!current || busy.current) return; start.current = { x: e.clientX, y: e.clientY }; (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId); setDrag({ dx: 0, dy: 0, active: true }); };
  const onPointerMove = (e: PointerEvent) => { if (!start.current) return; setDrag({ dx: e.clientX - start.current.x, dy: e.clientY - start.current.y, active: true }); };
  const onPointerUp = () => {
    if (!start.current) return;
    const dx = drag.dx;
    start.current = null;
    if (dx > 90) act('add'); else if (dx < -90) act('hide'); else setDrag({ dx: 0, dy: 0, active: false });
  };
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.target as HTMLElement).tagName === 'INPUT' || (e.target as HTMLElement).tagName === 'TEXTAREA') return;
      if (e.key === 'ArrowRight') act('add'); else if (e.key === 'ArrowLeft') act('hide'); else if (e.key === 'ArrowDown') act('skip'); else if (e.key === 'z' || e.key === 'Backspace') undo();
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  });

  const rot = drag.dx / 18;
  const stampAdd = Math.min(1, Math.max(0, drag.dx / 90));
  const stampHide = Math.min(1, Math.max(0, -drag.dx / 90));

  return (
    <Layout>
      <TopBar title={t('discover.title')} right={<button class="btn icon ghost" aria-label={t('common.refresh')} onClick={() => { seen.current = new Set(); setCards([]); fetchCards(true); }} disabled={!target || loading}><IRefresh /></button>} />
      <SpotifyBanner />
      {!target ? (
        <EmptyState icon={<IMusic />} title={t('discover.choose_target_title')} text={t('discover.choose_target_text')} action={<button class="btn primary lg" onClick={() => setPick(true)}>{t('discover.choose_target')}</button>} />
      ) : (
        <div class="feed">
          <button class="target-pill" onClick={() => setPick(true)} aria-label={t('discover.change_target')}>
            {target.image_url ? <img src={target.image_url} alt="" /> : <span class="avatar sm"><IMusic style="width:16px;height:16px" /></span>}
            <span class="truncate">{t('discover.target')}: {target.name}</span>
          </button>
          <div class="row wrap center gap-1">
            <Seg value={mode} onChange={setMode} options={[{ value: 'match', label: t('discover.mode_match') }, { value: 'discover', label: t('discover.mode_discover') }, { value: 'custom', label: t('discover.mode_custom') }]} />
            {mode === 'custom' && <button class="btn sm" onClick={() => setShowFilters(true)}><IFilter />{t('discover.filters')}</button>}
          </div>
          {playbackAvailable && (
            <div class="row gap-1 small">
              {!playback.enabled ? <button class="btn sm" onClick={() => setPlayback((p) => ({ ...p, enabled: true }))}><IPlay />{t('discover.enable_playback')}</button>
                : <button class="btn sm" onClick={() => { api('/api/playback/pause', { body: {} }).catch(() => undefined); setPlayback((p) => ({ ...p, enabled: false, playing: false })); }}><IPause />{t('discover.disable_playback')}</button>}
              {playback.error && <span class="tiny" style="color:var(--warning)">{playback.error}</span>}
            </div>
          )}
          <div class="deck">
            {error ? <ErrorState text={error.message} retry={() => fetchCards(true)} />
              : cards.length === 0 ? (loading ? <Loading text={t('discover.loading')} /> : <ExhaustedState reason={exhausted} mode={mode} onRetry={() => { seen.current = new Set(); fetchCards(true); }} />)
              : cards.slice(0, 2).reverse().map((c, i, arr) => {
                const isTop = i === arr.length - 1;
                const style = isTop ? { transform: fly ? undefined : `translate(${drag.dx}px, ${drag.dy * 0.3}px) rotate(${rot}deg)` } : undefined;
                return (
                  <div key={c.id} class={'swipe-card' + (isTop ? '' : ' behind') + (isTop && drag.active ? ' dragging' : '') + (isTop && fly ? ' fly-' + fly : '')} style={style}
                    onPointerDown={isTop ? onPointerDown : undefined} onPointerMove={isTop ? onPointerMove : undefined} onPointerUp={isTop ? onPointerUp : undefined} onPointerCancel={isTop ? onPointerUp : undefined}>
                    <div class="art">
                      {c.image_url ? <img src={c.image_url} alt="" draggable={false} /> : <div class="state" style="height:100%"><IMusic /></div>}
                      {isTop && <><div class="stamp add" style={{ opacity: stampAdd }}>{t('swipe.stamp_add')}</div><div class="stamp hide" style={{ opacity: stampHide }}>{t('swipe.stamp_hide')}</div></>}
                    </div>
                    <div class="info">
                      <div class="title truncate">{c.name}</div>
                      <div class="artist truncate">{artistsText(c.artists)}</div>
                      {c.album_name && <div class="album truncate">{c.album_name}</div>}
                      <div class="row between mt-1">
                        <span class="tiny muted truncate">{c.source ? t('discover.source_' + c.source.split(':')[0], { v: c.source.split(':').slice(1).join(':') }) : ''}</span>
                        {c.spotify_url && <a class="btn sm ghost" href={c.spotify_url} target="_blank" rel="noopener" onPointerDown={(e) => e.stopPropagation()}><IExternal />{t('track.listen_spotify')}</a>}
                      </div>
                    </div>
                  </div>
                );
              })}
          </div>
          <div class="swipe-controls" aria-label={t('swipe.controls')}>
            <button class="btn icon" aria-label={t('swipe.undo')} disabled={history.length === 0 || undoing} onClick={undo}>{undoing ? <Spinner small /> : <IUndo />}</button>
            <button class="btn icon danger main" aria-label={t('swipe.hide')} disabled={!current} onClick={() => act('hide')}><IX /></button>
            <button class="btn icon" aria-label={t('swipe.skip')} disabled={!current} onClick={() => act('skip')}><ISkip /></button>
            <button class="btn icon success main" aria-label={t('swipe.add')} disabled={!current} onClick={() => act('add')}><IHeart /></button>
          </div>
          <p class="tiny muted" style="text-align:center;max-width:40ch">{t('discover.method_note')}</p>
        </div>
      )}
      {pick && <PlaylistPicker title={t('discover.choose_target')} onClose={() => setPick(false)} onPick={(p) => { setTarget(p); localStorage.setItem('sg_target', p.id); setPick(false); }} />}
      {showFilters && <FilterSheet value={filters} onClose={() => setShowFilters(false)} onApply={(f) => { setFilters(f); setShowFilters(false); }} />}
    </Layout>
  );
}

function ExhaustedState({ reason, mode, onRetry }: { reason: string | null; mode: Mode; onRetry: () => void }) {
  const key = reason === 'empty_playlist' ? 'discover.empty_playlist' : reason === 'no_seed_data' ? 'discover.no_seed_data' : reason === 'no_filters' ? 'discover.no_filters' : reason === 'rate_limited' ? 'spotify.rate_limited' : 'discover.exhausted';
  return <EmptyState icon={<IMusic />} title={t(key + '_title')} text={t(key + '_text')} action={reason !== 'no_filters' && reason !== 'empty_playlist' ? <button class="btn" onClick={onRetry}>{t('common.retry')}</button> : mode === 'match' && reason === 'empty_playlist' ? <span class="small muted">{t('discover.try_custom')}</span> : undefined} />;
}

function FilterSheet({ value, onClose, onApply }: { value: Filters; onClose: () => void; onApply: (f: Filters) => void }) {
  const [f, setF] = useState<Filters>(value);
  const [aq, setAq] = useState('');
  const [found, setFound] = useState<{ id: string; name: string }[]>([]);
  const [genre, setGenre] = useState('');
  useEffect(() => {
    if (aq.trim().length < 2) { setFound([]); return; }
    const h = window.setTimeout(() => api<{ artists: { id: string; name: string }[] }>('/api/swipe/artists', { query: { q: aq } }).then((r) => setFound(r.artists)).catch(() => setFound([])), 350);
    return () => window.clearTimeout(h);
  }, [aq]);
  return (
    <Sheet title={t('discover.filters')} onClose={onClose} actions={<><button class="btn" onClick={() => setF({ q: '', artists: [], genres: [], years: '' })}>{t('common.reset')}</button><button class="btn primary" onClick={() => onApply(f)}>{t('common.apply')}</button></>}>
      <div class="col">
        <Field label={t('discover.f_query')}><input class="input" value={f.q} onInput={(e) => setF({ ...f, q: (e.target as HTMLInputElement).value })} maxLength={100} /></Field>
        <Field label={t('discover.f_artists')} hint={t('discover.f_artists_hint')}>
          <div class="row wrap gap-1 mb-1">{f.artists.map((a) => <span key={a.id} class="chip accent">{a.name}<button aria-label={t('common.remove')} onClick={() => setF({ ...f, artists: f.artists.filter((x) => x.id !== a.id) })}><IX style="width:14px;height:14px" /></button></span>)}</div>
          <div class="search"><ISearch /><input class="input" placeholder={t('discover.search_artist')} value={aq} onInput={(e) => setAq((e.target as HTMLInputElement).value)} /></div>
          {found.length > 0 && <div class="list mt-1">{found.slice(0, 6).map((a) => <button key={a.id} class="item clickable" style="min-height:44px;text-align:left" disabled={f.artists.length >= 5} onClick={() => { if (!f.artists.some((x) => x.id === a.id)) setF({ ...f, artists: [...f.artists, a] }); setAq(''); setFound([]); }}>{a.name}</button>)}</div>}
        </Field>
        <Field label={t('discover.f_genres')} hint={t('discover.f_genres_hint')}>
          <div class="row wrap gap-1 mb-1">{f.genres.map((g) => <span key={g} class="chip">{g}<button aria-label={t('common.remove')} onClick={() => setF({ ...f, genres: f.genres.filter((x) => x !== g) })}><IX style="width:14px;height:14px" /></button></span>)}</div>
          <form class="row" onSubmit={(e) => { e.preventDefault(); const g = genre.trim().toLowerCase(); if (g && f.genres.length < 5 && !f.genres.includes(g)) setF({ ...f, genres: [...f.genres, g] }); setGenre(''); }}>
            <input class="input grow" placeholder="z. B. indie, hip-hop, techno" value={genre} onInput={(e) => setGenre((e.target as HTMLInputElement).value)} maxLength={30} />
            <button class="btn" type="submit">{t('common.add')}</button>
          </form>
        </Field>
        <Field label={t('discover.f_years')} hint={t('discover.f_years_hint')}><input class="input" placeholder="2020-2026" value={f.years} onInput={(e) => setF({ ...f, years: (e.target as HTMLInputElement).value })} maxLength={9} /></Field>
      </div>
    </Sheet>
  );
}
