import { useEffect, useState } from 'preact/hooks';
import { Layout } from '../components/Layout';
import { api, showError, uuid } from '../lib/api';
import { t, fmtDate } from '../lib/i18n';
import { toast, user, platform } from '../lib/state';
import { route, href } from '../lib/router';
import type { Cover, Quota, Playlist } from '../lib/types';
import { Confirm, EmptyState, Field, Loading, Notice, Sheet, Spinner, TopBar } from '../components/ui';
import { PlaylistPicker } from '../components/PlaylistPicker';
import { ISparkle, IDownload, IImage, ITrash, IStar } from '../components/Icons';

export function Covers() {
  const [covers, setCovers] = useState<Cover[] | null>(null);
  const [quota, setQuota] = useState<Quota | null>(null);
  const [styles, setStyles] = useState<string[]>([]);
  const [prompt, setPrompt] = useState('');
  const [style, setStyle] = useState('none');
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<Cover | null>(null);
  const [apply, setApply] = useState<Cover | null>(null);
  const [applyTo, setApplyTo] = useState<{ cover: Cover; pl: Playlist } | null>(null);
  const [del, setDel] = useState<Cover | null>(null);
  const presetTarget = route.value.query.get('playlist');
  const me = user.value!;
  const load = () => api<{ covers: Cover[]; quota: Quota; styles: string[] }>('/api/covers').then((r) => { setCovers(r.covers); setQuota(r.quota); setStyles(r.styles); }).catch((e) => { showError(e); setCovers([]); });
  useEffect(() => { load(); }, []);
  const generate = async (e: Event) => {
    e.preventDefault();
    setBusy(true);
    setResult(null);
    try {
      const r = await api<{ cover: Cover; quota: Quota }>('/api/covers', { body: { job_id: uuid(), prompt, style } });
      setResult(r.cover);
      setQuota(r.quota);
      setCovers((c) => [r.cover, ...(c ?? [])]);
    } catch (err) { showError(err); } finally { setBusy(false); }
  };
  const doApply = async () => {
    if (!applyTo) return;
    setBusy(true);
    try {
      const c = await api<Cover>('/api/covers/' + applyTo.cover.id + '/apply', { body: { playlist_id: applyTo.pl.id } });
      setCovers((cs) => (cs ?? []).map((x) => (x.id === c.id ? c : x)));
      toast(t('covers.applied', { name: applyTo.pl.name }), 'ok');
      setApplyTo(null);
    } catch (e) { showError(e); } finally { setBusy(false); }
  };
  const remove = async () => {
    if (!del) return;
    try { await api('/api/covers/' + del.id, { method: 'DELETE' }); setCovers((cs) => (cs ?? []).filter((x) => x.id !== del.id)); setDel(null); } catch (e) { showError(e); }
  };
  const canGenerate = quota?.available && quota.premium && (quota.unlimited || (quota.remaining ?? 0) > 0);
  return (
    <Layout>
      <TopBar back="/profile" title={t('covers.title')} />
      {quota && (
        <div class="card row between wrap gap-1 mb-2">
          <div>
            <div class="small"><strong>{quota.unlimited ? t('covers.unlimited') : t('covers.remaining', { n: quota.remaining ?? 0, total: quota.limit })}</strong></div>
            {!quota.unlimited && quota.resets_at && <div class="tiny muted">{t('covers.resets', { date: fmtDate(quota.resets_at) })}</div>}
            {!quota.unlimited && <div class="progress mt-1" style="width:160px"><div style={{ width: Math.min(100, (quota.used / Math.max(1, quota.limit)) * 100) + '%' }} /></div>}
          </div>
          {!quota.premium && <a class="btn sm primary" href={href('/premium')}><IStar />{t('covers.get_premium')}</a>}
        </div>
      )}
      {quota && !quota.available && <Notice kind="warn">{t('covers.not_configured')}</Notice>}
      <form class="card col" onSubmit={generate}>
        <Field label={t('covers.prompt')} hint={t('covers.prompt_hint')}><textarea class="input" value={prompt} onInput={(e) => setPrompt((e.target as HTMLTextAreaElement).value)} maxLength={1500} placeholder={t('covers.prompt_placeholder')} /></Field>
        <Field label={t('covers.style')}>
          <div class="row wrap gap-1">{styles.map((s) => <button type="button" key={s} class={'chip' + (style === s ? ' accent' : '')} onClick={() => setStyle(s)}>{t('covers.style_' + s)}</button>)}</div>
        </Field>
        <button class="btn primary block" type="submit" disabled={busy || !canGenerate || prompt.trim().length < 3}>{busy ? <><Spinner small /> {t('covers.generating')}</> : <><ISparkle />{t('covers.generate')}</>}</button>
        <p class="tiny muted">{t('covers.privacy_note', { model: platform.value.cover_model })}</p>
      </form>
      {result && result.image_url && (
        <div class="card col mt-2" style="align-items:center">
          <img class="cover-preview" src={result.image_url} alt={result.prompt} />
          <div class="row wrap center gap-1">
            <a class="btn" href={result.image_url + '?download=1'} download><IDownload />{t('common.download')}</a>
            <button class="btn primary" onClick={() => setApply(result)}><IImage />{t('covers.use_as_cover')}</button>
          </div>
        </div>
      )}
      <h3 class="mt-3 mb-1">{t('covers.history')}</h3>
      {covers === null ? <Loading /> : covers.filter((c) => c.image_url).length === 0 ? <EmptyState icon={<IImage />} title={t('covers.none')} /> : (
        <div class="cover-grid">
          {covers.filter((c) => c.image_url).map((c) => (
            <div key={c.id} class="col gap-1">
              <img src={c.image_url!} alt={c.prompt} loading="lazy" onClick={() => setResult(c)} />
              <div class="row gap-1">
                <button class="btn sm grow" onClick={() => setApply(c)}>{t('covers.use')}</button>
                <button class="btn icon ghost sm" aria-label={t('common.delete')} onClick={() => setDel(c)}><ITrash /></button>
              </div>
              {c.applied_playlist_id && <span class="tiny muted truncate">{t('covers.applied_short')}</span>}
            </div>
          ))}
        </div>
      )}
      {apply && <PlaylistPicker title={t('covers.choose_playlist')} onClose={() => setApply(null)} onPick={(pl) => { setApplyTo({ cover: apply, pl }); setApply(null); }} />}
      {applyTo && <Confirm title={t('covers.confirm_title')} text={t('covers.confirm_text', { name: applyTo.pl.name })} busy={busy} onClose={() => setApplyTo(null)} onConfirm={doApply} confirmLabel={t('covers.set_cover')} />}
      {del && <Confirm title={t('common.delete')} danger text={t('covers.delete_text')} onClose={() => setDel(null)} onConfirm={remove} />}
      {presetTarget && !apply && !applyTo && covers && covers.length > 0 && null}
    </Layout>
  );
}
