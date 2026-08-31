<?php

/**
 * Der Testlauf.
 *
 * Reines PHP, kein Rahmenwerk, kein Server, keine Installation:
 *
 *     php tests/run.php
 *
 * Geprüft wird das, was wehtut, wenn es kaputtgeht – nicht das, was
 * sich leicht prüfen lässt. Also die Sicherheitszusagen (kein
 * Pfadausbruch, kein Zugriff auf das eigene Netz, kein fremdes Projekt),
 * die Stellen, an denen schon einmal ein Fehler steckte, und die
 * Zusage, dass eine Änderung nie den Rest der Website berührt.
 */

declare(strict_types=1);

// SQLite statt MySQL: Der Testlauf soll ohne Datenbankserver auskommen.
putenv('WEBATZE_TEST=1');
$_SERVER['WEBATZE_TEST'] = '1';

require __DIR__ . '/harness.php';
require __DIR__ . '/../public_html/app/bootstrap.php';

use WebAtze\Ai\ClaudeClient;
use WebAtze\Build\{Theme, ShowcaseBuilder};
use WebAtze\Core\{Crypto, Http, Request, Router, Validator};
use WebAtze\Templates\{Catalog, Page, Renderer, Schema};

// Die Tabellen auf den neuesten Stand bringen. Ohne das scheitert der
// Testlauf in einer frischen Kopie – und in einer alten immer dann,
// wenn eine Spalte dazugekommen ist.
\WebAtze\Core\Schema::migrate();

// ==================================================================
test('Router: Adressen finden ihr Ziel', function (): void {
    $router = new Router();
    $router->get('/', 'SiteController@home');
    $router->get('/referenzen/{slug}', 'SiteController@reference');
    $router->get('/vorschau/{token}/{path:.+}', 'PreviewController@file');
    $router->post('/create/projekt/{id}/bauen', 'ProjectController@rebuild');

    is('SiteController@home', route($router, 'GET', '/'), 'Startseite');
    is('SiteController@reference', route($router, 'GET', '/referenzen/cafe-nordlicht'), 'Referenz');
    is('PreviewController@file', route($router, 'GET', '/vorschau/abc/assets/css/site.css'),
        'Vorschau mit tiefem Pfad');
    is('ProjectController@rebuild', route($router, 'POST', '/create/projekt/12/bauen'), 'Bauen');

    // Was nicht passt, darf auch nicht passen.
    is(null, route($router, 'GET', '/gibtsnicht'), 'Unbekannte Adresse');
    is(null, route($router, 'GET', '/../app/config.php'), 'Pfadausbruch');
    is(null, route($router, 'POST', '/'), 'Falsche Methode');

    // Der Fehler von damals: preg_quote über das ganze Muster machte
    // aus {slug} einen Text, der nie passte.
    is('SiteController@reference', route($router, 'GET', '/referenzen/a-b_c.d'),
        'Kennung mit Sonderzeichen');
});

// ==================================================================
test('Passwörter: Argon2id, und falsche kommen nicht durch', function (): void {
    $hash = Crypto::hashPassword('ein-gutes-Passwort-2026');

    ok(str_starts_with($hash, '$argon2id$'), 'Argon2id wird benutzt');
    ok(Crypto::checkPassword('ein-gutes-Passwort-2026', $hash), 'Richtiges Passwort');
    ok(!Crypto::checkPassword('ein-gutes-Passwort-2025', $hash), 'Falsches Passwort');
    ok(!Crypto::checkPassword('', $hash), 'Leeres Passwort');
    ok(!Crypto::checkPassword('x', 'kein-hash'), 'Kaputter Hash wirft nicht');

    // Zweimal dasselbe Passwort ergibt zwei verschiedene Hashes.
    isnt($hash, Crypto::hashPassword('ein-gutes-Passwort-2026'), 'Salz je Hash');
});

// ==================================================================
test('Verschlüsselung: FTP-Zugangsdaten liegen nicht im Klartext', function (): void {
    $secret = 'ftp-Passwort-des-Kunden';
    $box = Crypto::encrypt($secret);

    ok(!str_contains($box, $secret), 'Der Klartext steht nicht darin');
    is($secret, Crypto::decrypt($box), 'Und kommt wieder heraus');
    is(null, Crypto::decrypt('unsinn'), 'Unsinn ergibt null statt einer Ausnahme');

    // Ein verändertes Zeichen macht den Umschlag ungültig.
    $verbogen = substr($box, 0, -2) . 'xy';
    is(null, Crypto::decrypt($verbogen), 'Veränderte Daten werden erkannt');
});

// ==================================================================
test('SSRF-Schutz: das eigene Netz bleibt unerreichbar', function (): void {
    $verboten = [
        'http://127.0.0.1/admin' => 'Loopback',
        'http://localhost/' => 'localhost',
        'http://10.0.0.5/' => 'Privates Netz A',
        'http://192.168.1.1/' => 'Privates Netz C',
        'http://172.16.0.1/' => 'Privates Netz B',
        'http://169.254.169.254/latest/meta-data/' => 'Metadatendienst',
        'http://100.64.0.1/' => 'Anbieter-internes Netz',
        'http://[::1]/' => 'Loopback über IPv6',
        'file:///etc/passwd' => 'Dateizugriff',
        'gopher://example.com/' => 'Fremdes Protokoll',
    ];

    foreach ($verboten as $url => $was) {
        isnt(null, Http::assertPublicUrl($url), $was . ' wird abgewiesen');
    }

    ok(!Http::isPublicIp('127.0.0.1'), '127.0.0.1 ist nicht öffentlich');
    ok(!Http::isPublicIp('::1'), '::1 ist nicht öffentlich');
    ok(Http::isPublicIp('93.184.216.34'), 'Eine echte Adresse ist öffentlich');
});

// ==================================================================
test('Ausgabe: nichts kommt ungeprüft in die Seite', function (): void {
    $böse = '<script>alert(1)</script>';

    is('&lt;script&gt;alert(1)&lt;/script&gt;', e($böse), 'e() entschärft');
    ok(!str_contains(json_out(['x' => $böse]), '<script'), 'json_out() auch');

    // Der Renderer lässt nur bekannte Auszeichnungen durch.
    $reich = Renderer::richtext('<p>Ein <strong>fetter</strong> Text.</p><script>alert(1)</script>');
    ok(str_contains($reich, '<strong>fetter</strong>'), 'Fett bleibt');
    ok(!str_contains($reich, '<script'), 'Skript verschwindet');

    // Reiner Text wird zu Absätzen – genau das, was dem Kunden im
    // Bearbeitungsbereich versprochen wird.
    $absätze = Renderer::richtext("Erster Absatz.\n\nZweiter Absatz.");
    is(2, substr_count($absätze, '<p>'), 'Leerzeile ergibt einen neuen Absatz');

    // Und nur Adressen, die keine sind, werden zu '#'.
    is('#', Renderer::safeUrl('javascript:alert(1)'), 'javascript: wird abgewiesen');
    is('#', Renderer::safeUrl('data:text/html,<script>'), 'data: wird abgewiesen');
    is('kontakt.html', Renderer::safeUrl('kontakt.html'), 'Seiteninterner Verweis bleibt relativ');
    is('https://beispiel.ch', Renderer::safeUrl('https://beispiel.ch'), 'https bleibt');
    is('mailto:a@b.ch', Renderer::safeUrl('mailto:a@b.ch'), 'mailto bleibt');
});

// ==================================================================
test('Gestaltungswerte: nur, was in eine CSS-Regel gehört', function (): void {
    is('2rem', Renderer::cssValue('2rem'), 'Eine Länge');
    is('#ff0000', Renderer::cssValue('#ff0000'), 'Eine Farbe');
    is('', Renderer::cssValue('red; background: url(javascript:alert(1))'), 'Eingeschleuste Regel');
    is('', Renderer::cssValue('expression(alert(1))'), 'Alter Trick mit expression');
    is('', Renderer::cssValue('url(http://fremd.example/x.png)'), 'Fremde Adresse');
});

// ==================================================================
test('Vorlagen: 20 je Abschnittstyp, und jede lässt sich bauen', function (): void {
    $typen = Schema::types();
    ok(count($typen) >= 15, 'Mindestens 15 Abschnittstypen (' . count($typen) . ')');

    foreach ($typen as $typ) {
        $varianten = Catalog::forType($typ);
        is(20, count($varianten), '20 Varianten für "' . $typ . '"');

        // Jede Variante muss sich rendern lassen – auch mit leerem Inhalt.
        foreach (array_keys($varianten) as $key) {
            $html = Renderer::section([
                'id' => 1,
                'type' => $typ,
                'template_key' => $key,
                'content' => [],
                'overrides' => [],
            ], ['brand' => 'Test', 'nav' => [], 'legal' => []]);

            ok($html !== '', $typ . '/' . $key . ' erzeugt HTML');
        }
    }
});

// ==================================================================
test('Farben: Text bleibt lesbar, auch bei schwieriger Wunschfarbe', function (): void {
    // Ein sehr helles Gelb als Hauptfarbe: Als Textfarbe unlesbar.
    $theme = Theme::build(['primary' => '#ffd400', 'secondary' => '#00ff00']);
    $farben = $theme['colors'];

    $kontrast = contrast($farben['primary'], $farben['bg']);
    ok($kontrast >= 4.5, sprintf('Textfarbe hat Kontrast %.2f (mindestens 4.5)', $kontrast));

    // Auf der gelben Fläche muss die Schrift dunkel sein.
    $aufFläche = contrast($farben['on_primary'], $farben['primary_raw']);
    ok($aufFläche >= 4.5, sprintf('Schrift auf der Fläche hat Kontrast %.2f', $aufFläche));

    // Eine dunkle Farbe darf bleiben, wie sie ist.
    $dunkel = Theme::build(['primary' => '#2b1b9e'])['colors'];
    is('#2b1b9e', strtolower($dunkel['primary']), 'Eine dunkle Farbe wird nicht verändert');
});

// ==================================================================
test('Referenztexte verraten nicht, wie die Websites entstehen', function (): void {
    $verräterisch = [
        'Diese Website wurde mit Claude erstellt.',
        'Dank KI in wenigen Minuten fertig.',
        'Automatisch generiert aus einem Template.',
        'Mit künstlicher Intelligenz gebaut.',
    ];

    foreach ($verräterisch as $text) {
        ok(ShowcaseBuilder::leaksMethod($text), 'Erkannt: "' . mb_substr($text, 0, 30) . '..."');
    }

    $unverfänglich = [
        'Ein frischer Auftritt für eine Schreinerei aus Olten.',
        'Klare Struktur, schnelle Ladezeiten, gut auf dem Handy.',
    ];

    foreach ($unverfänglich as $text) {
        ok(!ShowcaseBuilder::leaksMethod($text), 'In Ordnung: "' . mb_substr($text, 0, 30) . '..."');
    }
});

// ==================================================================
test('Preise: auf der Website steht keine einzige Zahl', function (): void {
    $seiten = array_merge(
        glob(dirname(__DIR__) . '/public_html/app/Views/pages/*.php') ?: [],
        glob(dirname(__DIR__) . '/public_html/app/Views/partials/*.php') ?: []
    );
    ok($seiten !== [], 'Es gibt öffentliche Seiten zum Prüfen');

    foreach ($seiten as $datei) {
        $inhalt = (string) file_get_contents($datei);

        // Ein Preis sieht so aus: CHF 990, 990.-, 990 Franken, ab 99 €
        $muster = '/(CHF|Fr\.|EUR|€)\s*\d|(\d+[\.\,]\-)|(\d+\s*(Franken|Euro))/i';

        ok(!preg_match($muster, $inhalt), basename($datei) . ' enthält keinen Preis');
    }
});

// ==================================================================
test('Mehrsprachige Kundenseiten', function (): void {
    // Das Formular bot "Deutsch und Französisch" an, gebaut wurde
    // trotzdem nur eine Sprache: SiteBuilder las locales nie.
    $sprachen = \WebAtze\Ai\Translator::localesOf(['locales' => 'de,fr,it']);
    is(['de', 'fr', 'it'], $sprachen, 'Die Sprachen werden gelesen');
    is(['fr', 'it'], \WebAtze\Ai\Translator::extraLocales(['locales' => 'de,fr,it']),
        'Zu übersetzen sind alle ausser der ersten');

    // Unsinn darf nicht durchkommen.
    is(['de'], \WebAtze\Ai\Translator::localesOf(['locales' => 'xx,,de']),
        'Unbekannte Sprachen werden verworfen');
    is(['de'], \WebAtze\Ai\Translator::localesOf([]), 'Ohne Angabe bleibt es bei Deutsch');

    // Die Hauptsprache liegt in der Wurzel, die weiteren im Unterordner.
    is('index.html', Page::fileNameFor('/', 'de', 'de'), 'Hauptsprache in der Wurzel');
    is('fr/index.html', Page::fileNameFor('/', 'fr', 'de'), 'Französisch im Unterordner');
    is('it/kontakt.html', Page::fileNameFor('/kontakt', 'it', 'de'), 'Auch Unterseiten');

    // Aus dem Unterordner heraus zeigt ein Verweis eine Ebene hinauf.
    is('index.html', Page::relativeLink('index.html', 'de', 'de'), 'In der Wurzel ohne Umweg');
    is('../index.html', Page::relativeLink('index.html', 'fr', 'de'), 'Aus fr/ eine Ebene hoch');

    // Eine dreisprachige Seite bauen und nachsehen.
    $site = [
        'brand' => 'Test AG',
        'locale' => 'de',
        'locales' => ['de', 'fr'],
        'theme' => ['colors' => ['primary' => '#123456']],
        'pages' => [
            ['path' => '/', 'title' => 'Start', 'in_navigation' => 1,
             'translations' => ['fr' => ['title' => 'Accueil', 'meta_description' => 'Bonjour']]],
            ['path' => '/kontakt', 'title' => 'Kontakt', 'in_navigation' => 1, 'translations' => []],
        ],
        'contact' => [],
    ];

    $page = [
        'path' => '/',
        'title' => 'Start',
        'meta_description' => 'Guten Tag',
        'translations' => ['fr' => ['title' => 'Accueil', 'meta_description' => 'Bonjour']],
        'sections' => [[
            'id' => 1,
            'type' => 'hero',
            'template_key' => Catalog::defaultKey('hero'),
            'content' => ['title' => 'Guten Tag'],
            'overrides' => [],
            'translations' => ['fr' => ['title' => 'Bonjour']],
        ]],
    ];

    $de = Page::render($site, $page, '', 'de');
    $fr = Page::render($site, $page, '', 'fr');

    ok(str_contains($de, 'Guten Tag'), 'Die deutsche Fassung trägt den deutschen Text');
    ok(str_contains($fr, 'Bonjour'), 'Die französische den französischen');
    ok(!str_contains($fr, 'Guten Tag'), 'Und nicht mehr den deutschen');

    ok(str_contains($de, '<html lang="de">'), 'lang stimmt in der Wurzel');
    ok(str_contains($fr, '<html lang="fr">'), 'Und im Unterordner');

    // Aus dem Unterordner müssen Stylesheet und Skript eine Ebene hoch.
    ok(str_contains($de, 'href="assets/css/site.css"'), 'Stylesheet in der Wurzel');
    ok(str_contains($fr, 'href="../assets/css/site.css"'), 'Stylesheet aus fr/ eine Ebene hoch');
    ok(str_contains($fr, 'src="../assets/js/site.js"'), 'Skript ebenso');

    // hreflang, sonst gilt beides als doppelter Inhalt.
    ok(str_contains($de, 'hreflang="fr"'), 'Die deutsche Seite nennt die französische');
    ok(str_contains($fr, 'hreflang="de"'), 'Und umgekehrt');
    ok(str_contains($de, 'hreflang="x-default"'), 'Und eine Vorgabe für alle anderen');

    // Einsprachig bleibt einsprachig – kein Umschalter, kein hreflang.
    $einzeln = Page::render(array_merge($site, ['locales' => ['de']]), $page, '', 'de');
    ok(!str_contains($einzeln, 'hreflang='), 'Ohne zweite Sprache kein hreflang');
    ok(!str_contains($einzeln, 's-header__langs'), 'Und kein Umschalter');
});

// ==================================================================
test('Besucherzählung: es verlässt nur eine Summe den Kundenserver', function (): void {
    $zaehler = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Kit/site/php/zaehler.php'
    );
    $zahlen = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Kit/site/php/zahlen.php'
    );

    // Das Salz wechselt täglich – sonst liesse sich jemand über Wochen
    // verfolgen, und genau das soll nicht gehen.
    ok(str_contains($zaehler, 'tagesSalz'), 'Die Kennung entsteht aus einem Tagessalz');
    ok(str_contains($zaehler, "date('Y-m-d')"), 'Und das Salz hängt am Tag');

    // Was hinausgeht, sind Zahlen. Die Kennungen bleiben liegen.
    ok(str_contains($zahlen, "count((array) (\$werte['besucher'] ?? []))"),
        'Herausgegeben wird die Anzahl, nicht die Liste');
    ok(!preg_match("/'besucher' => \\\$werte\\['besucher'\\]/", $zahlen),
        'Die Kennungen selbst verlassen den Server nie');
    ok(str_contains($zahlen, 'hash_equals'), 'Der Schlüssel wird zeitunabhängig verglichen');

    // Das Bild geht vor dem Zählen hinaus: Eine kaputte Zählung darf
    // niemanden warten lassen.
    $bildPos = strpos($zaehler, 'image/gif');
    $zaehlPos = strpos($zaehler, 'function zaehlen');
    $aufrufPos = strpos($zaehler, 'zaehlen($dataDir');
    ok($bildPos !== false && $aufrufPos !== false && $bildPos < $aufrufPos,
        'Erst das Bild, dann das Zählen');
    ok(str_contains($zaehler, 'fastcgi_finish_request'), 'Die Verbindung wird vorher geschlossen');
    ok($zaehlPos !== false, 'Gezählt wird in einer eigenen Funktion');

    // Ein Fehler beim Zählen darf keine Meldung hinterlassen.
    ok(str_contains($zaehler, 'catch (\\Throwable)'), 'Ein Fehler beim Zählen bleibt folgenlos');
});

// ==================================================================
test('Wochenbericht: Zahlen und Vergleich stimmen', function (): void {
    $projekt = ['id' => 4242, 'name' => 'Bäckerei Muster', 'report_email' => 'kunde@example.org'];

    // Zwei Wochen füllen: die vergangene und die davor.
    $bis = date('Y-m-d', strtotime('yesterday'));
    $von = date('Y-m-d', strtotime($bis . ' -6 days'));
    $davor = date('Y-m-d', strtotime($von . ' -3 days'));

    \WebAtze\Core\Db::delete('visits', 'project_id = :p', ['p' => 4242]);

    \WebAtze\Build\VisitCollector::store(4242, [
        ['tag' => $bis, 'pfad' => '/', 'herkunft' => '', 'aufrufe' => 30, 'besucher' => 20],
        ['tag' => $bis, 'pfad' => '/kontakt.html', 'herkunft' => 'google.ch', 'aufrufe' => 10, 'besucher' => 8],
        ['tag' => $davor, 'pfad' => '/', 'herkunft' => '', 'aufrufe' => 20, 'besucher' => 15],
        // Unsinn muss durchfallen, nicht die Summe verfälschen.
        ['tag' => 'gestern', 'pfad' => '/', 'herkunft' => '', 'aufrufe' => 999, 'besucher' => 999],
        ['tag' => $bis, 'pfad' => '/', 'herkunft' => '', 'aufrufe' => -5, 'besucher' => -5],
    ]);

    $woche = \WebAtze\Build\VisitCollector::week(4242, $bis);

    // Die letzte Zeile ersetzt die erste (gleicher Tag, gleiche Seite,
    // gleiche Herkunft) – und -5 wird zu 0.
    is(10, $woche['aufrufe'], 'Nur die Woche zählt, und negative Werte werden zu null');
    is(20, $woche['davor']['aufrufe'], 'Die Vorwoche steht getrennt daneben');
    is('/kontakt.html', $woche['seiten'][0]['pfad'], 'Die meistbesuchte Seite steht oben');
    is('Direkt eingegeben', $woche['herkunft'][1]['name'] ?? '',
        'Ohne Verweis heisst es "Direkt eingegeben"');
    is(7, count($woche['tage']), 'Eine Woche hat sieben Tage');

    // Der Text der Nachricht: verständlich, ohne Fachwörter.
    $text = \WebAtze\Build\VisitCollector::reportText($projekt, $woche);
    ok(str_contains($text, 'Seitenaufrufe'), 'Der Bericht nennt die Aufrufe');
    ok(str_contains($text, 'Bäckerei Muster'), 'Und die Firma');
    ok(str_contains($text, 'anonym'), 'Und sagt, dass niemand wiedererkannt wird');

    foreach (['Session', 'Bounce', 'Unique', 'Pageview', 'Tracking'] as $fachwort) {
        ok(!str_contains($text, $fachwort), 'Kein Fachwort: ' . $fachwort);
    }

    \WebAtze\Core\Db::delete('visits', 'project_id = :p', ['p' => 4242]);
});

// ==================================================================
test('Sicherungen: nichts wird ausserhalb des eigenen Ordners gelöscht', function (): void {
    $quelle = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Build/Backup.php'
    );

    ok(str_contains($quelle, 'insideBackups'), 'Vor dem Löschen wird der Pfad geprüft');
    ok(str_contains($quelle, 'realpath'), 'Und zwar über den aufgelösten Pfad');

    // Der Schlüssel gehört nicht in eine Sicherung, die herumwandert.
    ok(!str_contains($quelle, "'/config.php'"), 'config.php wird nicht mitgesichert');
    ok(str_contains($quelle, 'app/config.php'), 'Und darauf wird ausdrücklich hingewiesen');

    // Die SQL-Ausgabe muss Anführungszeichen verdoppeln, sonst bricht
    // ein Firmenname mit Apostroph die ganze Datei.
    $ref = new ReflectionMethod(\WebAtze\Build\Backup::class, 'literal');
    $ref->setAccessible(true);

    is("'O''Brien'", $ref->invoke(null, "O'Brien"), 'Ein Apostroph wird verdoppelt');
    is('NULL', $ref->invoke(null, null), 'null bleibt NULL');
    is('42', $ref->invoke(null, 42), 'Zahlen ohne Anführungszeichen');
    is("'a\\\\b'", $ref->invoke(null, 'a\\b'), 'Ein Backslash wird verdoppelt');

    // Die Aufbewahrung: 14 Tage plus Monatserste.
    ok(str_contains($quelle, 'KEEP_DAILY = 14'), 'Vierzehn Tagesstände');
    ok(str_contains($quelle, 'KEEP_MONTHLY'), 'Dazu Monatsstände');
});

// ==================================================================
test('Prüfung nach dem Bauen findet, was tatsächlich falsch ist', function (): void {
    $dist = sys_get_temp_dir() . '/wa-pruefung-' . getmypid();
    @mkdir($dist . '/assets/img', 0775, true);

    file_put_contents($dist . '/index.html',
        '<html><head><script src="a.js"></script></head><body>'
        . '<a href="kontakt">Kontakt</a>'
        . '<a href="gibtsnicht.html">Kaputt</a>'
        . '<img src="assets/img/x.png">'
        . '<link href="https://fonts.googleapis.com/css2?family=Inter">'
        . '</body></html>');

    file_put_contents($dist . '/kontakt.html', '<html><body>Kontakt</body></html>');
    file_put_contents($dist . '/assets/img/x.png', str_repeat('x', 400 * 1024));

    $projekt = ['id' => 0, 'slug' => 'pruefung', 'domain' => '', 'brief' => '{}'];

    // Verweise: der interne Verweis "kontakt" findet kontakt.html
    // (so steht es in der .htaccess), "gibtsnicht.html" nicht.
    $verweise = \WebAtze\Build\SiteChecker::links($projekt, $dist, 0.1);
    $text = json_encode($verweise['findings'], JSON_UNESCAPED_UNICODE);

    ok(str_contains($text, 'gibtsnicht.html'), 'Der tote Verweis wird gefunden');
    ok(!str_contains($text, '"kontakt"'), 'Der gültige nicht');
    ok(!$verweise['passed'], 'Damit ist die Prüfung nicht bestanden');

    // Tempo: das grosse Bild und das fehlende Breite/Höhe.
    $tempo = \WebAtze\Build\SiteChecker::speed($projekt, $dist);
    $text = json_encode($tempo['findings'], JSON_UNESCAPED_UNICODE);

    ok(str_contains($text, 'x.png'), 'Das grosse Bild fällt auf');
    ok(str_contains($text, 'springt'), 'Das Bild ohne feste Grösse ebenso');
    ok(str_contains($text, 'halten die Darstellung auf'), 'Und das Skript ohne defer');

    // Recht: Impressum fehlt, und Google Fonts wird eingebunden.
    $recht = \WebAtze\Build\SiteChecker::legal($projekt, $dist);
    $text = json_encode($recht['findings'], JSON_UNESCAPED_UNICODE);

    ok(str_contains($text, 'Impressum'), 'Das fehlende Impressum fällt auf');
    ok(str_contains($text, 'Datenschutz'), 'Der fehlende Datenschutz auch');
    ok(str_contains($text, 'Google Fonts'), 'Und der fremde Dienst');
    ok(str_contains($text, 'Zustimmungsbanner'), 'Mit der Begründung, warum das stört');
    ok(!$recht['passed'], 'Auch das ist nicht bestanden');

    // Und nun dasselbe, aber richtig gemacht.
    file_put_contents($dist . '/index.html',
        '<html><head><script src="a.js" defer></script></head><body>'
        . '<a href="kontakt">Kontakt</a>'
        . '<img src="assets/img/x.png" width="800" height="600">'
        . '</body></html>');
    file_put_contents($dist . '/impressum.html', '<html><body>' . str_repeat('Angaben zur Firma. ', 30) . '</body></html>');
    file_put_contents($dist . '/datenschutz.html', '<html><body>' . str_repeat('Wie wir mit Daten umgehen. ', 30) . '</body></html>');
    unlink($dist . '/assets/img/x.png');

    $projekt['brief'] = json_encode(['contact_email' => 'a@b.ch', 'contact_address' => 'Weg 1']);

    $recht = \WebAtze\Build\SiteChecker::legal($projekt, $dist);
    ok($recht['passed'], 'Mit Impressum und ohne fremden Dienst ist es bestanden');
    is(100, $recht['score'], 'Und ohne Abzug');

    $verweise = \WebAtze\Build\SiteChecker::links($projekt, $dist, 0.1);
    ok($verweise['passed'], 'Ohne toten Verweis ebenfalls');

    delete_tree($dist);
});

// ==================================================================
test('Die Anleitung beschreibt nur, was auch gebaut wurde', function (): void {
    $site = [
        'brand' => 'Muster AG',
        'locales' => ['de'],
        'theme' => ['colors' => ['primary' => '#2b1b9e']],
        'pages' => [['path' => '/', 'title' => 'Start']],
    ];

    // Ohne Bearbeitungsbereich darf kein Wort darüber darin stehen.
    $ohne = \WebAtze\Build\DocBuilder::render(
        ['name' => 'Muster AG', 'domain' => '', 'wants_admin' => 0],
        $site,
        ['wants_docs' => true]
    );

    ok(!str_contains($ohne, 'Bilder tauschen'), 'Ohne Backend kein Kapitel dazu');
    ok(!str_contains($ohne, '/admin'), 'Und keine Adresse dorthin');
    ok(str_contains($ohne, 'keinen eigenen'), 'Stattdessen steht da, dass es keinen gibt');

    // Mit allem.
    $mit = \WebAtze\Build\DocBuilder::render(
        ['name' => 'Muster AG', 'domain' => 'muster.ch', 'wants_admin' => 1, 'admin_username' => 'muster'],
        $site,
        ['wants_docs' => true, 'wants_stats' => true, 'wants_support' => true, 'report_email' => 'a@b.ch']
    );

    ok(str_contains($mit, 'https://muster.ch/admin'), 'Mit Backend steht die Adresse darin');
    ok(str_contains($mit, 'muster.ch/support'), 'Und die der Hilfeseite');
    ok(str_contains($mit, 'Besucherzahlen'), 'Und ein Kapitel zur Zählung');

    // Kein Wort darüber, wie die Website entstanden ist.
    foreach ([' KI', 'Claude', 'Anthropic', 'generiert', 'Sprachmodell'] as $wort) {
        ok(!str_contains($mit, $wort), 'Kein Hinweis auf: ' . trim($wort));
    }

    // Der Assistent heisst beim Kunden Assistent.
    ok(str_contains($mit, 'Assistent'), 'Der Assistent heisst Assistent');

    // Die Farbe landet unmaskiert im Stylesheet – Unsinn darf da nicht durch.
    $boes = \WebAtze\Build\DocBuilder::render(
        ['name' => 'X', 'domain' => '', 'wants_admin' => 0],
        ['brand' => 'X', 'locales' => ['de'], 'pages' => [],
         'theme' => ['colors' => ['primary' => 'red;} body{display:none']]],
        ['wants_docs' => true]
    );

    ok(!str_contains($boes, 'display:none'), 'Eine erfundene Farbe kommt nicht ins Stylesheet');
    ok(str_contains($boes, '--ton: #1f2937'), 'Stattdessen greift die Vorgabe');
});

// ==================================================================
test('Geld wird in Rappen gerechnet, nicht in Kommazahlen', function (): void {
    $r = static fn (string $v): int => \WebAtze\Http\BillingController::toRappen($v);

    is(123450, $r('1234.50'), 'Franken und Rappen');
    is(1210, $r('12.10'), 'Der Fall, an dem Fliesskomma scheitert');
    is(123455, $r("1'234.55"), 'Mit Tausendertrennzeichen');
    is(123455, $r('1234,55'), 'Mit Komma statt Punkt');
    is(99000, $r('990.-'), 'Die schweizerische Schreibweise');
    is(99000, $r('990'), 'Ohne Rappen');
    is(-5025, $r('-50.25'), 'Auch negativ, für einen Abzug');
    is(0, $r('abc'), 'Unsinn ergibt null');
    is(0, $r(''), 'Leer ebenfalls');

    // Das ist der Grund für den ganzen Umweg über Zeichenketten.
    ok($r('12.10') === 1210, 'Zwölf Franken zehn sind 1210 Rappen, nicht 1209');

    // Und die Rückrichtung.
    is("1'234.55", \WebAtze\Build\DocumentBuilder::money(123455), 'Zurück in Franken, mit Tausendertrennzeichen');
    is('0.05', \WebAtze\Build\DocumentBuilder::money(5), 'Fünf Rappen');

    // Summe mit Mehrwertsteuer: 2 × 100.00 + 8 % = 216.00
    $items = [['label' => 'x', 'quantity' => 2, 'price_rappen' => 10000]];
    is(21600, \WebAtze\Build\DocumentBuilder::total($items, 8), 'Summe mit Mehrwertsteuer');
    is(20000, \WebAtze\Build\DocumentBuilder::total($items, 0), 'Und ohne');
});

// ==================================================================
test('Das PDF ist eine gültige Datei', function (): void {
    $pdf = new \WebAtze\Core\Pdf('Prüfung');
    $pdf->text(50, 60, 'Rechnung mit Umlauten: Grüezi, Müller & Söhne', 14, true);
    $pdf->textRight(545, 90, "1'234.55");
    $pdf->line(50, 100, 545, 100);
    $pdf->addPage();
    $pdf->paragraph(50, 60, 400, "Zweite Seite.\n\nMit Absatz.");

    $bytes = $pdf->output();

    ok(str_starts_with($bytes, '%PDF-1.4'), 'Der Anfang stimmt');
    ok(str_ends_with(rtrim($bytes), '%%EOF'), 'Das Ende auch');
    ok(substr_count($bytes, '/Type /Page ') === 2, 'Zwei Seiten');

    // Die Sprungtabelle muss auf die richtigen Stellen zeigen – sonst
    // öffnet kein Betrachter die Datei.
    ok(preg_match('/startxref\s+(\d+)/', $bytes, $m) === 1, 'Es gibt eine Sprungtabelle');

    $start = (int) $m[1];
    is('xref', substr($bytes, $start, 4), 'Und sie liegt dort, wo es heisst');

    preg_match_all('/^(\d{10}) 00000 n $/m', substr($bytes, $start), $offsets);

    $alleRichtig = true;
    foreach ($offsets[1] as $index => $offset) {
        $erwartet = ($index + 1) . ' 0 obj';
        if (substr($bytes, (int) $offset, strlen($erwartet)) !== $erwartet) {
            $alleRichtig = false;
        }
    }

    ok($offsets[1] !== [], 'Die Tabelle ist nicht leer');
    ok($alleRichtig, 'Jeder Eintrag zeigt auf sein Objekt');

    // Umlaute müssen als WinAnsi im Strom stehen, nicht als UTF-8.
    ok(str_contains($bytes, "Gr\xFCezi"), 'Umlaute stehen als WinAnsi darin');
    ok(!str_contains($bytes, "Gr\xC3\xBCezi"), 'Und nicht als UTF-8');

    // Klammern würden den Textbefehl vorzeitig beenden.
    $klammer = new \WebAtze\Core\Pdf('x');
    $klammer->text(10, 10, 'Ein (Text) mit \\ Klammern');
    ok(str_contains($klammer->output(), 'Ein \\(Text\\) mit'), 'Klammern werden entwertet');

    // Ziffern sind gleich breit – nur deshalb stehen Beträge bündig.
    is(
        \WebAtze\Core\Pdf::widthOf('11111.11', 10),
        \WebAtze\Core\Pdf::widthOf('98765.43', 10),
        'Zwei gleich lange Beträge sind gleich breit'
    );
});

// ==================================================================
test('Rechnungsnummern zählen je Art und Jahr hoch', function (): void {
    \WebAtze\Core\Db::delete('documents', '1 = 1', []);

    $jahr = date('Y');

    is('O-' . $jahr . '-001', \WebAtze\Build\DocumentBuilder::nextNumber('offer'),
        'Die erste Offerte des Jahres');

    $id = \WebAtze\Build\DocumentBuilder::create([
        'kind' => 'offer',
        'project_id' => null,
        'title' => 'Website',
        'recipient' => ['name' => 'Muster AG', 'email' => 'a@b.ch'],
        'items' => [
            ['label' => 'Website mit fünf Seiten', 'quantity' => 1, 'price_rappen' => 190000],
            ['label' => 'Leere Zeile fliegt raus', 'quantity' => 1, 'price_rappen' => 0],
            ['label' => '', 'quantity' => 1, 'price_rappen' => 5000],
        ],
        'intro' => 'Guten Tag',
        'outro' => 'Freundliche Grüsse',
        'vat_percent' => 0,
        'due_days' => 30,
    ]);

    ok($id > 0, 'Die Offerte wurde angelegt');

    $doc = \WebAtze\Build\DocumentBuilder::find($id);

    is('O-' . $jahr . '-001', (string) $doc['number'], 'Sie trägt die erste Nummer');
    is(2, count((array) $doc['items']), 'Die Zeile ohne Text fiel weg');
    is(190000, (int) $doc['total_rappen'], 'Die Summe stimmt');
    is('draft', (string) $doc['status'], 'Und sie ist ein Entwurf');
    ok(is_file((string) $doc['file_path']), 'Das PDF liegt da');

    is('O-' . $jahr . '-002', \WebAtze\Build\DocumentBuilder::nextNumber('offer'),
        'Die nächste Offerte zählt hoch');
    is('R-' . $jahr . '-001', \WebAtze\Build\DocumentBuilder::nextNumber('invoice'),
        'Rechnungen zählen getrennt');

    // Aus der Offerte eine Rechnung.
    $rechnung = \WebAtze\Build\DocumentBuilder::toInvoice($id);
    $doc2 = \WebAtze\Build\DocumentBuilder::find((int) $rechnung);

    is('R-' . $jahr . '-001', (string) $doc2['number'], 'Die Rechnung bekommt eine eigene Nummer');
    is(190000, (int) $doc2['total_rappen'], 'Mit demselben Betrag');
    ok((string) $doc2['due_on'] !== '', 'Und einer Zahlungsfrist');

    // Überfällig wird sie erst, wenn sie verschickt und die Frist um ist.
    ok(!\WebAtze\Build\DocumentBuilder::isOverdue($doc2), 'Ein Entwurf ist nie überfällig');

    \WebAtze\Core\Db::update('documents',
        ['status' => 'sent', 'due_on' => date('Y-m-d', strtotime('-1 day'))],
        'id = :id', ['id' => (int) $rechnung]);

    ok(\WebAtze\Build\DocumentBuilder::isOverdue(
        \WebAtze\Build\DocumentBuilder::find((int) $rechnung)
    ), 'Verschickt und Frist vorbei: überfällig');

    \WebAtze\Build\DocumentBuilder::setStatus((int) $rechnung, 'paid');
    $bezahlt = \WebAtze\Build\DocumentBuilder::find((int) $rechnung);

    ok(!\WebAtze\Build\DocumentBuilder::isOverdue($bezahlt), 'Bezahlt ist nicht mehr überfällig');
    is(date('Y-m-d'), (string) $bezahlt['paid_on'], 'Und der Tag steht fest');

    \WebAtze\Core\Db::delete('documents', '1 = 1', []);
});

// ==================================================================
test('Der Fragebogen nimmt nur an, was hineingehört', function (): void {
    $neu = \WebAtze\Domain\Questionnaire::create('Muster AG', 'a@b.ch', 'Notiz');
    $row = \WebAtze\Domain\Questionnaire::byToken($neu['token']);

    ok($row !== null, 'Der Verweis findet den Fragebogen');
    is(null, \WebAtze\Domain\Questionnaire::byToken('zu-kurz'), 'Ein kurzer Verweis nicht');
    is(null, \WebAtze\Domain\Questionnaire::byToken(str_repeat('a', 48)), 'Ein erfundener auch nicht');

    // Pflichtfelder.
    $ergebnis = \WebAtze\Domain\Questionnaire::store($row, ['company_name' => 'Nur der Name']);
    ok(!$ergebnis['ok'], 'Ohne Beschreibung geht es nicht durch');
    ok(isset($ergebnis['errors']['description']), 'Und es steht dabei, was fehlt');

    // Eine erfundene Auswahl darf nicht durchkommen.
    $ergebnis = \WebAtze\Domain\Questionnaire::store($row, [
        'company_name' => 'Muster AG',
        'industry' => 'Schreinerei',
        'description' => str_repeat('Wir bauen Möbel. ', 3),
        'contact_email' => 'kunde@example.org',
        'tone' => 'erfunden',
        'style' => 'clean',
        'services' => "Küchen\nSchränke",
    ]);

    ok($ergebnis['ok'], 'Mit allem Nötigen geht es durch');

    $gespeichert = \WebAtze\Domain\Questionnaire::byToken($neu['token']);
    is('', (string) $gespeichert['answers']['tone'], 'Die erfundene Auswahl wurde verworfen');
    is('submitted', (string) $gespeichert['status'], 'Der Zustand steht auf ausgefüllt');

    // Eine ungültige E-Mail wird abgewiesen.
    $ergebnis = \WebAtze\Domain\Questionnaire::store($row, [
        'company_name' => 'Muster AG',
        'industry' => 'Schreinerei',
        'description' => str_repeat('Wir bauen Möbel. ', 3),
        'contact_email' => 'keine-adresse',
    ]);
    ok(!$ergebnis['ok'], 'Eine kaputte E-Mail-Adresse kommt nicht durch');

    // Die Übernahme ins Formular.
    $brief = \WebAtze\Domain\Questionnaire::toBrief((array) $gespeichert['answers']);

    is('Muster AG', $brief['company_name'], 'Der Name wandert ins Formular');
    ok(str_contains($brief['extra_notes'], 'Küchen'), 'Das Angebot landet in den Zusatzinfos');
    ok(str_contains($brief['extra_notes'], 'Angebot:'), 'Mit einer Überschrift davor');

    \WebAtze\Core\Db::delete('questionnaires', 'id = :id', ['id' => (int) $row['id']]);
});

// ==================================================================
test('Die Referenz-Miniatur überlebt den nächsten Tag', function (): void {
    // Früher zeigte preview_path auf /vorschau/<kennung>/. Diesen Ordner
    // räumt der Cron nach 24 Stunden weg – am Tag darauf stand in jeder
    // Referenzkarte ein leerer Rahmen. Jetzt liegt eine dauerhafte Kopie
    // unter storage/showcase/.
    $quelle = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Build/ShowcaseBuilder.php'
    );

    ok(!str_contains($quelle, "'/vorschau/' . \$token"),
        'Die Referenz zeigt nicht mehr auf die ablaufende Vorschau');
    ok(str_contains($quelle, "/referenz-ansicht/"),
        'Sondern auf die dauerhafte Kopie');
    ok(str_contains($quelle, "STORAGE_DIR . '/showcase/'"),
        'Die Kopie liegt unter storage/showcase/');

    // Und der Worker darf sie nicht mitaufräumen.
    $worker = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Core/Worker.php'
    );
    ok(!str_contains($worker, "/showcase"),
        'Das Aufräumen fasst den Referenzordner nicht an');
});

// ==================================================================
test('Die Designstudien sind vollständig und verschieden', function (): void {
    $seedFile = dirname(__DIR__) . '/public_html/app/Support/showcase-seed.php';
    ok(is_file($seedFile), 'Die Studien sind hinterlegt');

    $studien = require $seedFile;
    is(20, count($studien), 'Es sind zwanzig');

    $slugs = array_column($studien, 'slug');
    $farben = array_column($studien, 'accent_color');
    $titel = array_column($studien, 'title');

    is(20, count(array_unique($slugs)), 'Jede hat ihre eigene Kennung');
    is(20, count(array_unique($farben)), 'Und ihre eigene Akzentfarbe');
    is(20, count(array_unique($titel)), 'Und ihren eigenen Namen');

    foreach ($studien as $studie) {
        $slug = (string) $studie['slug'];

        // Ohne Seite wäre die Karte ein leerer Rahmen.
        $seite = dirname(__DIR__) . '/public_html/storage/showcase/' . $slug . '/index.html';
        ok(is_file($seite), 'Seite vorhanden: ' . $slug);

        $html = (string) file_get_contents($seite);

        // Eine Studie darf nicht verraten, wie sie entstanden ist – sie
        // steht auf der öffentlichen Seite.
        ok(!\WebAtze\Build\ShowcaseBuilder::leaksMethod(
            $studie['subtitle'] . ' ' . $studie['body_de'] . ' ' . $studie['body_en']
        ), 'Text verrät nichts: ' . $slug);

        // Und keine Preisangabe, wie überall sonst auf der Seite auch.
        ok(!preg_match('/(CHF|Fr\.|EUR|€)\s*\d/', $studie['body_de'] . $studie['body_en']),
            'Kein Preis im Text: ' . $slug);

        // Als Studie erkennbar – sonst wären es erfundene Kundenarbeiten.
        ok(str_contains((string) $studie['tags'], 'Designstudie'),
            'Als Studie gekennzeichnet: ' . $slug);

        // Die Miniatur zeigt auf die dauerhafte Kopie.
        is('/referenz-ansicht/' . $slug . '/', $studie['preview_path'],
            'Zeigt auf die eigene Kopie: ' . $slug);

        // Kein fremder Aufruf: Die Seite muss ohne Netz auskommen.
        ok(!preg_match('#(src|href)\s*=\s*["\']https?://#i', $html),
            'Lädt nichts von fremden Servern: ' . $slug);
    }
});

// ==================================================================
test('robots.txt verrät die versteckte Adresse nicht', function (): void {
    // robots.txt ist öffentlich. Steht der geheime Pfad darin, genügt ein
    // Aufruf dieser einen Datei, um ihn zu erfahren – dann ist der
    // versteckte Bereich nur noch dem Namen nach versteckt.
    $quelle = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Http/SiteController.php'
    );

    $start = strpos($quelle, 'public function robots(');
    ok($start !== false, 'Die Methode robots() gibt es');

    $ende = strpos($quelle, 'public function sitemap(', $start ?: 0);
    $block = substr($quelle, (int) $start, (int) $ende - (int) $start);

    // Der Pfad darf weder fest noch aus der Konfiguration hineingeraten.
    ok(!str_contains($block, "Config::get('create_path'"),
        'Der Pfad aus der Konfiguration steht nicht in robots.txt');
    ok(!preg_match('/Disallow:\s*\/create/', $block),
        'Auch nicht fest eingetragen');

    // Was dort stehen darf, steht auch weiterhin dort.
    foreach (['/vorschau/', '/api/', '/assistant/', '/worker/'] as $pfad) {
        ok(str_contains($block, 'Disallow: ' . $pfad),
            'Weiterhin gesperrt: ' . $pfad);
    }

    // Und die Seiten selbst müssen den Index von sich aus fernhalten –
    // das ist die Zusage, die den Disallow-Eintrag überflüssig macht.
    $response = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Core/Response.php'
    );
    ok(str_contains($response, "'X-Robots-Tag', 'noindex, nofollow, noarchive'"),
        'noIndex() schickt X-Robots-Tag');

    foreach (['layouts/admin.php', 'layouts/bare.php'] as $layout) {
        $html = (string) file_get_contents(
            dirname(__DIR__) . '/public_html/app/Views/' . $layout
        );
        ok(str_contains($html, 'name="robots" content="noindex, nofollow, noarchive"'),
            $layout . ' trägt die Meta-Angabe');
    }
});

// ==================================================================
test('Eingabeprüfung weist ab, was nicht passt', function (): void {
    $prüfung = Validator::make([
        'name' => '',
        'email' => 'keine-adresse',
        'farbe' => 'rot',
        'domain' => 'kein domain name',
    ])
        ->required('name', 'Name')
        ->email('email', 'E-Mail')
        ->hexColor('farbe', 'Farbe')
        ->domain('domain', 'Domain');

    ok($prüfung->fails(), 'Vier Fehler werden erkannt');
    is(4, count($prüfung->errors()), 'Und zwar genau vier');

    $gut = Validator::make([
        'name' => 'Holzwerk Brunner',
        'email' => 'info@holzwerk-brunner.ch',
        'farbe' => '#4a6b2a',
        'domain' => 'holzwerk-brunner.ch',
    ])
        ->required('name', 'Name')
        ->email('email', 'E-Mail')
        ->hexColor('farbe', 'Farbe')
        ->domain('domain', 'Domain');

    ok(!$gut->fails(), 'Gültige Eingaben kommen durch');
});

// ==================================================================
test('Kostenrechnung: Zahlen stimmen mit der Preisliste überein', function (): void {
    // 1 Mio. Eingabe- und 1 Mio. Ausgabe-Zeichen bei Opus 5:
    // 5.00 + 25.00 = 30.00 Dollar.
    // (Modell, Eingabe, Zwischenspeicher schreiben, lesen, Ausgabe)
    $kosten = ClaudeClient::costMicroDollars('claude-opus-5', 1_000_000, 0, 0, 1_000_000);
    is(30_000_000, $kosten, 'Opus 5: 5 Dollar Eingabe plus 25 Ausgabe');

    // Gelesener Zwischenspeicher kostet ein Zehntel der Eingabe.
    $gelesen = ClaudeClient::costMicroDollars('claude-opus-5', 0, 0, 1_000_000, 0);
    is(500_000, $gelesen, 'Gelesener Zwischenspeicher: ein Zehntel');

    // Geschriebener Zwischenspeicher kostet ein Viertel mehr.
    $geschrieben = ClaudeClient::costMicroDollars('claude-opus-5', 0, 1_000_000, 0, 0);
    is(6_250_000, $geschrieben, 'Geschriebener Zwischenspeicher: ein Viertel mehr');

    // Sonnet ist günstiger als Opus – sonst stimmte die Preisliste nicht.
    ok(
        ClaudeClient::costMicroDollars('claude-sonnet-5', 1_000_000, 0, 0, 1_000_000)
        < ClaudeClient::costMicroDollars('claude-opus-5', 1_000_000, 0, 0, 1_000_000),
        'Sonnet kostet weniger als Opus'
    );

    // Ein unbekanntes Modell darf nicht zum Absturz führen.
    ok(ClaudeClient::costMicroDollars('gibt-es-nicht', 1000, 0, 0, 1000) >= 0,
        'Unbekanntes Modell ergibt keine Ausnahme');
});

// ==================================================================
test('Der Seitenrahmen steht nur an einer Stelle', function (): void {
    $seite = Page::render(
        [
            'brand' => 'Holzwerk Brunner',
            'locale' => 'de',
            'theme' => ['colors' => ['primary' => '#4a6b2a']],
            'pages' => [
                ['path' => '/', 'title' => 'Start', 'in_navigation' => 1],
                ['path' => '/kontakt', 'title' => 'Kontakt', 'in_navigation' => 1],
                ['path' => '/impressum', 'title' => 'Impressum', 'in_navigation' => 1],
            ],
            'contact' => ['email' => 'info@holzwerk-brunner.ch'],
        ],
        [
            'path' => '/',
            'title' => 'Start',
            'meta_description' => 'Schreinerei aus Olten.',
            'sections' => [
                [
                    'id' => 1,
                    'type' => 'header',
                    'template_key' => Catalog::defaultKey('header'),
                    'content' => [],
                    'overrides' => [],
                ],
                [
                    'id' => 2,
                    'type' => 'hero',
                    'template_key' => Catalog::defaultKey('hero'),
                    'content' => ['title' => 'Möbel, die bleiben'],
                    'overrides' => [],
                ],
            ],
        ]
    );

    ok(str_contains($seite, '<!doctype html>'), 'Ein vollständiges Dokument');
    ok(str_contains($seite, 'Möbel, die bleiben'), 'Der Inhalt steht darin');
    ok(str_contains($seite, 'kontakt.html'), 'Verweise sind relativ');
    ok(!str_contains($seite, 'href="/kontakt.html"'), 'Und nicht absolut');

    // Der Fehler von damals: media="print" mit onload ist ein
    // Zeilenskript und wird von jeder strengen Richtlinie geblockt.
    ok(!str_contains($seite, 'onload='), 'Kein Zeilenskript im Kopf');
    ok(str_contains($seite, '<link rel="stylesheet" href="assets/css/site.css">'),
        'Das Stylesheet wird gewöhnlich eingebunden');

    // Impressum gehört in den Fussbereich, nicht ins Hauptmenü.
    $ctx = Page::context([
        'pages' => [
            ['path' => '/', 'title' => 'Start', 'in_navigation' => 1],
            ['path' => '/impressum', 'title' => 'Impressum', 'in_navigation' => 1],
        ],
    ], ['path' => '/']);

    is(1, count($ctx['nav']), 'Ein Eintrag im Hauptmenü');
    is(1, count($ctx['legal']), 'Und einer bei den Rechtlichen');
});

// ==================================================================
test('Kundenbackend: eine Änderung erreicht nur ihren Abschnitt', function (): void {
    require_once dirname(__DIR__) . '/public_html/app/Kit/admin/lib/Fields.php';

    $vorher = [
        'title' => 'Möbel, die bleiben',
        'lead' => 'Wir bauen Möbel nach Mass.',
        'image' => ['src' => 'assets/img/werkstatt.jpg', 'alt' => 'Die Werkstatt'],
    ];

    // Was der Typ kennt, wird übernommen.
    $nachher = \WebAtzeKit\Fields::merge('hero', $vorher, [
        'title' => 'Möbel fürs ganze Leben',
    ]);

    is('Möbel fürs ganze Leben', $nachher['title'], 'Die Überschrift ist neu');
    is('Wir bauen Möbel nach Mass.', $nachher['lead'], 'Der Rest bleibt unberührt');

    // Was der Typ nicht kennt, kommt nicht hinein.
    $versuch = \WebAtzeKit\Fields::merge('hero', $vorher, [
        'title' => 'Neu',
        'admin_password_hash' => '$argon2id$boese',
        'assistant_token' => 'geklaut',
        'gibt_es_nicht' => 'x',
    ]);

    ok(!isset($versuch['admin_password_hash']), 'Kein fremdes Feld wird gesetzt');
    ok(!isset($versuch['assistant_token']), 'Auch kein Kennwort');
    ok(!isset($versuch['gibt_es_nicht']), 'Und nichts Erfundenes');

    // Der Bildpfad lässt sich über das Formular nicht ändern – nur der
    // Bildtext. Sonst zeigte ein Bild plötzlich woandershin.
    $bild = \WebAtzeKit\Fields::merge('hero', $vorher, [
        'image' => ['src' => '../../../etc/passwd', 'alt' => 'Neuer Text'],
    ]);

    is('assets/img/werkstatt.jpg', $bild['image']['src'], 'Der Bildpfad bleibt');
    is('Neuer Text', $bild['image']['alt'], 'Der Bildtext ändert sich');

    // Zu langer Text wird gekürzt statt abgelehnt.
    $lang = \WebAtzeKit\Fields::merge('hero', $vorher, [
        'title' => str_repeat('a', 5000),
    ]);
    ok(mb_strlen($lang['title']) <= 140, 'Überlange Eingaben werden gekürzt');
});

// ==================================================================
test('Kundenbackend: die Ablage überlebt einen Abbruch', function (): void {
    require_once dirname(__DIR__) . '/public_html/app/Kit/admin/lib/Store.php';

    $dir = sys_get_temp_dir() . '/webatze-test-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);

    $store = new \WebAtzeKit\Store($dir);

    ok($store->save(['pages' => [['path' => '/', 'title' => 'Start', 'sections' => []]]]),
        'Speichern klappt');

    // Die Datei beginnt mit einem Abbruch – ein direkter Aufruf gibt
    // nichts preis.
    $roh = (string) file_get_contents($dir . '/site.php');
    ok(str_starts_with($roh, '<?php exit;'), 'Die Ablage schützt sich selbst');
    ok(str_contains($roh, '"title": "Start"'), 'Und enthält trotzdem die Daten');

    is('Start', $store->pages()[0]['title'], 'Gelesen wird, was geschrieben wurde');

    // Vor jeder Änderung eine Sicherung.
    $store->save(['pages' => [['path' => '/', 'title' => 'Neu', 'sections' => []]]]);
    is(1, count($store->backups()), 'Eine Sicherung ist da');

    $name = $store->backups()[0]['name'];
    ok($store->restore($name), 'Zurückholen klappt');
    is('Start', $store->pages()[0]['title'], 'Der frühere Stand ist zurück');

    // Über den Namen einer Sicherung darf kein Pfad hinausführen.
    ok(!$store->restore('../../../etc/passwd'), 'Pfadausbruch wird abgewiesen');
    ok(!$store->restore('site-99999999-999999.php'), 'Was es nicht gibt, kommt nicht zurück');

    delete_tree($dir);
});

// ==================================================================
summary();
