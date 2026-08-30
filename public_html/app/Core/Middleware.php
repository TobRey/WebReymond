<?php

declare(strict_types=1);

namespace WebAtze\Core;

/**
 * Vorprüfungen, die vor einer Route laufen.
 *
 * Gibt eine Prüfung eine Response zurück, wird die Route nicht ausgeführt.
 * Gibt sie null zurück, geht es normal weiter.
 */
final class Middleware
{
    public static function run(string $name, Request $request): ?Response
    {
        return match ($name) {
            'auth' => self::auth($request),
            'csrf' => self::csrf($request),
            'json' => null,
            default => throw new \RuntimeException('Unbekannte Vorprüfung: ' . $name),
        };
    }

    /** Nur für Angemeldete. Nicht Angemeldete landen beim Login. */
    private static function auth(Request $request): ?Response
    {
        if (Session::isLoggedIn()) {
            return null;
        }

        if ($request->wantsJson()) {
            return Response::json(['ok' => false, 'error' => 'Nicht angemeldet.'], 401)->noCache();
        }

        Session::put('_wa_intended', $request->path());

        return Response::redirect('/' . trim((string) Config::get('create_path', 'create'), '/'))
            ->noCache()
            ->noIndex();
    }

    /** Formularschutz. */
    private static function csrf(Request $request): ?Response
    {
        if (!$request->isPost()) {
            return null;
        }

        if (Csrf::check($request) && Csrf::sameOrigin($request)) {
            return null;
        }

        Logger::warning('CSRF-Prüfung fehlgeschlagen', [
            'path' => $request->path(),
            'ip' => $request->ip(),
        ]);

        if ($request->wantsJson()) {
            return Response::json([
                'ok' => false,
                'error' => 'Sicherheitsprüfung fehlgeschlagen. Bitte die Seite neu laden.',
            ], 419)->noCache();
        }

        Session::flash('error', __t('errors.csrf'));
        return Response::redirect($request->path())->noCache();
    }
}
