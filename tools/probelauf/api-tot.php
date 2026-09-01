<?php

/**
 * Eine Gegenstelle, die auf nichts antwortet.
 *
 * Gegenstueck zu nie-erreichbar.php: Jeder Aufruf laeuft ins Leere. So
 * laesst sich pruefen, ob der Bau auch dann ankommt, wenn die KI
 * ueberhaupt nicht erreichbar ist.
 */

declare(strict_types=1);

header('Content-Type: application/json');
http_response_code(503);

echo json_encode([
    'error' => ['type' => 'overloaded_error', 'message' => 'Nicht erreichbar (Probe)'],
]);
