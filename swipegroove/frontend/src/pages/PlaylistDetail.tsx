import { useEffect, useRef, useState } from 'preact/hooks';
import { Layout } from '../components/Layout';
import { api, ApiError, showError, uuid } from '../lib/api';
import { t, fmtDuration } from '../lib/i18n';
import { toast } from '../lib/state';
import { href, navigate } from '../lib/router';
import type { Playlist, Track, Operation } from '../lib/types';
import { Check, Confirm, Cropper, EmptyState, ErrorState, Field, Loading, Menu, Notice, Sheet, Spinner, TopBar, Toggle } from '../components/ui';
import { PlaylistPicker } from '../components/PlaylistPicker';
import { ICopy, IDown, IEdit, IExternal, IGlobe, IHandle, IImage, IMore, IMove, IMusic, IPlus, IRefresh, ISearch, ITrash, IUp, IX, ISparkle, IWarn } from '../components/Icons';
import { artistsText, readFileAsDataUrl } from '../lib/util';

interface Detail { playlist: Playlist; items: Track[]; complete: boolean }

export function PlaylistDetail({ id }: { id: string }) {
  const [data, setData] = useState<Detail | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [busy, setBusy] = useState(false);
  const [selectMode, setSelectMode] = useState(false);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [sheet, setSheet] = useState<null | 'edit' | 'cover' | 'add' | 'duplicates' | 'unfollow' | 'copy' | 'move' | 'remove' | 'duplicate' | 'op'>(null);
  const [op, setOp] = useState<Operation | null>(null);
  const [q, setQ] = useState('');
  const dragFrom = useRef<number | null>(null);
  const [dragOver, setDragOver] = useState<{ idx: number; after: boolean } | null>(null);

  const load = async (refresh = false) => {
    setError(null);
    try {
      setData(await api<Detail>('/api/playlists/' + id, { query: refresh ? { refresh: 1 } : undefined }));
    } catch (e) {
      if (e instanceof ApiError) setError(e); else showError(e);
    }
  };
  useEffect(() => { load(); setSelected(new Set()); setSelectMode(false); }, [id]);

  const handle = async (fn: () => Promise<void>, reloadAfter = true) => {
    setBusy(true);
    try {
      await fn();
      if (reloadAfter) await load();
    } catch (e) {
      if (e instanceof ApiError && e.status === 409 && e.code === 'playlist.snapshot_conflict') {
        toast(e.message, 'error', 5000);
        await load(true);
      } else showError(e);
    } finally {
      setBusy(false);
    }
  };

  const pl = data?.playlist;
  const items = data?.items ?? [];
  const canEdit = !!pl?.can_edit;
  const visible = q ? items.filter((i) => (i.name ?? '').toLowerCase().includes(q.toLowerCase()) || artistsText(i.artists).toLowerCase().includes(q.toLowerCase())) : items;
  const selItems = items.filter((i) => selected.has(i.position!));

  const toggleSel = (pos: number) => {
    const s = new Set(selected);
    s.has(pos) ? s.delete(pos) : s.add(pos);
    setSelected(s);
  };

  const reorder = (from: number, to: number) => {
    if (!pl || from === to) return;
    const insertBefore = to > from ? to + 1 : to;
    const next = [...items];
    const [m] = next.splice(from, 1);
    next.splice(to, 0, m);
    setData({ ...data!, items: next.map((it, i) => ({ ...it, position: i })) });
    handle(async () => {
      await api('/api/playlists/' + id + '/items/reorder', { body: { range_start: from, insert_before: insertBefore, range_length: 1, snapshot_id: pl.snapshot_id } });
    }, false).then(() => load());
  };

  const removeSelected = () => handle(async () => {
    const byUri: Record<string, number[]> = {};
    for (const it of selItems) { if (it.uri) (byUri[it.uri] ??= []).push(it.position!); }
    await api('/api/playlists/' + id + '/items/remove', { body: { snapshot_id: pl!.snapshot_id, items: Object.entries(byUri).map(([uri, positions]) => ({ uri, positions })) } });
    toast(t('playlist.removed_n', { n: selItems.length }), 'ok');
    setSelected(new Set()); setSelectMode(false); setSheet(null);
  });

  const runOp = async (path: string, body: Record<string, unknown>) => {
    setSheet('op');
    setOp(null);
    try {
      const r = await api<Operation>(path, { body: { op_id: uuid(), ...body } });
      setOp(r);
      if (r.status === 'done') { toast(t('op.done'), 'ok'); }
      await load();
      setSelected(new Set()); setSelectMode(false);
    } catch (e) { showError(e); setSheet(null); }
  };
  const resumeOp = async () => {
    if (!op) return;
    setBusy(true);
    try { const r = await api<Operation>('/api/playlists/operations/' + op.id + '/resume', { body: {} }); setOp(r); if (r.status === 'done') toast(t('op.done'), 'ok'); await load(); } catch (e) { showError(e); } finally { setBusy(false); }
  };

  if (error) {
    return <Layout><TopBar back="/" title={t('playlist.title')} /><ErrorState text={error.message} retry={() => load(true)} /></Layout>;
  }
  if (!pl) return <Layout><TopBar back="/" /><Loading text={t('playlist.loading_items')} /></Layout>;

  const menuItems = [
    ...(canEdit ? [
      { label: t('playlist.edit_details'), icon: <IEdit />, onClick: () => setSheet('edit') },
      { label: t('playlist.upload_cover'), icon: <IImage />, onClick: () => setSheet('cover') },
      { label: t('playlist.ai_cover'), icon: <ISparkle />, onClick: () => navigate('/covers?playlist=' + id) },
      { label: t('playlist.find_duplicates'), icon: <ICopy />, onClick: () => setSheet('duplicates') },
    ] : []),
    { label: t('playlist.duplicate'), icon: <ICopy />, onClick: () => setSheet('duplicate') },
    { label: t('playlist.refresh'), icon: <IRefresh />, onClick: () => load(true) },
    { label: t('playlist.open_spotify'), icon: <IExternal />, onClick: () => window.open(pl.spotify_url, '_blank', 'noopener') },
    { label: t('playlist.remove_from_library'), icon: <ITrash />, danger: true, onClick: () => setSheet('unfollow') },
  ];

  return (
    <Layout width="wide">
      <TopBar back="/" title={pl.name} right={<Menu button={<button class="btn icon ghost" aria-label={t('common.more')}><IMore /></button>} items={menuItems} />} />
      <div class="card row gap-2" style="align-items:flex-start">
        {pl.image_url ? <img src={pl.image_url} alt="" style="width:96px;height:96px;border-radius:12px;object-fit:cover" /> : <div class="thumb lg" style="width:96px;height:96px;border-radius:12px;background:var(--bg-card-2);display:grid;place-items:center"><IMusic /></div>}
        <div class="grow col gap-1">
          <h2 class="truncate">{pl.name}</h2>
          {pl.description && <p class="muted small">{pl.description}</p>}
          <div class="small muted">{t('playlist.n_songs', { n: items.length })} · {pl.is_owner ? t('playlist.yours') : pl.owner_name}{!canEdit ? ' · ' + t('playlist.read_only') : ''}</div>
          <div class="row wrap gap-1">
            {pl.spotify_public !== null && <span class="chip">{pl.spotify_public ? t('playlist.spotify_public_short') : t('playlist.spotify_private_short')}</span>}
            {pl.is_collaborative && <span class="chip">{t('playlist.collab')}</span>}
            {pl.is_published && <span class="chip accent"><IGlobe style="width:12px;height:12px" />{t('playlist.published')}</span>}
          </div>
        </div>
      </div>
      <div class="card mt-2" style="padding:0.25rem 1rem">
        <Toggle label={t('playlist.publish_toggle')} desc={t('playlist.publish_hint')} checked={pl.is_published} disabled={busy}
          onChange={(v) => handle(async () => { const r = await api<Playlist>('/api/playlists/' + id + '/publish', { body: { published: v } }); setData({ ...data!, playlist: r }); toast(v ? t('playlist.published_ok') : t('playlist.unpublished_ok'), 'ok'); }, false)} />
        {pl.is_published && pl.published_id && <div class="small" style="padding-bottom:.6rem"><a href={href('/community/playlists/' + pl.published_id)}>{t('playlist.view_community')}</a></div>}
      </div>

      <div class="row wrap gap-1 mt-2">
        <div class="search grow" style="min-width:180px"><ISearch /><input class="input" placeholder={t('playlist.filter_songs')} value={q} onInput={(e) => setQ((e.target as HTMLInputElement).value)} /></div>
        {canEdit && <button class="btn sm primary" onClick={() => setSheet('add')}><IPlus />{t('playlist.add_songs')}</button>}
        {canEdit && items.length > 0 && <button class={'btn sm' + (selectMode ? ' success' : '')} onClick={() => { setSelectMode(!selectMode); setSelected(new Set()); }}>{selectMode ? t('common.done') : t('playlist.select')}</button>}
      </div>
      {canEdit && !selectMode && items.length > 1 && <p class="kbd-hint mt-1">{t('playlist.reorder_hint')}</p>}

      <div class="list mt-2">
        {items.length === 0 ? <EmptyState icon={<IMusic />} title={t('playlist.empty_title')} text={canEdit ? t('playlist.empty_text') : undefined} action={canEdit ? <a class="btn primary" href={href('/discover?target=' + id)}>{t('playlist.go_discover')}</a> : undefined} />
          : visible.map((it, vi) => {
            const pos = it.position!;
            const isSel = selected.has(pos);
            return (
              <div key={pos + ':' + it.id} class={'item' + (isSel ? ' selected' : '') + (dragOver?.idx === pos ? (dragOver.after ? ' drop-after' : ' drop-before') : '')}
                draggable={canEdit && !selectMode && !q}
                onDragStart={(e) => { dragFrom.current = pos; e.dataTransfer?.setData('text/plain', String(pos)); }}
                onDragOver={(e) => { if (dragFrom.current === null) return; e.preventDefault(); const r = (e.currentTarget as HTMLElement).getBoundingClientRect(); setDragOver({ idx: pos, after: e.clientY > r.top + r.height / 2 }); }}
                onDragLeave={() => setDragOver(null)}
                onDrop={(e) => { e.preventDefault(); const from = dragFrom.current; dragFrom.current = null; setDragOver(null); if (from === null) return; let to = pos + (dragOver?.after ? 1 : 0); if (from < to) to--; reorder(from, to); }}
                onDragEnd={() => { dragFrom.current = null; setDragOver(null); }}
                onClick={() => selectMode && toggleSel(pos)}>
                {selectMode ? <Check on={isSel} onToggle={() => toggleSel(pos)} /> : canEdit && !q ? <span class="drag-handle hide-mobile" aria-hidden="true"><IHandle /></span> : null}
                {it.image_url ? <img class="thumb" src={it.image_url} alt="" loading="lazy" /> : <div class="thumb" />}
                <div class="meta">
                  <div class="title truncate">{it.name ?? t('playlist.unavailable_track')}</div>
                  <div class="sub truncate">{artistsText(it.artists)}{it.album_name ? ' · ' + it.album_name : ''}{it.is_local ? ' · ' + t('playlist.local_file') : ''}</div>
                </div>
                <span class="tiny muted hide-mobile">{fmtDuration(it.duration_ms)}</span>
                {!selectMode && (
                  <div class="actions">
                    {canEdit && !q && <span class="hide-desktop row gap-0" style="gap:0">
                      <button class="btn icon ghost sm" aria-label={t('playlist.move_up')} disabled={vi === 0 || busy} onClick={() => reorder(pos, pos - 1)}><IUp /></button>
                      <button class="btn icon ghost sm" aria-label={t('playlist.move_down')} disabled={vi === visible.length - 1 || busy} onClick={() => reorder(pos, pos + 1)}><IDown /></button>
                    </span>}
                    {it.spotify_url && <a class="btn icon ghost sm" href={it.spotify_url} target="_blank" rel="noopener" aria-label={t('track.open_spotify')}><IExternal /></a>}
                  </div>
                )}
              </div>
            );
          })}
      </div>

      {selectMode && selected.size > 0 && (
        <div class="selection-bar">
          <span class="small" style="white-space:nowrap">{t('playlist.n_selected', { n: selected.size })}</span>
          <span class="grow" />
          <button class="btn sm" onClick={() => setSelected(new Set(items.map((i) => i.position!)))}>{t('common.all')}</button>
          <button class="btn sm" onClick={() => setSheet('copy')}><ICopy />{t('playlist.copy_to')}</button>
          <button class="btn sm" onClick={() => setSheet('move')}><IMove />{t('playlist.move_to')}</button>
          <button class="btn sm danger" onClick={() => setSheet('remove')}><ITrash />{t('common.remove')}</button>
        </div>
      )}

      {sheet === 'edit' && <EditDetails pl={pl} onClose={() => setSheet(null)} onSaved={(p) => { setData({ ...data!, playlist: p }); setSheet(null); toast(t('common.saved'), 'ok'); }} />}
      {sheet === 'cover' && <CoverUpload id={id} onClose={() => setSheet(null)} onDone={() => { setSheet(null); toast(t('playlist.cover_uploaded'), 'ok'); load(true); }} />}
      {sheet === 'add' && <AddSongs id={id} existing={new Set(items.map((i) => i.id).filter(Boolean) as string[])} onClose={() => setSheet(null)} onAdded={() => load()} />}
      {sheet === 'duplicates' && <Duplicates id={id} onClose={() => setSheet(null)} onDone={() => { setSheet(null); load(); }} />}
      {sheet === 'unfollow' && <Confirm title={t('playlist.remove_from_library')} danger confirmLabel={t('playlist.remove_confirm')} busy={busy} onClose={() => setSheet(null)}
        text={<><p>{pl.is_owner ? t('playlist.remove_own_text') : t('playlist.remove_followed_text')}</p><p class="mt-1 tiny">{t('playlist.remove_note')}</p></>}
        onConfirm={() => handle(async () => { await api('/api/playlists/' + id + '/unfollow', { body: {} }); toast(t('playlist.removed_from_library'), 'ok'); navigate('/'); }, false)} />}
      {sheet === 'remove' && <Confirm title={t('playlist.remove_songs')} danger busy={busy} text={t('playlist.remove_songs_text', { n: selected.size })} onClose={() => setSheet(null)} onConfirm={removeSelected} />}
      {(sheet === 'copy' || sheet === 'move') && <PlaylistPicker title={sheet === 'copy' ? t('playlist.copy_to') : t('playlist.move_to')} exclude={[id]} allowCreate onClose={() => setSheet(null)}
        onPick={(target) => {
          const uris = selItems.map((i) => i.uri).filter(Boolean) as string[];
          if (sheet === 'copy') runOp('/api/playlists/copy', { source_id: id, target_id: target.id, uris, skip_existing: true });
          else {
            const byUri: Record<string, number[]> = {};
            for (const it of selItems) { if (it.uri) (byUri[it.uri] ??= []).push(it.position!); }
            runOp('/api/playlists/move', { source_id: id, target_id: target.id, snapshot_id: pl.snapshot_id, items: Object.entries(byUri).map(([uri, positions]) => ({ uri, positions })), skip_existing: true });
          }
        }} />}
      {sheet === 'duplicate' && <DuplicateSheet name={pl.name} onClose={() => setSheet(null)} onConfirm={(name) => runOp('/api/playlists/' + id + '/duplicate', { name })} />}
      {sheet === 'op' && <OpSheet op={op} busy={busy} onResume={resumeOp} onClose={() => setSheet(null)} />}
    </Layout>
  );
}

export function OpSheet({ op, busy, onResume, onClose }: { op: Operation | null; busy: boolean; onResume: () => void; onClose: () => void }) {
  const progress = (op?.progress ?? {}) as { added?: string[]; removed?: string[]; skipped?: string[]; total?: number; count?: number; target?: string };
  return (
    <Sheet title={t('op.title')} onClose={onClose} actions={op && op.status !== 'done' ? <><button class="btn" onClick={onClose}>{t('common.close')}</button><button class="btn primary" disabled={busy} onClick={onResume}>{busy ? <Spinner small /> : t('op.resume')}</button></> : <button class="btn primary" onClick={onClose}>{t('common.ok')}</button>}>
      {!op ? <Loading text={t('op.running')} /> : (
        <div class="col">
          {op.status === 'done' ? <Notice kind="ok">{t('op.done_text')}</Notice> : <Notice kind="warn">{t('op.partial_text')} {op.error ? <span class="mono">({op.error})</span> : null}</Notice>}
          <div class="small muted">
            {progress.added && <div>{t('op.added', { n: progress.added.length })}</div>}
            {progress.count !== undefined && <div>{t('op.added', { n: progress.count })}</div>}
            {progress.removed && <div>{t('op.removed', { n: progress.removed.length })}</div>}
            {progress.skipped && progress.skipped.length > 0 && <div>{t('op.skipped', { n: progress.skipped.length })}</div>}
          </div>
          {op.target_playlist_id && op.status === 'done' && <a class="btn" href={href('/playlists/' + op.target_playlist_id)} onClick={onClose}>{t('op.open_target')}</a>}
        </div>
      )}
    </Sheet>
  );
}

function EditDetails({ pl, onClose, onSaved }: { pl: Playlist; onClose: () => void; onSaved: (p: Playlist) => void }) {
  const [name, setName] = useState(pl.name);
  const [desc, setDesc] = useState(pl.description ?? '');
  const [pub, setPub] = useState(!!pl.spotify_public);
  const [busy, setBusy] = useState(false);
  const submit = async (e: Event) => {
    e.preventDefault();
    setBusy(true);
    try {
      const body: Record<string, unknown> = { name, description: desc };
      if (pl.is_owner) body.public = pub;
      onSaved(await api<Playlist>('/api/playlists/' + pl.id, { method: 'PATCH', body }));
    } catch (err) { showError(err); } finally { setBusy(false); }
  };
  return (
    <Sheet title={t('playlist.edit_details')} onClose={onClose}>
      <form class="col" onSubmit={submit}>
        <Field label={t('playlist.name')}><input class="input" value={name} onInput={(e) => setName((e.target as HTMLInputElement).value)} maxLength={100} required /></Field>
        <Field label={t('playlist.description')}><textarea class="input" value={desc} onInput={(e) => setDesc((e.target as HTMLTextAreaElement).value)} maxLength={300} /></Field>
        {pl.is_owner && <Toggle label={t('playlist.spotify_public')} desc={t('playlist.spotify_public_hint')} checked={pub} onChange={setPub} />}
        <button class="btn primary block" type="submit" disabled={busy || !name.trim()}>{busy ? <Spinner small /> : t('common.save')}</button>
      </form>
    </Sheet>
  );
}

export function CoverUpload({ id, onClose, onDone }: { id: string; onClose: () => void; onDone: () => void }) {
  const [src, setSrc] = useState<string | null>(null);
  const [file, setFile] = useState<File | null>(null);
  const [crop, setCrop] = useState({ x: 0, y: 0, w: 1, h: 1 });
  const [busy, setBusy] = useState(false);
  const submit = async () => {
    if (!file) return;
    setBusy(true);
    try {
      const fd = new FormData();
      fd.append('image', file);
      fd.append('crop', JSON.stringify(crop));
      await api('/api/playlists/' + id + '/cover', { form: fd });
      onDone();
    } catch (e) { showError(e); } finally { setBusy(false); }
  };
  return (
    <Sheet title={t('playlist.upload_cover')} onClose={onClose} actions={<><button class="btn" onClick={onClose}>{t('common.cancel')}</button><button class="btn primary" disabled={!file || busy} onClick={submit}>{busy ? <Spinner small /> : t('common.upload')}</button></>}>
      <p class="small muted mb-2">{t('playlist.cover_hint')}</p>
      <input class="input" type="file" accept="image/jpeg,image/png,image/webp" onChange={async (e) => { const f = (e.target as HTMLInputElement).files?.[0]; if (f) { setFile(f); setSrc(await readFileAsDataUrl(f)); } }} />
      {src && <div class="mt-2"><Cropper src={src} aspect={1} onChange={setCrop} /></div>}
    </Sheet>
  );
}

function AddSongs({ id, existing, onClose, onAdded }: { id: string; existing: Set<string>; onClose: () => void; onAdded: () => void }) {
  const [q, setQ] = useState('');
  const [results, setResults] = useState<Track[]>([]);
  const [loading, setLoading] = useState(false);
  const [offset, setOffset] = useState(0);
  const [added, setAdded] = useState<Set<string>>(new Set());
  const ctrl = useRef<AbortController | null>(null);
  const search = async (reset = true) => {
    if (q.trim().length < 2) { setResults([]); return; }
    ctrl.current?.abort();
    ctrl.current = new AbortController();
    setLoading(true);
    try {
      const off = reset ? 0 : offset + 10;
      const r = await api<{ tracks: Track[] }>('/api/spotify/search', { query: { q, offset: off }, signal: ctrl.current.signal });
      setResults(reset ? r.tracks : [...results, ...r.tracks]);
      setOffset(off);
    } catch (e) { if ((e as Error).name !== 'AbortError') showError(e); } finally { setLoading(false); }
  };
  useEffect(() => { const h = window.setTimeout(() => search(true), 350); return () => window.clearTimeout(h); }, [q]);
  const add = async (tr: Track) => {
    if (!tr.uri || !tr.id) return;
    try {
      await api('/api/playlists/' + id + '/items', { body: { uris: [tr.uri] } });
      setAdded(new Set([...added, tr.id]));
      toast(t('playlist.added_one', { name: tr.name ?? '' }), 'ok', 1800);
      onAdded();
    } catch (e) { showError(e); }
  };
  return (
    <Sheet title={t('playlist.add_songs')} onClose={onClose} wide>
      <div class="search"><ISearch /><input class="input" placeholder={t('playlist.search_spotify')} value={q} onInput={(e) => setQ((e.target as HTMLInputElement).value)} autoFocus /></div>
      <p class="tiny muted mt-1">{t('playlist.search_limit_note')}</p>
      <div class="list mt-2">
        {results.map((tr) => {
          const inList = existing.has(tr.id!) || added.has(tr.id!);
          return (
            <div key={tr.id} class="item">
              {tr.image_url ? <img class="thumb" src={tr.image_url} alt="" loading="lazy" /> : <div class="thumb" />}
              <div class="meta"><div class="title truncate">{tr.name}</div><div class="sub truncate">{artistsText(tr.artists)}{tr.album_name ? ' · ' + tr.album_name : ''}</div></div>
              <button class={'btn icon sm' + (inList ? ' success' : ' primary')} disabled={inList} aria-label={t('common.add')} onClick={() => add(tr)}>{inList ? <IX /> : <IPlus />}</button>
            </div>
          );
        })}
        {loading && <Loading />}
        {!loading && results.length > 0 && results.length % 10 === 0 && <button class="btn" onClick={() => search(false)}>{t('common.load_more')}</button>}
        {!loading && q.trim().length >= 2 && results.length === 0 && <EmptyState title={t('common.no_results')} />}
      </div>
    </Sheet>
  );
}

interface DupGroup { track_id: string; uri: string; name: string | null; artists: string[]; album_name: string | null; image_url: string | null; occurrences: { position: number; added_at: string | null }[]; keep_position: number }

function Duplicates({ id, onClose, onDone }: { id: string; onClose: () => void; onDone: () => void }) {
  const [data, setData] = useState<{ groups: DupGroup[]; snapshot_id: string | null } | null>(null);
  const [keep, setKeep] = useState<Record<string, number>>({});
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    api<{ groups: DupGroup[]; snapshot_id: string | null }>('/api/playlists/' + id + '/duplicates').then((r) => {
      setData(r);
      const k: Record<string, number> = {};
      for (const g of r.groups) k[g.track_id] = g.keep_position;
      setKeep(k);
    }).catch((e) => { showError(e); onClose(); });
  }, [id]);
  const removeCount = (data?.groups ?? []).reduce((n, g) => n + g.occurrences.length - 1, 0);
  const submit = async () => {
    if (!data) return;
    setBusy(true);
    try {
      const removals = data.groups.map((g) => ({ uri: g.uri, positions: g.occurrences.map((o) => o.position).filter((p) => p !== keep[g.track_id]) })).filter((r) => r.positions.length > 0);
      const r = await api<Operation>('/api/playlists/' + id + '/duplicates/remove', { body: { op_id: uuid(), snapshot_id: data.snapshot_id, removals } });
      if (r.status === 'done') toast(t('playlist.duplicates_removed', { n: removeCount }), 'ok'); else toast(t('op.partial_text'), 'error');
      onDone();
    } catch (e) {
      if (e instanceof ApiError && e.status === 409) { toast(e.message, 'error', 5000); onDone(); } else showError(e);
    } finally { setBusy(false); }
  };
  return (
    <Sheet title={t('playlist.find_duplicates')} onClose={onClose} wide actions={data && data.groups.length > 0 ? <><button class="btn" onClick={onClose}>{t('common.cancel')}</button><button class="btn danger" disabled={busy || removeCount === 0} onClick={submit}>{busy ? <Spinner small /> : t('playlist.remove_n_duplicates', { n: removeCount })}</button></> : undefined}>
      <p class="small muted mb-2">{t('playlist.duplicates_hint')}</p>
      {!data ? <Loading /> : data.groups.length === 0 ? <EmptyState title={t('playlist.no_duplicates')} /> : (
        <div class="list">
          {data.groups.map((g) => (
            <div key={g.track_id} class="card flat col gap-1">
              <div class="row">
                {g.image_url ? <img class="thumb" src={g.image_url} alt="" /> : <div class="thumb" />}
                <div class="meta"><div class="title truncate">{g.name}</div><div class="sub truncate">{artistsText(g.artists)} · {t('playlist.n_occurrences', { n: g.occurrences.length })}</div></div>
              </div>
              <div class="row wrap gap-1 small">
                <span class="muted">{t('playlist.keep')}:</span>
                {g.occurrences.map((o) => (
                  <button key={o.position} class={'chip' + (keep[g.track_id] === o.position ? ' ok' : '')} onClick={() => setKeep({ ...keep, [g.track_id]: o.position })}>#{o.position + 1}</button>
                ))}
              </div>
            </div>
          ))}
        </div>
      )}
    </Sheet>
  );
}

function DuplicateSheet({ name, onClose, onConfirm }: { name: string; onClose: () => void; onConfirm: (name: string) => void }) {
  const [n, setN] = useState(name + ' ' + t('playlist.copy_suffix'));
  return (
    <Sheet title={t('playlist.duplicate')} onClose={onClose} actions={<><button class="btn" onClick={onClose}>{t('common.cancel')}</button><button class="btn primary" disabled={!n.trim()} onClick={() => onConfirm(n)}>{t('playlist.duplicate')}</button></>}>
      <p class="small muted mb-2">{t('playlist.duplicate_hint')}</p>
      <Field label={t('playlist.name')}><input class="input" value={n} onInput={(e) => setN((e.target as HTMLInputElement).value)} maxLength={100} /></Field>
    </Sheet>
  );
}
