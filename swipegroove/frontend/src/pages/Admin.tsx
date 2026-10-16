import { useEffect, useState } from 'preact/hooks';
import { Layout } from '../components/Layout';
import { api, showError } from '../lib/api';
import { t, fmtDate } from '../lib/i18n';
import { platform, toast } from '../lib/state';
import { href, navigate } from '../lib/router';
import { Confirm, Field, Loading, Notice, Seg, Sheet, Spinner, Tabs, Toggle, TopBar } from '../components/ui';
import { ISearch } from '../components/Icons';

type Section = 'dashboard' | 'users' | 'reports' | 'subscriptions' | 'covers' | 'integrations' | 'settings' | 'errors' | 'audit';
const SECTIONS: Section[] = ['dashboard', 'users', 'reports', 'subscriptions', 'covers', 'integrations', 'settings', 'errors', 'audit'];
type J = Record<string, any>;

export function Admin({ section }: { section: string }) {
  const s = (SECTIONS.includes(section as Section) ? section : 'dashboard') as Section;
  return (
    <Layout width="wide">
      <TopBar title={t('admin.title')} />
      <Tabs value={s} onChange={(v) => navigate('/admin/' + v)} options={SECTIONS.map((x) => ({ value: x, label: t('admin.sec_' + x) }))} />
      <div class="mt-2">
        {s === 'dashboard' && <Dashboard />}
        {s === 'users' && <Users />}
        {s === 'reports' && <Reports />}
        {s === 'subscriptions' && <Subscriptions />}
        {s === 'covers' && <CoversAdmin />}
        {s === 'integrations' && <Integrations />}
        {s === 'settings' && <SettingsAdmin />}
        {s === 'errors' && <Errors />}
        {s === 'audit' && <Audit />}
      </div>
    </Layout>
  );
}

function useLoad<T>(path: string, deps: unknown[] = []): [T | null, () => void] {
  const [d, setD] = useState<T | null>(null);
  const load = () => { setD(null); api<T>(path).then(setD).catch((e) => { showError(e); }); };
  useEffect(load, deps);
  return [d, load];
}

function Stat({ v, l }: { v: string | number; l: string }) {
  return <div class="stat"><div class="v">{v}</div><div class="l">{l}</div></div>;
}

function Dashboard() {
  const [d, reload] = useLoad<J>('/api/admin/dashboard');
  const [cronBusy, setCronBusy] = useState(false);
  if (!d) return <Loading />;
  return (
    <div class="col gap-2">
      <div class="stat-grid">
        <Stat v={d.users.total} l={t('admin.users_total')} /><Stat v={d.users.active_7d} l={t('admin.users_active')} /><Stat v={d.users.new_24h} l={t('admin.users_new')} /><Stat v={d.users.suspended} l={t('admin.users_suspended')} />
        <Stat v={d.reports_open} l={t('admin.reports_open')} /><Stat v={d.subscriptions.active} l={t('admin.subs_active')} /><Stat v={d.subscriptions.past_due} l={t('admin.subs_past_due')} />
        <Stat v={d.covers.month_done} l={t('admin.covers_month')} /><Stat v={'$' + d.covers.month_cost_usd} l={t('admin.covers_cost_month')} /><Stat v={d.errors_24h} l={t('admin.errors_24h')} />
      </div>
      <div class="card">
        <h3 class="mb-1">{t('admin.integrations_24h')}</h3>
        <div class="table-wrap"><table class="table"><thead><tr><th>{t('admin.service')}</th><th>{t('admin.calls')}</th><th>{t('admin.errors')}</th><th>429</th><th>{t('admin.error_rate')}</th><th>Ø ms</th></tr></thead><tbody>
          {Object.entries(d.integrations as Record<string, J>).map(([k, v]) => <tr key={k}><td>{k}</td><td>{v.calls_24h}</td><td>{v.errors_24h}</td><td>{v.rate_limited_24h}</td><td>{Math.round(v.error_rate * 100)}%</td><td>{v.avg_ms}</td></tr>)}
        </tbody></table></div>
      </div>
      <div class="card row wrap between gap-1">
        <div class="small"><div>{t('admin.mode')}: <strong>{t('admin.mode_' + d.operation_mode)}</strong> · {t('admin.registration')}: <strong>{d.registration_open ? t('common.on') : t('common.off')}</strong></div><div class="muted tiny">{t('admin.last_cron')}: {d.last_cron_at ? fmtDate(d.last_cron_at, true) : t('admin.never')} · PHP {d.php_version} · v{d.version}</div></div>
        <button class="btn sm" disabled={cronBusy} onClick={async () => { setCronBusy(true); try { await api('/api/admin/cron', { body: {} }); toast(t('admin.cron_done'), 'ok'); reload(); } catch (e) { showError(e); } finally { setCronBusy(false); } }}>{cronBusy ? <Spinner small /> : t('admin.run_cron')}</button>
      </div>
    </div>
  );
}

function Users() {
  const [q, setQ] = useState('');
  const [status, setStatus] = useState('');
  const [d, reload] = useLoad<{ users: J[] }>('/api/admin/users?q=' + encodeURIComponent(q) + '&status=' + status, [q, status]);
  const [sel, setSel] = useState<J | null>(null);
  const [reason, setReason] = useState('');
  const [days, setDays] = useState('30');
  const act = async (path: string, body: J = {}) => { try { await api(path, { body }); toast(t('common.done'), 'ok'); setSel(null); reload(); } catch (e) { showError(e); } };
  return (
    <div class="col gap-2">
      <div class="row wrap gap-1">
        <div class="search grow"><ISearch /><input class="input" placeholder={t('admin.search_users')} value={q} onInput={(e) => setQ((e.target as HTMLInputElement).value)} /></div>
        <Seg value={status} onChange={setStatus} options={[{ value: '', label: t('common.all') }, { value: 'active', label: t('admin.active') }, { value: 'suspended', label: t('admin.suspended') }]} />
      </div>
      {!d ? <Loading /> : (
        <div class="table-wrap"><table class="table"><thead><tr><th>{t('profile.username')}</th><th>{t('admin.status')}</th><th>{t('admin.role')}</th><th>Premium</th><th>{t('admin.created')}</th><th /></tr></thead><tbody>
          {d.users.map((u) => <tr key={u.id}><td><a href={href('/u/' + u.username)}>@{u.username}</a><br /><span class="muted tiny">{u.display_name}</span></td><td><span class={'chip ' + (u.status === 'active' ? 'ok' : 'danger')}>{u.status}</span></td><td>{u.is_owner ? t('profile.admin_badge') : u.role}</td><td>{u.subscription_status ?? (u.premium_manual_until ? 'manual' : '–')}</td><td class="tiny">{fmtDate(u.created_at)}</td><td><button class="btn sm" onClick={() => setSel(u)}>{t('admin.manage')}</button></td></tr>)}
        </tbody></table></div>
      )}
      {sel && (
        <Sheet title={'@' + sel.username} onClose={() => setSel(null)}>
          <div class="col gap-2">
            <div class="small muted">{sel.display_name} · {sel.status} · {t('admin.created')} {fmtDate(sel.created_at)}{sel.suspended_reason ? ' · ' + sel.suspended_reason : ''}</div>
            {sel.status === 'active' && !sel.is_owner && <div class="col gap-1"><Field label={t('admin.suspend_reason')}><input class="input" value={reason} onInput={(e) => setReason((e.target as HTMLInputElement).value)} /></Field><button class="btn danger" onClick={() => act('/api/admin/users/' + sel.id + '/suspend', { reason })}>{t('admin.suspend')}</button></div>}
            {sel.status === 'suspended' && <button class="btn success" onClick={() => act('/api/admin/users/' + sel.id + '/unsuspend')}>{t('admin.unsuspend')}</button>}
            {!sel.is_owner && <div class="col gap-1"><Field label={t('admin.premium_days')} hint={t('admin.premium_days_hint')}><input class="input" type="number" min="0" value={days} onInput={(e) => setDays((e.target as HTMLInputElement).value)} /></Field><button class="btn" onClick={() => act('/api/admin/users/' + sel.id + '/premium', { days: Number(days) })}>{t('admin.grant_premium')}</button></div>}
          </div>
        </Sheet>
      )}
    </div>
  );
}

function Reports() {
  const [status, setStatus] = useState('open');
  const [d, reload] = useLoad<{ reports: J[] }>('/api/admin/reports?status=' + status, [status]);
  const [detail, setDetail] = useState<J | null>(null);
  const [note, setNote] = useState('');
  const open = async (id: number) => { try { setDetail(await api<J>('/api/admin/reports/' + id)); } catch (e) { showError(e); } };
  const resolve = async (st: string) => { if (!detail) return; try { await api('/api/admin/reports/' + detail.report.id + '/resolve', { body: { status: st, note } }); setDetail(null); reload(); } catch (e) { showError(e); } };
  const moderate = async (path: string) => { try { await api(path, { body: {} }); toast(t('admin.content_removed'), 'ok'); if (detail) open(detail.report.id); } catch (e) { showError(e); } };
  return (
    <div class="col gap-2">
      <Seg value={status} onChange={setStatus} options={[{ value: 'open', label: t('admin.open') }, { value: 'resolved', label: t('admin.resolved') }, { value: 'dismissed', label: t('admin.dismissed') }]} />
      {!d ? <Loading /> : d.reports.length === 0 ? <p class="muted small">{t('admin.no_reports')}</p> : (
        <div class="list">{d.reports.map((r) => <button key={r.id} class="item clickable" style="text-align:left;width:100%" onClick={() => open(r.id)}><div class="meta"><div class="title">{t('report.r_' + r.reason)} · {t('admin.target_' + r.target_type)} #{r.target_id}</div><div class="sub">{t('admin.reported_by')} @{r.reporter_username} → @{r.target_username ?? '?'} · {fmtDate(r.created_at, true)}{r.details ? ' · ' + r.details : ''}</div></div></button>)}</div>
      )}
      {detail && (
        <Sheet title={t('admin.report') + ' #' + detail.report.id} onClose={() => setDetail(null)} wide>
          <div class="col gap-2">
            <div class="small"><strong>{t('report.r_' + detail.report.reason)}</strong> · {t('admin.target_' + detail.report.target_type)} · @{detail.report.reporter_username} → @{detail.report.target_username}</div>
            {detail.report.details && <p class="small muted">{detail.report.details}</p>}
            <div class="surface small">
              <div class="tiny muted mb-1">{t('admin.reported_content')}</div>
              {detail.report.target_type === 'message' && detail.content?.messages?.map((m: J) => <div key={m.id} class={m.id === detail.report.target_id ? 'card flat' : 'muted'} style="margin:.2rem 0"><strong>@{m.username}</strong>: {m.deleted_at ? <em>{t('messages.deleted')}</em> : m.body}</div>)}
              {detail.report.target_type === 'comment' && detail.content && <div><strong>@{detail.content.username}</strong>: {detail.content.deleted_at ? <em>{t('admin.already_removed')}</em> : detail.content.body}</div>}
              {detail.report.target_type === 'playlist' && detail.content && <div>{detail.content.name} {detail.content.is_published ? '' : <em>({t('admin.already_removed')})</em>}</div>}
              {detail.report.target_type === 'user' && detail.content && <div>@{detail.content.username} · {detail.content.display_name} · {detail.content.status}<br />{detail.content.bio}</div>}
              {!detail.content && <em>{t('admin.content_gone')}</em>}
            </div>
            <div class="row wrap gap-1">
              {detail.report.target_type === 'comment' && <button class="btn sm danger" onClick={() => moderate('/api/admin/moderate/comment/' + detail.report.target_id)}>{t('admin.remove_comment')}</button>}
              {detail.report.target_type === 'message' && <button class="btn sm danger" onClick={() => moderate('/api/admin/moderate/message/' + detail.report.target_id)}>{t('admin.remove_message')}</button>}
              {detail.report.target_type === 'playlist' && <button class="btn sm danger" onClick={() => moderate('/api/admin/moderate/playlist/' + detail.report.target_id)}>{t('admin.unpublish')}</button>}
              {detail.report.target_user_id && <a class="btn sm" href={href('/admin/users')} onClick={() => setDetail(null)}>{t('admin.open_user')}</a>}
            </div>
            {detail.report.status === 'open' && <>
              <Field label={t('admin.resolution_note')}><input class="input" value={note} onInput={(e) => setNote((e.target as HTMLInputElement).value)} /></Field>
              <div class="row gap-1"><button class="btn success grow" onClick={() => resolve('resolved')}>{t('admin.mark_resolved')}</button><button class="btn grow" onClick={() => resolve('dismissed')}>{t('admin.dismiss')}</button></div>
            </>}
          </div>
        </Sheet>
      )}
    </div>
  );
}

function Subscriptions() {
  const [d] = useLoad<{ subscriptions: J[]; events: J[] }>('/api/admin/subscriptions');
  if (!d) return <Loading />;
  return (
    <div class="col gap-2">
      <div class="table-wrap"><table class="table"><thead><tr><th>{t('profile.username')}</th><th>{t('admin.status')}</th><th>{t('admin.period_end')}</th><th>{t('admin.cancels')}</th><th>{t('admin.payment_error')}</th><th>{t('admin.mode_short')}</th></tr></thead><tbody>
        {d.subscriptions.map((s) => <tr key={s.user_id}><td>@{s.username}</td><td>{s.status}</td><td class="tiny">{fmtDate(s.current_period_end)}</td><td>{s.cancel_at_period_end ? t('common.yes') : '–'}</td><td class="tiny">{s.last_payment_error ?? '–'}</td><td>{s.livemode ? 'live' : 'test'}</td></tr>)}
      </tbody></table></div>
      <h3>{t('admin.webhook_events')}</h3>
      <div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>{t('admin.type')}</th><th>{t('admin.status')}</th><th>{t('admin.received')}</th></tr></thead><tbody>
        {d.events.map((e) => <tr key={e.event_id}><td class="mono tiny">{e.event_id}</td><td class="tiny">{e.type}</td><td>{e.status}{e.error ? ' · ' + e.error : ''}</td><td class="tiny">{fmtDate(e.received_at, true)}</td></tr>)}
      </tbody></table></div>
    </div>
  );
}

function CoversAdmin() {
  const [d] = useLoad<{ jobs: J[]; by_month: J[]; top_users: J[]; estimate_note: string }>('/api/admin/covers');
  if (!d) return <Loading />;
  return (
    <div class="col gap-2">
      <Notice kind="info">{t('admin.cost_note')}</Notice>
      <div class="table-wrap"><table class="table"><thead><tr><th>{t('admin.month')}</th><th>{t('admin.jobs')}</th><th>{t('admin.done')}</th><th>{t('admin.failed')}</th><th>USD</th></tr></thead><tbody>
        {d.by_month.map((m) => <tr key={m.month}><td>{m.month}</td><td>{m.jobs}</td><td>{m.done}</td><td>{m.failed}</td><td>{m.cost_usd}</td></tr>)}
      </tbody></table></div>
      <h3>{t('admin.recent_jobs')}</h3>
      <div class="table-wrap"><table class="table"><thead><tr><th>{t('profile.username')}</th><th>{t('admin.model')}</th><th>{t('admin.status')}</th><th>USD</th><th>{t('admin.created')}</th></tr></thead><tbody>
        {d.jobs.map((j) => <tr key={j.job_uuid}><td>@{j.username}</td><td class="tiny">{j.model}/{j.quality}</td><td>{j.status}{j.error ? <span class="tiny muted"> · {j.error}</span> : null}</td><td>{j.cost_estimate_usd}</td><td class="tiny">{fmtDate(j.created_at, true)}</td></tr>)}
      </tbody></table></div>
    </div>
  );
}

function Integrations() {
  const [d, reload] = useLoad<J>('/api/admin/integrations');
  const [form, setForm] = useState<J>({});
  const [busy, setBusy] = useState(false);
  if (!d) return <Loading />;
  const save = async () => {
    setBusy(true);
    try { await api('/api/admin/integrations', { method: 'PATCH', body: form }); toast(t('common.saved'), 'ok'); setForm({}); reload(); platform.value = await api('/api/platform'); } catch (e) { showError(e); } finally { setBusy(false); }
  };
  const f = (k: string, label: string, hint?: string, type = 'password') => <Field label={label} hint={hint}><input class="input" type={type} autocomplete="off" placeholder={t('admin.unchanged')} value={form[k] ?? ''} onInput={(e) => setForm({ ...form, [k]: (e.target as HTMLInputElement).value })} /></Field>;
  return (
    <div class="col gap-2">
      <div class="card col gap-1">
        <h3>Spotify {d.spotify.configured ? <span class="chip ok">{t('admin.configured')}</span> : <span class="chip warn">{t('admin.missing')}</span>}</h3>
        <div class="small muted">Client ID: <span class="mono">{d.spotify.client_id || '–'}</span> · {t('admin.connected_accounts')}: {d.spotify.connected_accounts} · {t('admin.revoked_accounts')}: {d.spotify.revoked_accounts}</div>
        <div class="small">{t('admin.redirect_uri')}:</div><div class="code-block">{d.spotify.redirect_uri}</div>
        <div class="tiny muted">{t('admin.scopes')}: {d.spotify.scopes.join(', ')}</div>
        <Notice kind="warn">{t('admin.spotify_devmode_note')}</Notice>
        {f('spotify_client_id', 'Client ID', undefined, 'text')}{f('spotify_client_secret', 'Client Secret (optional)')}
      </div>
      <div class="card col gap-1">
        <h3>OpenAI {d.openai.configured ? <span class="chip ok">{t('admin.configured')}</span> : <span class="chip warn">{t('admin.missing')}</span>}</h3>
        <div class="small muted">API Key: <span class="mono">{d.openai.api_key || '–'}</span> · {t('admin.model')}: {d.openai.model} / {d.openai.quality}</div>
        {f('openai_api_key', 'OpenAI API Key')}
      </div>
      <div class="card col gap-1">
        <h3>Stripe {d.stripe.configured ? <span class="chip ok">{t('admin.configured')}</span> : <span class="chip warn">{t('admin.missing')}</span>} <span class="chip">{d.stripe.mode}</span></h3>
        <div class="small muted">Secret: <span class="mono">{d.stripe.secret_key || '–'}</span> · Price: <span class="mono">{d.stripe.price_id || '–'}</span> · Webhook secret: {d.stripe.webhook_secret_set ? '✓' : '–'}</div>
        <div class="small">{t('admin.webhook_url')}:</div><div class="code-block">{d.stripe.webhook_url}</div>
        <Field label={t('admin.stripe_mode')}><select class="input" value={form.stripe_mode ?? d.stripe.mode} onChange={(e) => setForm({ ...form, stripe_mode: (e.target as HTMLSelectElement).value })}><option value="test">test</option><option value="live">live</option></select></Field>
        {f('stripe_secret_key', 'Secret Key')}{f('stripe_publishable_key', 'Publishable Key', undefined, 'text')}{f('stripe_webhook_secret', 'Webhook Signing Secret')}{f('stripe_price_id', 'Price ID', t('admin.price_hint'), 'text')}
      </div>
      <div class="card col gap-1">
        <h3>Cron</h3>
        <div class="small muted">{t('admin.last_cron')}: {d.cron.last_run ? fmtDate(d.cron.last_run, true) : t('admin.never')}</div>
        <div class="code-block">{d.cron.command}</div>
      </div>
      <button class="btn primary" disabled={busy || Object.keys(form).length === 0} onClick={save}>{busy ? <Spinner small /> : t('common.save')}</button>
      <h3 class="mt-2">{t('admin.recent_integration_errors')}</h3>
      <div class="table-wrap"><table class="table"><thead><tr><th>{t('admin.service')}</th><th>{t('admin.endpoint')}</th><th>{t('admin.status')}</th><th>{t('admin.error')}</th><th>{t('admin.time')}</th></tr></thead><tbody>
        {d.recent_errors.map((e: J, i: number) => <tr key={i}><td>{e.service}</td><td class="mono tiny">{e.method} {e.endpoint}</td><td>{e.status}</td><td class="tiny">{e.error}</td><td class="tiny">{fmtDate(e.created_at, true)}</td></tr>)}
      </tbody></table></div>
    </div>
  );
}

function SettingsAdmin() {
  const [d, reload] = useLoad<J>('/api/admin/settings');
  const [form, setForm] = useState<J>({});
  const [busy, setBusy] = useState(false);
  const [confirmPublic, setConfirmPublic] = useState(false);
  if (!d) return <Loading />;
  const v = (k: string) => (k in form ? form[k] : d[k]);
  const save = async () => {
    setBusy(true);
    try { await api('/api/admin/settings', { method: 'PATCH', body: form }); toast(t('common.saved'), 'ok'); setForm({}); reload(); platform.value = await api('/api/platform'); } catch (e) { showError(e); } finally { setBusy(false); }
  };
  return (
    <div class="col gap-2">
      <div class="card col gap-1">
        <h3>{t('admin.operation')}</h3>
        <Field label={t('admin.mode')}><Seg value={v('operation_mode')} onChange={(m) => { if (m === 'public') setConfirmPublic(true); else setForm({ ...form, operation_mode: m, registration_open: false }); }} options={[{ value: 'closed_beta', label: t('admin.mode_closed_beta') }, { value: 'public', label: t('admin.mode_public') }]} /></Field>
        <Toggle label={t('admin.registration_open')} desc={t('admin.registration_open_hint')} checked={!!v('registration_open')} onChange={(x) => setForm({ ...form, registration_open: x })} />
      </div>
      <div class="card col gap-1">
        <h3>{t('settings.playback')}</h3>
        <Notice kind="warn">{t('admin.playback_note')}</Notice>
        <Seg value={v('playback_mode')} onChange={(m) => setForm({ ...form, playback_mode: m })} options={[{ value: 'off', label: t('common.off') }, { value: 'connect', label: 'Spotify Connect' }]} />
      </div>
      <div class="card col gap-1">
        <h3>{t('nav.covers')}</h3>
        <div class="small muted">{t('admin.price_quota')}: CHF {d.premium_price_chf} · {d.premium_cover_quota} {t('admin.covers_per_period')} ({t('admin.fixed_product')})</div>
        <Field label={t('admin.model')}><select class="input" value={v('cover_model')} onChange={(e) => setForm({ ...form, cover_model: (e.target as HTMLSelectElement).value })}>{['gpt-image-1-mini', 'gpt-image-1.5', 'gpt-image-1', 'gpt-image-2'].map((m) => <option key={m} value={m}>{m}</option>)}</select></Field>
        <Field label={t('admin.quality')}><Seg value={v('cover_quality')} onChange={(q) => setForm({ ...form, cover_quality: q })} options={['low', 'medium', 'high'].map((q) => ({ value: q, label: q }))} /></Field>
        <Field label={t('admin.max_parallel')}><input class="input" type="number" min="1" max="10" value={v('max_parallel_cover_jobs')} onInput={(e) => setForm({ ...form, max_parallel_cover_jobs: Number((e.target as HTMLInputElement).value) })} /></Field>
        <details><summary class="small">{t('admin.price_table')}</summary>
          <div class="table-wrap mt-1"><table class="table"><thead><tr><th>{t('admin.model')}</th><th>low</th><th>medium</th><th>high</th></tr></thead><tbody>
            {Object.entries((v('cover_price_table') ?? {}) as Record<string, Record<string, number>>).map(([m, q]) => <tr key={m}><td class="mono tiny">{m}</td>{['low', 'medium', 'high'].map((k) => <td key={k}><input class="input" style="min-height:34px;padding:.2rem .4rem;width:90px" type="number" step="0.001" value={q[k] ?? ''} onInput={(e) => { const tbl = JSON.parse(JSON.stringify(v('cover_price_table') ?? {})); tbl[m][k] = Number((e.target as HTMLInputElement).value); setForm({ ...form, cover_price_table: tbl }); }} /></td>)}</tr>)}
          </tbody></table></div>
        </details>
      </div>
      <div class="card col gap-1">
        <h3>{t('admin.cache')}</h3>
        <Field label={t('admin.cache_lists')}><input class="input" type="number" min="5" value={v('spotify_cache_ttl_minutes')} onInput={(e) => setForm({ ...form, spotify_cache_ttl_minutes: Number((e.target as HTMLInputElement).value) })} /></Field>
        <Field label={t('admin.cache_items')}><input class="input" type="number" min="1" value={v('spotify_items_cache_ttl_minutes')} onInput={(e) => setForm({ ...form, spotify_items_cache_ttl_minutes: Number((e.target as HTMLInputElement).value) })} /></Field>
      </div>
      <div class="card col gap-1">
        <h3>{t('admin.legal')}</h3>
        <p class="small muted">{t('admin.legal_hint')}</p>
        {['legal_operator_name', 'legal_operator_address', 'legal_operator_email', 'legal_operator_country'].map((k) => <Field key={k} label={t('admin.' + k)}><input class="input" value={v(k)} onInput={(e) => setForm({ ...form, [k]: (e.target as HTMLInputElement).value })} /></Field>)}
      </div>
      <button class="btn primary" disabled={busy || Object.keys(form).length === 0} onClick={save}>{busy ? <Spinner small /> : t('common.save')}</button>
      {confirmPublic && <Confirm title={t('admin.mode_public')} text={t('admin.public_confirm')} onClose={() => setConfirmPublic(false)} onConfirm={() => { setForm({ ...form, operation_mode: 'public', registration_open: true }); setConfirmPublic(false); }} />}
    </div>
  );
}

function Errors() {
  const [d] = useLoad<{ errors: J[] }>('/api/admin/errors');
  if (!d) return <Loading />;
  return d.errors.length === 0 ? <p class="muted small">{t('admin.no_errors')}</p> : (
    <div class="table-wrap"><table class="table"><thead><tr><th>{t('admin.time')}</th><th>{t('admin.scope')}</th><th>{t('admin.message')}</th><th>{t('admin.location')}</th></tr></thead><tbody>
      {d.errors.map((e) => <tr key={e.id}><td class="tiny">{fmtDate(e.created_at, true)}</td><td>{e.scope}</td><td class="tiny">{e.message}<br /><span class="muted">{e.path}</span></td><td class="mono tiny">{e.location}</td></tr>)}
    </tbody></table></div>
  );
}

function Audit() {
  const [d] = useLoad<{ entries: J[] }>('/api/admin/audit');
  if (!d) return <Loading />;
  return (
    <div class="table-wrap"><table class="table"><thead><tr><th>{t('admin.time')}</th><th>Admin</th><th>{t('admin.action')}</th><th>{t('admin.target')}</th><th>Details</th></tr></thead><tbody>
      {d.entries.map((e) => <tr key={e.id}><td class="tiny">{fmtDate(e.created_at, true)}</td><td>@{e.admin_username ?? '–'}</td><td class="mono tiny">{e.action}</td><td class="tiny">{e.target_type} {e.target_id}</td><td class="tiny mono">{e.details}</td></tr>)}
    </tbody></table></div>
  );
}
