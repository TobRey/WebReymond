import { Suspense } from 'react';
import { getTranslations, setRequestLocale } from 'next-intl/server';
import { AuthCard } from '@/components/AuthCard';
import { ResetPasswordForm } from '@/components/ResetPasswordForm';
import { routing } from '@/i18n/routing';

export function generateStaticParams() {
  return routing.locales.map((locale) => ({ locale }));
}

export default async function ResetPasswordPage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  setRequestLocale(locale);

  const t = await getTranslations('auth');

  return (
    <AuthCard locale={locale} title={t('reset.title')} description={t('reset.description')}>
      {/* Das Token steht in der Adresszeile – deshalb Suspense. */}
      <Suspense fallback={null}>
        <ResetPasswordForm locale={locale} />
      </Suspense>
    </AuthCard>
  );
}
