import { getTranslations, setRequestLocale } from 'next-intl/server';
import { PixelArtStudio } from '@/components/PixelArtStudio';
import { routing } from '@/i18n/routing';

export function generateStaticParams() {
  return routing.locales.map((locale) => ({ locale }));
}

export default async function PixelArtPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;
  setRequestLocale(locale);

  const t = await getTranslations('pixelArt');

  return (
    <>
      <div className="wh-brandbar" />

      <main className="mx-auto max-w-5xl px-6 py-12">
        <h1 className="text-3xl font-semibold tracking-tight">{t('title')}</h1>
        <p className="mt-3 mb-10 max-w-2xl text-content-muted">{t('intro')}</p>

        <PixelArtStudio />
      </main>
    </>
  );
}
