<?php

declare(strict_types=1);

namespace SkyKingdoms\Core;

/**
 * Schutz vor Cross-Site-Request-Forgery.
 *
 * Jede verändernde Anfrage (Formular oder API) muss das Token mitschicken:
 * Formulare als verstecktes Feld, die API im Kopf "X-SK-CSRF".
 */
final class Csrf
{
    public const FIELD  = '_token';
    public const HEADER = 'HTTP_X_SK_CSRF';

    public static function token(): string
    {
        Session::start();
        $token = Session::get('csrf');
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            Session::set('csrf', $token);
        }

        return $token;
    }

    /** Verstecktes Formularfeld. */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . e(self::token()) . '">';
    }

    public static function check(?string $candidate): bool
    {
        Session::start();
        $token = Session::get('csrf');

        return is_string($token) && is_string($candidate) && $candidate !== '' && hash_equals($token, $candidate);
    }

    /** Token aus Formular oder Kopfzeile lesen. */
    public static function fromRequest(): ?string
    {
        $token = $_POST[self::FIELD] ?? null;
        if (is_string($token) && $token !== '') {
            return $token;
        }
        $header = $_SERVER[self::HEADER] ?? null;

        return is_string($header) && $header !== '' ? $header : null;
    }

    /** Prüfen und bei Misserfolg abbrechen. */
    public static function verifyOrFail(): void
    {
        if (self::check(self::fromRequest())) {
            return;
        }

        Logger::suspicious('CSRF-Prüfung fehlgeschlagen', ['pfad' => Url::currentPath()]);

        if (App::mode() === App::MODE_JSON) {
            Response::json(['ok' => false, 'error' => 'Sicherheitsprüfung fehlgeschlagen. Bitte Seite neu laden.'], 419);
        }

        http_response_code(419);
        exit('Sicherheitsprüfung fehlgeschlagen. Bitte lade die Seite neu.');
    }
}
