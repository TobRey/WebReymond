import { defineRouting } from 'next-intl/routing';
import { DEFAULT_LOCALE, LOCALES } from '@webheaven/shared';

/**
 * Sprachen kommen aus @webheaven/shared, damit Portal, API und später
 * backendHeaven garantiert dieselbe Liste verwenden.
 */
export const routing = defineRouting({
  locales: [...LOCALES],
  defaultLocale: DEFAULT_LOCALE,
});
