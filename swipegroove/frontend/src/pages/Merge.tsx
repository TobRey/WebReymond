import { useEffect, useState } from 'preact/hooks';
import { Layout } from '../components/Layout';
import { api, showError, uuid } from '../lib/api';
import { t } from '../lib/i18n';
import type { Playlist, Operation } from '../lib/types';
import { Check, Field, Loading, Seg, Toggle, TopBar } from '../components/ui';
import { PlaylistPicker } from '../components/PlaylistPicker';
import { OpSheet } from './PlaylistDetail';
import { IMerge } from '../components/Icons';

export function Merge() {
  const [lists, setLists] = useState<Playlist[] | null>(null);
  const [sel, setSel] = useState<Set<string>>(new Set());
  const [mode, setMode] = useState<'new' | 'existing'>('new');
  const [name, setName] = useState('');
  const [skip, setSkip] = useState(true);
  const [pick, setPick] = useState(false);
  const [target, setTarget] = useState<Playlist | null>(null);
  const [op, setOp] = useState<Operation | null | undefined>(undefined);
  const [busy, setBusy] = useState(false);
  useEffect(() => { api<{ playlists: Playlist[] }>('/api/playlists').then((r) => setLists(r.playlists.filter((p) => p.can_edit))).catch(showError); }, []);
  const run = async () => {
    setOp(null);
    setBusy(true);
    try {
      const r = await api<Operation>('/api/playlists/merge', { body: { op_id: uuid(), sources: [...sel], target_id: mode === 'existing' ? target?.id : null, new_name: mode === 'new' ? name : '', skip_existing: skip } });
      setOp(r);
    } catch (e) { showError(e); setOp(undefined); } finally { setBusy(false); }
  };
  const resume = async () => { if (!op) return; setBusy(true); try { setOp(await api<Operation>('/api/playlists/operations/' + op.id + '/resume', { body: {} })); } catch (e) { showError(e); } finally { setBusy(false); } };
  const ok = sel.size >= 1 && (mode === 'new' ? name.trim().length > 0 : !!target);
  return (
    <Layout width="narrow">
      <TopBar back="/" title={t('merge.title')} />
      <p class="muted small mb-2">{t('merge.intro')}</p>
      <h3 class="mb-1">{t('merge.sources')}</h3>
      <div class="list" style="max-height:40vh;overflow:auto">
        {!lists ? <Loading /> : lists.map((p) => (
          <div key={p.id} class={'item clickable' + (sel.has(p.id) ? ' selected' : '')} onClick={() => { const s = new Set(sel); s.has(p.id) ? s.delete(p.id) : s.add(p.id); setSel(s); }}>
            <Check on={sel.has(p.id)} onToggle={() => { const s = new Set(sel); s.has(p.id) ? s.delete(p.id) : s.add(p.id); setSel(s); }} />
            {p.image_url ? <img class="thumb" src={p.image_url} alt="" loading="lazy" /> : <div class="thumb" />}
            <div class="meta"><div class="title truncate">{p.name}</div><div class="sub">{t('playlist.n_songs', { n: p.track_count })}</div></div>
          </div>
        ))}
      </div>
      <h3 class="mt-3 mb-1">{t('merge.target')}</h3>
      <Seg value={mode} onChange={setMode} options={[{ value: 'new', label: t('merge.new_playlist') }, { value: 'existing', label: t('merge.existing_playlist') }]} />
      <div class="mt-2">
        {mode === 'new' ? <Field label={t('playlist.name')}><input class="input" value={name} onInput={(e) => setName((e.target as HTMLInputElement).value)} maxLength={100} /></Field>
          : <button class="btn block" onClick={() => setPick(true)}>{target ? target.name : t('merge.choose_target')}</button>}
      </div>
      <Toggle label={t('merge.skip_existing')} desc={t('merge.skip_existing_hint')} checked={skip} onChange={setSkip} />
      <button class="btn primary block mt-2" disabled={!ok || busy} onClick={run}><IMerge />{t('merge.run')}</button>
      {pick && <PlaylistPicker title={t('merge.choose_target')} exclude={[...sel]} onClose={() => setPick(false)} onPick={(p) => { setTarget(p); setPick(false); }} />}
      {op !== undefined && <OpSheet op={op} busy={busy} onResume={resume} onClose={() => setOp(undefined)} />}
    </Layout>
  );
}
