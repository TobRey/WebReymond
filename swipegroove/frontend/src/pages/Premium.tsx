import { useEffect, useState } from 'preact/hooks';
import { Layout } from '../components/Layout';
import { api, showError } from '../lib/api';
import { t, fmtDate } from '../lib/i18n';
import { platform, setUser, toast, user } from '../lib/state';
import { route, href } from '../lib/router';
import type { Me } from '../lib/types';
import { Notice, Spinner, TopBar } from '../components/ui';
import { IStar, ISparkle, ICheck } from '../components/Icons';
import { money } from '../lib/util';

interface Billing { premium: Me['premium']; available: boolean; mode: string; price_chf: string; cover_quota: number }

export function Premium() {
  const [b, setB] = useState<Billing | null>(null);
  const [busy, setBusy] = useState(false);
  const me = user.value!;
  const p = platform.value;
  const load = () => api<Billing>('/api/billing').then(setB).catch(showError);
  useEffect(() => {
    load();
    if (route.value.query.get('checkout') === 'success') {
      api<{ premium: Me['premium'] }>('/api/billing/refresh', { body: {} }).then(async (r) => { setB((x) => x ? { ...x, premium: r.premium } : x); setUser(await api<Me>('/api/me')); if (r.premium.active) toast(t('premium.activated'), 'ok'); }).catch(() => undefined);
    }
  }, []);
  const go = async (path: string) => {
    setBusy(true);
    try { const r = await api<{ url: string }>(path, { body: {} }); location.href = r.url; } catch (e) { showError(e); setBusy(false); }
  };
  const prem = b?.premium ?? me.premium;
  return (
    <Layout width="narrow">
      <TopBar back="/profile" title={t('nav.premium')} />
      <div class="card col gap-2" style="text-align:center;padding:1.5rem">
        <IStar style="width:40px;height:40px;color:var(--accent);margin:0 auto" />
        <h2>SwipeGroove Premium</h2>
        <div style="font-size:2rem;font-weight:800">{money(p.premium_price_chf)}<span class="small muted" style="font-weight:500"> / {t('premium.month')}</span></div>
        <ul style="list-style:none;padding:0;margin:0;text-align:left" class="col gap-1 small">
          <li class="row gap-1"><ICheck style="color:var(--success);width:18px;height:18px" />{t('premium.feat_covers', { n: p.premium_cover_quota })}</li>
          <li class="row gap-1"><ICheck style="color:var(--success);width:18px;height:18px" />{t('premium.feat_cancel')}</li>
          <li class="row gap-1"><ICheck style="color:var(--success);width:18px;height:18px" />{t('premium.feat_free')}</li>
        </ul>
        {prem.active ? (
          <>
            <Notice kind="ok">{prem.source === 'owner' ? t('premium.owner') : prem.source === 'manual' ? t('premium.manual', { date: fmtDate(prem.manual_until) }) : prem.cancel_at_period_end ? t('premium.cancels_on', { date: fmtDate(prem.current_period_end) }) : t('premium.active_until', { date: fmtDate(prem.current_period_end) })}</Notice>
            {prem.payment_error && <Notice kind="danger">{t('premium.payment_error')}: {prem.payment_error}</Notice>}
            {prem.source === 'stripe' && <button class="btn block" disabled={busy} onClick={() => go('/api/billing/portal')}>{busy ? <Spinner small /> : t('premium.manage')}</button>}
            <a class="btn primary block" href={href('/covers')}><ISparkle />{t('premium.to_covers')}</a>
          </>
        ) : (
          <>
            {b && !b.available && <Notice kind="warn">{t('premium.unavailable')}</Notice>}
            {b?.mode === 'test' && b.available && <Notice kind="warn">{t('premium.test_mode')}</Notice>}
            <button class="btn primary lg block" disabled={busy || !b?.available} onClick={() => go('/api/billing/checkout')}>{busy ? <Spinner small /> : t('premium.subscribe')}</button>
            {prem.status !== 'none' && <button class="btn sm ghost" disabled={busy} onClick={() => go('/api/billing/portal')}>{t('premium.manage')}</button>}
          </>
        )}
        <p class="tiny muted">{t('premium.stripe_note')}</p>
      </div>
    </Layout>
  );
}
