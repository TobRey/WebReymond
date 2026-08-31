<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Core\{Db, Request, Response, Security};

/**
 * Nimmt Besuchszahlen entgegen.
 *
 * Der Normalfall ist, dass WebAtze sie abholt (siehe Build\VisitCollector).
 * Diese Adresse gibt es für den Fall, dass der Server des Kunden von
 * aussen nicht erreichbar ist – dann schiebt er sie selbst hierher.
 *
 * Es kommen nur Summen an. Wer wann welche Seite besucht hat, verlässt
 * den Server des Kunden nie.
 */
final class VisitController
{
    public function collect(Request $request): Response
    {
        $project = $this->authenticate($request);

        if ($project === null) {
            return Response::json(['ok' => false, 'error' => 'Zugang verweigert.'], 403)->noCache();
        }

        $rows = (array) ($request->jsonBody()['zeilen'] ?? []);

        $count = \WebAtze\Build\VisitCollector::store((int) $project['id'], $rows);

        return Response::json(['ok' => true, 'uebernommen' => $count])->noCache()->noIndex();
    }

    private function authenticate(Request $request): ?array
    {
        $token = trim($request->header('X-WebAtze-Token'));

        if (strlen($token) < 20 || strlen($token) > 100) {
            return null;
        }

        foreach (Db::all("SELECT * FROM projects WHERE assistant_token <> ''") as $row) {
            if (Security::equals((string) $row['assistant_token'], $token)) {
                return $row;
            }
        }

        return null;
    }
}
