<?php
/**
 * Pixel-Art-Werkzeug – der Teil, der auf dem Server läuft.
 *
 * Die Seite im Browser darf den Schlüssel nie sehen. Deshalb fragt sie
 * diese Datei, und erst diese Datei fragt Claude.
 *
 * Ein Aufruf zeichnet immer genau EIN Bild. Das ist Absicht: Auf einfachem
 * Webhosting bricht eine Anfrage nach ein bis zwei Minuten ab, und acht
 * Bilder am Stück würden diese Grenze reissen.
 */

require __DIR__ . '/config.php';

header('content-type: application/json; charset=utf-8');
header('x-content-type-options: nosniff');

/** Erlaubte Bildgrössen. Die erste ist die Vorgabe. */
$GROESSEN = [
    ['breite' => 32, 'hoehe' => 64],
    ['breite' => 16, 'hoehe' => 16],
    ['breite' => 32, 'hoehe' => 32],
    ['breite' => 48, 'hoehe' => 48],
    ['breite' => 64, 'hoehe' => 64],
];

/**
 * Die Animationen. Jeder Eintrag beschreibt eine Haltung – das ist die
 * Anweisung für genau ein Bild. Sechs bis acht Haltungen ergeben eine
 * Bewegung, die rund wirkt.
 */
$ANIMATIONEN = [
    'walk' => [
        'name' => 'Laufen',
        'haltungen' => [
            'walk cycle, contact pose: left leg stretched forward with the heel down, right leg stretched back, arms swinging opposite to the legs',
            'walk cycle, down pose: weight on the left leg which is slightly bent, body one pixel lower, right leg lifting behind',
            'walk cycle, passing pose: right leg passes close to the left leg, body at normal height, arms near the body',
            'walk cycle, up pose: body one pixel higher, left leg pushing off on the toes, right leg swinging forward',
            'walk cycle, contact pose mirrored: right leg stretched forward with the heel down, left leg stretched back, arms swapped',
            'walk cycle, down pose mirrored: weight on the right leg which is slightly bent, body one pixel lower, left leg lifting behind',
            'walk cycle, passing pose mirrored: left leg passes close to the right leg, body at normal height, arms near the body',
            'walk cycle, up pose mirrored: body one pixel higher, right leg pushing off on the toes, left leg swinging forward',
        ],
    ],
    'idle' => [
        'name' => 'Stehen',
        'haltungen' => [
            'idle pose, breathing out: standing relaxed, shoulders at rest',
            'idle pose, breathing in: chest one pixel wider, shoulders one pixel higher',
            'idle pose, top of the breath: body one pixel higher, head very slightly tilted',
            'idle pose, breathing out again: shoulders sinking back down',
            'idle pose, low point: body one pixel lower, shoulders relaxed',
            'idle pose, back to rest: same as a calm standing pose, blinking eyes',
        ],
    ],
    'jump' => [
        'name' => 'Springen',
        'haltungen' => [
            'jump, anticipation: deep crouch, knees bent, arms pulled back, body low',
            'jump, take-off: legs stretching, arms swinging up, body rising',
            'jump, rising: body stretched upwards in the air, legs together, arms up',
            'jump, peak: highest point, legs slightly tucked, arms out for balance',
            'jump, falling: legs reaching down for the ground, arms lowering',
            'jump, landing: knees bent to absorb the impact, body low, arms forward',
        ],
    ],
    'wave' => [
        'name' => 'Winken',
        'haltungen' => [
            'waving, arm lifting: one arm raised halfway, body standing still',
            'waving, arm up: the raised arm is fully up, hand open',
            'waving, hand tilted outwards: the raised forearm leans away from the body',
            'waving, hand tilted inwards: the raised forearm leans towards the head',
            'waving, hand tilted outwards again: same as before, a little wider',
            'waving, arm lowering: the arm sinks back down to the side',
        ],
    ],
];

/** Antwort schicken und Schluss. */
function antwort($status, $daten)
{
    http_response_code($status);
    echo json_encode($daten, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Einfache Bremse gegen Missbrauch.
 *
 * Gezählt wird je Besucher und Stunde in einer Datei im Temp-Ordner – das
 * funktioniert auf jedem Hosting, ohne Datenbank und ohne Schreibrechte im
 * eigenen Ordner.
 */
function limit_frei($proStunde)
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unbekannt';
    $datei = sys_get_temp_dir() . '/pixelart-' . md5($ip) . '.txt';
    $jetzt = time();

    $zeiten = [];
    if (is_readable($datei)) {
        foreach (explode(',', (string) file_get_contents($datei)) as $eintrag) {
            $zeit = (int) $eintrag;
            if ($zeit > $jetzt - 3600) {
                $zeiten[] = $zeit;
            }
        }
    }

    if (count($zeiten) >= $proStunde) {
        return false;
    }

    $zeiten[] = $jetzt;
    @file_put_contents($datei, implode(',', $zeiten), LOCK_EX);
    return true;
}

/** Die Regeln, an die sich das Modell halten muss – für jedes Bild dieselben. */
function system_text($breite, $hoehe)
{
    return implode("\n", [
        'You are a pixel art artist. You answer with JSON and nothing else.',
        '',
        'Answer shape:',
        '{"palette":{"a":"#rrggbb","b":"#rrggbb"},"frame":["..a..","..b.."]}',
        '',
        'Rules:',
        '- Exactly one frame.',
        '- The frame has exactly ' . $hoehe . ' strings, every string exactly ' . $breite . ' characters.',
        '- Every character is either "." (transparent) or a key of the palette.',
        '- Palette keys are single characters, colours are #rrggbb. Use at most 16 colours.',
        '- Draw a readable silhouette first: dark outline, one base tone and one shadow tone per material.',
        '- Keep the figure inside the canvas and leave a one pixel margin.',
        '- No text, no frame numbers, no background - the background stays transparent.',
        '- Output raw JSON, no markdown fences, no explanation.',
    ]);
}

/** Claude fragen und den Text der Antwort zurückgeben. */
function modell_fragen($system, $frage, $schluessel, $modell, $aufwand)
{
    $koerper = json_encode([
        'model' => $modell,
        'max_tokens' => 16000,
        'system' => $system,
        'output_config' => ['effort' => $aufwand],
        'messages' => [['role' => 'user', 'content' => $frage]],
    ]);

    $kopf = [
        'content-type: application/json',
        'x-api-key: ' . $schluessel,
        'anthropic-version: 2023-06-01',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $koerper);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $kopf);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 180);
        $rohantwort = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $fehler = curl_error($ch);
        curl_close($ch);

        if ($rohantwort === false) {
            antwort(502, ['fehler' => 'Der Server erreicht Claude nicht (' . $fehler . ').']);
        }
    } else {
        // Ohne cURL: der Weg über die eingebauten Streams.
        $kontext = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $kopf),
                'content' => $koerper,
                'timeout' => 180,
                'ignore_errors' => true,
            ],
        ]);
        $rohantwort = @file_get_contents('https://api.anthropic.com/v1/messages', false, $kontext);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $treffer)) {
            $status = (int) $treffer[1];
        }
        if ($rohantwort === false) {
            antwort(502, ['fehler' => 'Der Server erreicht Claude nicht.']);
        }
    }

    $daten = json_decode($rohantwort, true);

    if ($status === 401 || $status === 403) {
        antwort(502, ['fehler' => 'Claude weist den Schlüssel zurück. Steht er richtig in der config.php?']);
    }
    if ($status === 429) {
        antwort(502, ['fehler' => 'Claude ist gerade ausgelastet oder dein Guthaben ist aufgebraucht.']);
    }
    if ($status !== 200 || !is_array($daten)) {
        antwort(502, ['fehler' => 'Claude antwortet nicht wie erwartet (Status ' . $status . ').']);
    }
    if (isset($daten['stop_reason']) && $daten['stop_reason'] === 'refusal') {
        antwort(400, ['fehler' => 'Claude möchte diese Figur nicht zeichnen. Beschreibe sie anders.']);
    }

    $text = '';
    if (isset($daten['content']) && is_array($daten['content'])) {
        foreach ($daten['content'] as $block) {
            if (isset($block['type']) && $block['type'] === 'text') {
                $text .= $block['text'];
            }
        }
    }

    if (trim($text) === '') {
        antwort(502, ['fehler' => 'Claude hat nichts gezeichnet. Bitte noch einmal versuchen.']);
    }

    return $text;
}

/** Das JSON aus der Antwort schneiden – auch wenn Text drumherum steht. */
function json_ausschneiden($text)
{
    $start = strpos($text, '{');
    $ende = strrpos($text, '}');
    if ($start === false || $ende === false || $ende <= $start) {
        return null;
    }
    $daten = json_decode(substr($text, $start, $ende - $start + 1), true);
    return is_array($daten) ? $daten : null;
}

/**
 * Die Antwort auf das erwartete Raster bringen.
 *
 * Eine Zeile zu kurz, ein unbekanntes Zeichen, eine Farbe in Kurzschreibweise:
 * All das darf kein Fehler sein, den der Besucher zu sehen bekommt. Was fehlt,
 * wird durchsichtig.
 */
function raster_saeubern($daten, $breite, $hoehe, $grundPalette)
{
    if (!is_array($daten)) {
        return null;
    }

    $palette = is_array($grundPalette) ? $grundPalette : [];
    if (isset($daten['palette']) && is_array($daten['palette'])) {
        foreach ($daten['palette'] as $taste => $farbe) {
            $taste = (string) $taste;
            // Der Punkt ist für "durchsichtig" reserviert.
            if (strlen($taste) !== 1 || $taste === '.' || !is_string($farbe)) {
                continue;
            }
            $farbe = strtolower(trim($farbe));
            if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $farbe, $t)) {
                $farbe = '#' . $t[1] . $t[1] . $t[2] . $t[2] . $t[3] . $t[3];
            }
            if (preg_match('/^#[0-9a-f]{6}$/', $farbe)) {
                $palette[$taste] = $farbe;
            }
        }
    }

    if (count($palette) === 0) {
        return null;
    }

    $zeilen = null;
    if (isset($daten['frame']) && is_array($daten['frame'])) {
        $zeilen = $daten['frame'];
    } elseif (isset($daten['frames']) && is_array($daten['frames']) && isset($daten['frames'][0])) {
        $zeilen = $daten['frames'][0];
    }

    if (!is_array($zeilen)) {
        return null;
    }

    $bild = [];
    for ($y = 0; $y < $hoehe; $y++) {
        $zeile = isset($zeilen[$y]) && is_string($zeilen[$y]) ? $zeilen[$y] : '';
        $sauber = '';
        for ($x = 0; $x < $breite; $x++) {
            $zeichen = isset($zeile[$x]) ? $zeile[$x] : '.';
            $sauber .= isset($palette[$zeichen]) ? $zeichen : '.';
        }
        $bild[] = $sauber;
    }

    return ['palette' => $palette, 'bild' => $bild];
}

/* -------------------------------------------------------------------------- */
/* Ab hier die eigentliche Anfrage                                            */
/* -------------------------------------------------------------------------- */

$eingabe = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($eingabe)) {
    $eingabe = [];
}
$aktion = isset($eingabe['aktion']) ? (string) $eingabe['aktion'] : 'info';

if ($aktion === 'info') {
    $liste = [];
    foreach ($ANIMATIONEN as $id => $eintrag) {
        $liste[] = ['id' => $id, 'name' => $eintrag['name'], 'bilder' => count($eintrag['haltungen'])];
    }
    antwort(200, [
        'bereit' => $ANTHROPIC_API_KEY !== '',
        'passwortNoetig' => $SITE_PASSWORT !== '',
        'groessen' => $GROESSEN,
        'animationen' => $liste,
    ]);
}

if ($ANTHROPIC_API_KEY === '') {
    antwort(503, ['fehler' => 'Noch kein Schlüssel eingetragen. Trage ihn in der config.php ein.']);
}

// Die Bremse zuerst: So kann auch niemand das Passwort in Ruhe durchprobieren.
if (!limit_frei($LIMIT_PRO_STUNDE)) {
    antwort(429, ['fehler' => 'Für diese Stunde ist Schluss. Später noch einmal versuchen.']);
}

if ($SITE_PASSWORT !== '') {
    $gesendet = isset($eingabe['passwort']) ? (string) $eingabe['passwort'] : '';
    if (!hash_equals($SITE_PASSWORT, $gesendet)) {
        antwort(401, ['fehler' => 'Falsches Passwort.']);
    }
}

$beschreibung = isset($eingabe['beschreibung']) ? trim((string) $eingabe['beschreibung']) : '';
if (mb_strlen($beschreibung) < 3 || mb_strlen($beschreibung) > 300) {
    antwort(400, ['fehler' => 'Bitte die Figur in 3 bis 300 Zeichen beschreiben.']);
}

$breite = isset($eingabe['breite']) ? (int) $eingabe['breite'] : 0;
$hoehe = isset($eingabe['hoehe']) ? (int) $eingabe['hoehe'] : 0;
$groesseErlaubt = false;
foreach ($GROESSEN as $groesse) {
    if ($groesse['breite'] === $breite && $groesse['hoehe'] === $hoehe) {
        $groesseErlaubt = true;
    }
}
if (!$groesseErlaubt) {
    antwort(400, ['fehler' => 'Diese Bildgrösse gibt es nicht.']);
}

if ($aktion === 'figur') {
    $frage = 'Draw this character as a single pixel art sprite: ' . $beschreibung . "\n"
        . 'Full body, centred, standing in a neutral pose.';

    $text = modell_fragen(system_text($breite, $hoehe), $frage, $ANTHROPIC_API_KEY, $MODELL, $AUFWAND);
    $bild = raster_saeubern(json_ausschneiden($text), $breite, $hoehe, null);

    if ($bild === null) {
        antwort(502, ['fehler' => 'Die Figur kam unbrauchbar zurück. Bitte noch einmal versuchen.']);
    }

    antwort(200, $bild);
}

if ($aktion === 'bild') {
    $id = isset($eingabe['animation']) ? (string) $eingabe['animation'] : '';
    if (!isset($ANIMATIONEN[$id])) {
        antwort(400, ['fehler' => 'Diese Bewegung gibt es nicht.']);
    }

    $haltungen = $ANIMATIONEN[$id]['haltungen'];
    $nummer = isset($eingabe['nummer']) ? (int) $eingabe['nummer'] : -1;
    if ($nummer < 0 || $nummer >= count($haltungen)) {
        antwort(400, ['fehler' => 'Dieses Bild gibt es in der Bewegung nicht.']);
    }

    $basis = raster_saeubern(
        [
            'palette' => isset($eingabe['palette']) ? $eingabe['palette'] : null,
            'frame' => isset($eingabe['bild']) ? $eingabe['bild'] : null,
        ],
        $breite,
        $hoehe,
        null
    );
    if ($basis === null) {
        antwort(400, ['fehler' => 'Zuerst eine Figur zeichnen lassen.']);
    }

    $frage = implode("\n", [
        'Here is an existing pixel art character ("' . $beschreibung . '") as palette and grid:',
        json_encode(['palette' => $basis['palette'], 'frame' => $basis['bild']]),
        '',
        'Draw frame ' . ($nummer + 1) . ' of ' . count($haltungen) . ' of an animation of this character.',
        'Pose for this frame: ' . $haltungen[$nummer] . '.',
        'It is the SAME character: same palette keys, same colours, same proportions, same size.',
        'Only the pose changes. Keep the feet on the same baseline unless the movement lifts them.',
    ]);

    $text = modell_fragen(system_text($breite, $hoehe), $frage, $ANTHROPIC_API_KEY, $MODELL, $AUFWAND);
    $bild = raster_saeubern(json_ausschneiden($text), $breite, $hoehe, $basis['palette']);

    if ($bild === null) {
        antwort(502, ['fehler' => 'Dieses Bild kam unbrauchbar zurück. Bitte noch einmal versuchen.']);
    }

    antwort(200, $bild);
}

antwort(400, ['fehler' => 'Unbekannte Anfrage.']);
