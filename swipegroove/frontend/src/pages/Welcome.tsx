import { useState } from 'preact/hooks';
import { Bare } from '../components/Layout';
import { t, lang, type Lang } from '../lib/i18n';
import { user, setUser } from '../lib/state';
import { api, ApiError } from '../lib/api';
import { navigate, href } from '../lib/router';
import type { Me } from '../lib/types';
import { Field, Notice, Seg, Spinner } from '../components/ui';

export function Welcome() {
  const u = user.value!;
  const [username, setUsername] = useState(u.username);
  const [display, setDisplay] = useState(u.display_name);
  const [l, setL] = useState<Lang>(u.lang);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const submit = async (e: Event) => {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    try {
      const me = await api<Me>('/api/me/onboarding', { body: { username, display_name: display, lang: l } });
      setUser(me);
      lang.value = me.lang;
      document.documentElement.lang = me.lang;
      navigate('/', true);
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) setErrors(err.fields);
      else setErrors({ _: (err as Error).message });
    } finally {
      setBusy(false);
    }
  };
  return (
    <Bare>
      <div class="hero">
        <img class="logo" src={href('/assets/logo.svg')} alt="" />
        <h1>{t('welcome.title')}</h1>
        <p>{t('welcome.intro')}</p>
        <form class="col card" style="width:min(100%,440px);text-align:left" onSubmit={submit}>
          {u.is_owner && <Notice kind="ok">{t('welcome.owner_note')}</Notice>}
          <Field label={t('profile.username')} hint={t('profile.username_hint')} error={errors.username}>
            <input class={'input' + (errors.username ? ' error' : '')} value={username} onInput={(e) => setUsername((e.target as HTMLInputElement).value.toLowerCase())} maxLength={32} autocomplete="off" required />
          </Field>
          <Field label={t('profile.display_name')} error={errors.display_name}>
            <input class={'input' + (errors.display_name ? ' error' : '')} value={display} onInput={(e) => setDisplay((e.target as HTMLInputElement).value)} maxLength={60} required />
          </Field>
          <Field label={t('settings.language')}>
            <Seg value={l} options={[{ value: 'de', label: 'Deutsch' }, { value: 'en', label: 'English' }]} onChange={(v) => { setL(v); lang.value = v; }} />
          </Field>
          {errors._ && <Notice kind="danger">{errors._}</Notice>}
          <button class="btn primary lg block" type="submit" disabled={busy}>{busy ? <Spinner small /> : t('welcome.start')}</button>
        </form>
      </div>
    </Bare>
  );
}
