<?php

/**
 * Die Besucherzählung – auf dem Server des Kunden.
 *
 * Kein fremder Dienst, kein Cookie, keine Einwilligung nötig: Gezählt
 * wird hier, auf dem eigenen Server, und es verlässt keine einzelne
 * Person diese Datei.
 *
 * Was gespeichert wird, ist eine Summe je Tag, Seite und Herkunft.
 * Ob jemand wiederkommt, wird über eine Prüfsumme aus Adresse, Browser
 * und einem Salz erkannt, das sich täglich ändert. Damit lässt sich
 * niemand über den Tag hinaus verfolgen – auch nicht von uns.
 *
 * Eingebunden wird das über ein Bild von einem Pixel. Kein JavaScript:
 * So zählt es auch bei Leuten, die Skripte abschalten, und kostet
 * nichts an Ladezeit.
 */

declare(strict_types=1);

const GUARD = "<?php exit; ?>\n";

$dataDir = __DIR__ . '/data';
$file = $dataDir . '/zaehler.php';
$saltFile = $dataDir . '/zaehler-salz.php';

// ------------------------------------------------------- Immer antworten

// Das Bild geht als Erstes hinaus. Ob das Zählen klappt, darf den
// Besucher nicht warten lassen – und erst recht nichts kaputtmachen.
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('Content-Length: 43');

// Ein durchsichtiges GIF von 1x1 Pixel.
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
}

// -------------------------------------------------------------- Zählen

try {
    zaehlen($dataDir, $file, $saltFile);
} catch (\Throwable) {
    // Eine Zählung, die schiefgeht, ist ein Nichts. Sie darf keine
    // Fehlermeldung im Protokoll des Kunden hinterlassen.
}

exit;

// ==================================================================

function zaehlen(string $dataDir, string $file, string $saltFile): void
{
    $pfad = pfad();

    if ($pfad === null || istAutomat()) {
        return;
    }

    $tag = date('Y-m-d');
    $salz = tagesSalz($saltFile, $tag);

    // Die Prüfsumme wird nie gespeichert, nur verglichen – und sie
    // ändert sich mit dem Salz jede Nacht.
    $kennung = substr(hash('sha256',
        ($_SERVER['REMOTE_ADDR'] ?? '') . '|'
        . ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|'
        . $salz
    ), 0, 16);

    $daten = lesen($file);

    // Alles älter als 40 Tage kann weg – abgeholt wird täglich.
    foreach (array_keys($daten['tage'] ?? []) as $altTag) {
        if ($altTag < date('Y-m-d', strtotime('-40 days'))) {
            unset($daten['tage'][$altTag]);
        }
    }

    $herkunft = herkunft();

    $eintrag = &$daten['tage'][$tag][$pfad][$herkunft];
    $eintrag = $eintrag ?? ['aufrufe' => 0, 'besucher' => []];
    $eintrag['aufrufe']++;

    // Nur die Kennungen des laufenden Tages, und höchstens so viele,
    // dass die Datei nicht wächst.
    if (!in_array($kennung, $eintrag['besucher'], true)) {
        if (count($eintrag['besucher']) < 5000) {
            $eintrag['besucher'][] = $kennung;
        }
    }

    unset($eintrag);

    schreiben($file, $daten);
}

/** Welche Seite wurde aufgerufen? null, wenn es keine ist. */
function pfad(): ?string
{
    $roh = (string) ($_GET['s'] ?? '');
    $roh = parse_url($roh, PHP_URL_PATH) ?: '/';

    // Nur, was wie eine Seite dieser Website aussieht.
    if (!preg_match('#^/[a-zA-Z0-9/._-]*$#', $roh)) {
        return null;
    }

    return mb_substr($roh, 0, 190);
}

/** Woher kam der Besuch? Nur die Domain, nie die volle Adresse. */
function herkunft(): string
{
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');

    if ($ref === '') {
        return 'direkt';
    }

    $host = parse_url($ref, PHP_URL_HOST);

    if (!is_string($host) || $host === '') {
        return 'direkt';
    }

    // Verweise innerhalb der eigenen Website zählen nicht als Herkunft.
    if (strcasecmp($host, (string) ($_SERVER['HTTP_HOST'] ?? '')) === 0) {
        return 'direkt';
    }

    return mb_substr(preg_replace('/^www\./i', '', $host) ?? $host, 0, 100);
}

/** Sieht das nach einem Automaten aus? */
function istAutomat(): bool
{
    $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

    if ($ua === '') {
        return true;
    }

    foreach (['bot', 'crawl', 'spider', 'slurp', 'curl', 'wget', 'python',
              'headless', 'monitor', 'preview', 'fetch', 'scan'] as $wort) {
        if (str_contains($ua, $wort)) {
            return true;
        }
    }

    return false;
}

/**
 * Das Salz des Tages.
 *
 * Es wechselt jede Nacht. Damit lässt sich eine Person nicht über den
 * Tag hinaus wiedererkennen – auch dann nicht, wenn jemand die Datei in
 * die Hand bekommt.
 */
function tagesSalz(string $file, string $tag): string
{
    $daten = lesen($file);

    if (($daten['tag'] ?? '') === $tag && ($daten['salz'] ?? '') !== '') {
        return (string) $daten['salz'];
    }

    $salz = bin2hex(random_bytes(16));
    schreiben($file, ['tag' => $tag, 'salz' => $salz]);

    return $salz;
}

function lesen(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $raw = (string) file_get_contents($path);
    $ende = strpos($raw, '?>');

    if ($ende !== false) {
        $raw = substr($raw, $ende + 2);
    }

    $daten = json_decode(trim($raw), true);

    return is_array($daten) ? $daten : [];
}

function schreiben(string $path, array $daten): void
{
    $dir = dirname($path);

    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return;
    }

    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

    if (@file_put_contents($tmp, GUARD . json_encode($daten, JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
        return;
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return;
    }

    @chmod($path, 0640);
}
