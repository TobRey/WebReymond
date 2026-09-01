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
test('Buchung: eine belegte Zeit blockiert alles, was hineinragt', function (): void {
    require_once dirname(__DIR__) . '/public_html/app/Kit/site/php/buchen-zeiten.php';

    // Ein Dienstag. Offen 09:00–12:00 und 13:30–18:00, Takt 15 Minuten.
    $tag = '2026-09-01';
    $zeiten = [2 => ['09:00-12:00', '13:30-18:00']];
    $jetzt = strtotime('2026-08-25 08:00');

    $frei = freieZeitenAus([], $tag, 45, 15, 0, $zeiten, [], $jetzt);

    is('09:00', $frei[0], 'Der erste Takt liegt am Anfang des Fensters');
    ok(in_array('11:15', $frei, true), '11:15 passt noch: 11:15 + 45 = 12:00');
    ok(!in_array('11:30', $frei, true), '11:30 nicht mehr – das ragte über 12:00 hinaus');
    ok(in_array('17:15', $frei, true), 'Und abends bis 17:15');
    ok(!in_array('17:30', $frei, true), 'Aber nicht darüber');
    ok(!in_array('12:15', $frei, true), 'In der Mittagspause geht nichts');

    // Jetzt eine Buchung von 14:00 bis 14:45.
    $belegt = [['tag' => $tag, 'zeit' => '14:00', 'dauer' => 45, 'zustand' => 'offen']];
    $frei = freieZeitenAus($belegt, $tag, 45, 15, 0, $zeiten, [], $jetzt);

    ok(!in_array('14:00', $frei, true), 'Die gebuchte Zeit ist weg');

    // Das ist der Fehler, den man leicht macht: Nur den Anfangszeitpunkt
    // zu vergleichen liesse 13:30 stehen – und 13:30 + 45 Minuten läuft
    // mitten in den bestehenden Termin hinein.
    ok(!in_array('13:30', $frei, true), '13:30 auch – es ragte hinein');
    ok(!in_array('13:45', $frei, true), '13:45 ebenso');
    ok(in_array('13:15', $frei, true) === false, '13:15 liegt vor dem Fenster');
    ok(in_array('14:45', $frei, true), 'Direkt danach geht es weiter');
    ok(in_array('11:00', $frei, true), 'Der Vormittag bleibt unberührt');

    // Eine abgesagte Buchung gibt die Zeit wieder frei.
    $abgesagt = [['tag' => $tag, 'zeit' => '14:00', 'dauer' => 45, 'zustand' => 'abgesagt']];
    ok(in_array('14:00', freieZeitenAus($abgesagt, $tag, 45, 15, 0, $zeiten, [], $jetzt), true),
        'Abgesagt heisst wieder frei');

    // Eine längere Leistung passt an weniger Stellen.
    $lang = freieZeitenAus($belegt, $tag, 90, 15, 0, $zeiten, [], $jetzt);
    ok(!in_array('13:30', $lang, true), '90 Minuten passen vor dem Termin nicht mehr');
    ok(in_array('14:45', $lang, true), 'Danach schon');
    ok(!in_array('16:45', $lang, true), 'Und nicht über 18:00 hinaus');

    // Geschlossene Tage.
    is([], freieZeitenAus([], '2026-09-06', 45, 15, 0, $zeiten, [], $jetzt),
        'Am Sonntag ist zu – dafür steht kein Fenster in der Liste');
    is([], freieZeitenAus([], $tag, 45, 15, 0, $zeiten, ['2026-09-01'], $jetzt),
        'Ein einzelner geschlossener Tag ebenfalls');

    // Der Vorlauf.
    $knapp = freieZeitenAus([], $tag, 45, 15, 24, $zeiten, [], strtotime('2026-08-31 14:00'));
    ok(!in_array('09:00', $knapp, true), 'Mit 24 Stunden Vorlauf fällt der frühe Morgen weg');
    ok(in_array('14:15', $knapp, true), 'Was weiter weg liegt, bleibt');

    // Die Tagesliste überspringt geschlossene Tage.
    $tage = naechsteTage(24, 14, $zeiten, [], strtotime('2026-08-31 10:00'));
    is('2026-09-01', $tage[0], 'Der erste buchbare Tag ist der Dienstag');
    is('2026-09-08', $tage[1], 'Danach kommt gleich der nächste Dienstag');

    // Ein Datum ausserhalb des Bereichs wird nicht angenommen.
    $jetzt = strtotime('2026-08-31 10:00');
    is('', erlaubterTag('2026-08-30', 24, 30, $jetzt), 'Gestern geht nicht');
    is('', erlaubterTag('2027-01-01', 24, 30, $jetzt), 'Und zu weit weg auch nicht');
    is('', erlaubterTag('kein datum', 24, 30, $jetzt), 'Unsinn erst recht nicht');
    is('2026-09-05', erlaubterTag('2026-09-05', 24, 30, $jetzt), 'Was dazwischen liegt, schon');
});

// ==================================================================
test('Buchung wird nur gebaut, wenn sie beschrieben wurde', function (): void {
    $ohne = ['extra_notes' => 'Bitte modern und mit vielen Bildern.', 'description' => 'Wir bauen Möbel.'];
    $mit = ['extra_notes' => 'Die Kunden sollen online einen Termin buchen können.', 'description' => ''];

    ok(!\WebAtze\Build\BookingKit::wanted($ohne), 'Ohne Beschreibung kein Buchungssystem');
    ok(\WebAtze\Build\BookingKit::wanted($mit), 'Mit schon');

    // Die Öffnungszeiten aus dem Freitext.
    $zeiten = \WebAtze\Build\BookingKit::hours("Mo–Fr 08:00–12:00, 13:30–18:00\nSa 09:00–13:00");

    is(['08:00-12:00', '13:30-18:00'], $zeiten[1], 'Montag mit zwei Fenstern');
    is(['09:00-13:00'], $zeiten[6], 'Samstag mit einem');
    ok(!isset($zeiten[7]), 'Sonntag steht nicht darin – dann ist zu');

    $einzeln = \WebAtze\Build\BookingKit::hours("Mo, Di, Do 08:00-17:00\nMi geschlossen\nFr 08:00-15:00");
    ok(isset($einzeln[1], $einzeln[2], $einzeln[4], $einzeln[5]), 'Einzelne Tage werden erkannt');
    ok(!isset($einzeln[3]), 'Der Mittwoch bleibt draussen');

    // Was nicht zu lesen ist, wird nicht geraten.
    $vorgabe = \WebAtze\Build\BookingKit::hours('Wir sind eigentlich immer da.');
    is(['09:00-17:00'], $vorgabe[1], 'Sonst greift Montag bis Freitag, 9 bis 17');
    ok(!isset($vorgabe[6]), 'Und am Wochenende ist zu');
});

// ==================================================================
test('Der Name der SQLite-Datei lässt sich nicht erraten', function (): void {
    // Die Datei liegt im Web-Verzeichnis. Gesperrt wird sie durch
    // storage/.htaccess – aber die greift bei nginx gar nicht, und
    // anders als eine PHP-Datei kann eine Datenbankdatei keinen
    // Wächter tragen: Sie muss mit "SQLite format 3" beginnen.
    // Bleibt der Name.
    $install = (string) file_get_contents(dirname(__DIR__) . '/public_html/install.php');

    ok(!str_contains($install, "storage/webatze.sqlite'"),
        'install.php schreibt keinen festen Dateinamen');
    ok(str_contains($install, "'/storage/db-' . bin2hex(random_bytes(8))"),
        'Sondern hängt Zufall an');

    // Und die Absperrungen stehen trotzdem.
    $htaccess = (string) file_get_contents(dirname(__DIR__) . '/public_html/storage/.htaccess');
    ok(str_contains($htaccess, 'Require all denied'), 'storage/ ist per .htaccess gesperrt');

    ok(is_file(dirname(__DIR__) . '/public_html/storage/index.php'),
        'Und trägt zusätzlich einen Wächter');
    ok(is_file(dirname(__DIR__) . '/public_html/app/.htaccess'), 'app/ ebenso');
});

// ==================================================================
test('Der fehlende Arbeitsbereich wird erkannt und behoben', function (): void {
    // Der Fehler aus dem Betrieb, wortwörtlich:
    $echt = 'anthropic-workspace-id is required when authenticating with an '
        . 'identity-linked API key; send the id of the workspace this request acts in.';

    ok(ClaudeClient::isWorkspaceMissing(400, $echt), 'Die echte Meldung wird erkannt');
    ok(ClaudeClient::isWorkspaceMissing(400, 'workspace_id_required'),
        'Der Fehlercode allein genügt auch');
    ok(ClaudeClient::isWorkspaceMissing(400, mb_strtoupper($echt)),
        'Gross- und Kleinschreibung spielt keine Rolle');

    // Und was nicht dazugehört, wird nicht dafür gehalten.
    ok(!ClaudeClient::isWorkspaceMissing(400, 'max_tokens must be greater than 0'),
        'Ein anderer 400er nicht');
    ok(!ClaudeClient::isWorkspaceMissing(401, $echt), 'Ein 401 ebenfalls nicht');
    ok(!ClaudeClient::isWorkspaceMissing(500, $echt), 'Und ein Serverfehler auch nicht');

    // Die Kennung wird mitgeschickt, sobald sie bekannt ist.
    \WebAtze\Core\Settings::put('anthropic_workspace_id', 'wrkspc_01TESTTESTTEST');
    is('wrkspc_01TESTTESTTEST', ClaudeClient::workspaceId(), 'Sie wird aus den Einstellungen gelesen');

    $quelle = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Ai/ClaudeClient.php'
    );

    ok(str_contains($quelle, "'anthropic-workspace-id: ' . \$workspace"),
        'Und als Kopfzeile an jede Anfrage gehängt');

    \WebAtze\Core\Settings::put('anthropic_workspace_id', '');
    is('', ClaudeClient::workspaceId(), 'Leer heisst: keine Kopfzeile');

    // Wer die Kennung sucht, findet sie in der Adresszeile – und fügt
    // dann die ganze Adresse ein. Das darf nicht scheitern.
    $rein = static fn (string $v): string => ClaudeClient::cleanWorkspaceId($v);

    is('wrkspc_01ABCdef123', $rein('wrkspc_01ABCdef123'), 'Die blosse Kennung');
    is('wrkspc_01ABCdef123', $rein('https://platform.claude.com/workspaces/wrkspc_01ABCdef123/usage'),
        'Die ganze Adresse aus der Konsole');
    is('wrkspc_01ABCdef123', $rein('  wrkspc_01ABCdef123  '), 'Mit Leerzeichen drumherum');
    is('', $rein('https://platform.claude.com/dashboard'), 'Eine Adresse ohne Kennung');
    is('', $rein('mein arbeitsbereich'), 'Fliesstext');
    is('', $rein('<script>alert(1)</script>'), 'Und Unfug erst recht');

    // Der zweite Wortlaut derselben Sache. Ihn nicht zu erkennen war ein
    // Fehler mit Folgen: Der abgewiesene Wert blieb stehen und liess von
    // da an jede Anfrage scheitern.
    ok(ClaudeClient::isWorkspaceMissing(400,
        'anthropic-workspace-id header must be a valid workspace ID'),
        '"muss gültig sein" zählt genauso wie "fehlt"');
    ok(ClaudeClient::isWorkspaceMissing(400,
        'The anthropic-workspace-id header must be a valid workspace ID.'),
        'Auch als ganzer Satz');

    $quelle = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Ai/ClaudeClient.php'
    );

    // "default" ist nachweislich keine gültige Kennung – die
    // Schnittstelle hat es abgelehnt. Also wird nicht mehr geraten.
    ok(!str_contains($quelle, "LAST_RESORT"),
        'Es wird keine Kennung mehr geraten');

    ok(str_contains($quelle, 'if (self::wasGuessed()) {'),
        'Eine frühere Vermutung wird trotzdem wieder entfernt');

    // Und der Weg, der zum Ziel führt: mit einem Admin-Schlüssel fragen.
    ok(str_contains($quelle, 'public static function lookupWith('),
        'Nachschlagen mit einem Admin-Schlüssel ist möglich');
    ok(!str_contains($quelle, "remember('admin_key'"),
        'Der Admin-Schlüssel wird nirgends abgelegt');
});

// ==================================================================
test('Ein Einstellungsfehler wird nicht ewig wiederholt', function (): void {
    // Das war der eigentliche Ärger: Eine falsche Einstellung ist kein
    // vorübergehender Fehler. Sie alle dreissig Sekunden erneut zu
    // versuchen bringt nichts und hört nie auf.
    $ref = new ReflectionMethod(\WebAtze\Core\Worker::class, 'looksTemporary');
    $ref->setAccessible(true);

    $einstellung = new \WebAtze\Core\ConfigurationError(
        'Der Schlüssel gehört zu einer Person.',
        'Trag den Arbeitsbereich ein.',
        'anthropic_workspace_id'
    );

    ok(!$ref->invoke(null, $einstellung), 'Ein Einstellungsfehler wird nicht wiederholt');

    // Was wirklich vorübergehend ist, schon.
    ok($ref->invoke(null, new RuntimeException('Connection timed out')),
        'Eine Zeitüberschreitung dagegen schon');
    ok($ref->invoke(null, new RuntimeException('HTTP 503: overloaded')),
        'Ein überlasteter Server ebenfalls');
    ok(!$ref->invoke(null, new InvalidArgumentException('falscher Wert')),
        'Ein Programmierfehler nicht');

    // Die Anweisung gehört zur Meldung – eine Fehlermeldung ohne
    // Anweisung lässt jemanden ratlos zurück.
    ok(str_contains($einstellung->full(), 'Trag den Arbeitsbereich ein.'),
        'Die Meldung enthält, was zu tun ist');
    is('anthropic_workspace_id', $einstellung->field(), 'Und welches Feld gemeint ist');

    // Ein angehaltener Auftrag lässt sich fortsetzen, ohne von vorne
    // zu beginnen – sonst wäre schon bezahlte Arbeit verloren.
    $jobId = \WebAtze\Core\Jobs::enqueue('build', ['test' => 1], null);
    \WebAtze\Core\Jobs::progress($jobId, 'inhalte', 62, 'Texte werden geschrieben', ['seite' => 3]);
    \WebAtze\Core\Jobs::fail($jobId, 'Arbeitsbereich fehlt.', false, 'Angehalten.');

    $job = \WebAtze\Core\Jobs::find($jobId);
    is('failed', (string) $job['status'], 'Der Auftrag steht');
    is('Angehalten.', (string) $job['message'], 'Mit einer Meldung, die etwas sagt');

    ok(\WebAtze\Core\Jobs::retry($jobId), 'Er lässt sich fortsetzen');

    $job = \WebAtze\Core\Jobs::find($jobId);
    is('queued', (string) $job['status'], 'Danach steht er wieder in der Warteschlange');
    is('inhalte', (string) $job['step'], 'Beim Schritt, an dem es klemmte');
    is(3, (int) ($job['state']['seite'] ?? 0), 'Und mit dem Zwischenstand von vorher');
    is(0, (int) $job['attempts'], 'Die Versuchszählung beginnt neu');

    ok(!\WebAtze\Core\Jobs::retry($jobId), 'Ein laufender Auftrag lässt sich nicht fortsetzen');

    \WebAtze\Core\Db::delete('jobs', 'id = :id', ['id' => $jobId]);
});

// ==================================================================
test('Kein Schema enthält, was die strukturierte Ausgabe ablehnt', function (): void {
    // Die Schnittstelle kennt keine Grössenangaben und weist die ganze
    // Anfrage ab: "For 'array' type, property 'maxItems' is not
    // supported". Die fertigen Bibliotheken entfernen sie still; hier
    // läuft es über cURL, also muss es selbst geschehen.
    $seal = new ReflectionMethod(ClaudeClient::class, 'sealSchema');
    $seal->setAccessible(true);

    $verboten = ['minItems', 'maxItems', 'uniqueItems', 'contains', 'minContains',
                 'maxContains', 'prefixItems', 'minimum', 'maximum', 'exclusiveMinimum',
                 'exclusiveMaximum', 'multipleOf', 'minLength', 'maxLength', 'pattern',
                 'minProperties', 'maxProperties', 'propertyNames'];

    $suchen = static function (array $s, array $verboten, string $pfad = '$') use (&$suchen): array {
        $treffer = [];
        foreach ($s as $k => $v) {
            if (is_string($k) && in_array($k, $verboten, true)) {
                $treffer[] = $pfad . '.' . $k;
            }
            if (is_array($v)) {
                $treffer = array_merge($treffer, $suchen($v, $verboten, $pfad . '.' . $k));
            }
        }
        return $treffer;
    };

    // Alle Schemas, die tatsächlich verschickt werden.
    $schemas = [
        'Seitenplan' => \WebAtze\Ai\JsonSchema::sitePlan(),
        'Seiteninhalt' => \WebAtze\Ai\JsonSchema::pageContent([
            ['type' => 'hero'], ['type' => 'services'], ['type' => 'contact'],
        ]),
        'Abschnitt' => \WebAtze\Ai\JsonSchema::sectionContent('hero'),
        'Referenztext' => \WebAtze\Ai\JsonSchema::reference(),
        'Übersetzung' => \WebAtze\Ai\JsonSchema::translation([
            ['type' => 'hero'], ['type' => 'text'],
        ]),
    ];

    foreach ($schemas as $name => $schema) {
        $übrig = $suchen($seal->invoke(null, $schema), $verboten);
        ok($übrig === [], $name . ': nichts Unerlaubtes mehr'
            . ($übrig === [] ? '' : ' (' . implode(', ', $übrig) . ')'));
    }

    // Die Absicht darf nicht verlorengehen – sie wandert in die
    // Beschreibung, wo das Modell sie liest.
    $plan = $seal->invoke(null, \WebAtze\Ai\JsonSchema::sitePlan());
    $text = (string) ($plan['properties']['pages']['description'] ?? '');

    ok(str_contains($text, 'Höchstens 12'), 'Die Obergrenze steht als Satz in der Beschreibung');
    ok(str_contains($text, 'Mindestens 1 Eintrag'), 'Und zwar in richtigem Deutsch – Einzahl');

    // Was gebraucht wird, bleibt stehen.
    ok(($plan['additionalProperties'] ?? null) === false, 'additionalProperties bleibt false');
    ok(isset($plan['required']), 'required bleibt');

    // Auch in Verzweigungen wird aufgeräumt. Das fehlte zuerst.
    $mitAnyOf = $seal->invoke(null, [
        'type' => 'object',
        'properties' => [
            'x' => ['anyOf' => [
                ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 5],
                ['type' => 'string', 'maxLength' => 10],
            ]],
        ],
    ]);

    is([], $suchen($mitAnyOf, $verboten), 'Auch unter anyOf ist nichts übrig');

    // anyOf selbst bleibt – das ist keine Grenze, sondern die Bedeutung.
    ok(isset($mitAnyOf['properties']['x']['anyOf']), 'anyOf selbst bleibt erhalten');
});

// ==================================================================
test('Das Seitenschema bleibt klein genug für die Grammatik', function (): void {
    // Hier stand eine Liste, deren Einträge über "anyOf" jede
    // Abschnittsform annehmen durften. Die Schnittstelle übersetzt das
    // in eine Grammatik, und "jede Form an jeder Stelle" wächst mit dem
    // Produkt: Ab acht Abschnitten kam "the compiled grammar is too
    // large". Ausserdem war es gar nicht gemeint – die Reihenfolge steht
    // ja fest.
    $seal = new ReflectionMethod(ClaudeClient::class, 'sealSchema');
    $seal->setAccessible(true);

    $typen = ['header', 'hero', 'services', 'about', 'gallery',
              'testimonials', 'faq', 'cta', 'contact', 'footer'];

    $sections = [];
    foreach ($typen as $t) {
        $sections[] = ['type' => $t];
    }

    $schema = $seal->invoke(null, \WebAtze\Ai\JsonSchema::pageContent($sections));
    $json = json_encode($schema);

    is(0, substr_count($json, '"anyOf"'), 'Keine einzige Verzweigung mehr');
    ok(strlen($json) < 20000, 'Und das Schema bleibt handlich (' . strlen($json) . ' Zeichen)');

    // Jede Position hat genau eine erlaubte Form – das war der stillere
    // Fehler: "anyOf" liess an Position 3 die Form von Position 5 zu.
    $slots = $schema['properties']['sections'];

    is('object', $slots['type'], 'Ein Objekt statt einer Liste');
    is(10, count($slots['properties']), 'Ein Feld je Abschnitt');
    ok(isset($slots['properties']['abschnitt_0']), 'Benannt nach der Position');
    ok(isset($slots['properties']['abschnitt_9']), 'Auch der letzte');
    is(10, count($slots['required']), 'Alle werden verlangt');

    // Die Form je Position gehört zum Typ, der dort steht.
    ok(isset($slots['properties']['abschnitt_1']['properties']['eyebrow']),
        'Position 1 trägt die Form von "hero"');
    ok(!isset($slots['properties']['abschnitt_1']['properties']['questions']),
        'Und nicht die von "faq"');

    // Die Übersetzung benutzt dieselbe Form.
    $üb = $seal->invoke(null, \WebAtze\Ai\JsonSchema::translation($sections));
    is(0, substr_count(json_encode($üb), '"anyOf"'), 'Auch bei der Übersetzung keine Verzweigung');
    ok(isset($üb['properties']['sections']['properties']['abschnitt_0']),
        'Und dieselben Feldnamen');

    // Der Rückweg: aus der Antwort wieder Positionen machen.
    $antwort = ['sections' => [
        'abschnitt_0' => ['title' => 'Erster'],
        'abschnitt_2' => ['title' => 'Dritter'],
        'quatsch' => ['title' => 'gehört nicht dazu'],
    ]];

    $byIndex = [];
    foreach ((array) $antwort['sections'] as $key => $content) {
        if (is_array($content) && preg_match('/^abschnitt_(\d+)$/', (string) $key, $m)) {
            $byIndex[(int) $m[1]] = $content;
        }
    }

    is([0, 2], array_keys($byIndex), 'Die Nummer steckt im Namen');
    is('Dritter', $byIndex[2]['title'], 'Und zeigt auf den richtigen Inhalt');
});

// ==================================================================
test('Lange Seiten werden in Gruppen aufgeteilt', function (): void {
    // Ein festes Feld je Abschnitt war nur die halbe Miete. Am echten
    // Dienst gemessen: fünf Abschnitte gehen durch, sechs nicht mehr –
    // "the compiled grammar is too large". Die Grenze hängt am Umfang,
    // nicht an der Anzahl. Also wird gemessen und aufgeteilt.
    $typen = ['header', 'hero', 'services', 'about', 'gallery',
              'testimonials', 'faq', 'cta', 'contact', 'footer'];

    $sections = [];
    foreach ($typen as $t) {
        $sections[] = ['type' => $t];
    }

    $gruppen = \WebAtze\Ai\JsonSchema::chunkSections($sections);

    ok(count($gruppen) > 1, 'Zehn Abschnitte passen nicht in eine Anfrage'
        . ' (' . count($gruppen) . ' Gruppen)');

    // Keiner darf verlorengehen, keiner doppelt vorkommen.
    $gesehen = [];
    foreach ($gruppen as $gruppe) {
        ok($gruppe !== [], 'Keine leere Gruppe');

        foreach ($gruppe as $index => $section) {
            ok(!isset($gesehen[$index]), 'Abschnitt ' . $index . ' kommt nur einmal vor');
            $gesehen[$index] = $section['type'];
        }
    }

    is(array_keys($sections), array_keys($gesehen), 'Alle Abschnitte sind dabei');
    is($typen, array_values($gesehen), 'Und in ihrer ursprünglichen Reihenfolge');

    // Der eigentliche Punkt: Jede einzelne Anfrage bleibt klein genug.
    // Der Messwert stammt aus dem Versuch am echten Dienst – 7246
    // Zeichen gingen durch, 8533 nicht.
    $seal = new ReflectionMethod(ClaudeClient::class, 'sealSchema');
    $seal->setAccessible(true);

    foreach ($gruppen as $nummer => $gruppe) {
        foreach ([
            'Inhalt' => \WebAtze\Ai\JsonSchema::pageContent($gruppe),
            'Übersetzung' => \WebAtze\Ai\JsonSchema::translation($gruppe),
        ] as $was => $schema) {
            $länge = strlen((string) json_encode($seal->invoke(null, $schema)));

            ok($länge < 7300, $was . ' der Gruppe ' . ($nummer + 1) . ' bleibt unter der Grenze'
                . ' (' . $länge . ' Zeichen)');
        }
    }

    // Die Feldnamen tragen weiter die ursprüngliche Nummer – sonst
    // landet die Antwort der zweiten Gruppe an der falschen Stelle.
    $letzte = end($gruppen);
    $erster = (int) array_key_first($letzte);

    ok($erster > 0, 'Die letzte Gruppe fängt nicht bei null an');
    ok(isset(\WebAtze\Ai\JsonSchema::pageContent($letzte)['properties']['sections']
        ['properties']['abschnitt_' . $erster]),
        'Sie heisst weiter abschnitt_' . $erster);

    // Sonderfälle: nichts, einer, und einer der allein zu gross ist.
    is([], \WebAtze\Ai\JsonSchema::chunkSections([]), 'Ohne Abschnitte keine Gruppe');
    is(1, count(\WebAtze\Ai\JsonSchema::chunkSections([['type' => 'hero']])),
        'Ein Abschnitt bleibt eine Gruppe');
    is(1, count(\WebAtze\Ai\JsonSchema::chunkSections([['type' => 'contact']], 10)),
        'Ein zu grosser Abschnitt wird nicht weggelassen');
});

// ==================================================================
test('Ein Abbruch mitten in der Seite wirft nichts weg', function (): void {
    // Der teure Fehler: Die Seite wurde erst gespeichert, wenn alle
    // Gruppen geschrieben waren. Wurde der Vorgang vorher abgeschossen -
    // und PHPs Zeitlimit tat genau das - war die bezahlte Arbeit weg,
    // der Zaehler stand weiter auf null, und der naechste Durchlauf fing
    // dieselbe Seite von vorn an. Ohne Fehlermeldung, aber mit Rechnung.
    $projectId = (int) \WebAtze\Core\Db::insert('projects', [
        'name' => 'Abbruchtest', 'slug' => 'abbruchtest-' . bin2hex(random_bytes(4)),
        'status' => 'building', 'created_at' => \WebAtze\Core\Db::now(),
        'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    $seite = [
        'path' => '/', 'title' => 'Start', 'meta_description' => '',
        'in_navigation' => true,
        'sections' => [
            ['type' => 'hero', 'template' => 'hero-01', 'purpose' => ''],
            ['type' => 'services', 'template' => 'services-01', 'purpose' => ''],
            ['type' => 'faq', 'template' => 'faq-01', 'purpose' => ''],
        ],
    ];

    $writer = new \WebAtze\Ai\ContentWriter($projectId);

    // Zweimal aufgerufen darf keine zweite Seitenzeile entstehen.
    $pageId = $writer->preparePage($projectId, $seite);
    is($pageId, $writer->preparePage($projectId, $seite), 'Dieselbe Seite wird wiederverwendet');

    $zeilen = \WebAtze\Core\Db::all(
        'SELECT id FROM project_pages WHERE project_id = :p', ['p' => $projectId]
    );
    is(1, count($zeilen), 'Und es bleibt bei einer Zeile');

    // Erste Gruppe schreiben - das ist der Punkt: Sie steht sofort da,
    // nicht erst wenn die ganze Seite fertig ist.
    $gruppen = $writer->groupsFor($seite);
    ok($gruppen !== [], 'Die Seite hat mindestens eine Gruppe');

    $writer->writeGroup($projectId, $pageId, $seite, [], '', 0);

    $nachErster = \WebAtze\Core\Db::all(
        'SELECT sort_order FROM project_sections WHERE page_id = :p ORDER BY sort_order',
        ['p' => $pageId]
    );

    ok(count($nachErster) > 0, 'Nach der ersten Gruppe steht schon etwas in der Datenbank');

    // Jetzt so tun, als waere hier abgebrochen und neu begonnen worden:
    // dieselbe Gruppe noch einmal. Es darf nichts doppelt entstehen.
    $writer->writeGroup($projectId, $pageId, $seite, [], '', 0);

    $nachWiederholung = \WebAtze\Core\Db::all(
        'SELECT sort_order FROM project_sections WHERE page_id = :p ORDER BY sort_order',
        ['p' => $pageId]
    );

    is(count($nachErster), count($nachWiederholung), 'Eine Wiederholung legt nichts doppelt an');

    // Die uebrigen Gruppen nachziehen - am Ende ist die Seite komplett.
    foreach (array_keys($gruppen) as $nummer) {
        $writer->writeGroup($projectId, $pageId, $seite, [], '', (int) $nummer);
    }

    $fertig = \WebAtze\Core\Db::all(
        'SELECT sort_order, type FROM project_sections WHERE page_id = :p ORDER BY sort_order',
        ['p' => $pageId]
    );

    is(3, count($fertig), 'Am Ende stehen alle drei Abschnitte da');
    is([0, 1, 2], array_map(static fn (array $r): int => (int) $r['sort_order'], $fertig),
        'Jede Position genau einmal');
    is(['hero', 'services', 'faq'], array_map(static fn (array $r): string => (string) $r['type'], $fertig),
        'Und in der richtigen Reihenfolge');

    \WebAtze\Core\Db::delete('project_sections', 'project_id = :p', ['p' => $projectId]);
    \WebAtze\Core\Db::delete('project_pages', 'project_id = :p', ['p' => $projectId]);
    \WebAtze\Core\Db::delete('projects', 'id = :p', ['p' => $projectId]);
});

// ==================================================================
test('Ein laufender Auftrag zeigt, dass er lebt', function (): void {
    // Das eigentliche Aergernis, und es war nie ein Defekt: Ein Bau
    // dauert je nach Umfang zwanzig Minuten, ein einzelner Aufruf an die
    // KI zwanzig bis dreissig Sekunden. Die Prozentzahl steht deshalb
    // minutenlang still - und "arbeitet gerade" war von "haengt fest"
    // nicht zu unterscheiden. Wer abbricht und neu anfaengt, zahlt alles
    // noch einmal.
    $controller = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Http/JobController.php'
    );

    ok(str_contains($controller, 'still_seit'), 'Die Antwort sagt, wann zuletzt etwas passierte');
    ok(str_contains($controller, "'lebt'"), 'Und ob das noch als lebendig gilt');
    ok(str_contains($controller, 'aufrufe'), 'Dazu, wie viele Schritte schon erledigt sind');

    // Kein Schritt darf minutenlang auf derselben Zahl stehen.
    $pipeline = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Build/Pipeline.php'
    );

    foreach ([
        'plan' => '12 + (int) round(14 *',
        'inhalte' => '28 + (int) round(34 *',
        'sprachen' => '70 + (int) round(12 *',
    ] as $schritt => $formel) {
        ok(str_contains($pipeline, $formel),
            'Der Schritt "' . $schritt . '" hat eine eigene Spanne statt einer festen Zahl');
    }

    // Und die Anzeige braucht die Stelle dafuer.
    foreach (['project', 'dashboard', 'deploy'] as $seite) {
        $ansicht = (string) file_get_contents(
            dirname(__DIR__) . '/public_html/app/Views/admin/' . $seite . '.php'
        );
        ok(str_contains($ansicht, 'data-job-puls'), $seite . ': zeigt das Lebenszeichen');
    }

    // Die Schaetzung muss mit dem Umfang wachsen, sonst ist sie wertlos.
    $schaetzen = new ReflectionMethod(\WebAtze\Http\CreateController::class, 'dauerSchaetzen');
    $schaetzen->setAccessible(true);

    $klein = (string) $schaetzen->invoke(null, ['scope' => 'small', 'locales' => 'de']);
    $gross = (string) $schaetzen->invoke(null, ['scope' => 'large', 'locales' => 'de,en,fr']);

    ok($klein !== '', 'Eine kleine Website bekommt eine Schaetzung');
    ok($gross !== '', 'Eine grosse auch');
    ok($klein !== $gross, 'Und sie unterscheiden sich (' . $klein . ' / ' . $gross . ')');

    preg_match('/(\d+)/', $gross, $g);
    preg_match('/(\d+)/', $klein, $k);
    ok((int) ($g[1] ?? 0) >= (int) ($k[1] ?? 0),
        'Die grosse Website dauert laenger, nicht kuerzer');
});

// ==================================================================
test('Der Verbindungstest stuerzt nie ab', function (): void {
    // Hier stand ein Aufruf von ftp_connect() ohne die Pruefung, ob es
    // die FTP-Erweiterung auf diesem Server ueberhaupt gibt. Fehlt sie -
    // auf geteiltem Hosting oft der Fall - ist das kein Fehler, den man
    // abfangen kann, sondern ein Absturz: Fehler 500, ohne einen Hinweis
    // worauf. In den anderen Methoden stand die Pruefung laengst.
    $quelle = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Build/FtpDeployer.php'
    );

    // Jeder Aufruf einer ftp_-Funktion braucht davor eine Pruefung.
    $stellen = [];
    foreach (explode("\n", $quelle) as $nummer => $zeile) {
        if (preg_match('/(?<!function_exists\(.)ftp_(connect|ssl_connect)\(/', $zeile)) {
            $stellen[] = $nummer + 1;
        }
    }

    ok($stellen !== [], 'Es gibt Stellen, die FTP benutzen');

    $zeilen = explode("\n", $quelle);

    foreach ($stellen as $zeile) {
        // In den 30 Zeilen davor muss die Pruefung stehen.
        $davor = implode("\n", array_slice($zeilen, max(0, $zeile - 31), 30));

        ok(str_contains($davor, "function_exists('ftp_connect')"),
            'Vor Zeile ' . $zeile . ' wird geprueft, ob dieser Server FTP kann');
    }

    // Und der Test selbst muss ein Ergebnis liefern statt zu werfen -
    // auch wenn dort nichts erreichbar ist.
    $projectId = (int) \WebAtze\Core\Db::insert('projects', [
        'name' => 'Uploadtest', 'slug' => 'upload-' . bin2hex(random_bytes(4)),
        'status' => 'ready', 'created_at' => \WebAtze\Core\Db::now(),
        'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    foreach (['sftp' => 22, 'ftp' => 21] as $protokoll => $port) {
        \WebAtze\Build\FtpDeployer::saveTarget($projectId, [
            'protocol' => $protokoll,
            'host' => '127.0.0.1',
            'port' => 1,               // dort lauscht nichts
            'username' => 'niemand',
            'password' => 'auch-nicht',
            'path' => '/public_html/preview',
        ]);

        $ergebnis = \WebAtze\Build\FtpDeployer::test($projectId);

        is(false, $ergebnis['ok'], $protokoll . ': meldet sauber einen Fehlschlag');
        ok(($ergebnis['message'] ?? '') !== '', $protokoll . ': und sagt auch, warum');
        ok(array_key_exists('ordner', $ergebnis), $protokoll . ': die Antwort hat die erwartete Form');
    }

    \WebAtze\Core\Db::delete('deploy_targets', 'project_id = :p', ['p' => $projectId]);
    \WebAtze\Core\Db::delete('projects', 'id = :p', ['p' => $projectId]);
});

// ==================================================================
test('Der Pfad einer Subdomain wird gefunden', function (): void {
    // Ein FTP-Zugang laesst sich in cPanel nur fuer die Hauptdomain
    // anlegen. Der Ordner einer Subdomain liegt darunter, meist in
    // public_html und benannt wie die Subdomain. Wer das nicht weiss,
    // raet - und bekam frueher nur "gibt es dort nicht" zurueck.
    $orte = new ReflectionMethod(\WebAtze\Build\FtpDeployer::class, 'suchorte');
    $orte->setAccessible(true);

    $gesucht = $orte->invoke(null, '/public_html/preview', '/home/kunde');

    ok(in_array('/home/kunde', $gesucht, true), 'Im Heimatverzeichnis wird nachgesehen');
    ok(in_array('/home/kunde/public_html', $gesucht, true), 'Und in dessen public_html');
    ok(in_array('/public_html', $gesucht, true), 'Und eine Ebene ueber dem gewuenschten Pfad');
    ok(count($gesucht) === count(array_unique($gesucht)), 'Keine Stelle doppelt');

    // Aus dem Gefundenen soll der passende Ordner vorgeschlagen werden.
    $vor = new ReflectionMethod(\WebAtze\Build\FtpDeployer::class, 'vorschlagen');
    $vor->setAccessible(true);

    $gefunden = ['/public_html/alt', '/public_html/preview', '/public_html/mail'];

    is('/public_html/preview', $vor->invoke(null, $gefunden, '/public_html/preview'),
        'Der gleichnamige Ordner wird vorgeschlagen');
    is('/public_html/preview', $vor->invoke(null, $gefunden, '/irgendwo/preview'),
        'Auch wenn der Betreiber den falschen Oberordner geraten hat');
    is('', $vor->invoke(null, $gefunden, '/public_html/gibtsnicht'),
        'Ohne Treffer wird nichts erfunden');
    is('', $vor->invoke(null, [], '/public_html/preview'), 'Und ohne Fundstuecke erst recht nicht');

    // Der Pfad selbst darf nicht aus dem Zielverzeichnis ausbrechen.
    $sauber = new ReflectionMethod(\WebAtze\Build\FtpDeployer::class, 'cleanPath');
    $sauber->setAccessible(true);

    is('/public_html/preview', $sauber->invoke(null, 'public_html/preview'),
        'Ein fehlender Schraegstrich wird ergaenzt');
    is('/public_html/preview', $sauber->invoke(null, '/public_html/preview/'),
        'Ein ueberzaehliger faellt weg');
    is('/public_html/preview', $sauber->invoke(null, '//public_html///preview'),
        'Doppelte werden zusammengefasst');
    ok(!str_contains((string) $sauber->invoke(null, '/public_html/../../etc'), '..'),
        'Ausbrechen geht nicht');
});

// ==================================================================
test('Der Arbeiter arbeitet so lange, wie er darf', function (): void {
    // Das war die eigentliche Ursache, und sie ist unspektakulaer: Der
    // Arbeiter durfte fuenfzig Sekunden. Ein Aufruf an die KI dauert 20
    // bis 30 - also zwei Aufrufe je Durchlauf. Eine Website mit elf
    // Seiten und drei Sprachen braucht gegen achtzig Aufrufe. Bei einem
    // Cronjob je Minute sind das vierzig Minuten, in denen sich die
    // Anzeige kaum bewegt. Der Bau war nie kaputt, er war zaeh - und wer
    // nach zehn Minuten abbricht, sieht nur Stillstand.
    is(300, (int) \WebAtze\Core\Config::get('worker_budget_seconds', 0),
        'Fuenf Minuten je Durchlauf statt fuenfzig Sekunden');

    $worker = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Core/Worker.php'
    );

    // Bestehende Installationen tragen in ihrer config.php noch die
    // alten fuenfzig Sekunden - und die Datei wird beim Update
    // absichtlich nicht angefasst. Also darf nicht die Datei
    // entscheiden, sondern die Messung.
    ok(str_contains($worker, 'max($budget, 300)'),
        'Ohne beobachteten Abbruch wird laenger gearbeitet, auch bei alter Konfiguration');

    ok(str_contains($worker, 'min($budget, $ueberlebt - 8)'),
        'Nach einem Abbruch gilt die gemessene Grenze des Servers');

    // Zwei Arbeiter duerfen sich nicht ins Gehege kommen: Der zweite
    // findet den Auftrag gesperrt und geht sofort wieder.
    $jobs = (string) file_get_contents(dirname(__DIR__) . '/public_html/app/Core/Jobs.php');
    ok(str_contains($jobs, 'locked_until IS NULL OR locked_until <='),
        'Ein gesperrter Auftrag wird nicht zweimal genommen');

    // Und ein paar Fehlschlaege duerfen keinen halbfertigen Bau wegwerfen.
    preg_match('/MAX_ATTEMPTS = (\d+)/', $jobs, $t);
    ok((int) ($t[1] ?? 0) >= 5,
        'Ein Bau ueberlebt eine voruebergehende Stoerung (' . ($t[1] ?? '?') . ' Versuche)');
});

// ==================================================================
test('Ein Schritt haelt den Bau nicht endlos auf', function (): void {
    // Der Kern der Sache. Kommt ein Aufruf nicht durch, wurde bisher
    // derselbe Versuch wiederholt - wieder und wieder, ohne Fehler, ohne
    // Fortschritt. Das war der Stillstand bei 22 Prozent: kein Defekt,
    // sondern eine Schleife.
    //
    // Jetzt wird zweimal versucht, danach nimmt der Bau den Weg, der
    // ohne KI funktioniert. Eine Website nach dem Standardaufbau ist
    // unendlich viel besser als eine, die nie entsteht.
    $pipeline = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Build/Pipeline.php'
    );

    ok(str_contains($pipeline, 'function withFallback'), 'Es gibt einen Weg ohne die KI');
    ok(str_contains($pipeline, '$offen >= 2'), 'Nach zwei Fehlschlaegen wird er genommen');

    // Der Zaehler muss den Fehlschlag ueberleben, sonst faengt jeder
    // Anlauf bei null an und die Schleife bleibt.
    ok(str_contains($pipeline, 'Jobs::saveState'), 'Der Zaehler wird vor dem Weiterreichen gesichert');

    // Und die Position gehoert VOR die Arbeit in den Zustand. Andersherum
    // sichert ein Fehlschlag die alte Position mit, der Bau faellt
    // zurueck und faengt dieselbe Seite wieder an.
    $vorPlan = strpos($pipeline, "\$state['plan_index'] = \$index;");
    $planArbeit = strpos($pipeline, "'abschnitte-' . \$index");
    ok($vorPlan !== false && $planArbeit !== false && $vorPlan < $planArbeit,
        'Die Seitennummer steht im Zustand, bevor geplant wird');

    $vorTexte = strpos($pipeline, "\$state['group_index'] = \$gruppe;");
    $texteArbeit = strpos($pipeline, "'texte-' . \$index");
    ok($vorTexte !== false && $texteArbeit !== false && $vorTexte < $texteArbeit,
        'Und die Gruppennummer, bevor geschrieben wird');

    // Ein Fehler, den Warten nicht behebt - leeres Guthaben, falscher
    // Schluessel - darf NICHT durch einen Ersatzaufbau vertuscht werden.
    ok(str_contains($pipeline, 'catch (ConfigurationError $e)'),
        'Fehlende Einstellungen werden durchgereicht statt uebertuencht');

    // Fuer jede Stelle muss es einen Ersatz geben.
    $planer = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Ai/SitePlanner.php'
    );
    ok(str_contains($planer, 'function fallbackPlan'), 'Ersatz fuer die Seitenliste');
    ok(str_contains($planer, 'function fallbackSections'), 'Ersatz fuer die Abschnitte einer Seite');

    $schreiber = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Ai/ContentWriter.php'
    );
    ok(str_contains($schreiber, 'function writeGroupPlain'), 'Ersatz fuer die Texte');

    // Der Ersatz muss vollstaendige Seiten liefern, nicht Bruchstuecke.
    foreach (['/', '/leistungen', '/kontakt', '/impressum'] as $pfad) {
        $abschnitte = \WebAtze\Ai\SitePlanner::fallbackSections(['path' => $pfad, 'title' => 'Probe']);

        ok(count($abschnitte) >= 3, 'Ersatzaufbau fuer ' . $pfad . ' hat Substanz ('
            . count($abschnitte) . ' Abschnitte)');

        foreach ($abschnitte as $a) {
            ok(\WebAtze\Templates\Schema::forType((string) $a['type']) !== null,
                'Abschnittstyp "' . $a['type'] . '" gibt es wirklich');
        }
    }

    // Und was ersetzt wurde, muss dem Betreiber gesagt werden.
    ok(str_contains($pipeline, "\$state['hinweise'][]"), 'Ersatz wird vermerkt');
    ok(str_contains($pipeline, 'Hinweis: '), 'Und in der Schlussmeldung genannt');
});

// ==================================================================
test('Der Fehlerzaehler zaehlt Fehler, nicht Abholungen', function (): void {
    // Ein Bau laeuft absichtlich in vielen kleinen Takten und wird
    // deshalb oft abgeholt. Frueher stieg der Zaehler bei jeder
    // Abholung: Nach drei Takten galt jeder voruebergehende Fehler als
    // endgueltig, und eine Notbremse, die denselben Zaehler las, hielt
    // einen kerngesunden Bau an. Genau das ist im Probelauf passiert -
    // "nach 70 Anlaeufen angehalten", obwohl alles lief.
    $projectId = (int) \WebAtze\Core\Db::insert('projects', [
        'name' => 'Zaehlertest', 'slug' => 'zaehler-' . bin2hex(random_bytes(4)),
        'status' => 'building', 'created_at' => \WebAtze\Core\Db::now(),
        'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    $jobId = \WebAtze\Core\Jobs::enqueue('generate', [], $projectId);

    // Fuenfmal abholen und wieder freigeben - so laeuft ein normaler Bau.
    for ($i = 0; $i < 5; $i++) {
        $job = \WebAtze\Core\Jobs::claim();
        \WebAtze\Core\Jobs::yield((int) $job['id'], ['step' => 'inhalte']);
    }

    $job = \WebAtze\Core\Db::first('SELECT attempts FROM jobs WHERE id = :id', ['id' => $jobId]);
    is(0, (int) $job['attempts'], 'Fuenf Abholungen sind kein einziger Fehler');

    // Ein echter Fehlschlag zaehlt dagegen.
    \WebAtze\Core\Jobs::claim();
    \WebAtze\Core\Jobs::fail($jobId, 'Etwas ging schief', true);

    $job = \WebAtze\Core\Db::first('SELECT attempts, status FROM jobs WHERE id = :id', ['id' => $jobId]);
    is(1, (int) $job['attempts'], 'Ein Fehlschlag zaehlt');
    is('queued', (string) $job['status'], 'Und wird noch einmal versucht');

    // Geht es danach weiter, war der Fehler voruebergehend - der Zaehler
    // darf zurueck. Sonst summierten sich Fehler ueber Stunden auf.
    \WebAtze\Core\Jobs::progress($jobId, 'inhalte', 40, 'Weiter geht es', ['step' => 'inhalte']);

    $job = \WebAtze\Core\Db::first('SELECT attempts FROM jobs WHERE id = :id', ['id' => $jobId]);
    is(0, (int) $job['attempts'], 'Fortschritt setzt den Fehlerzaehler zurueck');

    \WebAtze\Core\Db::delete('jobs', 'project_id = :p', ['p' => $projectId]);
    \WebAtze\Core\Db::delete('projects', 'id = :p', ['p' => $projectId]);

    // Die Notbremse sieht auf die Uhr, nicht auf den Zaehler.
    $worker = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Core/Worker.php'
    );
    ok(str_contains($worker, 'MAX_RUNTIME_SECONDS'), 'Die Notbremse misst die Laufzeit');
    ok(!str_contains($worker, 'MAX_TICKS'), 'Und nicht mehr die Zahl der Abholungen');
});

// ==================================================================
test('Kein einzelner Aufruf muss gross sein', function (): void {
    // Der Plan entstand frueher in einem Aufruf: bis zu zwoelf Seiten
    // samt allen Abschnitten, mit hohem Aufwand gerechnet. Am echten
    // Dienst gemessen dauerte das 57 Sekunden. Bricht der Server vorher
    // ab, kommt dieser Aufruf nie zustande - und kein Fortsetzen der
    // Welt hilft, weil es nichts zum Fortsetzen gibt. Der Bau blieb
    // haengen, bevor ein einziges Wort geschrieben war.
    $planer = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Ai/SitePlanner.php'
    );

    ok(str_contains($planer, 'function planPages'), 'Die Seitenliste ist ein eigener Aufruf');
    ok(str_contains($planer, 'function planSections'), 'Die Abschnitte je Seite ebenfalls');

    // Und beide muessen klein sein.
    preg_match_all("/'max_tokens' => (\d+)/", $planer, $t);
    $groessen = array_map('intval', $t[1]);

    ok($groessen !== [], 'Es gibt Groessenangaben');

    foreach ($groessen as $g) {
        ok($g <= 3000, 'Ein Planungsaufruf bleibt klein (' . $g . ')');
    }

    // Der Planungsschritt muss sich Seite fuer Seite fortsetzen lassen.
    $pipeline = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Build/Pipeline.php'
    );

    ok(str_contains($pipeline, 'plan_index'), 'Der Planungsschritt merkt sich, wie weit er ist');
    ok(str_contains($pipeline, 'plan_pages'), 'Und behaelt die bereits geplanten Seiten');

    // Ebenso beim Schreiben und Uebersetzen.
    foreach (['ContentWriter', 'Translator'] as $datei) {
        $inhalt = (string) file_get_contents(
            dirname(__DIR__) . '/public_html/app/Ai/' . $datei . '.php'
        );
        preg_match("/'max_tokens' => (\d+)/", $inhalt, $m);
        ok((int) ($m[1] ?? 99999) <= 8000, $datei . ' fragt in kleinen Haeppchen');
    }
});

// ==================================================================
test('Ein toter Vorgang gibt seinen Auftrag schnell frei', function (): void {
    // Das war der eigentliche Stillstand: kein Fehler, sondern
    // Wartezeit. Die Sperre musste lang sein, damit kein zweiter
    // Arbeiter dazwischenfunkt - und nach jedem Abschuss lag der Auftrag
    // genau so lange brach. Bei 300 Sekunden hiess das fuenf Minuten je
    // Gruppe.
    //
    // Jetzt haelt ein lebender Vorgang seine Sperre selbst aufrecht
    // (Worker::beat alle fuenf Sekunden), also darf sie kurz sein.
    $sperre = (int) \WebAtze\Core\Config::get('job_lock_seconds', 0);

    ok($sperre > 0 && $sperre <= 60,
        'Die Sperre ist kurz (' . $sperre . 's)');

    $worker = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Core/Worker.php'
    );

    ok(str_contains($worker, 'Jobs::keepAlive'),
        'Der Herzschlag zieht die Sperre mit');

    // Und der Herzschlag muss auch waehrend eines laufenden Aufrufs
    // schlagen - dort stirbt der Vorgang ja.
    $client = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Ai/ClaudeClient.php'
    );

    ok(str_contains($client, 'CURLOPT_PROGRESSFUNCTION'),
        'Auch mitten in einem Aufruf schlaegt das Herz');
    ok(str_contains($client, 'Worker::beat'),
        'Und meldet sich beim Arbeiter');
});

// ==================================================================
test('Der Arbeiter misst, wie lange der Server ihn leben laesst', function (): void {
    // Auf geteiltem Hosting steht in der php.ini eine Zeitgrenze, und
    // set_time_limit() ist manchmal gesperrt. Wird der Vorgang
    // abgeschossen, endet er nicht sauber: Der Auftrag bleibt gesperrt
    // liegen, bis die Sperre ablaeuft. Das sah von aussen aus wie
    // Stillstand - in Wahrheit kam je Sperrzeit genau eine Gruppe dazu.
    //
    // Also wird gemessen. Ein Merker beim Start, ein Lebenszeichen
    // waehrend der Arbeit; findet der naechste Durchlauf den Merker noch
    // vor, ist der letzte gestorben - und der Abstand sagt, wie lange
    // dieser Server einem Vorgang zugesteht.
    $messen = new ReflectionMethod(\WebAtze\Core\Worker::class, 'measureLastTick');
    $messen->setAccessible(true);

    \WebAtze\Core\Settings::put('worker_survival_seconds', '');

    // Ein Durchlauf, der vor 60 Sekunden begann und nach 25 Sekunden
    // das letzte Lebenszeichen gab - also dort abgeschossen wurde.
    \WebAtze\Core\Settings::put('worker_tick_open', (string) (time() - 60));
    \WebAtze\Core\Settings::put('worker_tick_beat', (string) (time() - 35));

    $messen->invoke(null);

    is('25', (string) \WebAtze\Core\Settings::get('worker_survival_seconds', ''),
        'Die durchgehaltene Zeit wird erkannt');
    is('', (string) \WebAtze\Core\Settings::get('worker_tick_open', ''),
        'Und der Merker danach geleert');

    // Ein zweiter, laengerer Durchlauf darf den Wert nicht schoenrechnen.
    // Massgeblich ist die kuerzeste beobachtete Grenze.
    \WebAtze\Core\Settings::put('worker_tick_open', (string) (time() - 60));
    \WebAtze\Core\Settings::put('worker_tick_beat', (string) (time() - 20));
    $messen->invoke(null);

    is('25', (string) \WebAtze\Core\Settings::get('worker_survival_seconds', ''),
        'Ein guter Durchlauf hebt die gemessene Grenze nicht an');

    // Ein kuerzerer schon - danach muss vorsichtiger gerechnet werden.
    \WebAtze\Core\Settings::put('worker_tick_open', (string) (time() - 60));
    \WebAtze\Core\Settings::put('worker_tick_beat', (string) (time() - 46));
    $messen->invoke(null);

    is('14', (string) \WebAtze\Core\Settings::get('worker_survival_seconds', ''),
        'Ein kuerzerer senkt sie');

    // Ein sauber beendeter Durchlauf hinterlaesst keinen Merker - dann
    // gibt es nichts zu messen.
    \WebAtze\Core\Settings::put('worker_survival_seconds', '');
    \WebAtze\Core\Settings::put('worker_tick_open', '');
    $messen->invoke(null);

    is('', (string) \WebAtze\Core\Settings::get('worker_survival_seconds', ''),
        'Ohne Abbruch wird nichts gemessen');

    // Unsinnige Werte werden verworfen, nicht uebernommen.
    \WebAtze\Core\Settings::put('worker_tick_open', (string) (time() - 10));
    \WebAtze\Core\Settings::put('worker_tick_beat', (string) (time() - 8));
    $messen->invoke(null);

    is('', (string) \WebAtze\Core\Settings::get('worker_survival_seconds', ''),
        'Ein Fehlstart unter fuenf Sekunden zaehlt nicht');

    \WebAtze\Core\Settings::put('worker_survival_seconds', '');
    \WebAtze\Core\Settings::put('worker_tick_open', '');

    // Und die Anfragen selbst muessen knapp genug sein, dass eine in ein
    // schmales Zeitfenster passt. Die Dauer haengt vor allem daran, wie
    // viel geschrieben wird.
    $schreiber = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Ai/ContentWriter.php'
    );

    preg_match("/'max_tokens' => (\d+)/", $schreiber, $t);
    $grenze = (int) ($t[1] ?? 0);

    ok($grenze > 0 && $grenze <= 8000,
        'Eine Anfrage bleibt knapp genug fuer geteiltes Hosting (' . $grenze . ' Zeichen)');
});

// ==================================================================
test('Ein Server, der immer an derselben Stelle abbricht, wird erkannt', function (): void {
    // Der teuerste Fall: Der Cronjob ruft die Adresse per curl auf, also
    // gilt die Zeitgrenze des Webservers. Die ist kuerzer als eine
    // Anfrage an die KI dauert. Der Vorgang stirbt jedes Mal an
    // derselben Stelle, der Auftrag faengt sie jedes Mal neu an, und
    // bezahlt wird jedes Mal. Von aussen sieht das aus wie Stillstand.
    //
    // Nach drei Anlaeufen an derselben Gruppe wird deshalb angehalten -
    // mit einer Meldung, die sagt, was in cPanel zu aendern ist.
    $quelle = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Build/Pipeline.php'
    );

    ok(str_contains($quelle, 'gruppe_versuche'), 'Die Anlaeufe je Gruppe werden gezaehlt');
    ok(str_contains($quelle, '$versuche > 3'), 'Nach dem dritten ist Schluss');

    // Die Meldung muss brauchbar sein, nicht nur richtig.
    ok(str_contains($quelle, 'worker.php'), 'Sie nennt die Datei fuer den Cronjob');
    ok(str_contains($quelle, 'curl'), 'Und den wahrscheinlichen Grund');

    // Ein ConfigurationError haelt an, statt alle 30 Sekunden neu zu
    // probieren - sonst waere die Bremse wirkungslos.
    $stelle = strpos($quelle, '$versuche > 3');
    $danach = substr($quelle, (int) $stelle, 400);
    ok(str_contains($danach, 'ConfigurationError'),
        'Und der Auftrag haelt an, statt weiter Geld auszugeben');

    // Der Zaehler muss nach einer geschafften Gruppe zurueckgesetzt
    // werden, sonst haelt ein langsamer, aber gesunder Bau faelschlich an.
    ok(str_contains($quelle, "\$state['gruppe_versuche'] = [];"),
        'Nach einer geschafften Gruppe faengt die Zaehlung von vorn an');

    // Und die Sperre wird vor jedem Aufruf aufgefrischt, damit kein
    // zweiter Arbeiter dieselbe Anfrage noch einmal bezahlt.
    ok(str_contains($quelle, 'Jobs::keepAlive'), 'Die Sperre wird waehrend der Arbeit gehalten');

    $sperre = (int) \WebAtze\Core\Config::get('job_lock_seconds', 0);

    ok($sperre >= 15 && $sperre <= 60,
        'Die Sperre ist kurz genug, dass es nach einem Abbruch zuegig weitergeht ('
        . $sperre . 's)');
});

// ==================================================================
test('Das Aussehen erreicht auch den Verwaltungsbereich', function (): void {
    // Die Menueschaltflaeche stand in components.css. Die laedt nur die
    // oeffentliche Website. Im Verwaltungsbereich hatte sie deshalb gar
    // kein Aussehen - null mal null Pixel, nichts zum Antippen: Auf dem
    // Handy kam man nicht mehr ins Menue.
    $verzeichnis = dirname(__DIR__) . '/frontend/src';

    $admin = (string) file_get_contents($verzeichnis . '/admin/admin.css');
    $app = (string) file_get_contents($verzeichnis . '/styles/app.css');

    // Welche Bausteine bindet welche Seite ein?
    $importe = static function (string $inhalt): array {
        preg_match_all("/@import\s+'([^']+)'/", $inhalt, $t);
        return array_map(static fn (string $x): string => basename($x), $t[1]);
    };

    $adminTeile = $importe($admin);
    $appTeile = $importe($app);

    ok(in_array('controls.css', $adminTeile, true), 'Der Verwaltungsbereich laedt controls.css');
    ok(in_array('controls.css', $appTeile, true), 'Die Website ebenfalls');

    // Klassen, die in beiden Oberflaechen vorkommen, gehoeren in eine
    // Datei, die beide laden. Sonst fehlt das Aussehen auf einer Seite.
    $gemeinsam = ['wa-burger', 'wa-input', 'wa-select', 'wa-btn'];

    $controls = (string) file_get_contents($verzeichnis . '/styles/controls.css');
    $components = (string) file_get_contents($verzeichnis . '/styles/components.css');

    foreach ($gemeinsam as $klasse) {
        $inGeteilt = str_contains($controls, '.' . $klasse) || str_contains($admin, '.' . $klasse);
        $nurInComponents = str_contains($components, '.' . $klasse) && !$inGeteilt;

        ok(!$nurInComponents, 'Nicht nur in components.css: .' . $klasse);
    }

    // Und die Schaltflaeche braucht eine Groesse - ein <label> hat von
    // sich aus keine.
    preg_match('/\.wa-burger\s*\{([^}]*)\}/', $controls, $regel);
    $block = (string) ($regel[1] ?? '');

    ok(str_contains($block, 'display'), 'Die Menueschaltflaeche bekommt ein display');
    ok(str_contains($block, 'inline-size') || str_contains($block, 'width'),
        'Und eine Breite - sonst ist sie null Pixel gross');
});

// ==================================================================
test('Das Menue geht auch ohne JavaScript auf', function (): void {
    // Eine Verwaltung, deren Navigation an einem Skript haengt, ist
    // unbedienbar, sobald das Skript einmal nicht ankommt: alte Datei im
    // Zwischenspeicher, blockierte Adresse, abgeschaltetes JavaScript.
    // Deshalb schaltet ein Kaestchen die Leiste, rein ueber CSS.
    $layout = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Views/layouts/admin.php'
    );

    ok(str_contains($layout, 'id="wa-admin-nav"'), 'Es gibt ein Kaestchen zum Schalten');
    ok(str_contains($layout, 'for="wa-admin-nav"'), 'Und eine Beschriftung, die darauf zeigt');

    // Das Kaestchen muss VOR der Leiste stehen, sonst greift der
    // Geschwister-Wahlschalter nicht.
    $kaestchen = strpos($layout, 'id="wa-admin-nav"');
    $leiste = strpos($layout, 'class="wa-admin__side"');

    ok($kaestchen !== false && $leiste !== false && $kaestchen < $leiste,
        'Das Kaestchen steht vor der Leiste');

    $css = (string) file_get_contents(dirname(__DIR__) . '/frontend/src/admin/admin.css');

    ok(str_contains($css, '.wa-admin__nav-state:checked ~ .wa-admin__side'),
        'CSS oeffnet die Leiste ueber das Kaestchen');
    ok(!str_contains($css, '.wa-admin__nav-state { display: none'),
        'Das Kaestchen bleibt mit der Tabulatortaste erreichbar');
});

// ==================================================================
test('Auswahllisten sind auch aufgeklappt lesbar', function (): void {
    // Die aufgeklappte Liste zeichnet das Betriebssystem. Sie erbt die
    // helle Schriftfarbe des Feldes, setzt aber ihren eigenen, meist
    // weissen Hintergrund: weisse Schrift auf Weiss. Zu sehen war die
    // Auswahl erst, wenn der Zeiger eine Zeile markierte.
    $controls = (string) file_get_contents(
        dirname(__DIR__) . '/frontend/src/styles/controls.css'
    );

    ok(str_contains($controls, '.wa-select option'), 'Die Optionen bekommen eigene Farben');

    preg_match('/\.wa-select option,?\s*\.wa-select optgroup\s*\{([^}]*)\}/', $controls, $t);
    $block = (string) ($t[1] ?? '');

    ok(str_contains($block, 'background-color'), 'Mit eigener Flaeche');
    ok(str_contains($block, 'color'), 'Und eigener Schriftfarbe');

    // Entscheidend: undurchsichtig. Eine durchscheinende Flaeche laege
    // im Aufklappmenue wieder auf Weiss.
    ok(!str_contains($block, 'glass') && !str_contains($block, 'rgb(255 255 255 /'),
        'Die Flaeche ist undurchsichtig, nicht durchscheinend');
});

// ==================================================================
test('Jede benutzte Klasse ist auch eingebunden', function (): void {
    // Der Übersetzungsschritt scheiterte im Betrieb an einem fehlenden
    // "use": Translator liegt in Ai, Pipeline in Build. PHP merkt das
    // erst beim Aufruf – und der Aufruf passierte beim Kunden.
    $eingebaut = ['self', 'static', 'parent', 'ZipArchive', 'DateTime', 'DateTimeImmutable',
                  'Throwable', 'RuntimeException', 'InvalidArgumentException', 'Exception',
                  'PDO', 'PDOException', 'ReflectionClass', 'ReflectionMethod', 'TypeError',
                  'Error', 'Closure', 'Generator', 'JsonException', 'LogicException',
                  'DomainException', 'SplFileInfo'];

    $fehlend = [];
    $geprüft = 0;

    foreach (['Ai', 'Build', 'Core', 'Http', 'Domain', 'Templates'] as $ordner) {
        foreach (glob(dirname(__DIR__) . '/public_html/app/' . $ordner . '/*.php') ?: [] as $datei) {
            $geprüft++;
            $tokens = token_get_all((string) file_get_contents($datei));
            $ns = '';
            $use = [];
            $n = count($tokens);

            for ($i = 0; $i < $n; $i++) {
                $t = $tokens[$i];

                if (!is_array($t)) {
                    continue;
                }

                if ($t[0] === T_NAMESPACE) {
                    for ($j = $i + 1; $j < $n && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                        if (is_array($tokens[$j])
                            && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                            $ns .= $tokens[$j][1];
                        }
                    }
                    continue;
                }

                if ($t[0] === T_USE && $ns !== '') {
                    $roh = '';
                    for ($j = $i + 1; $j < $n && $tokens[$j] !== ';'; $j++) {
                        if (is_array($tokens[$j])) {
                            $roh .= $tokens[$j][1];
                        } elseif (in_array($tokens[$j], ['{', '}', ','], true)) {
                            $roh .= $tokens[$j];
                        }
                    }

                    $roh = trim($roh);

                    if ($roh === '' || str_contains($roh, '(')) {
                        continue;
                    }

                    if (preg_match('/^(.*?)\\\\\{(.+)\}$/s', $roh, $m) === 1) {
                        foreach (explode(',', $m[2]) as $teil) {
                            $teil = trim($teil);
                            if ($teil !== '') {
                                $use[$teil] = true;
                            }
                        }
                    } else {
                        $pos = strrpos($roh, '\\');
                        $use[$pos !== false ? substr($roh, $pos + 1) : $roh] = true;
                    }
                    continue;
                }

                if ($t[0] !== T_STRING || preg_match('/^[A-Z]/', $t[1]) !== 1) {
                    continue;
                }

                $vor = $tokens[$i - 1] ?? null;
                $nach = $tokens[$i + 1] ?? null;

                if (is_array($vor) && in_array($vor[0],
                        [T_NS_SEPARATOR, T_OBJECT_OPERATOR, T_FUNCTION, T_CONST], true)) {
                    continue;
                }

                $istKlasse = (is_array($nach) && $nach[0] === T_DOUBLE_COLON)
                    || (is_array($vor) && $vor[0] === T_NEW);

                if (!$istKlasse || isset($use[$t[1]]) || in_array($t[1], $eingebaut, true)) {
                    continue;
                }

                if (class_exists($ns . '\\' . $t[1]) || interface_exists($ns . '\\' . $t[1])
                    || class_exists($t[1])) {
                    continue;
                }

                $fehlend[] = basename($datei) . ':' . $t[2] . ' ' . $t[1];
            }
        }
    }

    ok($geprüft > 40, $geprüft . ' Dateien untersucht');
    ok($fehlend === [], 'Keine Klasse ohne Einbindung'
        . ($fehlend === [] ? '' : ': ' . implode(', ', array_slice($fehlend, 0, 5))));
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
