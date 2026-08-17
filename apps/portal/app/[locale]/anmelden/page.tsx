import { getTranslations, setRequestLocale } from 'next-intl/server';
import Link from 'next/link';
import { AuthCard } from '@/components/AuthCard';
import { LoginForm } from '@/components/LoginForm';
import { routing } from '@/i18n/routing';

export function generateStaticParams() {
  return routing.locales.map((locale) => ({ locale }));
}

export default async function LoginPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;
  setRequestLocale(locale);

  const t = await getTranslations('auth');

  return (
    <AuthCard locale={locale} title={t('login.title')} description={t('login.description')}>
      <LoginForm locale={locale} />

      <div className="mt-8 flex flex-col gap-2 text-sm text-content-muted">
        <Link href={`/${locale}/passwort-vergessen`} className="text-accent">
          {t('login.forgotPassword')}
        </Link>
        <span>
          {t('login.noAccount')}{' '}
          <Link href={`/${locale}/registrieren`} className="text-accent">
            {t('register.title')}
          </Link>
        </span>
      </div>
    </AuthCard>
  );
}
