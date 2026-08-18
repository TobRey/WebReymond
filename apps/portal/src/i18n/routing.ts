import { defineRouting } from 'next-intl/routing';
import { DEFAULT_LOCALE, LOCALES } from '@webreymond/shared';

/**
 * Sprachen kommen aus @webreymond/shared, damit Portal, API und später
 * backendReymond garantiert dieselbe Liste verwenden.
 */
export const routing = defineRouting({
  locales: [...LOCALES],
  defaultLocale: DEFAULT_LOCALE,
});
