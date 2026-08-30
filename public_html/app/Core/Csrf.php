<?php

declare(strict_types=1);

namespace WebAtze\Core;

/**
 * Schutz gegen fremdgesteuerte Formulare (CSRF).
 *
 * Jedes Formular, das etwas verändert, trägt ein Einmalzeichen. Fehlt es
 * oder passt es nicht, wird die Anfrage verworfen – auch dann, wenn der
 * Benutzer angemeldet ist.
 */
final class Csrf
{
    public const FIELD = '_token';

    public static function token(): string
    {
        return Session::csrfToken();
    }

    /** Fertiges verstecktes Formularfeld. */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . e(self::token()) . '">';
    }

    public static function check(Request $request): bool
    {
        if (!$request->isPost()) {
            return true;
        }

        $given = $request->input(self::FIELD);
        if ($given === '') {
            $given = $request->header('X-CSRF-Token');
        }
        if ($given === '') {
            $body = $request->jsonBody();
            $given = is_string($body[self::FIELD] ?? null) ? $body[self::FIELD] : '';
        }

        return $given !== '' && hash_equals(Session::csrfToken(), $given);
    }

    /**
     * Zusätzlich prüfen, ob die Anfrage von der eigenen Seite kommt.
     * Zweite Absicherung, falls das Token einmal abhandenkommt.
     */
    public static function sameOrigin(Request $request): bool
    {
        $origin = $request->header('Origin');

        // "null" ist keine Herkunft, sondern die Auskunft, dass der Browser
        // sie nicht nennen will (etwa bei einer strengen Referrer-Richtlinie
        // oder aus einem abgeschotteten Rahmen). Dann entscheidet das Token –
        // so wie auch dann, wenn gar nichts mitgeschickt wird.
        if ($origin === 'null') {
            $origin = '';
        }

        if ($origin === '') {
            $referer = $request->header('Referer');
            if ($referer === '') {
                return true; // Manche Browser senden beides nicht – Token entscheidet.
            }
            $origin = (string) (parse_url($referer, PHP_URL_SCHEME) . '://' . parse_url($referer, PHP_URL_HOST));
            $port = parse_url($referer, PHP_URL_PORT);
            if ($port !== null) {
                $origin .= ':' . $port;
            }
        }

        $expected = $request->baseUrl();
        return rtrim($origin, '/') === rtrim($expected, '/');
    }
}
