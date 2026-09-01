<?php

/**
 * Eine Gegenstelle, die genau die grosse Planungsanfrage verweigert.
 *
 * Der Bau plant seit dem Umbau die ganze Website in einer Anfrage. Das
 * spart bei elf Seiten elf Aufrufe - aber es ist auch ein einzelner
 * Punkt, an dem alles haengen koennte. Deshalb faellt er auf den Weg
 * Seite fuer Seite zurueck, wenn es nicht klappt.
 *
 * Diese Attrappe erzwingt genau diesen Fall: Sie erkennt die grosse
 * Anfrage daran, dass ihr Schema sowohl den Seitentitel als auch die
 * Abschnitte enthaelt, und weist sie ab. Alles andere laeuft normal.
 */

declare(strict_types=1);

$roh = file_get_contents('php://input') ?: '';

if (str_contains($roh, 'site_title') && str_contains($roh, 'sections')) {
    header('Content-Type: application/json');
    http_response_code(529);
    echo json_encode([
        'error' => ['type' => 'overloaded_error', 'message' => 'Grosse Planung: Probe-Ausfall'],
    ]);
    return;
}

require __DIR__ . '/api-attrappe.php';
