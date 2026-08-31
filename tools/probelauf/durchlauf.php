<?php

/**
 * Ein vollständiger Durchlauf: vom Formular bis zum Paket.
 *
 * Der Grund für diese Datei ist unangenehm und soll es bleiben. Es sind
 * nacheinander drei Fehler in Betrieb gegangen, die eine einzige echte
 * Anfrage aufgedeckt hätte: die fehlende Kopfzeile mit dem
 * Arbeitsbereich, Grössenangaben im Schema, und eine Auswahl, aus der
 * eine zu grosse Grammatik wurde. Jeder davon kostete den Betreiber
 * einen Anlauf.
 *
 * Also läuft der Bau hier einmal ganz durch, gegen eine Attrappe, die
 * dieselben Regeln durchsetzt wie der echte Dienst. Was sie annimmt,
 * scheitert dort nicht mehr an der Form der Anfrage.
 *
 * Was diese Prüfung NICHT leistet: Sie sagt nichts über die Qualität
 * der Texte. Dafür braucht es den echten Dienst und einen Schlüssel.
 *
 * Aufruf:
 *   php -S 127.0.0.1:8126 tools/probelauf/api-attrappe.php &
 *   php tools/probelauf/durchlauf.php
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/public_html/app/bootstrap.php';

use WebAtze\Ai\ClaudeClient;
use WebAtze\Build\{Pipeline, SiteChecker};
use WebAtze\Core\{Config, Db, Jobs, Schema, Settings, Worker};
use WebAtze\Domain\Brief;

$fehler = 0;
$schritte = 0;

function titel(string $text): void
{
    echo "\n\033[1m", $text, "\033[0m\n";
}

function prüfe(bool $bedingung, string $was, string $zusatz = ''): void
{
    global $fehler, $schritte;
    $schritte++;

    if ($bedingung) {
        echo "  \033[32m✓\033[0m ", $was, $zusatz !== '' ? "  \033[2m$zusatz\033[0m" : '', "\n";
        return;
    }

    $fehler++;
    echo "  \033[31m✗\033[0m ", $was, $zusatz !== '' ? "  $zusatz" : '', "\n";
}

// ------------------------------------------------------------ Vorbereiten

titel('Vorbereiten');

Schema::migrate();

$echt = in_array('--echt', $argv, true);
$mitApi = $echt || in_array('--mit-api', $argv, true);

if ($echt) {
    // Gegen den richtigen Dienst. Kostet Geld – deshalb nur auf Ansage.
    prüfe(ClaudeClient::isConfigured(), 'Schlüssel aus der Konfiguration');
    prüfe(str_starts_with((string) Config::get('anthropic.api_key', ''), 'sk-ant-'),
        'Sieht nach einem echten Schlüssel aus');
    echo "  \033[33mAchtung: Dieser Durchlauf kostet echtes Guthaben.\033[0m\n";
} elseif ($mitApi) {
    // Auf die Attrappe umbiegen. Sie prüft die Anfragen streng.
    $werte = (new ReflectionClass(Config::class))->getProperty('values');
    $werte->setAccessible(true);
    $alle = $werte->getValue();
    $alle['anthropic']['api_key'] = 'sk-ant-api03-attrappe';
    $alle['anthropic']['base_url'] = 'http://127.0.0.1:8126';
    $werte->setValue(null, $alle);

    Settings::put('anthropic_workspace_id', 'wrkspc_01ATTRAPPE');

    prüfe(ClaudeClient::isConfigured(), 'Schlüssel gesetzt (Attrappe)');
    prüfe(ClaudeClient::workspaceId() !== '', 'Arbeitsbereich gesetzt', ClaudeClient::workspaceId());
} else {
    prüfe(!ClaudeClient::isConfigured(), 'Ohne Schlüssel – Übungsmodus');
}

// Alte Durchläufe wegräumen – vollständig. Ein Probelauf, der Spuren
// hinterlässt, verfälscht den nächsten und bringt build.php aus dem
// Tritt (das zählt die Designstudien).
aufräumen();

/** Alles entfernen, was ein früherer Probelauf angelegt hat. */
function aufräumen(): void
{
    foreach (Db::all("SELECT id, slug FROM projects WHERE slug LIKE 'probelauf%'") as $alt) {
        $id = (int) $alt['id'];

        foreach (['jobs', 'project_sections', 'project_pages', 'site_checks',
                  'ai_calls', 'builds', 'showcase', 'visits', 'section_changes'] as $tabelle) {
            try {
                Db::delete($tabelle, 'project_id = :p', ['p' => $id]);
            } catch (\Throwable) {
                // Tabelle kennt keine project_id – dann gibt es dort nichts.
            }
        }

        Db::delete('projects', 'id = :id', ['id' => $id]);
        delete_tree(STORAGE_DIR . '/projects/' . (string) $alt['slug']);
    }

    // Die Referenzkopien tragen den Firmennamen, nicht den Projektnamen.
    foreach (glob(STORAGE_DIR . '/showcase/alpenvolt-ag*') ?: [] as $ordner) {
        delete_tree($ordner);
    }

    Db::delete('showcase', "slug LIKE 'alpenvolt-ag%'", []);
}

// ------------------------------------------------------------- Das Formular

titel('Das Formular ausfüllen');

$eingabe = [
    'company_name' => 'AlpenVolt AG',
    'slogan' => 'Strom, der von hier kommt',
    'industry' => 'Photovoltaik und Speicher',
    'description' => 'Wir planen und montieren Photovoltaikanlagen für Ein- und '
        . 'Mehrfamilienhäuser im Berner Oberland. Dazu Batteriespeicher, Wallboxen '
        . 'und die Anmeldung beim Netzbetreiber. Seit 2014, elf Mitarbeitende.',
    'audience' => 'Hauseigentümer im Berner Oberland, dazu kleinere Gewerbebetriebe.',
    'style' => 'natural',
    'tone' => 'sie',
    'scope' => 'small',
    'locales' => 'de,fr',
    'color_mode' => 'manual',
    'color_primary' => '#1B6B4A',
    'color_secondary' => '#0E3B2A',
    'color_accent' => '#F2B705',
    'contact_email' => 'info@alpenvolt.example',
    'contact_phone' => '033 111 22 33',
    'contact_address' => "Bahnhofstrasse 12\n3800 Interlaken",
    'opening_hours' => "Mo-Fr 08:00-12:00, 13:30-17:30",
    'domain' => 'alpenvolt.example',
    'wants_admin' => '1',
    'admin_username' => 'alpenvolt',
    'admin_password' => 'ein-langes-Testpasswort-2026',
    'wants_stats' => '1',
    'report_email' => 'info@alpenvolt.example',
    'wants_support' => '1',
    'wants_docs' => '1',
    'extra_notes' => "Kunden sollen online einen Beratungstermin buchen können:\n"
        . "- Erstberatung, 45 Min\n- Dachbesichtigung, 90 Min",
];

$geprüft = Brief::validate($eingabe);

prüfe($geprüft['ok'], 'Eingaben werden angenommen',
    $geprüft['ok'] ? '' : json_encode($geprüft['errors'], JSON_UNESCAPED_UNICODE));

if (!$geprüft['ok']) {
    echo "\nOhne gültiges Formular geht es nicht weiter.\n";
    exit(1);
}

$brief = $geprüft['data'];

prüfe($brief['wants_stats'] === true, 'Besucherzählung angehakt');
prüfe($brief['wants_docs'] === true, 'Anleitung angehakt');
prüfe(\WebAtze\Build\BookingKit::wanted($brief), 'Terminbuchung wird erkannt');

// ------------------------------------------------------------ Projekt anlegen

titel('Projekt anlegen');

$theme = \WebAtze\Build\Theme::build([
    'primary' => $brief['color_primary'],
    'secondary' => $brief['color_secondary'],
    'accent' => $brief['color_accent'],
], $brief['style']);

$projektId = Db::insert('projects', [
    'slug' => 'probelauf-alpenvolt',
    'name' => $brief['company_name'],
    'status' => 'building',
    'brief' => Brief::withoutSecrets($brief),
    'theme' => $theme,
    'locale' => 'de',
    'locales' => $brief['locales'],
    'domain' => $brief['domain'],
    'wants_admin' => 1,
    'admin_username' => $brief['admin_username'],
    'admin_password_hash' => \WebAtze\Core\Crypto::hashPassword($brief['admin_password']),
    'assistant_token' => random_token(24),
    'stats_token' => random_token(32),
    'report_email' => $brief['report_email'],
    'preview_token' => '',
    'created_at' => Db::now(),
    'updated_at' => Db::now(),
]);

prüfe($projektId > 0, 'Projekt in der Datenbank', 'Nummer ' . $projektId);

$jobId = Jobs::enqueue('generate', [], $projektId);
prüfe($jobId > 0, 'Auftrag in der Warteschlange', 'Nummer ' . $jobId);

// ------------------------------------------------------------ Der Bau

titel('Bauen');

$runden = 0;
$gesehen = [];

// Ein knappes Zeitbudget, damit der Worker nach jedem Schritt zurückkommt.
// Sonst läuft der ganze Bau in einem Rutsch durch, und die einzelnen
// Schritte sind hinterher nicht mehr zu sehen.
while ($runden < 120) {
    $runden++;

    $vorher = Jobs::find($jobId);
    $schritt = (string) ($vorher['step'] ?? '');

    if ($schritt !== '' && !in_array($schritt, $gesehen, true)) {
        $gesehen[] = $schritt;
        printf("  \033[2m%3d%%  %-12s %s\033[0m\n",
            (int) $vorher['progress'], $schritt, (string) $vorher['message']);
    }

    if (in_array((string) ($vorher['status'] ?? ''), ['done', 'failed', 'cancelled'], true)) {
        break;
    }

    Worker::run(3);
}

// Die Pipeline schreibt selbst mit, welche Schritte sie gelaufen ist.
$letzter = Jobs::find($jobId);
$gesehen = array_values(array_unique(array_merge(
    $gesehen,
    (array) (($letzter['state'] ?? [])['schritte'] ?? [])
)));

$job = Jobs::find($jobId);

prüfe((string) $job['status'] === 'done', 'Auftrag ist fertig geworden',
    (string) $job['status'] . ($job['error'] !== '' ? ' – ' . (string) $job['error'] : ''));

if ((string) $job['status'] !== 'done') {
    echo "\n\033[31mAbgebrochen. Die Fehlermeldung steht oben.\033[0m\n";
    exit(1);
}

foreach (['analyse', 'plan', 'theme', 'inhalte', 'bilder', 'sprachen', 'bauen',
          'pruefen', 'vorschau', 'zip', 'referenz'] as $erwartet) {
    prüfe(in_array($erwartet, $gesehen, true), 'Schritt gelaufen: ' . $erwartet);
}

// ------------------------------------------------------------ Das Ergebnis

titel('Was herausgekommen ist');

$projekt = Db::first('SELECT * FROM projects WHERE id = :id', ['id' => $projektId]);
$dist = STORAGE_DIR . '/projects/probelauf-alpenvolt/dist';

prüfe(is_dir($dist), 'Der Ordner mit der Website ist da');

$seiten = glob($dist . '/*.html') ?: [];
prüfe(count($seiten) >= 3, 'Mehrere Seiten gebaut', count($seiten) . ' Stück');

prüfe(is_file($dist . '/index.html'), 'Startseite');
prüfe(is_file($dist . '/impressum.html'), 'Impressum');
prüfe(is_file($dist . '/datenschutz.html'), 'Datenschutz');
prüfe(is_file($dist . '/404.html'), '404-Seite');
prüfe(is_file($dist . '/robots.txt'), 'robots.txt');
prüfe(is_file($dist . '/sitemap.xml'), 'sitemap.xml');
prüfe(is_file($dist . '/.htaccess'), '.htaccess');
prüfe(is_file($dist . '/assets/css/site.css'), 'Stylesheet');
prüfe(is_dir($dist . '/fr'), 'Französische Fassung im Unterordner');
prüfe(is_file($dist . '/admin/index.php'), 'Bearbeitungsbereich');
prüfe(is_file($dist . '/contact.php'), 'Kontaktformular');
prüfe(is_file($dist . '/zaehler.php'), 'Besucherzählung');
prüfe(is_file($dist . '/zahlen.php'), 'Ausgabe der Zahlen');
prüfe(is_file($dist . '/support.php'), 'Hilfeseite');
prüfe(is_file($dist . '/doc.html'), 'Anleitung');
prüfe(is_file($dist . '/buchen.php'), 'Terminbuchung');
prüfe(is_file($dist . '/data/buchung.php'), 'Einstellungen der Buchung');

// ------------------------------------------------------------ Inhalt prüfen

titel('Die Seiten selbst');

$start = (string) file_get_contents($dist . '/index.html');

prüfe(str_starts_with(trim($start), '<!doctype html>'), 'Die Startseite ist ein vollständiges Dokument');
prüfe(str_contains($start, 'AlpenVolt'), 'Der Firmenname steht darin');
prüfe(str_contains($start, '<html lang="de">'), 'Sprache gesetzt');
prüfe(str_contains($start, 'hreflang="fr"'), 'Verweis auf die französische Fassung');
prüfe(str_contains($start, 'zaehler.php'), 'Der Zählpixel ist eingebaut');
prüfe(substr_count($start, '<h1') <= 1, 'Höchstens eine Hauptüberschrift');

// Nichts darf verraten, wie die Website entsteht.
foreach ([' KI ', 'Claude', 'Anthropic', 'ChatGPT', 'Sprachmodell', 'generiert'] as $wort) {
    $treffer = 0;
    foreach ($seiten as $datei) {
        if (str_contains((string) file_get_contents($datei), $wort)) {
            $treffer++;
        }
    }
    prüfe($treffer === 0, 'Kein Hinweis auf: ' . trim($wort));
}

// Und keine kaputten Platzhalter.
prüfe(!str_contains($start, '{{'), 'Keine offenen Platzhalter');
prüfe(!str_contains($start, 'Array'), 'Kein "Array" im Text');
prüfe(!str_contains($start, 'NULL'), 'Kein "NULL" im Text');

// ------------------------------------------------------------ Die Prüfungen

titel('Die Prüfungen nach dem Bauen');

$prüfungen = SiteChecker::latest($projektId);
prüfe($prüfungen !== [], 'Es wurde geprüft', count($prüfungen) . ' Prüfungen');

foreach ($prüfungen as $p) {
    $art = (string) $p['kind'];
    $punkte = (int) $p['score'];
    printf("  \033[2m%-10s %3d von 100  %s\033[0m\n", $art, $punkte,
        (int) $p['passed'] === 1 ? 'bestanden' : 'zu beachten');

    foreach ((array) $p['findings'] as $f) {
        if (($f['level'] ?? '') === 'error') {
            printf("      \033[33m%s\033[0m\n", mb_substr((string) $f['text'], 0, 110));
        }
    }
}

// ------------------------------------------------------------ Paket

titel('Das Paket');

$build = Db::first('SELECT * FROM builds WHERE project_id = :p ORDER BY id DESC LIMIT 1',
    ['p' => $projektId]);

prüfe($build !== null, 'Ein Paket wurde geschnürt');

if ($build !== null) {
    // zip_path ist relativ zum Ablageordner für Pakete.
    $zip = STORAGE_DIR . '/zips/' . (string) $build['zip_path'];
    prüfe(is_file($zip), 'Die Datei liegt da', basename($zip));

    if (is_file($zip)) {
        $z = new ZipArchive();
        prüfe($z->open($zip) === true, 'Und lässt sich öffnen');
        prüfe($z->numFiles > 20, 'Mit Inhalt', $z->numFiles . ' Dateien');
        prüfe($z->locateName('index.html') !== false, 'Startseite im Paket');
        $z->close();
    }
}

// ------------------------------------------------------------ Vorschau

titel('Vorschau und Referenz');

prüfe((string) $projekt['preview_token'] !== '', 'Vorschau bereitgestellt');
prüfe(is_dir(STORAGE_DIR . '/previews/' . (string) $projekt['preview_token']), 'Der Ordner dazu ist da');

$referenz = Db::first('SELECT * FROM showcase WHERE project_id = :p', ['p' => $projektId]);
prüfe($referenz !== null, 'Referenz angelegt');

if ($referenz !== null) {
    prüfe(!\WebAtze\Build\ShowcaseBuilder::leaksMethod((string) $referenz['summary']),
        'Der Referenztext verrät nichts');
}

// ------------------------------------------------------------ Kosten

titel('Kosten');

$aufrufe = Db::all('SELECT * FROM ai_calls WHERE project_id = :p', ['p' => $projektId]);
$summe = 0;
foreach ($aufrufe as $a) {
    $summe += (int) $a['cost_micro'];
}

printf("  \033[2m%d Aufrufe, zusammen %.4f US-Dollar\033[0m\n", count($aufrufe), $summe / 1_000_000);

if ($mitApi) {
    prüfe(count($aufrufe) > 0, 'Aufrufe wurden protokolliert');
}

// ------------------------------------------------------------ Schluss

titel('Ergebnis');

// Nichts stehen lassen. Der Probelauf ist eine Prüfung, kein Projekt.
if (!in_array('--behalten', $argv, true)) {
    aufräumen();
    echo "  \033[2mAufgeräumt – es bleibt nichts zurück.\033[0m\n";
}

if ($fehler === 0) {
    echo "\n  \033[32m" . $schritte . " Prüfungen bestanden – der Durchlauf ist sauber.\033[0m\n\n";
    exit(0);
}

echo "\n  \033[31m" . $fehler . " von " . $schritte . " Prüfungen gescheitert.\033[0m\n\n";
exit(1);
