<?php
declare(strict_types=1);

namespace App\Core;

/**
 * CSRF-Schutz mit Double-Submit-Token pro Session.
 */
final class Csrf
{
    public static function token(): string
    {
        if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = Crypto::randomToken(32);
        }
        return $_SESSION['_csrf'];
    }

    public static function check(Request $request): void
    {
        if (!$request->isPost()) {
            return;
        }
        $token = $request->str('_csrf', '');
        if ($token === '') {
            $token = $request->header('X-CSRF-Token');
        }
        $expected = $_SESSION['_csrf'] ?? '';
        if (!is_string($expected) || $expected === '' || !hash_equals($expected, $token)) {
            Logger::security('CSRF-Token ungueltig', ['path' => $request->path()]);
            throw HttpException::forbidden('Sicherheitstoken abgelaufen. Bitte Seite neu laden.');
        }
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}
