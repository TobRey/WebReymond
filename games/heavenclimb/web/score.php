<?php

declare(strict_types=1);

/**
 * HeavenClimb – weltweite Bestenliste ohne Datenbank.
 *
 * Speichert die besten 100 Ergebnisse in einer flachen JSON-Datei. Kein SQL,
 * keine Erweiterungen, keine Abhängigkeiten – läuft auf jedem Webspace mit PHP.
 *
 *   GET  score.php            -> { ok, mode, count, scores: [...] }
 *   POST score.php            -> { ok, rank, scores: [...] }   (JSON im Rumpf)
 *
 * EHRLICHER HINWEIS ZUR SICHERHEIT
 * Punkte werden im Browser berechnet. Wer will, kann mit einem einzigen
 * curl-Aufruf eine erfundene Zahl schicken – das lässt sich ohne
 * serverseitige Nachsimulation des kompletten Laufs nicht verhindern.
 * Was hier steht, hält Gelegenheitsspam und XSS über Namen ab, nicht mehr:
 *   - feste Wertebereiche und eine Plausibilitätsprüfung Punkte gegen Spielzeit
 *   - Namen ohne HTML-Zeichen, auf 12 Zeichen gekürzt
 *   - Ratenbegrenzung je IP (die IP wird nur gehasht gespeichert)
 *   - jeder Schreibvorgang unter flock() und mit atomarem rename()
 */

// Fehler nie an den Browser ausliefern: eine PHP-Warnung im Rumpf macht aus
// gültigem JSON Müll, und der Client fiele grundlos auf "lokal" zurück.
ini_set('display_errors', '0');
error_reporting(E_ALL);

// ---------------------------------------------------------------------------
// Einstellungen
// ---------------------------------------------------------------------------

/**
 * Ablageort. Standard ist ein Unterverzeichnis "data" neben dieser Datei.
 * Wenn dein Webspace das Beschreiben des Webroots verbietet oder du die Daten
 * ausserhalb des öffentlichen Bereichs willst, trage hier einen absoluten Pfad
 * ein, zum Beispiel: '/home/DEINBENUTZER/heavenclimb-data'
 */
const DATA_DIR = __DIR__ . '/data';

/**
 * Beliebiger Zufallstext. Damit werden IP-Adressen gehasht, bevor sie für die
 * Ratenbegrenzung gespeichert werden – so liegt nie eine Klartext-IP auf der
 * Platte. BITTE EINMALIG ÄNDERN.
 */
const RATE_SALT = 'bitte-aendern-in-etwas-zufaelliges';

const MAX_ENTRIES     = 100;   // so viele Ergebnisse werden aufbewahrt
const DEFAULT_TOP     = 25;    // so viele werden zurückgegeben
const NAME_MAX        = 12;
const SCORE_MAX       = 200000;
const TIME_MIN        = 3;
const TIME_MAX        = 7200;
const MAX_PER_SECOND  = 20;    // Plausibilität: Punkte je Sekunde
const SCORE_GRACE     = 100;
const RATE_PER_MINUTE = 5;
const RATE_PER_HOUR   = 60;
const MAX_BODY_BYTES  = 2048;

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

function fail(int $status, string $error): never
{
    respond($status, ['ok' => false, 'error' => $error]);
}

// ---------------------------------------------------------------------------
// Ablage
// ---------------------------------------------------------------------------

function ensureStore(): string
{
    if (!is_dir(DATA_DIR)) {
        // @ unterdrückt die Warnung, wenn das Verzeichnis nicht angelegt
        // werden darf – der Fall wird gleich darunter sauber gemeldet.
        @mkdir(DATA_DIR, 0775, true);
    }
    if (!is_dir(DATA_DIR) || !is_writable(DATA_DIR)) {
        fail(503, 'store_unavailable');
    }
    return DATA_DIR;
}

/**
 * Liest die Ablage, lässt den Aufrufer sie ändern und schreibt sie zurück –
 * alles innerhalb einer exklusiven Sperre.
 *
 * Ohne flock() überschreiben sich zwei gleichzeitige Einträge gegenseitig; das
 * passiert auf einem Webspace nicht theoretisch, sondern sobald zwei Leute
 * gleichzeitig sterben. Geschrieben wird in eine temporäre Datei und dann
 * umbenannt: rename() ist auf demselben Dateisystem atomar, ein Abbruch
 * mitten im Schreiben kann die Liste also nicht halbieren.
 *
 * @param callable(array): ?array $mutate Rückgabe null = nichts schreiben.
 * @return array die (ggf. geänderte) Ablage
 */
function withStore(callable $mutate): array
{
    $dir  = ensureStore();
    $file = $dir . '/scores.json';
    $lock = $dir . '/.lock';

    $handle = @fopen($lock, 'c');
    if ($handle === false) {
        fail(503, 'store_unavailable');
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        fail(503, 'store_unavailable');
    }

    $store = ['version' => 1, 'updated' => 0, 'scores' => [], 'rate' => []];
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false && $raw !== '') {
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) {
                $store['scores'] = isset($parsed['scores']) && is_array($parsed['scores']) ? $parsed['scores'] : [];
                $store['rate']   = isset($parsed['rate']) && is_array($parsed['rate']) ? $parsed['rate'] : [];
                $store['updated'] = isset($parsed['updated']) ? (int) $parsed['updated'] : 0;
            }
        }
    }

    $changed = $mutate($store);

    if (is_array($changed)) {
        $changed['version'] = 1;
        $changed['updated'] = time();
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
// Prüfungen (dieselben Regeln wie in src/net/scoreRules.js)
// ---------------------------------------------------------------------------

function sanitizeName(mixed $raw): string
{
    if (!is_string($raw)) {
        return 'ANON';
    }
    // Nur Buchstaben, Ziffern, Leerzeichen und - _ . überleben. Damit kann
    // kein HTML und kein Steuerzeichen in die Liste gelangen.
    $clean = preg_replace('/[^\p{L}\p{N} _.\-]/u', '', $raw) ?? '';
    $clean = preg_replace('/\s+/u', ' ', $clean) ?? '';
    $clean = trim($clean);
    $clean = mb_substr($clean, 0, NAME_MAX, 'UTF-8');
    return $clean === '' ? 'ANON' : $clean;
}

/** @return array{name: string, score: int, time: int} */
function validatePayload(array $data): array
{
    if (!isset($data['score'], $data['time'])) {
        fail(400, 'invalid_payload');
    }
    if (!is_int($data['score']) && !(is_string($data['score']) && ctype_digit($data['score']))) {
        fail(400, 'invalid_payload');
    }
    if (!is_int($data['time']) && !(is_string($data['time']) && ctype_digit($data['time']))) {
        fail(400, 'invalid_payload');
    }

    $score = (int) $data['score'];
    $time  = (int) $data['time'];

    if ($score < 1 || $score > SCORE_MAX) {
        fail(400, 'invalid_payload');
    }
    if ($time < TIME_MIN || $time > TIME_MAX) {
        fail(400, 'invalid_payload');
    }
    // Plausibilität: schneller als MAX_PER_SECOND Punkte je Sekunde kann
    // niemand dauerhaft klettern.
    if ($score > MAX_PER_SECOND * $time + SCORE_GRACE) {
        fail(400, 'invalid_payload');
    }

    return [
        'name'  => sanitizeName($data['name'] ?? null),
        'score' => $score,
        'time'  => $time,
    ];
}

// ---------------------------------------------------------------------------
// Ratenbegrenzung
// ---------------------------------------------------------------------------

function rateKey(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // Nur der Hash wird gespeichert, und er wechselt täglich: die Liste
    // enthält damit keine dauerhaft zuordenbare Personenkennung.
    return substr(hash('sha256', $ip . '|' . date('Y-m-d') . '|' . RATE_SALT), 0, 32);
}

/**
 * @param array<string, int[]> $rate
 * @return array{0: bool, 1: array<string, int[]>}
 */
function checkRate(array $rate, string $key, int $now): array
{
    $pruned = [];
    foreach ($rate as $k => $stamps) {
        if (!is_array($stamps)) {
            continue;
        }
        $keep = array_values(array_filter($stamps, static fn($t) => is_int($t) && $t > $now - 3600));
        if ($keep !== []) {
            $pruned[$k] = $keep;
        }
    }

    $mine     = $pruned[$key] ?? [];
    $lastMin  = count(array_filter($mine, static fn($t) => $t > $now - 60));
    $lastHour = count($mine);

    if ($lastMin >= RATE_PER_MINUTE || $lastHour >= RATE_PER_HOUR) {
        return [false, $pruned];
    }

    $mine[]       = $now;
    $pruned[$key] = $mine;
    return [true, $pruned];
}

// ---------------------------------------------------------------------------
// Ablauf
// ---------------------------------------------------------------------------

/** @param array<int, array<string, mixed>> $scores */
function sortScores(array $scores): array
{
    usort($scores, static function (array $a, array $b): int {
        $sa = (int) ($a['score'] ?? 0);
        $sb = (int) ($b['score'] ?? 0);
        if ($sa !== $sb) {
            return $sb <=> $sa;
        }
        return ((int) ($a['at'] ?? 0)) <=> ((int) ($b['at'] ?? 0));
    });
    return $scores;
}

function topSlice(array $scores, int $count): array
{
    return array_values(array_slice($scores, 0, $count));
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $wanted = isset($_GET['top']) ? (int) $_GET['top'] : DEFAULT_TOP;
    $wanted = max(1, min(MAX_ENTRIES, $wanted));

    $store = withStore(static fn(array $s): ?array => null);
    $scores = sortScores($store['scores']);

    respond(200, [
        'ok'     => true,
        'mode'   => 'server',
        'count'  => count($scores),
        'scores' => topSlice($scores, $wanted),
    ]);
}

if ($method !== 'POST') {
    fail(405, 'method_not_allowed');
}

$body = file_get_contents('php://input');
if ($body === false || strlen($body) > MAX_BODY_BYTES) {
    fail(400, 'invalid_payload');
}
$data = json_decode($body, true);
if (!is_array($data)) {
    fail(400, 'invalid_payload');
}

$entry = validatePayload($data);
$now   = time();
$key   = rateKey();
$rank  = 0;
$limited = false;

$store = withStore(static function (array $s) use ($entry, $now, $key, &$rank, &$limited): ?array {
    [$allowed, $rate] = checkRate(is_array($s['rate']) ? $s['rate'] : [], $key, $now);
    if (!$allowed) {
        $limited = true;
        return null;
    }

    $fresh    = ['name' => $entry['name'], 'score' => $entry['score'], 'time' => $entry['time'], 'at' => $now];
    $scores   = sortScores(array_merge($s['scores'], [$fresh]));
    $position = 0;
    foreach ($scores as $index => $row) {
        if (($row['at'] ?? null) === $now && ($row['score'] ?? null) === $entry['score']
            && ($row['name'] ?? null) === $entry['name']) {
            $position = $index + 1;
            break;
        }
    }
    $rank = $position > 0 && $position <= MAX_ENTRIES ? $position : 0;

    $s['scores'] = topSlice($scores, MAX_ENTRIES);
    $s['rate']   = $rate;
    return $s;
});

if ($limited) {
    fail(429, 'rate_limited');
}

respond(200, [
    'ok'     => true,
    'mode'   => 'server',
    'rank'   => $rank,
    'scores' => topSlice(sortScores($store['scores']), DEFAULT_TOP),
]);
