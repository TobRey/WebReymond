'use client';

import { useState, type FormEvent } from 'react';
import { useTranslations } from 'next-intl';
import { Button, Input } from '@webreymond/ui';
import { api } from '@/lib/api';
import { FormMessage } from './FormMessage';

export function ForgotPasswordForm({ locale }: { locale: string }) {
  const t = useTranslations('auth');
  const [pending, setPending] = useState(false);
  const [done, setDone] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPending(true);
    setError(null);

    const form = new FormData(event.currentTarget);
    const result = await api.requestPasswordReset({
      email: String(form.get('email') ?? ''),
      redirectTo: `${window.location.origin}/${locale}/passwort-neu`,
    });

    setPending(false);

    if (result.status === 0) {
      setError(t('common.networkError'));
      return;
    }

    // Absichtlich immer dieselbe Rückmeldung: Ob es das Konto gibt,
    // geht Unbeteiligte nichts an.
    setDone(true);
  }

  if (done) {
    return <FormMessage kind="success">{t('forgot.sent')}</FormMessage>;
  }

  return (
    <form onSubmit={onSubmit} className="flex flex-col gap-5">
      <Input label={t('fields.email')} name="email" type="email" autoComplete="email" required />
      <Button type="submit" disabled={pending}>
        {pending ? t('common.pending') : t('forgot.submit')}
      </Button>
      {error ? <FormMessage kind="error">{error}</FormMessage> : null}
    </form>
  );
}
