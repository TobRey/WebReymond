'use client';

import { useState, type FormEvent } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import { Button, Input } from '@webheaven/ui';
import { api } from '@/lib/api';
import { FormMessage } from './FormMessage';

export function RegisterForm({ locale }: { locale: string }) {
  const t = useTranslations('auth');
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPending(true);
    setError(null);

    const form = new FormData(event.currentTarget);
    const result = await api.signUp({
      name: String(form.get('name') ?? ''),
      email: String(form.get('email') ?? ''),
      password: String(form.get('password') ?? ''),
    });

    if (result.ok) {
      // Die Registrierung meldet direkt an – deshalb geht es ins Dashboard.
      router.push(`/${locale}/dashboard`);
      router.refresh();
      return;
    }

    setPending(false);
    setError(result.status === 0 ? t('common.networkError') : t('common.genericError'));
  }

  return (
    <form onSubmit={onSubmit} className="flex flex-col gap-5">
      <Input label={t('fields.name')} name="name" autoComplete="name" required />
      <Input label={t('fields.email')} name="email" type="email" autoComplete="email" required />
      <Input
        label={t('fields.password')}
        name="password"
        type="password"
        autoComplete="new-password"
        minLength={12}
        hint={t('fields.passwordHint')}
        required
      />
      <Button type="submit" disabled={pending}>
        {pending ? t('common.pending') : t('register.submit')}
      </Button>
      {error ? <FormMessage kind="error">{error}</FormMessage> : null}
    </form>
  );
}
