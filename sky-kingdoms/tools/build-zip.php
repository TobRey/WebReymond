<?php
/**
 * Baut das Auslieferungspaket.
 *
 *   php tools/build-zip.php [zielordner]
 *
 * Das Ergebnis enthält KEINEN zusätzlichen Oberordner: Nach dem Entpacken
 * liegen index.php, install/ und die übrigen Ordner direkt vor einem.
 * Spielstände, lokale Konfiguration und Protokolle bleiben aussen vor.
 */

declare(strict_types=1);

if (!class_exists('ZipArchive')) {
    exit("Die PHP-Erweiterung zip wird benötigt.\n");
}

$root    = dirname(__DIR__);
$version = '1.0.0';
foreach (file($root . '/config/game.php') ?: [] as $line) {
    if (preg_match("/'version'\s*=>\s*'([^']+)'/", $line, $m)) {
        $version = $m[1];
        break;
    }
}

$targetDir = $argv[1] ?? dirname($root);
$zipPath   = rtrim($targetDir, '/') . '/sky-kingdoms-' . $version . '.zip';

// Was nicht mit ausgeliefert wird
$skipDirs = [
    '.git', 'node_modules', 'storage/data/users', 'storage/data/index',
    'storage/data/meta', 'storage/data/limits', 'storage/data/resets',
    'storage/data/alliances', 'storage/data/trades', 'storage/data/attacks',
    'storage/data/logs', 'storage/backups', 'storage/sessions', 'storage/cache',
];
$skipFiles = ['config/config.php', '.DS_Store', 'smoke-run.log', 'browser-test.log'];

@unlink($zipPath);
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    exit("Die ZIP-Datei konnte nicht angelegt werden: $zipPath\n");
}

$items = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$files = 0;
$bytes = 0;

foreach ($items as $item) {
    /** @var SplFileInfo $item */
    $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));

    // Schutzdateien werden IMMER mitgenommen – auch aus übersprungenen Ordnern.
    // Sonst käme z. B. storage/sessions ohne .htaccess beim Kunden an.
    $isGuard = str_ends_with($relative, '/.htaccess')
        || str_ends_with($relative, '/index.html')
        || $relative === '.htaccess';

    if (!$isGuard) {
        foreach ($skipDirs as $skip) {
            if ($relative === $skip || str_starts_with($relative, $skip . '/')) {
                continue 2;
            }
        }
    }
    if (in_array($relative, $skipFiles, true)) {
        continue;
    }
    // Laufzeitdaten: nur die Schutzdateien mitnehmen
    // Laufzeitdaten (Spielstände, Protokolle, Sitzungen) bleiben draussen
    if (!$isGuard && preg_match('#^storage/.+\.(php|json|jsonl|log|bak|lock|zip)$#', $relative)) {
        continue;
    }

    if ($item->isDir()) {
        $zip->addEmptyDir($relative);
        continue;
    }

    $zip->addFile($item->getPathname(), $relative);
    $files++;
    $bytes += $item->getSize();
}

// Leere Laufzeitordner ausdrücklich anlegen, damit sie nach dem Entpacken da sind
foreach (['storage/data', 'storage/logs', 'storage/cache', 'storage/sessions', 'storage/backups'] as $dir) {
    $zip->addEmptyDir($dir);
}

$zip->setArchiveComment(
    "Sky Kingdoms " . $version . "\n"
    . "Entpacken, /install/ im Browser aufrufen, fertig.\n"
    . "Benötigt PHP 8.2+ mit json, mbstring und session. Keine Datenbank nötig."
);

$zip->close();

printf(
    "Fertig: %s\n%d Dateien, %.1f MB unkomprimiert, %.1f MB als ZIP\n",
    $zipPath,
    $files,
    $bytes / 1048576,
    filesize($zipPath) / 1048576
);

// Gegenprobe: Liegt index.php wirklich auf oberster Ebene?
$check = new ZipArchive();
$check->open($zipPath);
$hasIndex   = $check->locateName('index.php') !== false;
$hasInstall = $check->locateName('install/index.php') !== false;
$hasConfig  = $check->locateName('config/config.php') !== false;
$check->close();

echo $hasIndex   ? "index.php liegt auf oberster Ebene.\n"        : "FEHLER: index.php fehlt!\n";
echo $hasInstall ? "install/index.php ist enthalten.\n"           : "FEHLER: install/ fehlt!\n";
echo $hasConfig  ? "FEHLER: config/config.php ist enthalten!\n"    : "Keine lokale Konfiguration enthalten.\n";

// Jeder Laufzeitordner muss seine Schutzdateien mitbringen
$check2 = new ZipArchive();
$check2->open($zipPath);
$missing = [];
foreach (['storage', 'storage/data', 'storage/logs', 'storage/cache', 'storage/sessions', 'storage/backups',
          'app', 'config', 'tests', 'tools'] as $dir) {
    if ($check2->locateName($dir . '/.htaccess') === false) {
        $missing[] = $dir;
    }
}
$check2->close();

echo $missing === []
    ? "Alle geschützten Ordner bringen ihre .htaccess mit.\n"
    : "FEHLER: Schutzdatei fehlt in: " . implode(', ', $missing) . "\n";
