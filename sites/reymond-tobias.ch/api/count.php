<?php
/**
 * Besucherzaehlung ohne Drittanbieter.
 *
 * Gespeichert werden ausschliesslich: Datum, Seitenadresse innerhalb dieser
 * Website und zwei Zahlen (Aufrufe, Besuche). Es wird keine IP-Adresse,
 * kein Browserkennzeichen und kein Wiedererkennungsmerkmal abgelegt.
 */

require __DIR__ . '/../lib/store.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    exit;
}

/* Bekannte Maschinen zaehlen nicht mit. Die Kennung wird nicht gespeichert. */
$agent = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower($_SERVER['HTTP_USER_AGENT']) : '';
if ($agent === '' || preg_match('/bot|crawl|spider|slurp|preview|headless|monitor|scan|curl|wget|python-requests/', $agent)) {
    http_response_code(204);
    exit;
}

/* Adresse saeubern: nur der Pfad, nichts sonst. */
$raw = isset($_GET['p']) ? (string) $_GET['p'] : '/';
$raw = parse_url($raw, PHP_URL_PATH);
if (!is_string($raw) || $raw === '') { $raw = '/'; }
$raw = '/' . ltrim($raw, '/');
$raw = preg_replace('#/{2,}#', '/', $raw);

if (!preg_match('#^/[A-Za-z0-9/._-]{0,120}$#', $raw) || strpos($raw, '..') !== false) {
    http_response_code(204);
    exit;
}
if (substr($raw, -1) === '/') { $raw .= 'index.html'; }
/* Die Startseite wird von PHP erzeugt, zaehlt aber unter demselben Namen. */
$raw = preg_replace('#/index\.php$#i', '/index.html', $raw);

/* Nur Seiten zaehlen, keine Dateien. */
if (!preg_match('/\.(html?|php)$/i', $raw)) {
    http_response_code(204);
    exit;
}

$isNewVisit = (isset($_GET['n']) && $_GET['n'] === '1');
$today = date('Y-m-d');
$file  = RT_PRIVATE . '/stats/' . date('Y-m') . '.php';

rt_update_locked($file, function ($data) use ($today, $raw, $isNewVisit) {
    if (!isset($data[$today]) || !is_array($data[$today])) { $data[$today] = array(); }
    if (!isset($data[$today][$raw]) || !is_array($data[$today][$raw])) {
        $data[$today][$raw] = array('v' => 0, 's' => 0);
    }
    $data[$today][$raw]['v'] = (int) $data[$today][$raw]['v'] + 1;
    if ($isNewVisit) {
        $data[$today][$raw]['s'] = (int) $data[$today][$raw]['s'] + 1;
    }

    /* Notbremse: eine Datei bleibt uebersichtlich, sonst wird nichts Neues angelegt. */
    if (count($data[$today]) > 400) {
        $data[$today] = array_slice($data[$today], 0, 400, true);
    }
    return $data;
});

http_response_code(204);
