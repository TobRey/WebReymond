import { getTranslations, setRequestLocale } from 'next-intl/server';
import Link from 'next/link';
import { AuthCard } from '@/components/AuthCard';
import { RegisterForm } from '@/components/RegisterForm';
import { routing } from '@/i18n/routing';

export function generateStaticParams() {
  return routing.locales.map((locale) => ({ locale }));
}

export default async function RegisterPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;
  setRequestLocale(locale);

  const t = await getTranslations('auth');

  return (
    <AuthCard locale={locale} title={t('register.title')} description={t('register.description')}>
      <RegisterForm locale={locale} />
      <p className="mt-8 text-sm text-content-muted">
        {t('register.haveAccount')}{' '}
        <Link href={`/${locale}/anmelden`} className="text-accent">
          {t('login.title')}
        </Link>
      </p>
    </AuthCard>
  );
}
