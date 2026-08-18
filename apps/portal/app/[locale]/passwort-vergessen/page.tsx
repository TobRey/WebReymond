import { getTranslations, setRequestLocale } from 'next-intl/server';
import Link from 'next/link';
import { AuthCard } from '@/components/AuthCard';
import { ForgotPasswordForm } from '@/components/ForgotPasswordForm';
import { routing } from '@/i18n/routing';

export function generateStaticParams() {
  return routing.locales.map((locale) => ({ locale }));
}

export default async function ForgotPasswordPage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  setRequestLocale(locale);

  const t = await getTranslations('auth');

  return (
    <AuthCard locale={locale} title={t('forgot.title')} description={t('forgot.description')}>
      <ForgotPasswordForm locale={locale} />
      <p className="mt-8 text-sm text-content-muted">
        <Link href={`/${locale}/anmelden`} className="text-accent">
          {t('common.backToLogin')}
        </Link>
      </p>
    </AuthCard>
  );
}
