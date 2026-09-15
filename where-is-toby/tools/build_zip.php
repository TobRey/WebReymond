<?php
/**
 * Baut das Installationspaket where-is-toby-<version>.zip aus src/.
 * Aufruf: php tools/build_zip.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$source = $root . '/src';
$distDir = $root . '/dist';
$version = '1.0.0';

/*
 * Zwei Varianten:
 *   Standard        - mit Installationsassistent (install.php)
 *   ohne-installer  - richtet sich beim ersten Seitenaufruf selbst ein
 */
$withoutInstaller = in_array('--no-installer', $argv, true);
$zipName = $withoutInstaller
    ? 'where-is-toby-' . $version . '-ohne-installer.zip'
    : 'where-is-toby-' . $version . '.zip';
$zipPath = $distDir . '/' . $zipName;

@mkdir($distDir, 0755, true);
if (is_file($zipPath)) {
    unlink($zipPath);
}

/* Dateien, die niemals ins Paket gehoeren */
$blockedFiles = ['config.local.php', 'install.lock', '.DS_Store', 'Thumbs.db', 'router.php'];
if ($withoutInstaller) {
    $blockedFiles[] = 'install.php';
    $blockedFiles[] = 'install.css';
}
$blockedExtensions = ['log', 'lock', 'tmp', 'bak', 'old'];

/* Verzeichnisse, die leer (nur mit Schutzdateien) ausgeliefert werden */
$emptyDirs = [
    'storage/users', 'storage/cases', 'storage/cases/_versions', 'storage/progress',
    'storage/sessions', 'storage/logs', 'storage/backups', 'storage/settings',
    'storage/cache', 'storage/cache/ratelimit', 'storage/cache/audio', 'storage/media',
    'uploads/media', 'uploads/thumbs',
];

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "ZIP konnte nicht angelegt werden.\n");
    exit(1);
}

$added = 0;
$skipped = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $item) {
    /** @var SplFileInfo $item */
    $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($source) + 1));
    if ($relative === '') {
        continue;
    }

    if ($item->isDir()) {
        $zip->addEmptyDir($relative);
        continue;
    }

    $name = $item->getFilename();
    $extension = strtolower($item->getExtension());

    if (in_array($name, $blockedFiles, true) || in_array($extension, $blockedExtensions, true)) {
        $skipped[] = $relative;
        continue;
    }
    // Spieldaten aus Testlaeufen nicht mitliefern (Vorlagen liegen in app/Data)
    if (preg_match('~^storage/(users|cases|progress|sessions|backups|settings|cache|media)/.+~', $relative)
        && !str_ends_with($relative, 'index.php') && !str_ends_with($relative, '.gitkeep')) {
        $skipped[] = $relative;
        continue;
    }
    if (preg_match('~^uploads/(media|thumbs)/.+~', $relative)
        && !str_ends_with($relative, 'index.php') && !str_ends_with($relative, '.gitkeep')) {
        $skipped[] = $relative;
        continue;
    }

    $zip->addFile($item->getPathname(), $relative);
    $added++;
}

foreach ($emptyDirs as $dir) {
    $zip->addEmptyDir($dir);
}

$zip->setArchiveComment(
    "WHERE IS TOBY? " . $version . " - FBI-Ermittlungsspiel\n"
    . "In public_html hochladen, entpacken, Domain im Browser oeffnen.\n"
    . ($withoutInstaller
        ? "Variante ohne Installationsassistent: Die Einrichtung laeuft beim ersten Aufruf automatisch.\nAdmin: tobi / Marihuana420!! (bitte sofort aendern)"
        : "Der Installationsassistent startet automatisch.")
);
$zip->close();

/* ---------------------------------------------------------------
 |  Pruefung des Pakets
 --------------------------------------------------------------- */
$check = new ZipArchive();
$check->open($zipPath);
$names = [];
for ($i = 0; $i < $check->numFiles; $i++) {
    $names[] = (string)$check->getNameIndex($i);
}
$check->close();

$required = [
    'index.php', '.htaccess', 'README.md',
    'app/bootstrap.php', 'app/.htaccess', 'app/Core/Router.php',
    'app/Data/cases/toby.json', 'app/View/layout_game.php',
    'assets/css/base.css', 'assets/css/game.css', 'assets/css/admin.css',
    'assets/js/game.js', 'assets/js/admin.js', 'assets/js/core.js',
    'assets/img/scenes/map-millbrook.svg', 'assets/img/avatars/toby.svg',
    'assets/fonts/DejaVuSans.ttf', 'storage/.htaccess', 'uploads/.htaccess',
    'app/Data/htaccess.dist',
    'docs/INSTALLATION-GODADDY.md', 'docs/KI-ANBIETER.md',
];
if (!$withoutInstaller) {
    $required[] = 'install.php';
    $required[] = 'assets/css/install.css';
}
$missing = array_values(array_diff($required, $names));
if ($withoutInstaller && in_array('install.php', $names, true)) {
    $missing[] = 'FEHLER: install.php darf in dieser Variante nicht enthalten sein';
}
/* Die Vorlage stellt die .htaccess wieder her, wenn ein Upload-Werkzeug Punktdateien
   weglaesst. Beide Fassungen muessen deshalb identisch sein. */
$original = (string)@file_get_contents($source . '/.htaccess');
$template = (string)@file_get_contents($source . '/app/Data/htaccess.dist');
if ($original === '' || $original !== $template) {
    $missing[] = 'FEHLER: app/Data/htaccess.dist stimmt nicht mit .htaccess ueberein';
}

$forbidden = array_values(array_filter($names, static fn(string $n): bool =>
    str_contains($n, 'config.local.php') || str_ends_with($n, '.log') || str_contains($n, 'install.lock')));

printf("Paket: %s%s\n", str_replace($root . '/', '', $zipPath), $withoutInstaller ? ' (ohne Installationsassistent)' : '');
printf("Groesse: %.2f MB (%d Dateien)\n", filesize($zipPath) / 1048576, $added);
printf("Uebersprungen: %d Datei(en)\n", count($skipped));

if ($missing !== []) {
    echo "\nFEHLT im Paket:\n - " . implode("\n - ", $missing) . "\n";
}
if ($forbidden !== []) {
    echo "\nDARF NICHT im Paket sein:\n - " . implode("\n - ", $forbidden) . "\n";
}
if ($missing === [] && $forbidden === []) {
    echo "\nPruefung bestanden: alle Pflichtdateien vorhanden, keine Geheimnisse enthalten.\n";
}

exit(($missing === [] && $forbidden === []) ? 0 : 1);
