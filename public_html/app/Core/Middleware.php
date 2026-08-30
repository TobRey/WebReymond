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

        $tokenOk = Csrf::check($request);
        $originOk = Csrf::sameOrigin($request);

        if ($tokenOk && $originOk) {
            return null;
        }

        // Welche der beiden Prüfungen gegriffen hat, gehört ins Protokoll:
        // ein abgelaufenes Token ist harmlos, eine fremde Herkunft nicht –
        // und wenn "app_url" nicht zur echten Adresse passt, scheitert jedes
        // Formular. Ohne diesen Hinweis sucht man lange.
        Logger::warning('CSRF-Prüfung fehlgeschlagen', [
            'path' => $request->path(),
            'ip' => $request->ip(),
            'grund' => $tokenOk
                ? 'Herkunft weicht ab (erwartet ' . $request->baseUrl() . ', gemeldet '
                  . ($request->header('Origin') ?: $request->header('Referer') ?: 'nichts') . ')'
                : 'Token fehlt oder ist abgelaufen',
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
