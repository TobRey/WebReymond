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

/**
 * Ganze Verzeichnisse, relativ zu public_html.
 *
 * Alles hier drin gehoert dem Betreiber, nicht dem Paket. Bei
 * storage/backups ist das keine Ordnungsfrage, sondern eine
 * Sicherheitsfrage: Dort liegt seit der Dateisicherung ein Archiv, in
 * dem app/config.php steckt - und damit der Tresorschluessel. Ein Paket,
 * das die eigene Sicherung mitnimmt, verteilt sie.
 */
$skipDirs = [
    'storage/projects',
    'storage/zips',
    'storage/previews',
    'storage/uploads',
    'storage/logs',
    'storage/tmp',
    'storage/backups',
    'storage/documents',
    'storage/briefkopf',
    'storage/cache',
    // Das installierte Editor-Plugin. Es gehoert der Installation, auf
    // der es liegt: Ein Paket, das es mitnimmt, wuerde beim naechsten
    // Aufspielen eine fremde Editorfassung mitbringen - und genau das
    // soll die Trennung in zwei Pakete ja verhindern.
    'storage/plugins',
];

/** Einzelne Dateien. */
$skipFiles = [
    'app/config.php',
    // Entsteht aus der Datenbank neu. Im Paket waere es das Aussehen
    // einer fremden Installation.
    'storage/frontend-fix.css',
    'storage/schema-version.txt',
    'storage/schema-version.txt.lock',
];

/**
 * Muster, die nirgends mitkommen.
 *
 * Der Editor steht hier, und das ist der Sinn der Sache: Er wird als
 * eigenes Paket ausgeliefert (webatze-editor-<version>.zip). Damit
 * braucht ein Editor-Update kein neues Hauptpaket - und, wichtiger, ein
 * neues Hauptpaket wirft nicht den Editor um, den der Betreiber gerade
 * installiert hat. Vorher lagen beide im selben Archiv, und jede
 * Auslieferung war zwangslaeufig eine Auslieferung von beidem.
 */
$skipPatterns = [
    '#^assets/editor-[^/]+\.(js|css)(\.map)?$#',
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
foreach ([
    'projects', 'zips', 'previews', 'uploads', 'logs', 'tmp', 'backups',
    'documents', 'cache',
    // Hier landet das Editor-Plugin. Ohne den Ordner muesste die
    // Installation ihn selbst anlegen, und das geht auf geteiltem
    // Hosting nicht immer.
    'plugins',
] as $ordner) {
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
// Das Aktualisierungspaket
// ------------------------------------------------------------------
//
// Dasselbe ohne install.php und ohne die Ablageordner. Wer schon
// eingerichtet hat, entpackt dieses hier über den bestehenden Stand:
// Konfiguration, Datenbank, Projekte und Sicherungen bleiben, wo sie
// sind, und es ist nichts neu einzugeben.
//
// Der Grund für diese zweite Datei ist eine berechtigte Beschwerde:
// Wer bei jeder Korrektur das volle Paket entpackt, hat install.php
// wieder vor sich – und tippt womöglich alles noch einmal ein.

$updateZiel = $distDir . '/webatze-update-' . $version . '.zip';
@unlink($updateZiel);

$update = new ZipArchive();

if ($update->open($updateZiel, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Das Aktualisierungspaket liess sich nicht anlegen.\n");
    exit(1);
}

$updateDateien = 0;

foreach (dateien($source, $skipDirs, $skipFiles, $skipPatterns) as $absolut => $relativ) {
    // install.php gehört nicht hinein – sie ist der ganze Punkt.
    if ($relativ === 'install.php') {
        continue;
    }

    // Und nichts aus der Ablage: Dort stehen seine Daten.
    if (str_starts_with($relativ, 'storage/')) {
        continue;
    }

    $update->addFile($absolut, $relativ);
    $updateDateien++;
}

$update->setArchiveComment(
    "WebAtze {$version} – nur zum Aktualisieren\n\n"
    . "Über eine bestehende Installation nach public_html entpacken.\n"
    . "Konfiguration, Datenbank und Projekte bleiben unangetastet.\n"
    . "install.php ist absichtlich nicht dabei."
);

$update->close();

// Nachsehen, dass wirklich nichts Heikles darin steckt.
$prüfUpdate = new ZipArchive();

if ($prüfUpdate->open($updateZiel) === true) {
    for ($i = 0; $i < $prüfUpdate->numFiles; $i++) {
        $name = (string) $prüfUpdate->getNameIndex($i);

        if ($name === 'install.php' || str_starts_with($name, 'storage/')
            || $name === 'app/config.php') {
            fwrite(STDERR, "Im Aktualisierungspaket steckt, was nicht hineingehört: {$name}\n");
            exit(1);
        }
    }
    $prüfUpdate->close();
}

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
    'app/Support/showcase-seed.php',
    'storage/showcase/nordlicht-studio/index.html',
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
        || (!$istPlatzhalter && preg_match(
            '#^storage/(projects|zips|previews|uploads|logs|tmp|backups|documents|briefkopf|cache|plugins)/#',
            $name
        ))
        || $name === 'storage/frontend-fix.css'
    ) {
        $verräterisch[] = $name;
    }
}

// Die Designstudien: Ein Paket mit halber Referenzseite wäre schlechter
// als eines ganz ohne.
$studien = 0;
for ($i = 0; $i < $prüfung->numFiles; $i++) {
    if (preg_match('#^storage/showcase/[^/]+/index\.html$#', (string) $prüfung->getNameIndex($i))) {
        $studien++;
    }
}

if ($studien !== 20) {
    $fehlend[] = 'nur ' . $studien . ' von 20 Designstudien im Paket';
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
// Das Editor-Paket
// ------------------------------------------------------------------

/**
 * Der Editor als eigenes, austauschbares Archiv.
 *
 * Es enthaelt JavaScript und CSS und sonst nichts - kein PHP, keine
 * Vorlagen, keine Konfiguration. Der Serverteil des Editors reist im
 * Hauptpaket; dieses Archiv bringt nur die Oberflaeche.
 *
 * Das ist keine Bequemlichkeit, sondern die Grundlage dafuer, das
 * Hochladen ueberhaupt anbieten zu duerfen: Ein Feld, das
 * ausfuehrbaren Code entgegennimmt und in den Webserver-Ordner legt,
 * waere eine Hintertuer mit Formular - auch hinter Anmeldung und
 * geheimem Pfad. JavaScript und CSS sind es nicht.
 *
 * editor.json nennt die Fassung, die noetige Kernfassung und je eine
 * Pruefsumme. Beim Einspielen wird alles davon geprueft, bevor die
 * erste Datei geschrieben wird.
 */

$manifest = json_decode((string) file_get_contents($source . '/assets/manifest.json'), true);
$manifest = is_array($manifest) ? $manifest : [];

$editorDateien = [];
$editorSummen = [];
$editorGroesse = 0;

foreach (['editor.js', 'editor.css'] as $logisch) {
    $pfad = (string) ($manifest[$logisch] ?? '');
    $voll = $source . '/assets/' . $pfad;

    if ($pfad === '' || !is_file($voll)) {
        fwrite(STDERR, "Der Editor fehlt im Build: {$logisch}. Zuerst: cd frontend && npm run build\n");
        exit(1);
    }

    $inhalt = (string) file_get_contents($voll);

    $editorDateien[$logisch] = 'assets/' . $pfad;
    $editorSummen['assets/' . $pfad] = hash('sha256', $inhalt);
    $editorGroesse += strlen($inhalt);
}

$editorZiel = $distDir . '/webatze-editor-' . $version . '.zip';

$editorZip = new ZipArchive();

if ($editorZip->open($editorZiel, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Das Editor-Paket laesst sich nicht anlegen.\n");
    exit(1);
}

$editorZip->addFromString('editor.json', json_encode([
    'name' => 'WebAtze Editor',
    'version' => $version,
    // Welche Kernfassung dieses Paket voraussetzt. Der Serverteil
    // liegt im Hauptpaket - ein Editor, der eine Schnittstelle
    // braucht, die es dort noch nicht gibt, wird abgewiesen statt
    // halb installiert und dann stumm.
    'min_core' => '1.0.0',
    'files' => $editorDateien,
    'sha256' => $editorSummen,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

foreach ($editorDateien as $logisch => $imPaket) {
    $editorZip->addFile($source . '/' . $imPaket, $imPaket);

    // Dasselbe noch einmal unter einem festen Namen fuer die
    // Kundenbackends. Dort gibt es kein Manifest, das den Pruefwert im
    // Dateinamen aufloesen koennte - die Datei muss heissen, wie sie
    // eingebunden wird.
    $editorZip->addFile(
        $source . '/' . $imPaket,
        'kit/' . str_replace('.', '-kit.', $logisch)
    );
}

$editorZip->close();

// Nachpruefen: kein PHP, nichts ausserhalb der erlaubten Ordner.
$editorPruefung = new ZipArchive();

if ($editorPruefung->open($editorZiel) !== true) {
    fwrite(STDERR, "Das Editor-Paket laesst sich nicht oeffnen.\n");
    exit(1);
}

for ($i = 0; $i < $editorPruefung->numFiles; $i++) {
    $name = (string) $editorPruefung->getNameIndex($i);

    $erlaubt = $name === 'editor.json'
        || preg_match('#^assets/editor-[A-Za-z0-9_.-]+\.(js|css)$#', $name) === 1
        || preg_match('#^kit/[A-Za-z0-9_.-]+\.(js|css)$#', $name) === 1;

    if (!$erlaubt) {
        fwrite(STDERR, "Im Editor-Paket steckt, was nicht hineingehoert: {$name}\n");
        exit(1);
    }
}

$editorPruefung->close();

// Und im Hauptpaket darf der Editor jetzt nicht mehr stecken.
$ohneEditor = new ZipArchive();

if ($ohneEditor->open($target) === true) {
    for ($i = 0; $i < $ohneEditor->numFiles; $i++) {
        $name = (string) $ohneEditor->getNameIndex($i);

        if (preg_match('#^assets/editor-#', $name) === 1) {
            fwrite(STDERR, "Der Editor steckt noch im Hauptpaket: {$name}\n");
            exit(1);
        }
    }

    $ohneEditor->close();
}

// ------------------------------------------------------------------
// Fertig
// ------------------------------------------------------------------

printf(
    "\n  WebAtze %s\n\n"
    . "  %s\n"
    . "  %d Dateien, %s gepackt (%s ungepackt)\n\n"
    . "  Nächster Schritt: im cPanel-Dateimanager nach public_html hochladen,\n"
    . "  entpacken und install.php im Browser aufrufen.\n\n"
    . "  Schon eingerichtet? Dann reicht dieses hier:\n"
    . '  ' . str_replace(dirname(__DIR__) . '/', '', $updateZiel) . "\n"
    . "  Darin ist keine install.php – Konfiguration und Daten bleiben.\n"
    . '  ' . $updateDateien . " Dateien, " . grösse((int) filesize($updateZiel)) . " gepackt\n\n"
    . "  Und der Editor, getrennt davon:\n"
    . '  ' . str_replace($root . '/', '', $editorZiel) . "\n"
    . '  ' . grösse((int) filesize($editorZiel)) . " gepackt (" . grösse($editorGroesse) . " ungepackt)\n"
    . "  Im Backend unter Einstellungen → Editor hochladen. Er bleibt danach\n"
    . "  liegen, auch wenn das Hauptpaket erneuert wird.\n\n",
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
