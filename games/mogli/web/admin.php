<?php

declare(strict_types=1);

/**
 * Mogli – Admin-Bereich: eigene Grafik einsetzen.
 *
 *   POST admin.php  {"action":"login","code":"…"}    -> { ok, token, until }
 *   POST admin.php  {"action":"load","token":"…"}    -> { ok, pack }
 *   POST admin.php  {"action":"save","token":"…","pack":{…}} -> { ok, bytes }
 *   POST admin.php  {"action":"clear","token":"…"}   -> { ok }
 *
 * EHRLICHER HINWEIS ZUR SICHERHEIT
 *
 * 1. Der Zugangscode ist kurz. Vier Ziffern sind 10 000 Möglichkeiten – ohne
 *    Bremse in Sekunden durchprobiert. Was ihn brauchbar macht, ist die
 *    Ratenbegrenzung weiter unten, nicht seine Länge. Wer den Admin-Bereich
 *    wirklich schützen will, trägt bei ADMIN_CODE ein längeres Wort ein; die
 *    Konstante nimmt jede Zeichenkette.
 *
 * 2. Geprüft wird HIER, nicht im Browser. Im ausgelieferten JavaScript steht
 *    der Code nirgends.
 *
 * 3. Hochgeladene Bilder werden NIE als Dateien abgelegt. Alles wandert als
 *    base64 in eine einzige JSON-Datei. Damit gibt es keinen Pfad, unter dem
 *    eine als PNG getarnte .php im Webverzeichnis landen und ausgeführt werden
 *    könnte – die häufigste Art, wie Bilder-Uploads zur Übernahme eines
 *    Webspace führen. Zusätzlich wird die PNG-Signatur geprüft, nicht nur der
 *    MIME-Typ im Text davor.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

// ---------------------------------------------------------------------------
// Einstellungen
// ---------------------------------------------------------------------------

/**
 * Der Zugangscode. BITTE ÄNDERN – jede Zeichenkette ist erlaubt, und ein
 * längeres Wort ist der einzige wirkliche Schutz.
 */
const ADMIN_CODE = '6713';

/**
 * Beliebiger Zufallstext. Daraus werden die Sitzungsmarken abgeleitet und die
 * IP-Adressen für die Ratenbegrenzung gehasht. BITTE EINMALIG ÄNDERN.
 */
const ADMIN_SALT = 'bitte-aendern-in-etwas-zufaelliges';

/** Dieselbe Ablage wie die Bestenliste. */
const DATA_DIR = __DIR__ . '/data';

const MAX_PACK_BYTES   = 5 * 1024 * 1024;  // deckt sich mit LIMITS in assetRules.js
const MAX_IMAGE_BYTES  = 256 * 1024;
// Eine Hintergrundebene deckt den ganzen Bildschirm und wiegt entsprechend
// mehr als ein 32x32-Sprite. Deckt sich mit maxLayerBytes in assetRules.js.
const MAX_LAYER_BYTES  = 768 * 1024;

// --- Karten (Editor). Deckt sich mit MAP_LIMITS in src/net/mapRules.js. ---
const MAX_MAPS          = 30;
const MAX_MAP_ELEMENTS  = 600;
const MAP_MIN_COLS      = 20;
const MAP_MAX_COLS      = 600;
const MAP_MIN_ROWS      = 12;
const MAP_MAX_ROWS      = 40;
const MAP_CELLS         = [16, 32, 48];
const MAP_NAME_MAX      = 24;
const MAP_NOTE_MAX      = 200;
const MAX_MAP_BYTES     = 256 * 1024;
const MAX_MAPS_BYTES    = 2 * 1024 * 1024;
/** Die Element-Typen aus src/game/elements.js. Ein Test haelt beide Listen zusammen. */
const MAP_ELEMENT_TYPES = ['ground', 'platform', 'crumble', 'mover', 'spring', 'spikes',
    'emerald', 'key', 'door', 'portal', 'walker', 'flyer', 'checkpoint', 'flag'];
const FRAMES_PER_ANIM  = 5;
const MAX_BG_LAYERS    = 4;
const MIN_HITBOX       = 4;
const FRAME_SIZE       = 32;
const TILE_SIZE        = 16;

/** Anmeldeversuche je gehashter IP. */
const TRY_PER_10_MIN = 5;

const ANIMATIONS = ['idle', 'run', 'jump', 'fall', 'wallslide', 'land', 'vine', 'death'];

const TILES = [
    'STONE_A', 'STONE_B', 'BURIED_A', 'BURIED_B',
    'CRUMBLE_0', 'CRUMBLE_1', 'CRUMBLE_2',
    'WALL_A', 'WALL_B', 'SPIKE', 'LEAF',
    'EMERALD_0', 'EMERALD_1', 'EMERALD_2', 'EMERALD_3',
    'VINE_0', 'VINE_1',
];

/** An Wänden wird abgesprungen – ihr Kasten liegt fest. */
const FIXED_BOX_TILES = ['WALL_A', 'WALL_B'];

// ---------------------------------------------------------------------------
// Antworten
// ---------------------------------------------------------------------------

function respond(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(int $status, string $error, string $detail = ''): never
{
    $payload = ['ok' => false, 'error' => $error];
    if ($detail !== '') {
        $payload['detail'] = $detail;
    }
    respond($status, $payload);
}

// ---------------------------------------------------------------------------
// Ablage
// ---------------------------------------------------------------------------

function ensureStore(): string
{
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0775, true);
    }
    if (!is_dir(DATA_DIR) || !is_writable(DATA_DIR)) {
        fail(503, 'store_unavailable');
    }
    return DATA_DIR;
}

/**
 * Lesen-Ändern-Schreiben unter exklusiver Sperre, Abschluss mit atomarem
 * rename(). Dieselbe Vorgehensweise wie in score.php und aus demselben Grund:
 * ohne sie zerschiessen sich zwei gleichzeitige Schreibvorgänge.
 *
 * @param callable(array): ?array $mutate Rückgabe null = nichts schreiben.
 */
function withStore(string $name, callable $mutate): array
{
    $dir  = ensureStore();
    $file = $dir . '/' . $name;
    $lock = $dir . '/.lock';

    $handle = @fopen($lock, 'c');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if ($handle !== false) {
            fclose($handle);
        }
        fail(503, 'store_unavailable');
    }

    $store = [];
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false && $raw !== '') {
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) {
                $store = $parsed;
            }
        }
    }

    $changed = $mutate($store);

    if (is_array($changed)) {
        $tmp = $file . '.tmp';
        $ok  = @file_put_contents(
            $tmp,
            json_encode($changed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        if ($ok === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            flock($handle, LOCK_UN);
            fclose($handle);
            fail(503, 'store_unavailable');
        }
        $store = $changed;
    }

    flock($handle, LOCK_UN);
    fclose($handle);
    return $store;
}

// ---------------------------------------------------------------------------
// Anmeldung
// ---------------------------------------------------------------------------

function rateKey(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return substr(hash('sha256', $ip . '|' . date('Y-m-d') . '|' . ADMIN_SALT), 0, 32);
}

/**
 * Die Marke gilt bis Mitternacht und hängt am Code: wird der Code geändert,
 * sind alle alten Marken sofort wertlos.
 */
function makeToken(): string
{
    return hash_hmac('sha256', 'admin|' . date('Y-m-d'), ADMIN_SALT . '|' . ADMIN_CODE);
}

function requireToken(mixed $given): void
{
    if (!is_string($given) || !hash_equals(makeToken(), $given)) {
        fail(401, 'not_signed_in');
    }
}

/** @return array{0: bool, 1: array<string, int[]>} */
function checkRate(array $rate, string $key, int $now): array
{
    $pruned = [];
    foreach ($rate as $k => $stamps) {
        if (!is_array($stamps)) {
            continue;
        }
        $keep = array_values(array_filter($stamps, static fn($t) => is_int($t) && $t > $now - 600));
        if ($keep !== []) {
            $pruned[$k] = $keep;
        }
    }

    $mine = $pruned[$key] ?? [];
    if (count($mine) >= TRY_PER_10_MIN) {
        return [false, $pruned];
    }

    $mine[]       = $now;
    $pruned[$key] = $mine;
    return [true, $pruned];
}

function login(mixed $code): never
{
    $now     = time();
    $key     = rateKey();
    $limited = false;

    withStore('admin-rate.json', static function (array $store) use ($key, $now, &$limited): ?array {
        [$allowed, $rate] = checkRate(is_array($store['rate'] ?? null) ? $store['rate'] : [], $key, $now);
        if (!$allowed) {
            $limited = true;
            return null;
        }
        $store['rate'] = $rate;
        return $store;
    });

    if ($limited) {
        fail(429, 'rate_limited');
    }

    // hash_equals statt ===: die Laufzeit soll nicht verraten, wie viele
    // Zeichen stimmen.
    if (!is_string($code) || !hash_equals(ADMIN_CODE, $code)) {
        fail(401, 'wrong_code');
    }

    respond(200, [
        'ok'    => true,
        'token' => makeToken(),
        'until' => strtotime('tomorrow') ?: ($now + 3600),
    ]);
}

// ---------------------------------------------------------------------------
// Prüfung des Pakets (spiegelt src/net/assetRules.js)
// ---------------------------------------------------------------------------

/** Ist das eine PNG-Daten-URL mit gültiger Signatur? */
function isPngDataUrl(mixed $value): bool
{
    if (!is_string($value) || !str_starts_with($value, 'data:image/png;base64,')) {
        return false;
    }
    $base64 = substr($value, strlen('data:image/png;base64,'));
    if ($base64 === '' || preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $base64) !== 1) {
        return false;
    }
    $bytes = base64_decode(substr($base64, 0, 12), true);
    if ($bytes === false || strlen($bytes) < 8) {
        return false;
    }
    // Die ersten acht Bytes eines PNG liegen fest. Diese Prüfung ist der Grund,
    // warum hier nichts anderes als ein Bild in die Ablage kommt.
    return str_starts_with($bytes, "\x89PNG\r\n\x1a\n");
}

function dataUrlBytes(string $value): int
{
    $comma  = strpos($value, ',');
    $base64 = $comma === false ? $value : substr($value, $comma + 1);
    $padding = str_ends_with($base64, '==') ? 2 : (str_ends_with($base64, '=') ? 1 : 0);
    return intdiv(strlen($base64) * 3, 4) - $padding;
}

function checkImage(mixed $value, string $where, int $maxBytes = MAX_IMAGE_BYTES): string
{
    if (!isPngDataUrl($value)) {
        fail(400, 'not_a_png', $where);
    }
    if (dataUrlBytes($value) > $maxBytes) {
        fail(400, 'image_too_large', $where);
    }
    return $value;
}

function isInt(mixed $value, int $min, int $max): bool
{
    return is_int($value) && $value >= $min && $value <= $max;
}

/** @return array{x:int,y:int,w:int,h:int} */
function checkBox(mixed $box, int $size, string $error, string $where): array
{
    if (!is_array($box)
        || !isInt($box['x'] ?? null, 0, $size - 1)
        || !isInt($box['y'] ?? null, 0, $size - 1)
        || !isInt($box['w'] ?? null, 1, $size)
        || !isInt($box['h'] ?? null, 1, $size)
        || $box['x'] + $box['w'] > $size
        || $box['y'] + $box['h'] > $size
    ) {
        fail(400, $error, $where);
    }
    return ['x' => $box['x'], 'y' => $box['y'], 'w' => $box['w'], 'h' => $box['h']];
}

/**
 * Baut aus der Eingabe ein sauberes Paket. Nur bekannte Schlüssel überleben –
 * was hier durchkäme, läge danach in der Ablage und ginge an jeden Besucher.
 */
function cleanPack(mixed $input): array
{
    if (!is_array($input)) {
        fail(400, 'invalid_pack');
    }
    if (($input['version'] ?? null) !== 1) {
        fail(400, 'wrong_version');
    }

    $pack = ['version' => 1];

    // --- Figur ---
    $inPlayer = $input['player'] ?? null;
    if (is_array($inPlayer)) {
        $player = [];

        if (isset($inPlayer['hitbox'])) {
            $box = checkBox($inPlayer['hitbox'], FRAME_SIZE, 'invalid_hitbox', 'hitbox');
            if ($box['w'] < MIN_HITBOX || $box['h'] < MIN_HITBOX) {
                fail(400, 'hitbox_too_small');
            }
            $player['hitbox'] = $box;
        }

        if (isset($inPlayer['frames']) && is_array($inPlayer['frames'])) {
            $frames = [];
            foreach ($inPlayer['frames'] as $name => $list) {
                if (!in_array($name, ANIMATIONS, true)) {
                    fail(400, 'unknown_animation', (string) $name);
                }
                if (!is_array($list) || count($list) !== FRAMES_PER_ANIM) {
                    fail(400, 'wrong_frame_count', (string) $name);
                }
                $clean = [];
                $any   = false;
                foreach ($list as $image) {
                    if ($image === null) {
                        $clean[] = null;
                        continue;
                    }
                    $clean[] = checkImage($image, (string) $name);
                    $any     = true;
                }
                if ($any) {
                    $frames[$name] = $clean;
                }
            }
            if ($frames !== []) {
                $player['frames'] = $frames;
            }
        }

        if ($player !== []) {
            $pack['player'] = $player;
        }
    }

    // --- Kacheln ---
    $inTiles = $input['tiles'] ?? null;
    if (is_array($inTiles)) {
        $tiles = [];
        foreach ($inTiles as $name => $entry) {
            if (!in_array($name, TILES, true)) {
                fail(400, 'unknown_tile', (string) $name);
            }
            if (!is_array($entry)) {
                fail(400, 'invalid_tile', (string) $name);
            }
            $clean = [];
            if (isset($entry['image'])) {
                $clean['image'] = checkImage($entry['image'], (string) $name);
            }
            if (isset($entry['box'])) {
                if (in_array($name, FIXED_BOX_TILES, true)) {
                    fail(400, 'box_not_allowed', (string) $name);
                }
                $clean['box'] = checkBox($entry['box'], TILE_SIZE, 'invalid_box', (string) $name);
            }
            if ($clean !== []) {
                $tiles[$name] = $clean;
            }
        }
        if ($tiles !== []) {
            $pack['tiles'] = $tiles;
        }
    }

    // --- Hintergrund ---
    $inBackground = $input['background'] ?? null;
    if (is_array($inBackground) && is_array($inBackground['layers'] ?? null)) {
        $layers = $inBackground['layers'];
        if (count($layers) > MAX_BG_LAYERS) {
            fail(400, 'too_many_layers');
        }
        $clean = [];
        foreach ($layers as $layer) {
            if (!is_array($layer)) {
                fail(400, 'invalid_layer');
            }
            $speed = $layer['speed'] ?? null;
            if (!is_int($speed) && !is_float($speed)) {
                fail(400, 'invalid_speed');
            }
            if ($speed < 0 || $speed > 2) {
                fail(400, 'invalid_speed');
            }
            $clean[] = [
                'image' => checkImage($layer['image'] ?? null, 'layer', MAX_LAYER_BYTES),
                'speed' => round((float) $speed, 2),
            ];
        }
        if ($clean !== []) {
            $pack['background'] = ['layers' => $clean];
        }
    }

    // --- Elemente ------------------------------------------------------------
    if (isset($input['elements'])) {
        if (!is_array($input['elements'])) {
            fail(400, 'invalid_elements');
        }
        $cleanElements = [];
        foreach ($input['elements'] as $type => $entry) {
            if (!in_array($type, MAP_ELEMENT_TYPES, true)) {
                fail(400, 'unknown_element', (string) $type);
            }
            if (!is_array($entry)) {
                fail(400, 'invalid_element', $type);
            }
            $out = [];
            if (isset($entry['image'])) {
                $out['image'] = checkImage($entry['image'], (string) $type);
            }
            if (isset($entry['box'])) {
                $out['box'] = checkBox($entry['box'], 4096, 'invalid_box', (string) $type);
            }
            if ($out !== []) {
                $cleanElements[$type] = $out;
            }
        }
        if ($cleanElements !== []) {
            $pack['elements'] = $cleanElements;
        }
    }

    $size = strlen((string) json_encode($pack));
    if ($size > MAX_PACK_BYTES) {
        fail(413, 'pack_too_large', (string) $size);
    }

    return $pack;
}

/**
 * Prueft und bereinigt die Kartensammlung.
 *
 * Wie ueberall gilt: massgeblich ist DIESE Pruefung, die im Editor ist die
 * freundliche Vorpruefung. Die Eigenschaften je Element werden hier nur grob
 * gefasst (kurze Skalare); ihre Bedeutung prueft das Spiel beim Laden noch
 * einmal ueber mapRules.js - ein unsinniger Wert kann also gespeichert
 * werden, aber nichts kaputt machen.
 */
function cleanMaps(mixed $input): array
{
    if (!is_array($input) || !array_is_list($input)) {
        fail(400, 'invalid_maps');
    }
    if (count($input) > MAX_MAPS) {
        fail(400, 'too_many_maps');
    }

    $maps = [];
    $ids  = [];
    foreach ($input as $rawMap) {
        if (!is_array($rawMap)) {
            fail(400, 'invalid_map');
        }
        $id = $rawMap['id'] ?? null;
        if (!is_string($id) || preg_match('/^[a-z0-9-]{1,24}$/', $id) !== 1 || isset($ids[$id])) {
            fail(400, 'invalid_id');
        }
        $ids[$id] = true;

        $name = is_string($rawMap['name'] ?? null) ? trim($rawMap['name']) : '';
        $name = mb_substr(preg_replace('/[\x00-\x1f]/u', '', $name) ?? '', 0, MAP_NAME_MAX);
        if ($name === '') {
            fail(400, 'invalid_name');
        }

        $cols = $rawMap['cols'] ?? null;
        $rows = $rawMap['rows'] ?? null;
        $cell = $rawMap['cell'] ?? null;
        if (!isInt($cols, MAP_MIN_COLS, MAP_MAX_COLS) || !isInt($rows, MAP_MIN_ROWS, MAP_MAX_ROWS)
            || !in_array($cell, MAP_CELLS, true)) {
            fail(400, 'invalid_size', $id);
        }

        $spawn = $rawMap['spawn'] ?? null;
        if (!is_array($spawn) || !isInt($spawn['x'] ?? null, 0, $cols - 1)
            || !isInt($spawn['y'] ?? null, 0, $rows - 1)) {
            fail(400, 'invalid_spawn', $id);
        }

        $rawElements = $rawMap['elements'] ?? null;
        if (!is_array($rawElements) || !array_is_list($rawElements)) {
            fail(400, 'invalid_elements', $id);
        }
        if (count($rawElements) > MAX_MAP_ELEMENTS) {
            fail(400, 'too_many_elements', $id);
        }

        $elements = [];
        $seen     = [];
        $flags    = 0;
        foreach ($rawElements as $raw) {
            if (!is_array($raw)) {
                fail(400, 'invalid_element', $id);
            }
            $eid  = $raw['id'] ?? null;
            $type = $raw['type'] ?? null;
            if (!is_string($eid) || preg_match('/^[a-z0-9-]{1,24}$/', $eid) !== 1 || isset($seen[$eid])) {
                fail(400, 'invalid_element_id', $id);
            }
            $seen[$eid] = true;
            if (!in_array($type, MAP_ELEMENT_TYPES, true)) {
                fail(400, 'unknown_type', (string) $type);
            }
            if ($type === 'flag') {
                $flags += 1;
            }
            $x = $raw['x'] ?? null;
            $y = $raw['y'] ?? null;
            $w = $raw['w'] ?? 1;
            $h = $raw['h'] ?? 1;
            if (!isInt($x, 0, $cols - 1) || !isInt($y, 0, $rows - 1)
                || !isInt($w, 1, $cols) || !isInt($h, 1, $rows)
                || $x + $w > $cols || $y + $h > $rows) {
                fail(400, 'element_out_of_bounds', $type);
            }

            $note = is_string($raw['note'] ?? null) ? trim($raw['note']) : '';
            $note = mb_substr(preg_replace('/[\x00-\x1f]/u', '', $note) ?? '', 0, MAP_NOTE_MAX);

            $props = [];
            if (is_array($raw['props'] ?? null)) {
                foreach ($raw['props'] as $key => $value) {
                    if (!is_string($key) || preg_match('/^[a-zA-Z]{1,16}$/', $key) !== 1) {
                        continue;
                    }
                    if (is_int($value) || is_float($value) || is_bool($value)) {
                        $props[$key] = $value;
                    } elseif (is_string($value) && preg_match('/^[a-z0-9-]{0,24}$/', $value) === 1) {
                        $props[$key] = $value;
                    }
                }
            }

            $elements[] = ['id' => $eid, 'type' => $type, 'x' => $x, 'y' => $y,
                'w' => $w, 'h' => $h, 'props' => (object) $props, 'note' => $note];
        }

        if ($flags !== 1) {
            fail(400, 'needs_one_flag', $id);
        }

        $map = ['version' => 1, 'id' => $id, 'name' => $name, 'cols' => $cols, 'rows' => $rows,
            'cell' => $cell, 'spawn' => ['x' => $spawn['x'], 'y' => $spawn['y']],
            'elements' => $elements];
        if (strlen((string) json_encode($map)) > MAX_MAP_BYTES) {
            fail(400, 'map_too_large', $id);
        }
        $maps[] = $map;
    }

    if (strlen((string) json_encode($maps)) > MAX_MAPS_BYTES) {
        fail(400, 'maps_too_large');
    }
    return $maps;
}

// ---------------------------------------------------------------------------
// Ablauf
// ---------------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    fail(405, 'method_not_allowed');
}

$body = file_get_contents('php://input');
if ($body === false || strlen($body) > MAX_PACK_BYTES + 4096) {
    fail(413, 'pack_too_large');
}
$data = json_decode($body, true);
if (!is_array($data)) {
    fail(400, 'invalid_payload');
}

$action = $data['action'] ?? '';

if ($action === 'login') {
    login($data['code'] ?? null);
}

requireToken($data['token'] ?? null);

if ($action === 'load') {
    $store = withStore('assets.json', static fn(array $s): ?array => null);
    respond(200, ['ok' => true, 'pack' => is_array($store['pack'] ?? null) ? $store['pack'] : ['version' => 1]]);
}

if ($action === 'save') {
    $pack = cleanPack($data['pack'] ?? null);
    withStore('assets.json', static function (array $store) use ($pack): array {
        $store['version'] = 1;
        $store['updated'] = time();
        $store['pack']    = $pack;
        return $store;
    });
    respond(200, ['ok' => true, 'bytes' => strlen((string) json_encode($pack))]);
}

if ($action === 'maps_load') {
    $store = withStore('maps.json', static fn(array $s): ?array => null);
    respond(200, ['ok' => true, 'maps' => is_array($store['maps'] ?? null) ? $store['maps'] : []]);
}

if ($action === 'maps_save') {
    $maps = cleanMaps($data['maps'] ?? null);
    withStore('maps.json', static function (array $store) use ($maps): array {
        $store['version'] = 1;
        $store['updated'] = time();
        $store['maps']    = $maps;
        return $store;
    });
    respond(200, ['ok' => true, 'count' => count($maps),
        'bytes' => strlen((string) json_encode($maps))]);
}

if ($action === 'clear') {
    withStore('assets.json', static function (array $store): array {
        $store['version'] = 1;
        $store['updated'] = time();
        $store['pack']    = ['version' => 1];
        return $store;
    });
    respond(200, ['ok' => true]);
}

fail(400, 'unknown_action');
