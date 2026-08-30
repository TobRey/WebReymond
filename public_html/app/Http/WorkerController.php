<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Core\{Jobs, Request, Response, Security, Session, Worker};

/**
 * Startet den Worker über eine Adresse.
 *
 * Zwei Wege sind erlaubt:
 *   - mit dem richtigen Schlüssel (so stösst sich WebAtze selbst an)
 *   - angemeldet im Adminbereich (praktisch zum Nachhelfen von Hand)
 *
 * Ohne beides gibt es ein 404 – die Adresse soll gar nicht erst als
 * vorhanden erkennbar sein.
 */
final class WorkerController
{
    public function tick(Request $request): Response
    {
        $key = $request->query('key');
        $authorised = ($key !== '' && Security::equals(Jobs::nudgeKey(), $key))
            || Session::isLoggedIn();

        if (!$authorised) {
            return Response::notFound();
        }

        // Antwort sofort abschliessen, damit der Anstossende nicht wartet.
        if (function_exists('fastcgi_finish_request') && $key !== '') {
            ignore_user_abort(true);
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Length: 3');
            echo "ok\n";
            fastcgi_finish_request();

            Worker::run();
            exit;
        }

        $result = Worker::run();

        return Response::json([
            'ok' => true,
            'jobs' => $result['jobs'],
            'seconds' => $result['seconds'],
            'housekeeping' => $result['housekeeping'],
        ])->noCache()->noIndex();
    }
}
