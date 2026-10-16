import { useEffect, useState } from 'preact/hooks';
import { api, showError } from '../lib/api';
import { t } from '../lib/i18n';
import type { Playlist } from '../lib/types';
import { Loading, Sheet, EmptyState } from './ui';
import { ISearch, IMusic } from './Icons';

/** Auswahl einer bearbeitbaren Zielplaylist (Bottom-Sheet). */
export function PlaylistPicker({ title, exclude, onPick, onClose, allowCreate }: { title: string; exclude?: string[]; onPick: (p: Playlist) => void; onClose: () => void; allowCreate?: boolean }) {
  const [lists, setLists] = useState<Playlist[] | null>(null);
  const [q, setQ] = useState('');
  const [creating, setCreating] = useState(false);
  const [name, setName] = useState('');
  useEffect(() => {
    api<{ playlists: Playlist[] }>('/api/playlists').then((r) => setLists(r.playlists.filter((p) => p.can_edit && !exclude?.includes(p.id)))).catch((e) => { showError(e); onClose(); });
  }, []);
  const create = async (e: Event) => {
    e.preventDefault();
    setCreating(true);
    try {
      const p = await api<Playlist>('/api/playlists', { body: { name, description: '', public: false } });
      onPick(p);
    } catch (err) { showError(err); } finally { setCreating(false); }
  };
  const filtered = (lists ?? []).filter((p) => !q || p.name.toLowerCase().includes(q.toLowerCase()));
  return (
    <Sheet title={title} onClose={onClose}>
      <div class="search mb-2"><ISearch /><input class="input" placeholder={t('common.search')} value={q} onInput={(e) => setQ((e.target as HTMLInputElement).value)} /></div>
      {allowCreate && (
        <form class="row mb-2" onSubmit={create}>
          <input class="input grow" placeholder={t('playlist.new_name')} value={name} onInput={(e) => setName((e.target as HTMLInputElement).value)} maxLength={100} />
          <button class="btn primary" type="submit" disabled={creating || name.trim().length === 0}>{t('playlist.create_short')}</button>
        </form>
      )}
      {lists === null ? <Loading /> : filtered.length === 0 ? <EmptyState icon={<IMusic />} title={t('playlist.none_editable')} /> : (
        <div class="list">
          {filtered.map((p) => (
            <button key={p.id} class="item clickable" style="text-align:left;width:100%" onClick={() => onPick(p)}>
              {p.image_url ? <img class="thumb" src={p.image_url} alt="" loading="lazy" /> : <div class="thumb" />}
              <div class="meta"><div class="title truncate">{p.name}</div><div class="sub">{t('playlist.n_songs', { n: p.track_count })}</div></div>
            </button>
          ))}
        </div>
      )}
    </Sheet>
  );
}
