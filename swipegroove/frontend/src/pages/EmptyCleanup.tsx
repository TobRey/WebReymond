import { useEffect, useState } from 'preact/hooks';
import { Layout } from '../components/Layout';
import { api, showError } from '../lib/api';
import { t } from '../lib/i18n';
import { toast } from '../lib/state';
import type { Playlist } from '../lib/types';
import { Check, Confirm, EmptyState, Loading, Notice, TopBar } from '../components/ui';
import { ITrash } from '../components/Icons';

export function EmptyCleanup() {
  const [data, setData] = useState<{ candidates: Playlist[]; unverified: string[] } | null>(null);
  const [sel, setSel] = useState<Set<string>>(new Set());
  const [confirm, setConfirm] = useState(false);
  const [busy, setBusy] = useState(false);
  const [results, setResults] = useState<{ id: string; status: string; reason?: string }[] | null>(null);
  const load = async () => {
    setData(null);
    try {
      const r = await api<{ candidates: Playlist[]; unverified: string[] }>('/api/playlists/empty');
      setData(r);
      setSel(new Set(r.candidates.filter((c) => c.is_owner).map((c) => c.id)));
    } catch (e) { showError(e); setData({ candidates: [], unverified: [] }); }
  };
  useEffect(() => { load(); }, []);
  const remove = async () => {
    setBusy(true);
    try {
      const r = await api<{ results: { id: string; status: string; reason?: string }[] }>('/api/playlists/empty/remove', { body: { ids: [...sel] } });
      setResults(r.results);
      const n = r.results.filter((x) => x.status === 'removed').length;
      toast(t('empty.removed_n', { n }), 'ok');
      setConfirm(false);
      await load();
    } catch (e) { showError(e); } finally { setBusy(false); }
  };
  return (
    <Layout width="narrow">
      <TopBar back="/" title={t('empty.title')} />
      <p class="muted small">{t('empty.intro')}</p>
      <Notice kind="info">{t('empty.spotify_note')}</Notice>
      {data && data.unverified.length > 0 && <div class="mt-2"><Notice kind="warn">{t('empty.unverified', { n: data.unverified.length })}</Notice></div>}
      {results && results.some((r) => r.status !== 'removed') && <div class="mt-2"><Notice kind="warn">{t('empty.some_failed')}</Notice></div>}
      <div class="list mt-2">
        {!data ? <Loading text={t('empty.checking')} /> : data.candidates.length === 0 ? <EmptyState icon={<ITrash />} title={t('empty.none')} /> : data.candidates.map((p) => (
          <div key={p.id} class={'item clickable' + (sel.has(p.id) ? ' selected' : '')} onClick={() => { const s = new Set(sel); s.has(p.id) ? s.delete(p.id) : s.add(p.id); setSel(s); }}>
            <Check on={sel.has(p.id)} onToggle={() => { const s = new Set(sel); s.has(p.id) ? s.delete(p.id) : s.add(p.id); setSel(s); }} />
            {p.image_url ? <img class="thumb" src={p.image_url} alt="" loading="lazy" /> : <div class="thumb" />}
            <div class="meta"><div class="title truncate">{p.name}</div><div class="sub">{p.is_owner ? t('playlist.yours') : t('empty.foreign', { owner: p.owner_name ?? '' })}</div></div>
          </div>
        ))}
      </div>
      {data && data.candidates.length > 0 && <button class="btn danger block mt-2" disabled={sel.size === 0} onClick={() => setConfirm(true)}><ITrash />{t('empty.remove_n', { n: sel.size })}</button>}
      {confirm && <Confirm title={t('empty.confirm_title')} danger busy={busy} text={t('empty.confirm_text', { n: sel.size })} confirmLabel={t('playlist.remove_confirm')} onClose={() => setConfirm(false)} onConfirm={remove} />}
    </Layout>
  );
}
