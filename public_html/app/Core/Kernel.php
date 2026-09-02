<?php

declare(strict_types=1);

namespace WebAtze\Core;

use Throwable;

/**
 * Nimmt die Anfrage entgegen, richtet alles ein und schickt die Antwort.
 */
final class Kernel
{
    public static function handle(): void
    {
        $request = Request::capture();

        // Ist noch keine config.php da, führt alles zur Einrichtung.
        if (!Config::isInstalled()) {
            self::redirectToInstaller($request);
            return;
        }

        try {
            Db::connection();
            Schema::ensureCurrent();
            Session::start();
            I18n::boot($request);

            self::shareViewData($request);

            $router = new Router();
            Routes::register($router);

            $response = $router->dispatch($request) ?? self::notFound($request);
        } catch (Throwable $e) {
            $id = Logger::exception($e);
            $response = self::serverError($request, $id);
        }

        $response->send();
    }

    private static function shareViewData(Request $request): void
    {
        View::share('locale', I18n::locale());
        View::share('request', $request);
        View::share('nonce', Security::nonce());
        View::share('currentPath', $request->path());
        View::share('assets', Assets::manifest());
        View::share('flash', Session::takeFlash());
        View::share('authUser', Session::user());
        View::share('siteSettings', Settings::all());
    }

    private static function notFound(Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['ok' => false, 'error' => 'Nicht gefunden.'], 404);
        }

        return Response::html(
            View::page('pages/not-found', ['title' => __t('errors.not_found_title')]),
            404
        );
    }

    private static function serverError(Request $request, string $errorId): Response
    {
        if ($request->wantsJson()) {
            return Response::json([
                'ok' => false,
                'error' => __t('errors.server_text'),
                'id' => $errorId,
            ], 500);
        }

        // Die Fehlerseite darf selbst nicht scheitern – deshalb ohne Vorlage,
        // falls auch das Rendern kaputt ist.
        try {
            $html = View::page('pages/error', [
                'title' => __t('errors.server_title'),
                'errorId' => $errorId,
            ]);
        } catch (Throwable) {
            $html = '<!doctype html><meta charset="utf-8"><title>Fehler</title>'
                . '<p>Etwas ist schiefgelaufen. Kennnummer: ' . e($errorId) . '</p>';
        }

        return Response::html($html, 500);
    }

    private static function redirectToInstaller(Request $request): void
    {
        if (!is_file(BASE_DIR . '/install.php')) {
            Response::text(
                "WebAtze ist noch nicht eingerichtet.\n\n"
                . "Es fehlt die Datei app/config.php und install.php ist nicht vorhanden.\n"
                . "Bitte app/config.example.php nach app/config.php kopieren und ausfüllen.",
                503
            )->send();
            return;
        }

        if (str_ends_with($request->path(), '/install.php')) {
            return;
        }

        Response::redirect('/install.php')->send();
    }
}
