'use client';

import { useState, type FormEvent } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import { Button, Input } from '@webheaven/ui';
import { api } from '@/lib/api';
import { FormMessage } from './FormMessage';

export function LoginForm({ locale }: { locale: string }) {
  const t = useTranslations('auth');
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPending(true);
    setError(null);

    const form = new FormData(event.currentTarget);
    const result = await api.signIn({
      email: String(form.get('email') ?? ''),
      password: String(form.get('password') ?? ''),
    });

    if (result.ok) {
      router.push(`/${locale}/dashboard`);
      router.refresh();
      return;
    }

    setPending(false);

    // Bewusst dieselbe Meldung für "Konto gibt es nicht" und "Passwort falsch".
    // Alles andere wäre eine Einladung, Konten durchzuprobieren.
    if (result.status === 429) setError(t('login.tooManyAttempts'));
    else if (result.status === 0) setError(t('common.networkError'));
    else setError(t('login.invalidCredentials'));
  }

  return (
    <form onSubmit={onSubmit} className="flex flex-col gap-5">
      <Input label={t('fields.email')} name="email" type="email" autoComplete="email" required />
      <Input
        label={t('fields.password')}
        name="password"
        type="password"
        autoComplete="current-password"
        required
      />
      <Button type="submit" disabled={pending}>
        {pending ? t('common.pending') : t('login.submit')}
      </Button>
      {error ? <FormMessage kind="error">{error}</FormMessage> : null}
    </form>
  );
}
