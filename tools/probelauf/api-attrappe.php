<?php

/**
 * Eine Attrappe der Anthropic-Schnittstelle, die streng ist.
 *
 * Sie antwortet nicht einfach "ja". Sie prüft die Anfrage nach denselben
 * Regeln, an denen der echte Dienst sie abgewiesen hat – und genau die
 * sind hier dreimal hintereinander übersehen worden:
 *
 *   1. Die Kopfzeile mit dem Arbeitsbereich muss da sein.
 *   2. Das Schema darf keine Grössenangaben enthalten.
 *   3. Die daraus entstehende Grammatik darf nicht ausufern.
 *
 * Wer diese Attrappe zufriedenstellt, scheitert beim echten Dienst
 * nicht mehr an der Form der Anfrage. Über die Qualität der Texte sagt
 * sie nichts – dafür braucht es den echten Dienst.
 *
 * Aufruf:  php -S 127.0.0.1:8126 tools/probelauf/api-attrappe.php
 */

declare(strict_types=1);

const PROTOKOLL = __DIR__ . '/anfragen.log';

/** Was die strukturierte Ausgabe nicht kennt. */
const VERBOTEN = [
    'minItems', 'maxItems', 'uniqueItems', 'contains', 'minContains', 'maxContains',
    'prefixItems', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum',
    'multipleOf', 'minLength', 'maxLength', 'pattern', 'minProperties',
    'maxProperties', 'propertyNames',
];

/** Ab hier gilt die Grammatik als zu gross. Geschätzt, nicht gemessen. */
const GRAMMATIK_GRENZE = 60000;

$pfad = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$körper = (string) file_get_contents('php://input');
$anfrage = json_decode($körper, true) ?: [];

header('Content-Type: application/json');

// ------------------------------------------------------------- Modellliste

if (str_contains((string) $pfad, '/v1/models')) {
    echo json_encode(['data' => [['id' => 'claude-opus-5', 'display_name' => 'Opus 5']]]);
    exit;
}

if (str_contains((string) $pfad, '/organizations/workspaces')) {
    http_response_code(401);
    echo json_encode(['error' => ['message' => 'This API key does not have admin permissions.']]);
    exit;
}

// ------------------------------------------------------------ Regel 1: Kopf

$arbeitsbereich = (string) ($_SERVER['HTTP_ANTHROPIC_WORKSPACE_ID'] ?? '');

if ($arbeitsbereich === '') {
    fehler(400, 'anthropic-workspace-id is required when authenticating with an '
        . 'identity-linked API key; send the id of the workspace this request acts in.');
}

if (!str_starts_with($arbeitsbereich, 'wrkspc_')) {
    fehler(400, 'anthropic-workspace-id header must be a valid workspace ID');
}

// ------------------------------------------------------------ Regel 2 und 3

$schema = $anfrage['output_config']['format']['schema'] ?? null;
$zweck = 'text';

if (is_array($schema)) {
    $zweck = 'strukturiert';

    $gefunden = verboteneFinden($schema);

    if ($gefunden !== []) {
        $erste = $gefunden[0];
        fehler(400, sprintf(
            "output_config.format.schema: For '%s' type, property '%s' is not supported",
            $erste['typ'],
            $erste['name']
        ));
    }

    $grösse = grammatik($schema);

    if ($grösse > GRAMMATIK_GRENZE) {
        fehler(400, 'The compiled grammar is too large, which would cause performance '
            . 'issues. Simplify your tool schemas or reduce the number of strict tools. '
            . '(geschätzt: ' . $grösse . ')');
    }

    protokoll(sprintf('%-14s Schema %5d Zeichen, Grammatik ~%d', $zweck, strlen(json_encode($schema)), $grösse));

    echo json_encode([
        'id' => 'msg_' . bin2hex(random_bytes(4)),
        'type' => 'message',
        'role' => 'assistant',
        'model' => (string) ($anfrage['model'] ?? '?'),
        'content' => [['type' => 'text', 'text' => json_encode(erfinden($schema))]],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 1200, 'output_tokens' => 800],
    ]);
    exit;
}

// ------------------------------------------------------------- Freier Text

protokoll('freier Text');

echo json_encode([
    'id' => 'msg_' . bin2hex(random_bytes(4)),
    'type' => 'message',
    'role' => 'assistant',
    'model' => (string) ($anfrage['model'] ?? '?'),
    'content' => [['type' => 'text', 'text' => 'Ein Satz aus der Attrappe.']],
    'stop_reason' => 'end_turn',
    'usage' => ['input_tokens' => 400, 'output_tokens' => 60],
]);

// ==================================================================

function fehler(int $status, string $nachricht): never
{
    protokoll('ABGEWIESEN ' . $status . ': ' . $nachricht);

    http_response_code($status);
    echo json_encode(['type' => 'error', 'error' => [
        'type' => 'invalid_request_error',
        'message' => $nachricht,
    ]]);
    exit;
}

function protokoll(string $zeile): void
{
    file_put_contents(PROTOKOLL, date('H:i:s') . '  ' . $zeile . "\n", FILE_APPEND);
}

/**
 * Verbotene Angaben suchen – überall, auch tief verschachtelt.
 *
 * @return array<int, array{name:string, typ:string, pfad:string}>
 */
function verboteneFinden(array $schema, string $pfad = '$'): array
{
    $treffer = [];
    $typ = (string) ($schema['type'] ?? 'object');

    foreach ($schema as $schlüssel => $wert) {
        if (is_string($schlüssel) && in_array($schlüssel, VERBOTEN, true)) {
            $treffer[] = ['name' => $schlüssel, 'typ' => $typ, 'pfad' => $pfad];
        }

        if (is_array($wert)) {
            $treffer = array_merge($treffer, verboteneFinden($wert, $pfad . '.' . $schlüssel));
        }
    }

    return $treffer;
}

/**
 * Wie gross wird die Grammatik ungefähr?
 *
 * Kein Nachbau des echten Übersetzers, sondern eine Schätzung mit der
 * richtigen Form: Eine Auswahl multipliziert, alles andere addiert.
 * Genau daran ist die Liste mit "anyOf" gescheitert.
 */
function grammatik(array $schema): int
{
    $summe = 20;

    foreach (['anyOf', 'oneOf', 'allOf'] as $zweigart) {
        if (!isset($schema[$zweigart]) || !is_array($schema[$zweigart])) {
            continue;
        }

        $zweige = 0;
        foreach ($schema[$zweigart] as $zweig) {
            if (is_array($zweig)) {
                $zweige += grammatik($zweig);
            }
        }

        // Eine Auswahl in einer Liste vervielfacht sich mit deren Länge.
        $summe += $zweige * count($schema[$zweigart]);
    }

    foreach ((array) ($schema['properties'] ?? []) as $eigenschaft) {
        if (is_array($eigenschaft)) {
            $summe += grammatik($eigenschaft) + 12;
        }
    }

    if (isset($schema['items']) && is_array($schema['items'])) {
        // Eine Liste kann sich wiederholen – das kostet mehr als einmal.
        $summe += grammatik($schema['items']) * 3;
    }

    foreach ((array) ($schema['enum'] ?? []) as $wert) {
        $summe += mb_strlen((string) $wert) + 4;
    }

    return $summe;
}

/**
 * Eine Antwort erfinden, die genau zum Schema passt.
 *
 * Der Feldname wird berücksichtigt: Ein Feld namens "url" bekommt eine
 * Adresse, keinen Fliesstext. Sonst meldete die Prüfung nach dem Bauen
 * lauter tote Verweise – und ein echter ginge darin unter.
 */
function erfinden(array $schema, string $feld = ''): mixed
{
    $typ = (string) ($schema['type'] ?? 'object');

    if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== []) {
        return $schema['enum'][0];
    }

    if (isset($schema['anyOf'][0]) && is_array($schema['anyOf'][0])) {
        return erfinden($schema['anyOf'][0], $feld);
    }

    return match ($typ) {
        'object' => (function () use ($schema): array {
            $out = [];
            foreach ((array) ($schema['properties'] ?? []) as $name => $unter) {
                if (is_array($unter)) {
                    $out[$name] = erfinden($unter, (string) $name);
                }
            }
            return $out;
        })(),
        'array' => [
            erfinden(is_array($schema['items'] ?? null) ? $schema['items'] : ['type' => 'string'], $feld),
            erfinden(is_array($schema['items'] ?? null) ? $schema['items'] : ['type' => 'string'], $feld),
        ],
        'integer' => 1,
        'number' => 1.0,
        'boolean' => true,
        default => text($feld),
    };
}

/** Etwas Passendes je nach Feldname. */
function text(string $feld): string
{
    return match (true) {
        $feld === 'url' || str_ends_with($feld, '_url') => 'index.html',
        $feld === 'path' => '/beispiel',
        $feld === 'src' || str_ends_with($feld, '_image') => '',
        $feld === 'icon' => 'check',
        str_contains($feld, 'email') => 'kontakt@beispiel.test',
        str_contains($feld, 'slug') => 'beispiel',
        default => 'Attrappentext',
    };
}
