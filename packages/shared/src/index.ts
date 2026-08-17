/**
 * Gemeinsame Grundlagen für Portal, API und Worker.
 *
 * Alles, was über eine Systemgrenze geht, wird hier EINMAL beschrieben.
 * Damit können Frontend und Backend nicht auseinanderlaufen, und jede
 * Eingabe wird an der Grenze validiert (siehe SECURITY.md, Abschnitt 3).
 */

export {
  localeSchema,
  DEFAULT_LOCALE,
  LOCALES,
  type Locale,
  roleSchema,
  ROLES,
  type Role,
  hasAtLeastRole,
} from './domain.js';

export {
  healthResponseSchema,
  type HealthResponse,
  pingResponseSchema,
  type PingResponse,
  apiErrorSchema,
  type ApiError,
  ApiErrorCode,
} from './api.js';
