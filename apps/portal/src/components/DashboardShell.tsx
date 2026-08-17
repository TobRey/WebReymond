'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import { Button, Card, Logo } from '@webheaven/ui';
import { api, type Me } from '@/lib/api';

/**
 * Kundenbereich.
 *
 * Die Daten kommen aus /v1/me – also vom Server, der die Sitzung prüft.
 * Ohne gültige Sitzung landet man auf der Anmeldeseite. Wichtig: Das ist
 * Bequemlichkeit, kein Schutz. Der eigentliche Schutz sitzt in der API,
 * die ohne Sitzung schlicht keine Daten herausgibt.
 */
export function DashboardShell({ locale }: { locale: string }) {
  const t = useTranslations('dashboard');
  const router = useRouter();
  const [me, setMe] = useState<Me | null>(null);

  useEffect(() => {
    let active = true;

    void api.me().then((result) => {
      if (!active) return;
      if (result.ok && result.data) {
        setMe(result.data);
      } else {
        router.replace(`/${locale}/anmelden`);
      }
    });

    return () => {
      active = false;
    };
  }, [locale, router]);

  if (!me) {
    return (
      <p className="mx-auto max-w-5xl px-6 py-12 text-sm text-content-muted">{t('loading')}</p>
    );
  }

  async function onSignOut() {
    await api.signOut();
    router.replace(`/${locale}/anmelden`);
    router.refresh();
  }

  return (
    <>
      <header className="border-b border-border-subtle">
        <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-5">
          <Logo />
          <Button variant="ghost" onClick={onSignOut}>
            {t('signOut')}
          </Button>
        </div>
      </header>

      <main className="mx-auto max-w-5xl px-6 py-12">
        <h1 className="text-2xl font-semibold tracking-tight">
          {t('greeting', { name: me.name })}
        </h1>

        <dl className="mt-8 grid gap-4 sm:grid-cols-2">
          <Card>
            <dt className="text-sm text-content-muted">{t('email')}</dt>
            <dd className="mt-1 font-medium">{me.email}</dd>
          </Card>
          <Card>
            <dt className="text-sm text-content-muted">{t('role')}</dt>
            <dd className="mt-1 font-medium">{t(`roles.${me.role}`)}</dd>
          </Card>
        </dl>

        <div className="mt-8">
          <Card title={t('comingSoonTitle')} description={t('comingSoonDescription')} />
        </div>
      </main>
    </>
  );
}
