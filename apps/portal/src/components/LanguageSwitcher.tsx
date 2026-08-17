import Link from 'next/link';
import { routing } from '@/i18n/routing';

type Props = {
  currentLocale: string;
  labels: { german: string; english: string; language: string };
};

/**
 * Sprachumschalter.
 *
 * Bewusst als einfache Links umgesetzt: funktioniert auch ohne JavaScript und
 * ist damit für Suchmaschinen und Screenreader eindeutig.
 */
export function LanguageSwitcher({ currentLocale, labels }: Props) {
  const labelFor: Record<string, string> = {
    de: labels.german,
    en: labels.english,
  };

  return (
    <nav aria-label={labels.language} className="flex items-center gap-1 text-sm">
      {routing.locales.map((locale) => {
        const isActive = locale === currentLocale;
        return (
          <Link
            key={locale}
            href={`/${locale}`}
            hrefLang={locale}
            aria-current={isActive ? 'true' : undefined}
            className={
              isActive
                ? 'rounded-token-sm bg-surface-muted px-3 py-1.5 font-medium'
                : 'rounded-token-sm px-3 py-1.5 text-content-muted hover:text-content'
            }
          >
            {labelFor[locale] ?? locale}
          </Link>
        );
      })}
    </nav>
  );
}
