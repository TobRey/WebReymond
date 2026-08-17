'use client';

import { useState, type FormEvent } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { useTranslations } from 'next-intl';
import { Button, Input } from '@webheaven/ui';
import { api } from '@/lib/api';
import { FormMessage } from './FormMessage';

export function ResetPasswordForm({ locale }: { locale: string }) {
  const t = useTranslations('auth');
  const router = useRouter();
  const searchParams = useSearchParams();
  const token = searchParams.get('token');

  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!token) return;

    setPending(true);
    setError(null);

    const form = new FormData(event.currentTarget);
    const result = await api.resetPassword({
      newPassword: String(form.get('password') ?? ''),
      token,
    });

    if (result.ok) {
      router.push(`/${locale}/anmelden`);
      return;
    }

    setPending(false);
    setError(result.status === 0 ? t('common.networkError') : t('reset.invalidToken'));
  }

  if (!token) {
    return <FormMessage kind="error">{t('reset.missingToken')}</FormMessage>;
  }

  return (
    <form onSubmit={onSubmit} className="flex flex-col gap-5">
      <Input
        label={t('fields.newPassword')}
        name="password"
        type="password"
        autoComplete="new-password"
        minLength={12}
        hint={t('fields.passwordHint')}
        required
      />
      <Button type="submit" disabled={pending}>
        {pending ? t('common.pending') : t('reset.submit')}
      </Button>
      {error ? <FormMessage kind="error">{error}</FormMessage> : null}
    </form>
  );
}
