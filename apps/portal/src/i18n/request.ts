import { getRequestConfig } from 'next-intl/server';
import { hasLocale } from 'next-intl';
import { routing } from './routing';

/**
 * Lädt den Textkatalog für die angefragte Sprache.
 *
 * Unbekannte Sprachen fallen auf die Standardsprache zurück, statt einen
 * Fehler zu werfen – eine manipulierte URL darf die Seite nicht abschiessen.
 */
export default getRequestConfig(async ({ requestLocale }) => {
  const requested = await requestLocale;
  const locale = hasLocale(routing.locales, requested) ? requested : routing.defaultLocale;

  return {
    locale,
    messages: (await import(`../../messages/${locale}.json`)).default,
  };
});
