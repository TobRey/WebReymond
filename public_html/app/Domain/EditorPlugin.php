<?php

declare(strict_types=1);

namespace WebAtze\Domain;

use WebAtze\Core\{Assets, Audit, Logger};

/**
 * Der Editor als eigenes, austauschbares Stück.
 *
 * Bisher steckte der Editor im Hauptpaket. Das hiess: Jede Änderung am
 * Editor verlangte ein neues Hauptpaket, und jedes neue Hauptpaket
 * brachte einen neuen Editor mit, ob man wollte oder nicht. Beides
 * gleichzeitig auszuliefern ist genau der Fall, in dem ein Fehler im
 * einen den anderen mit umwirft.
 *
 * Jetzt sind es zwei Pakete. Das Hauptpaket bringt die Website und den
 * Serverteil des Editors; dieses Plugin bringt den Editor selbst. Einmal
 * hochgeladen bleibt es liegen – auch über ein neues Hauptpaket hinweg –
 * und lässt sich jederzeit durch eine andere Fassung ersetzen.
 *
 * ------------------------------------------------------------------
 * Warum das Plugin kein PHP enthält
 * ------------------------------------------------------------------
 *
 * Ein Feld, das ausführbaren Code entgegennimmt und in den
 * Webserver-Ordner legt, ist eine Hintertür mit Formular. Daran ändert
 * auch nichts, dass es hinter Anmeldung und geheimem Pfad liegt: Wer
 * dort hineinkommt, bekommt sonst nicht nur Zugriff auf die
 * Verwaltung, sondern auf den ganzen Server.
 *
 * Deshalb ist die Regel hart und ohne Ausnahme: Das Plugin enthält
 * ausschliesslich JavaScript und CSS. Der Serverteil des Editors
 * (Http/EditorController.php, Kit/admin/editor.php) reist im
 * Hauptpaket.
 *
 * Der Preis dafür ist ehrlich zu nennen: Ein Editor-Update, das eine
 * neue Schnittstelle braucht, kommt nicht ohne ein neues Hauptpaket
 * aus. Dafür nennt editor.json ein min_core, und ein Plugin, das mehr
 * verlangt als vorhanden ist, wird abgewiesen – statt sich halb zu
 * installieren und dann stumm zu bleiben.
 */
final class EditorPlugin
{
    /** Höchstens so gross darf das Archiv sein. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** Und höchstens so viele Einträge enthalten. */
    public const MAX_ENTRIES = 40;

    /** Was der Kern dieser Installation kann. */
    public const CORE_VERSION = '1.0.0';

    /**
     * Welche Dateien überhaupt vorkommen dürfen.
     *
     * Eine Liste erlaubter Muster und nicht eine Liste verbotener: Was
     * hier nicht steht, kommt nicht durch. Andersherum wäre jede
     * Endung, an die niemand gedacht hat, ein Loch.
     */
    private const ERLAUBT = [
        '#^assets/editor-[A-Za-z0-9_.-]+\.(js|css)$#',
        '#^assets/editor-[A-Za-z0-9_.-]+\.(js|css)\.map$#',
        '#^kit/[A-Za-z0-9_./-]+\.(js|css)$#',
    ];

    // ------------------------------------------------------------------
    // Zustand
    // ------------------------------------------------------------------

    /** Wo die Angaben zum installierten Plugin liegen. */
    public static function datei(): string
    {
        return STORAGE_DIR . '/plugins/editor/plugin.json';
    }

    /**
     * Was gerade installiert ist – oder nichts.
     *
     * @return array{name:string, version:string, installed_at:string, files:array<string,string>}|null
     */
    public static function installiert(): ?array
    {
        $datei = self::datei();

        if (!is_file($datei)) {
            return null;
        }

        $daten = json_decode((string) file_get_contents($datei), true);

        if (!is_array($daten) || !is_array($daten['files'] ?? null)) {
            return null;
        }

        // Steht es in der Liste, muss es die Datei auch geben. Sonst
        // meldet die Seite "installiert" und der Editor bleibt stumm –
        // die schlechteste aller Auskünfte.
        foreach ($daten['files'] as $pfad) {
            if (!is_file(BASE_DIR . '/assets/' . ltrim((string) $pfad, '/'))) {
                return null;
            }
        }

        return [
            'name' => (string) ($daten['name'] ?? 'Editor'),
            'version' => (string) ($daten['version'] ?? '?'),
            'installed_at' => (string) ($daten['installed_at'] ?? ''),
            'files' => $daten['files'],
        ];
    }

    /** Kann der Editor gerade benutzt werden? */
    public static function bereit(): bool
    {
        return Assets::exists('editor.js') && Assets::exists('editor.css');
    }

    // ------------------------------------------------------------------
    // Installieren
    // ------------------------------------------------------------------

    /**
     * Ein hochgeladenes Archiv einspielen.
     *
     * Geprüft wird vollständig, bevor die erste Datei geschrieben wird.
     * Ein Archiv, das zur Hälfte durchkommt, hinterlässt sonst eine
     * Installation, die weder alt noch neu ist.
     *
     * @return array{ok:bool, message:string, version:string}
     */
    public static function install(string $zipPfad): array
    {
        if (!is_file($zipPfad)) {
            return self::fehler('Es ist keine Datei angekommen.');
        }

        $groesse = (int) filesize($zipPfad);

        if ($groesse > self::MAX_BYTES) {
            return self::fehler(sprintf(
                'Das Archiv ist %s gross, erlaubt sind %s. Das ist kein Editor-Plugin.',
                self::bytes($groesse),
                self::bytes(self::MAX_BYTES)
            ));
        }

        if (!class_exists(\ZipArchive::class)) {
            return self::fehler('Dieser Server kann keine ZIP-Dateien lesen (PHP-Erweiterung zip fehlt).');
        }

        $zip = new \ZipArchive();

        if ($zip->open($zipPfad) !== true) {
            return self::fehler('Das ist keine lesbare ZIP-Datei.');
        }

        try {
            $pruefung = self::pruefen($zip);

            if (!$pruefung['ok']) {
                return $pruefung;
            }

            return self::schreiben($zip, $pruefung['manifest']);
        } finally {
            $zip->close();
        }
    }

    /**
     * Das Archiv durchsehen, ohne etwas zu schreiben.
     *
     * @return array{ok:bool, message:string, version:string, manifest:array<string,mixed>}
     */
    private static function pruefen(\ZipArchive $zip): array
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            return self::fehler($zip->numFiles . ' Einträge sind zu viele für ein Editor-Plugin.');
        }

        $roh = $zip->getFromName('editor.json');

        if ($roh === false) {
            return self::fehler(
                'In diesem Archiv fehlt editor.json. Das ist die Datei, die sagt, '
                . 'was drin ist – ohne sie ist es kein Editor-Plugin.'
            );
        }

        $manifest = json_decode((string) $roh, true);

        if (!is_array($manifest)) {
            return self::fehler('editor.json lässt sich nicht lesen.');
        }

        $version = (string) ($manifest['version'] ?? '');

        if ($version === '' || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $version) !== 1) {
            return self::fehler('In editor.json fehlt eine Version der Form 1.2.3.');
        }

        $minCore = (string) ($manifest['min_core'] ?? '0.0.0');

        if (version_compare($minCore, self::CORE_VERSION, '>')) {
            return self::fehler(sprintf(
                'Dieses Plugin braucht WebAtze %s, installiert ist %s. '
                . 'Zuerst das Hauptpaket aktualisieren – sonst fehlt dem Editor '
                . 'die Gegenstelle auf dem Server und er bliebe stumm.',
                $minCore,
                self::CORE_VERSION
            ));
        }

        $dateien = $manifest['files'] ?? null;

        if (!is_array($dateien) || $dateien === []) {
            return self::fehler('In editor.json steht nicht, welche Dateien dazugehören.');
        }

        // Jeder Eintrag im Archiv – nicht nur die, die im Manifest
        // stehen. Sonst reist eine .php-Datei einfach unerwähnt mit.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if ($name === 'editor.json' || str_ends_with($name, '/')) {
                continue;
            }

            $grund = self::wasStimmtNicht($name);

            if ($grund !== '') {
                return self::fehler($grund);
            }
        }

        // Und umgekehrt: Was im Manifest steht, muss auch drin sein und
        // die angegebene Prüfsumme haben.
        foreach ($dateien as $logisch => $pfad) {
            if (!is_string($logisch) || !is_string($pfad)) {
                return self::fehler('Die Dateiliste in editor.json hat eine unerwartete Form.');
            }

            $inhalt = $zip->getFromName($pfad);

            if ($inhalt === false) {
                return self::fehler('editor.json nennt ' . $pfad . ', im Archiv liegt die Datei nicht.');
            }

            $erwartet = (string) ($manifest['sha256'][$pfad] ?? '');

            if ($erwartet === '') {
                return self::fehler('Für ' . $pfad . ' fehlt die Prüfsumme in editor.json.');
            }

            if (!hash_equals($erwartet, hash('sha256', $inhalt))) {
                return self::fehler(
                    'Die Prüfsumme von ' . $pfad . ' stimmt nicht. Das Archiv ist '
                    . 'unterwegs beschädigt worden oder wurde verändert.'
                );
            }
        }

        return [
            'ok' => true,
            'message' => '',
            'version' => $version,
            'manifest' => $manifest,
        ];
    }

    /**
     * Warum ein Eintrag nicht durchkommt – oder ein leerer Text.
     *
     * Die Reihenfolge ist Absicht: Erst das, was ein Angriff wäre, dann
     * das, was bloss nicht hineingehört. So nennt die Meldung im
     * Zweifel den ernsteren Grund.
     */
    private static function wasStimmtNicht(string $name): string
    {
        if ($name === '') {
            return 'Das Archiv enthält einen Eintrag ohne Namen.';
        }

        if (str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name) === 1) {
            return 'Das Archiv enthält einen absoluten Pfad (' . $name . '). Das gehört dort nicht hin.';
        }

        if (str_contains($name, '..')) {
            return 'Das Archiv enthält einen Pfad, der aus dem Ordner ausbricht (' . $name . ').';
        }

        if (str_contains($name, "\0")) {
            return 'Das Archiv enthält einen Pfad mit einem Nullbyte.';
        }

        // Der Grund, aus dem es diese Prüfung gibt.
        if (preg_match('/\.(php[0-9]?|phtml|phar|htaccess|cgi|pl|py|sh)$/i', $name) === 1) {
            return 'Das Archiv enthält ausführbaren Code (' . $name . '). '
                . 'Ein Editor-Plugin besteht aus JavaScript und CSS und aus sonst nichts – '
                . 'der Serverteil kommt mit dem Hauptpaket.';
        }

        foreach (self::ERLAUBT as $muster) {
            if (preg_match($muster, $name) === 1) {
                return '';
            }
        }

        return $name . ' gehört nicht in ein Editor-Plugin. '
            . 'Erlaubt sind assets/editor-*.js, assets/editor-*.css und kit/*.';
    }

    /**
     * Die geprüften Dateien ablegen.
     *
     * @param array<string, mixed> $manifest
     * @return array{ok:bool, message:string, version:string}
     */
    private static function schreiben(\ZipArchive $zip, array $manifest): array
    {
        $alt = self::installiert();
        $ordner = dirname(self::datei());

        if (!is_dir($ordner) && !@mkdir($ordner, 0755, true) && !is_dir($ordner)) {
            return self::fehler('Der Ordner für Plugins lässt sich nicht anlegen.');
        }

        $geschrieben = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if ($name === 'editor.json' || str_ends_with($name, '/')) {
                continue;
            }

            $inhalt = $zip->getFromIndex($i);

            if ($inhalt === false) {
                return self::fehler('Die Datei ' . $name . ' liess sich nicht auspacken.');
            }

            // kit/ geht ins Auslieferungspaket für Kundenbackends,
            // alles andere in den öffentlichen Ordner.
            $ziel = str_starts_with($name, 'kit/')
                ? $ordner . '/' . $name
                : BASE_DIR . '/' . $name;

            if (!is_dir(dirname($ziel))) {
                @mkdir(dirname($ziel), 0755, true);
            }

            if (@file_put_contents($ziel, $inhalt) === false) {
                return self::fehler('Die Datei ' . $name . ' liess sich nicht schreiben.');
            }

            $geschrieben[] = $name;
        }

        // In der Überlagerung stehen die Werte so, wie sie auch in
        // assets/manifest.json stünden: der Pfad *innerhalb* von
        // assets/, ohne das Verzeichnis davor. Assets::url() hängt es
        // an – stünde es hier noch einmal, käme /assets/assets/… heraus
        // und der Editor wäre wieder unauffindbar.
        $manifestForm = [];

        foreach ((array) $manifest['files'] as $logisch => $imArchiv) {
            $manifestForm[(string) $logisch] = self::ohneAssets((string) $imArchiv);
        }

        $eintrag = [
            'name' => (string) ($manifest['name'] ?? 'Editor'),
            'version' => (string) $manifest['version'],
            'min_core' => (string) ($manifest['min_core'] ?? '0.0.0'),
            'installed_at' => date('c'),
            'files' => $manifestForm,
            'entries' => $geschrieben,
        ];

        if (@file_put_contents(self::datei(), json_encode($eintrag, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            return self::fehler('Die Angaben zum Plugin liessen sich nicht speichern.');
        }

        // Erst jetzt die alten Dateien weg: Bis hierher konnte noch
        // etwas schiefgehen, und dann wäre die alte Fassung der einzige
        // funktionierende Stand gewesen.
        self::aufraeumen($alt, $eintrag);

        Assets::forget();

        Audit::log('editor.plugin.installed', (string) $eintrag['version'], [
            'dateien' => count($geschrieben),
            'vorher' => $alt['version'] ?? '—',
        ]);

        return [
            'ok' => true,
            'message' => $alt === null
                ? 'Editor ' . $eintrag['version'] . ' installiert.'
                : 'Editor ' . $alt['version'] . ' durch ' . $eintrag['version'] . ' ersetzt.',
            'version' => (string) $eintrag['version'],
        ];
    }

    /**
     * Dateien der vorigen Fassung entfernen.
     *
     * Nur die, die zur alten gehörten und in der neuen nicht mehr
     * vorkommen. Die Namen tragen einen Prüfwert, also heissen sie nach
     * jedem Build anders – ohne das hier bliebe jede Fassung liegen.
     *
     * @param array{files:array<string,string>}|null $alt
     * @param array<string, mixed> $neu
     */
    private static function aufraeumen(?array $alt, array $neu): void
    {
        if ($alt === null) {
            return;
        }

        $behalten = array_values((array) $neu['files']);

        foreach ($alt['files'] as $pfad) {
            $pfad = (string) $pfad;

            if ($pfad === '' || in_array($pfad, $behalten, true)) {
                continue;
            }

            // Nur, was tatsächlich wie eine Editor-Datei aussieht. Ein
            // fehlerhaftes plugin.json darf nicht dazu führen, dass
            // hier beliebige Dateien gelöscht werden.
            if (preg_match('#^editor-[A-Za-z0-9_.-]+\.(js|css)(\.map)?$#', $pfad) !== 1) {
                continue;
            }

            @unlink(BASE_DIR . '/assets/' . $pfad);
        }
    }

    // ------------------------------------------------------------------
    // Entfernen
    // ------------------------------------------------------------------

    /** Das Plugin wieder loswerden. */
    public static function remove(): bool
    {
        $alt = self::installiert();

        if ($alt === null) {
            @unlink(self::datei());

            return false;
        }

        self::aufraeumen($alt, ['files' => []]);

        @unlink(self::datei());

        $kit = dirname(self::datei()) . '/kit';

        if (is_dir($kit)) {
            foreach ((array) glob($kit . '/*') as $datei) {
                if (is_string($datei) && is_file($datei)) {
                    @unlink($datei);
                }
            }
        }

        Assets::forget();

        Audit::log('editor.plugin.removed', (string) $alt['version'], []);

        return true;
    }

    // ------------------------------------------------------------------

    /**
     * Die Kit-Dateien für Kundenbackends.
     *
     * @return array<string, string> Name im Kundenpaket => voller Pfad
     */
    public static function kitFiles(): array
    {
        $ordner = dirname(self::datei()) . '/kit';

        if (!is_dir($ordner)) {
            return [];
        }

        $raus = [];

        foreach ((array) glob($ordner . '/*.{js,css}', GLOB_BRACE) as $datei) {
            if (is_string($datei) && is_file($datei)) {
                $raus[basename($datei)] = $datei;
            }
        }

        return $raus;
    }

    /** Aus dem Archivpfad den Wert machen, der ins Manifest gehört. */
    private static function ohneAssets(string $pfad): string
    {
        return str_starts_with($pfad, 'assets/') ? substr($pfad, 7) : $pfad;
    }

    /** @return array{ok:bool, message:string, version:string, manifest:array<string,mixed>} */
    private static function fehler(string $text): array
    {
        Logger::warning('Editor-Plugin abgewiesen', ['grund' => $text]);

        return ['ok' => false, 'message' => $text, 'version' => '', 'manifest' => []];
    }

    private static function bytes(int $zahl): string
    {
        return $zahl >= 1024 * 1024
            ? round($zahl / 1024 / 1024, 1) . ' MB'
            : round($zahl / 1024) . ' KB';
    }
}
