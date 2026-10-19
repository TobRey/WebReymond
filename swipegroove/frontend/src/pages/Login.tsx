import { useEffect, useState } from 'preact/hooks';
import { Bare } from '../components/Layout';
import { t, lang, setServerBundle, type Lang } from '../lib/i18n';
import { platform } from '../lib/state';
import { route, href } from '../lib/router';
import { api } from '../lib/api';
import { ICompass, ISpotify } from '../components/Icons';
import { Notice } from '../components/ui';

export function LangSwitch() {
  const switchTo = async (l: Lang) => {
    lang.value = l;
    document.documentElement.lang = l;
    try {
      const r = await api<{ i18n: Record<string, string> }>('/api/lang', { body: { lang: l } });
      setServerBundle(l, r.i18n);
    } catch { /* Cookie wird serverseitig gesetzt; Fehler hier ignorieren */ }
  };
  return (
    <div class="seg" role="group" aria-label="Sprache / Language">
      <button class={lang.value === 'de' ? 'active' : ''} onClick={() => switchTo('de')}>DE</button>
      <button class={lang.value === 'en' ? 'active' : ''} onClick={() => switchTo('en')}>EN</button>
    </div>
  );
}

export function Login() {
  const p = platform.value;
  const q = route.value.query;
  const err = q.get('error');
  const redirect = q.get('redirect') ?? '';
  const canLogin = p.spotify_configured && p.owner_registered;
  const startUrl = href('/auth/spotify/start?lang=' + lang.value + (redirect.startsWith('/') ? '&redirect=' + encodeURIComponent(redirect) : ''));
  return (
    <Bare>
      <div class="auth">
        <div class="auth-box">
          <div class="auth-art" aria-hidden="true">{[0, 1, 2, 3, 4, 5].map((i) => <span key={i} class={'ph-' + i} />)}</div>
          <div class="wordmark">swipe<b>groove</b></div>
          <p class="tag">{t('login.tagline')}</p>
          {err && <Notice kind="danger">{t(err)}</Notice>}
          {!p.owner_registered && <Notice kind="warn">{t('login.setup_pending')} <a href={href('/setup')}>{t('login.setup_link')}</a></Notice>}
          {p.owner_registered && !p.spotify_configured && <Notice kind="warn">{t('auth.spotify_not_configured')}</Notice>}
          {p.owner_registered && p.spotify_configured && !p.registration_open && <Notice kind="info">{t('login.registration_closed_note')}</Notice>}
          <a class={'btn spotify lg' + (canLogin ? '' : ' disabled')} aria-disabled={!canLogin} href={canLogin ? startUrl : '#'} onClick={(e) => { if (!canLogin) e.preventDefault(); }}>
            <ISpotify />{t('login.with_spotify')}
          </a>
          <a class="btn outline lg" href={href('/explore')}><ICompass />{t('login.browse')}</a>
          <p class="tiny muted" style="max-width:34ch">{t('login.no_password')}</p>
          <LangSwitch />
          <div class="legal-links">
            <a href={href('/legal/privacy')}>{t('legal.privacy')}</a>
            <a href={href('/legal/terms')}>{t('legal.terms')}</a>
            <a href={href('/legal/imprint')}>{t('legal.imprint')}</a>
          </div>
        </div>
      </div>
    </Bare>
  );
}

export function Setup() {
  const [key, setKey] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const p = platform.value;
  useEffect(() => { document.title = 'SwipeGroove – Setup'; }, []);
  const start = async (e: Event) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const r = await api<{ url: string }>('/api/auth/setup-start', { body: { setup_key: key, lang: lang.value } });
      location.href = r.url;
    } catch (err) {
      setError((err as Error).message);
      setBusy(false);
    }
  };
  return (
    <Bare>
      <div class="auth">
        <div class="auth-box">
        <div class="wordmark">swipe<b>groove</b></div>
        {p.owner_registered ? (
          <>
            <Notice kind="ok">{t('setup.done')}</Notice>
            <a class="btn primary" href={href('/login')}>{t('setup.to_login')}</a>
          </>
        ) : (
          <form class="col" style="width:min(100%,420px)" onSubmit={start}>
            <h2>{t('setup.title')}</h2>
            <p class="muted small">{t('setup.intro')}</p>
            {!p.spotify_configured && <Notice kind="warn">{t('auth.spotify_not_configured')}</Notice>}
            <input class="input" type="password" autocomplete="off" placeholder={t('setup.key_placeholder')} value={key} onInput={(e) => setKey((e.target as HTMLInputElement).value)} required />
            {error && <Notice kind="danger">{error}</Notice>}
            <button class="btn primary lg" type="submit" disabled={busy || !p.spotify_configured || key.length < 8}><ISpotify />{t('setup.continue')}</button>
            <LangSwitch />
          </form>
        )}
        </div>
      </div>
    </Bare>
  );
}
