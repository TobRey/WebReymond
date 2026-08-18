import { getTranslations, setRequestLocale } from 'next-intl/server';
import Link from 'next/link';
import { Button, Card, Input, Logo } from '@webreymond/ui';
import { LanguageSwitcher } from '@/components/LanguageSwitcher';
import { routing } from '@/i18n/routing';

export function generateStaticParams() {
  return routing.locales.map((locale) => ({ locale }));
}

export default async function HomePage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;
  setRequestLocale(locale);

  const t = await getTranslations('home');
  const tNav = await getTranslations('nav');
  const tFooter = await getTranslations('footer');

  const features = ['hosting', 'domains', 'cms'] as const;

  return (
    <>
      <a
        href="#inhalt"
        className="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:rounded-token-md focus:bg-surface focus:px-4 focus:py-2"
      >
        {tNav('skipToContent')}
      </a>

      <div className="wr-brandbar" />

      <header className="border-b border-border-subtle">
        <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-5">
          <Logo />
          <div className="flex items-center gap-2">
            <LanguageSwitcher
              currentLocale={locale}
              labels={{
                german: tNav('german'),
                english: tNav('english'),
                language: tNav('language'),
              }}
            />
            <Link
              href={`/${locale}/anmelden`}
              className="rounded-token-sm px-3 py-1.5 text-sm text-content-muted hover:text-content"
            >
              {tNav('login')}
            </Link>
          </div>
        </div>
      </header>

      <main id="inhalt" className="mx-auto max-w-5xl px-6">
        <section className="py-16 sm:py-24">
          <p className="mb-4 inline-block rounded-token-sm bg-accent-subtle px-3 py-1 text-xs font-medium text-accent">
            {t('badge')}
          </p>
          <h1 className="max-w-3xl text-4xl leading-tight font-semibold tracking-tight sm:text-5xl">
            {t('title')}
          </h1>
          <p className="mt-5 max-w-2xl text-lg text-content-muted">{t('subtitle')}</p>
          <div className="mt-8 flex flex-wrap gap-3">
            <Link href={`/${locale}/registrieren`}>
              <Button variant="primary">{tNav('register')}</Button>
            </Link>
            <Button variant="secondary">{t('secondaryAction')}</Button>
          </div>
        </section>

        <section className="border-t border-border-subtle py-16">
          <h2 className="mb-8 text-2xl font-semibold tracking-tight">{t('featuresTitle')}</h2>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {features.map((key) => (
              <Card
                key={key}
                title={t(`features.${key}.title`)}
                description={t(`features.${key}.description`)}
              />
            ))}
          </div>
        </section>

        <section className="border-t border-border-subtle py-16">
          <h2 className="mb-3 text-2xl font-semibold tracking-tight">{t('demoTitle')}</h2>
          <p className="mb-8 max-w-2xl text-content-muted">{t('demoDescription')}</p>
          <div className="max-w-md">
            <Input
              label={t('demoFieldLabel')}
              hint={t('demoFieldHint')}
              placeholder={t('demoFieldPlaceholder')}
              name="domain"
              autoComplete="off"
            />
          </div>
        </section>
      </main>

      <footer className="border-t border-border-subtle">
        <div className="mx-auto flex max-w-5xl flex-col gap-2 px-6 py-8 text-sm text-content-muted sm:flex-row sm:items-center sm:justify-between">
          <span>{tFooter('status')}</span>
          <span>{tFooter('docs')}</span>
        </div>
      </footer>
    </>
  );
}
