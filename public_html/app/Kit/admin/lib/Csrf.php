<?php

declare(strict_types=1);

namespace WebAtzeKit;

/**
 * Formularschutz.
 *
 * Ohne ihn könnte eine fremde Seite im Namen des angemeldeten Kunden
 * Änderungen auslösen, einfach indem sie ein unsichtbares Formular
 * abschickt. Das Kennwort steht in der Sitzung und muss bei jeder
 * ändernden Anfrage mitkommen.
 */
final class Csrf
{
    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }

        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="'
            . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function check(): bool
    {
        $expected = (string) ($_SESSION['csrf'] ?? '');
        if ($expected === '') {
            return false;
        }

        $given = (string) ($_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

        if (!hash_equals($expected, $given)) {
            return false;
        }

        return self::sameOrigin();
    }

    /**
     * Kommt die Anfrage von dieser Website?
     *
     * "null" heisst: Der Browser will die Herkunft nicht nennen. Das ist
     * keine fremde Herkunft, sondern gar keine – dann entscheidet das
     * Kennwort allein.
     */
    private static function sameOrigin(): bool
    {
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');

        if ($origin === '' || $origin === 'null') {
            $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
            if ($referer === '') {
                return true;
            }
            $parts = parse_url($referer);
            $origin = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? '')
                . (isset($parts['port']) ? ':' . $parts['port'] : '');
        }

        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $expected = (Auth::isSecure() ? 'https://' : 'http://') . $host;

        return rtrim($origin, '/') === rtrim($expected, '/');
    }
}
