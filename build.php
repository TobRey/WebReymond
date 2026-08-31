<?php

/**
 * Schnürt das Paket zum Hochladen.
 *
 *     php build.php
 *
 * Heraus kommt dist/webatze-<version>.zip. Der Inhalt wird im
 * cPanel-Dateimanager nach public_html entpackt und läuft sofort.
 *
 * Was nicht mitkommt: die eigene Konfiguration mit den Zugangsdaten,
 * die Datenbank des Probelaufs, Protokolle, erzeugte Kundenprojekte,
 * der Quelltext des Frontends und alles aus dem Versionsverwaltung.
 * Was mitkommt, ist genau das, was auf dem Server gebraucht wird.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Dieses Skript läuft nur auf der Kommandozeile.\n");
}

$root = __DIR__;
$source = $root . '/public_html';
$distDir = $root . '/dist';

// ------------------------------------------------------------------
// Version
// ------------------------------------------------------------------

$version = '1.0.0';
$bootstrap = (string) @file_get_contents($source . '/app/bootstrap.php');
if (preg_match("/WEBATZE_VERSION',\s*'([^']+)'/", $bootstrap, $m)) {
    $version = $m[1];
}

$target = $distDir . '/webatze-' . $version . '.zip';

// ------------------------------------------------------------------
// Was nicht mitkommt
// ------------------------------------------------------------------

/** Ganze Verzeichnisse, relativ zu public_html. */
$skipDirs = [
    'storage/projects',
    'storage/zips',
    'storage/previews',
    'storage/uploads',
    'storage/logs',
    'storage/tmp',
];

/** Einzelne Dateien. */
$skipFiles = [
    'app/config.php',
];

/** Muster, die nirgends mitkommen. */
$skipPatterns = [
    '/\.git/',
    '/\.DS_Store$/',
    '/Thumbs\.db$/',
    '/\.sqlite(-shm|-wal)?$/',
    '/\.log$/',
    '/\.tmp$/',
    '/~$/',
];

// ------------------------------------------------------------------
// Vorprüfung
// ------------------------------------------------------------------

$probleme = [];

if (!is_dir($source)) {
    $probleme[] = 'Das Verzeichnis public_html fehlt.';
}
if (!is_file($source . '/index.php')) {
    $probleme[] = 'index.php fehlt.';
}
if (!is_file($source . '/install.php')) {
    $probleme[] = 'install.php fehlt – ohne sie lässt sich nichts einrichten.';
}
if (!is_file($source . '/assets/manifest.json')) {
    $probleme[] = 'Das Frontend ist nicht gebaut. Zuerst: cd frontend && npm run build';
}
if (!class_exists('ZipArchive')) {
    $probleme[] = 'Die PHP-Erweiterung zip fehlt.';
}

// Ein Paket mit fehlerhaftem PHP wäre nutzlos – lieber hier merken.
$fehlerhaft = [];
foreach (dateien($source, $skipDirs, $skipFiles, $skipPatterns) as $absolut => $relativ) {
    if (!str_ends_with($relativ, '.php')) {
        continue;
    }

    $ausgabe = [];
    $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($absolut) . ' 2>&1', $ausgabe, $status);

    if ($status !== 0) {
        $fehlerhaft[] = $relativ . ': ' . trim(implode(' ', $ausgabe));
    }
}

if ($fehlerhaft !== []) {
    $probleme[] = count($fehlerhaft) . ' PHP-Datei(en) mit Syntaxfehlern:';
    foreach (array_slice($fehlerhaft, 0, 5) as $f) {
        $probleme[] = '    ' . $f;
    }
}

if ($probleme !== []) {
    fwrite(STDERR, "\nDas Paket wurde nicht gebaut:\n\n");
    foreach ($probleme as $problem) {
        fwrite(STDERR, '  • ' . $problem . "\n");
    }
    fwrite(STDERR, "\n");
    exit(1);
}

// ------------------------------------------------------------------
// Packen
// ------------------------------------------------------------------

@mkdir($distDir, 0755, true);
@unlink($target);

$zip = new ZipArchive();

if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Das Paket liess sich nicht anlegen: {$target}\n");
    exit(1);
}

$dateien = 0;
$bytes = 0;

foreach (dateien($source, $skipDirs, $skipFiles, $skipPatterns) as $absolut => $relativ) {
    $zip->addFile($absolut, $relativ);
    $dateien++;
    $bytes += (int) filesize($absolut);
}

// Die leeren Ablageordner müssen mit, sonst fehlen sie auf dem Server.
foreach (['projects', 'zips', 'previews', 'uploads', 'logs', 'tmp'] as $ordner) {
    $zip->addEmptyDir('storage/' . $ordner);
    $zip->addFromString('storage/' . $ordner . '/.gitkeep', '');
    $dateien++;
}

// Eine Beispielkonfiguration statt der echten.
if (is_file($source . '/app/config.example.php')) {
    $dateien++;
}

$zip->setArchiveComment(
    "WebAtze {$version}\n\n"
    . "Inhalt im cPanel-Dateimanager nach public_html entpacken,\n"
    . "danach install.php im Browser aufrufen."
);

$zip->close();

// ------------------------------------------------------------------
// Nachprüfen
// ------------------------------------------------------------------

$prüfung = new ZipArchive();
if ($prüfung->open($target) !== true) {
    fwrite(STDERR, "Das fertige Paket lässt sich nicht öffnen.\n");
    exit(1);
}

$fehlend = [];
foreach ([
    'index.php',
    'install.php',
    'worker.php',
    '.htaccess',
    'app/bootstrap.php',
    'app/config.example.php',
    'app/Core/Kernel.php',
    'app/Kit/admin/index.php',
    'app/Kit/site/php/contact.php',
    'assets/manifest.json',
] as $pflicht) {
    if ($prüfung->locateName($pflicht) === false) {
        $fehlend[] = $pflicht;
    }
}

// Und nichts, was nicht hineingehört.
$verräterisch = [];
for ($i = 0; $i < $prüfung->numFiles; $i++) {
    $name = (string) $prüfung->getNameIndex($i);

    // Die leeren Ablageordner samt .gitkeep gehören hinein – ihr
    // Inhalt aus dem Probelauf nicht.
    $istPlatzhalter = str_ends_with($name, '/.gitkeep') || str_ends_with($name, '/');

    if ($name === 'app/config.php'
        || str_contains($name, '.sqlite')
        || str_ends_with($name, '.log')
        || (!$istPlatzhalter && preg_match('#^storage/(projects|zips|previews|uploads|logs|tmp)/#', $name))
    ) {
        $verräterisch[] = $name;
    }
}

$prüfung->close();

if ($fehlend !== [] || $verräterisch !== []) {
    fwrite(STDERR, "\nDas Paket ist nicht in Ordnung:\n");
    foreach ($fehlend as $f) {
        fwrite(STDERR, "  • fehlt: {$f}\n");
    }
    foreach ($verräterisch as $f) {
        fwrite(STDERR, "  • gehört nicht hinein: {$f}\n");
    }
    fwrite(STDERR, "\n");
    exit(1);
}

// ------------------------------------------------------------------
// Fertig
// ------------------------------------------------------------------

printf(
    "\n  WebAtze %s\n\n"
    . "  %s\n"
    . "  %d Dateien, %s gepackt (%s ungepackt)\n\n"
    . "  Nächster Schritt: im cPanel-Dateimanager nach public_html hochladen,\n"
    . "  entpacken und install.php im Browser aufrufen.\n\n",
    $version,
    str_replace($root . '/', '', $target),
    $dateien,
    grösse((int) filesize($target)),
    grösse($bytes)
);

exit(0);

// ==================================================================

/**
 * Alle Dateien unterhalb von $dir, die mitkommen sollen.
 *
 * @return array<string, string> absoluter Pfad => Pfad im Paket
 */
function dateien(string $dir, array $skipDirs, array $skipFiles, array $skipPatterns): array
{
    $out = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }

        $absolut = str_replace('\\', '/', $item->getPathname());
        $relativ = ltrim(substr($absolut, strlen($dir)), '/');

        foreach ($skipDirs as $skip) {
            if (str_starts_with($relativ, $skip . '/')) {
                continue 2;
            }
        }

        if (in_array($relativ, $skipFiles, true)) {
            continue;
        }

        foreach ($skipPatterns as $muster) {
            if (preg_match($muster, $relativ)) {
                continue 2;
            }
        }

        $out[$absolut] = $relativ;
    }

    ksort($out);

    return $out;
}

function grösse(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', "'") . ' MB';
    }
    return number_format($bytes / 1024, 0, ',', "'") . ' KB';
}
