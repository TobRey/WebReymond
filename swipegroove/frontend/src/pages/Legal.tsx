import { Bare, Layout } from '../components/Layout';
import { t } from '../lib/i18n';
import { platform, user } from '../lib/state';
import { href } from '../lib/router';
import { TopBar } from '../components/ui';

function Placeholder({ value, label }: { value: string; label: string }) {
  return value ? <span>{value}</span> : <span class="placeholder">[{label}]</span>;
}

export function Legal({ page }: { page: 'privacy' | 'terms' | 'imprint' }) {
  const l = platform.value.legal;
  const op = <Placeholder value={l.operator_name} label={t('legal.ph_operator')} />;
  const body = (
    <div class="prose">
      <TopBar title={t('legal.' + page)} back={user.value ? '/settings' : '/login'} />
      <div class="notice warn mb-2">{t('legal.draft_note')}</div>
      {page === 'imprint' && (
        <>
          <h2>{t('legal.imprint')}</h2>
          <p><strong>{t('legal.operator')}:</strong> {op}</p>
          <p><strong>{t('legal.address')}:</strong> <Placeholder value={l.operator_address} label={t('legal.ph_address')} /></p>
          <p><strong>{t('legal.country')}:</strong> <Placeholder value={l.operator_country} label={t('legal.ph_country')} /></p>
          <p><strong>{t('legal.contact')}:</strong> <Placeholder value={l.operator_email} label={t('legal.ph_email')} /></p>
          <p>{t('legal.imprint_spotify_note')}</p>
        </>
      )}
      {page === 'privacy' && (
        <>
          <h2>{t('legal.privacy')}</h2>
          <p><strong>{t('legal.responsible')}:</strong> {op}, <Placeholder value={l.operator_email} label={t('legal.ph_email')} /></p>
          <h3>{t('legal.p_data_title')}</h3>
          <p>{t('legal.p_data_1')}</p>
          <p>{t('legal.p_data_2')}</p>
          <h3>{t('legal.p_spotify_title')}</h3>
          <p>{t('legal.p_spotify_1')}</p>
          <p>{t('legal.p_spotify_2')}</p>
          <h3>{t('legal.p_ai_title')}</h3>
          <p>{t('legal.p_ai_1')}</p>
          <h3>{t('legal.p_payment_title')}</h3>
          <p>{t('legal.p_payment_1')}</p>
          <h3>{t('legal.p_cookies_title')}</h3>
          <p>{t('legal.p_cookies_1')}</p>
          <h3>{t('legal.p_rights_title')}</h3>
          <p>{t('legal.p_rights_1')}</p>
          <h3>{t('legal.p_retention_title')}</h3>
          <p>{t('legal.p_retention_1')}</p>
        </>
      )}
      {page === 'terms' && (
        <>
          <h2>{t('legal.terms')}</h2>
          <p>{t('legal.t_1')}</p>
          <p>{t('legal.t_2')}</p>
          <p>{t('legal.t_3')}</p>
          <p>{t('legal.t_4')}</p>
          <p>{t('legal.t_5')}</p>
          <p><strong>{t('legal.operator')}:</strong> {op}</p>
        </>
      )}
      <div class="legal-links mt-3">
        <a href={href('/legal/privacy')}>{t('legal.privacy')}</a>
        <a href={href('/legal/terms')}>{t('legal.terms')}</a>
        <a href={href('/legal/imprint')}>{t('legal.imprint')}</a>
      </div>
    </div>
  );
  return user.value ? <Layout width="narrow">{body}</Layout> : <Bare><div class="main narrow" style="position:relative;z-index:1">{body}</div></Bare>;
}
