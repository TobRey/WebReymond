<?php

/**
 * Die eigene Website in Abschnittsdaten überführen.
 *
 *     php tools/eigene-website-uebernehmen.php          zeigt, was entstünde
 *     php tools/eigene-website-uebernehmen.php --schreiben   legt es an
 *
 * Läuft NUR auf der Kommandozeile. Das ist keine Förmlichkeit: Liefe es
 * über eine Adresse, könnte es jemand aus Versehen zum zweiten Mal
 * aufrufen und hätte danach jede Seite doppelt. Und es gehört nicht auf
 * den Server, sondern auf den eigenen Rechner, gegen eine Kopie der
 * Datenbank - dort schadet ein Fehlversuch nichts.
 *
 * Was es tut: Es liest die Texte, die heute in Lang/de.php und
 * Lang/en.php stehen, und schreibt sie als Abschnitte in die Datenbank.
 * Danach lassen sie sich im Editor ziehen und ändern.
 *
 * Was es nicht tut: die handgeschriebenen Ansichten löschen. Die bleiben
 * liegen, denn sie sind der Rückweg. Ausgeliefert wird per ZIP über eine
 * laufende Installation, und das überschreibt, löscht aber nie - "das
 * vorige ZIP nochmal hochladen" funktioniert nur, solange die Ansichten
 * noch da sind, die dessen SiteController braucht.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../public_html/app/bootstrap.php';

use WebAtze\Core\{Db, I18n, Schema as DbSchema};
use WebAtze\Build\Theme;
use WebAtze\Templates\Catalog;

$schreiben = in_array('--schreiben', $argv, true);

DbSchema::migrate();

echo "\n  Die eigene Website übernehmen\n";
echo "  " . str_repeat('=', 44) . "\n\n";

// ------------------------------------------------------------------
// Die Texte holen – einmal je Sprache
// ------------------------------------------------------------------

/** Alle Texte einer Sprache. */
function texte(string $sprache): array
{
    I18n::setLocale($sprache);

    return require dirname(__DIR__) . '/public_html/app/Lang/' . $sprache . '.php';
}

$de = texte('de');
$en = texte('en');

// ------------------------------------------------------------------
// Aus den Texten Abschnitte machen
// ------------------------------------------------------------------

/**
 * Die Seiten und ihre Abschnitte.
 *
 * Jeder Abschnitt bekommt seinen Typ aus dem Vorrat der geprüften
 * Vorlagen. Was auf der eigenen Website eine Besonderheit ist – der
 * 3D-Auftakt, der waagerechte Ablauf – steckt in der Variante, nicht in
 * einem eigenen Typ: Ein eigener Typ würde in jedes Kundenbackend
 * mitkopiert und bräuchte zwanzig erfundene Varianten, die genau eine
 * Website benutzen kann.
 */
function seiten(array $t): array
{
    $verweis = static fn (string $label, string $ziel): array => ['label' => $label, 'url' => $ziel];

    return [
        [
            'route_key' => '',
            'path' => '/',
            'title' => $t['meta']['tagline'] ?? 'Start',
            'meta_description' => $t['meta']['description'] ?? '',
            'in_navigation' => 0,
            'sections' => [
                ['hero', 'wa-3d', [
                    'eyebrow' => $t['hero']['eyebrow'] ?? '',
                    'title' => $t['hero']['title_plain'] ?? '',
                    'lead' => $t['hero']['lead'] ?? '',
                    'cta' => $verweis($t['hero']['cta_primary'] ?? '', '/kontakt'),
                    'cta_secondary' => $verweis($t['hero']['cta_secondary'] ?? '', '#referenzen'),
                    'badges' => array_map(
                        static fn (string $b): array => ['text' => $b],
                        array_values((array) ($t['hero']['badges'] ?? []))
                    ),
                ], ['anchor' => 'auftakt', 'heading' => 'h1']],

                ['logos', 'wa-laufband', [
                    'items' => array_map(
                        static fn (string $n): array => ['name' => $n, 'image' => null],
                        array_values((array) ($t['marquee'] ?? []))
                    ),
                ], []],

                ['services', 'raster-3', [
                    'eyebrow' => $t['services']['eyebrow'] ?? '',
                    'title' => $t['services']['title'] ?? '',
                    'lead' => $t['services']['lead'] ?? '',
                    'items' => eintraege($t['services']['items'] ?? [], [
                        'website' => 'rocket', 'redesign' => 'refresh', 'backend' => 'sliders',
                        'shop' => 'cart', 'booking' => 'calendar', 'care' => 'globe',
                    ]),
                ], ['anchor' => 'leistungen']],

                ['process', 'wa-waagerecht', [
                    'eyebrow' => $t['process']['eyebrow'] ?? '',
                    'title' => $t['process']['title'] ?? '',
                    'lead' => $t['process']['lead'] ?? '',
                    'items' => eintraege($t['process']['steps'] ?? []),
                ], ['anchor' => 'ablauf']],

                ['features', 'nummeriert', [
                    'eyebrow' => $t['why']['eyebrow'] ?? '',
                    'title' => $t['why']['title'] ?? '',
                    'lead' => $t['why']['lead'] ?? '',
                    'items' => eintraege($t['why']['items'] ?? [], [
                        0 => 'bolt', 1 => 'rocket', 2 => 'check',
                        3 => 'chat', 4 => 'shield', 5 => 'unlock',
                    ]),
                ], ['anchor' => 'warum']],

                // Referenzen sind Karten mit Titel und Untertitel, keine
                // Bilder mit Unterschrift - deshalb 'services' und nicht
                // 'gallery'. Ein eigener Typ waere die dritte
                // Moeglichkeit und die schlechteste: Er wuerde in jedes
                // Kundenbackend mitkopiert, fuer eine einzige Website.
                ['services', 'wa-karten', [
                    'eyebrow' => $t['work']['eyebrow'] ?? '',
                    'title' => $t['work']['title'] ?? '',
                    'lead' => $t['work']['lead'] ?? '',
                    'items' => [],
                ], ['anchor' => 'referenzen', 'quelle' => 'showcase', 'anzahl' => 9]],

                ['faq', 'standard', [
                    'eyebrow' => $t['faq']['eyebrow'] ?? '',
                    'title' => $t['faq']['title'] ?? '',
                    'items' => fragen($t['faq']['items'] ?? []),
                ], ['anchor' => 'fragen']],

                ['cta', 'wa-karte', [
                    'title' => $t['cta']['title'] ?? '',
                    'lead' => $t['cta']['lead'] ?? '',
                    'cta' => $verweis($t['cta']['button'] ?? '', '/kontakt'),
                ], []],
            ],
        ],

        [
            'route_key' => 'services',
            'path' => '/leistungen',
            'title' => $t['services']['title'] ?? 'Leistungen',
            'meta_description' => $t['services']['lead'] ?? '',
            'in_navigation' => 1,
            'sections' => [
                ['services', 'abwechselnd', [
                    'eyebrow' => $t['services']['eyebrow'] ?? '',
                    'title' => $t['services']['title'] ?? '',
                    'lead' => $t['services']['lead'] ?? '',
                    'items' => eintraege($t['services']['items'] ?? [], [
                        'website' => 'rocket', 'redesign' => 'refresh', 'backend' => 'sliders',
                        'shop' => 'cart', 'booking' => 'calendar', 'care' => 'globe',
                    ]),
                ], ['heading' => 'h1', 'anchor' => 'leistungen']],

                ['cta', 'wa-karte', [
                    'title' => $t['cta']['title'] ?? '',
                    'lead' => $t['cta']['lead'] ?? '',
                    'cta' => ['label' => $t['cta']['button'] ?? '', 'url' => '/kontakt'],
                ], []],
            ],
        ],

        [
            'route_key' => 'process',
            'path' => '/ablauf',
            'title' => $t['process']['title'] ?? 'Ablauf',
            'meta_description' => $t['process']['lead'] ?? '',
            'in_navigation' => 1,
            'sections' => [
                ['process', 'wa-waagerecht', [
                    'eyebrow' => $t['process']['eyebrow'] ?? '',
                    'title' => $t['process']['title'] ?? '',
                    'lead' => $t['process']['lead'] ?? '',
                    'items' => eintraege($t['process']['steps'] ?? []),
                ], ['heading' => 'h1', 'anchor' => 'ablauf']],

                ['faq', 'standard', [
                    'eyebrow' => $t['faq']['eyebrow'] ?? '',
                    'title' => $t['faq']['title'] ?? '',
                    'items' => fragen($t['faq']['items'] ?? []),
                ], ['anchor' => 'fragen']],

                ['cta', 'wa-karte', [
                    'title' => $t['cta']['title'] ?? '',
                    'lead' => $t['cta']['lead'] ?? '',
                    'cta' => ['label' => $t['cta']['button'] ?? '', 'url' => '/kontakt'],
                ], []],
            ],
        ],

        [
            'route_key' => 'work',
            'path' => '/referenzen',
            'title' => $t['work']['title'] ?? 'Referenzen',
            'meta_description' => $t['work']['lead'] ?? '',
            'in_navigation' => 1,
            'sections' => [
                ['services', 'wa-karten', [
                    'eyebrow' => $t['work']['eyebrow'] ?? '',
                    'title' => $t['work']['title'] ?? '',
                    'lead' => $t['work']['lead'] ?? '',
                    'items' => [],
                ], ['heading' => 'h1', 'anchor' => 'referenzen', 'quelle' => 'showcase']],
            ],
        ],

        [
            'route_key' => 'contact',
            'path' => '/kontakt',
            'title' => $t['contact']['title'] ?? 'Kontakt',
            'meta_description' => $t['contact']['lead'] ?? '',
            'in_navigation' => 1,
            'sections' => [
                ['contact', 'standard', [
                    'eyebrow' => $t['contact']['eyebrow'] ?? '',
                    'title' => $t['contact']['title'] ?? '',
                    'lead' => $t['contact']['lead'] ?? '',
                    'show_form' => true,
                ], ['heading' => 'h1', 'anchor' => 'kontakt']],
            ],
        ],

        [
            'route_key' => 'imprint',
            'path' => '/impressum',
            'title' => $t['imprint']['title'] ?? 'Impressum',
            'meta_description' => '',
            'in_navigation' => 0,
            'sections' => [
                ['text', 'standard', [
                    'title' => $t['imprint']['title'] ?? '',
                    'body' => fliesstext([
                        $t['imprint']['intro'] ?? '',
                        $t['imprint']['responsible'] ?? '',
                        $t['imprint']['contact'] ?? '',
                        '<h3>' . ($t['imprint']['disclaimer_title'] ?? '') . '</h3>',
                        $t['imprint']['disclaimer'] ?? '',
                        '<h3>' . ($t['imprint']['copyright_title'] ?? '') . '</h3>',
                        $t['imprint']['copyright'] ?? '',
                    ]),
                ], ['heading' => 'h1', 'anchor' => 'impressum']],
            ],
        ],

        [
            'route_key' => 'privacy',
            'path' => '/datenschutz',
            'title' => $t['privacy']['title'] ?? 'Datenschutz',
            'meta_description' => '',
            'in_navigation' => 0,
            'sections' => [
                ['text', 'standard', [
                    'title' => $t['privacy']['title'] ?? '',
                    'body' => fliesstext(array_merge(
                        [$t['privacy']['intro'] ?? ''],
                        abschnitte($t['privacy']['sections'] ?? [])
                    )),
                ], ['heading' => 'h1', 'anchor' => 'datenschutz']],
            ],
        ],
    ];
}

/** Aus einer Liste von Einträgen die Form machen, die das Schema erwartet. */
function eintraege(array $roh, array $symbole = []): array
{
    $liste = [];

    foreach ($roh as $schluessel => $eintrag) {
        if (!is_array($eintrag)) {
            continue;
        }

        $liste[] = array_filter([
            'title' => (string) ($eintrag['title'] ?? ''),
            'text' => (string) ($eintrag['text'] ?? $eintrag['lead'] ?? ''),
            'icon' => (string) ($symbole[$schluessel] ?? $symbole[count($liste)] ?? 'check'),
        ], static fn (string $v): bool => $v !== '');
    }

    return $liste;
}

/** Fragen und Antworten. */
function fragen(array $roh): array
{
    $liste = [];

    foreach ($roh as $eintrag) {
        if (!is_array($eintrag)) {
            continue;
        }

        $liste[] = [
            'question' => (string) ($eintrag['q'] ?? $eintrag['question'] ?? ''),
            'answer' => (string) ($eintrag['a'] ?? $eintrag['answer'] ?? ''),
        ];
    }

    return $liste;
}

/** Aus benannten Abschnitten eines Rechtstexts Überschrift und Absatz. */
function abschnitte(array $roh): array
{
    $teile = [];

    foreach ($roh as $eintrag) {
        if (is_array($eintrag)) {
            $teile[] = '<h3>' . (string) ($eintrag['title'] ?? '') . '</h3>';
            $teile[] = (string) ($eintrag['text'] ?? $eintrag['body'] ?? '');
            continue;
        }

        $teile[] = (string) $eintrag;
    }

    return $teile;
}

/** Aus einer Reihe Textstücke einen Fliesstext. */
function fliesstext(array $teile): string
{
    $html = '';

    foreach ($teile as $teil) {
        $teil = trim((string) $teil);

        if ($teil === '') {
            continue;
        }

        $html .= str_starts_with($teil, '<') ? $teil : '<p>' . $teil . '</p>';
    }

    return $html;
}

// ------------------------------------------------------------------
// Zeigen oder schreiben
// ------------------------------------------------------------------

$seitenDe = seiten($de);
$seitenEn = seiten($en);

$abschnitteGesamt = array_sum(array_map(
    static fn (array $s): int => count($s['sections']),
    $seitenDe
));

printf("  %d Seiten, %d Abschnitte\n\n", count($seitenDe), $abschnitteGesamt);

foreach ($seitenDe as $platz => $seite) {
    printf("  %-14s %-28s %d Abschnitte\n", $seite['path'], $seite['title'], count($seite['sections']));

    foreach ($seite['sections'] as [$typ, $variante, $inhalt, $ueber]) {
        $eintraege = count($inhalt['items'] ?? []);

        printf(
            "      %-12s %-16s %s%s\n",
            $typ,
            $variante,
            mb_substr((string) ($inhalt['title'] ?? ''), 0, 32),
            $eintraege > 0 ? ' (' . $eintraege . ')' : ''
        );
    }
}

if (!$schreiben) {
    echo "\n  Nichts geschrieben. Mit --schreiben anlegen.\n\n";
    exit(0);
}

// ------------------------------------------------------------------

$vorhanden = Db::first("SELECT id FROM projects WHERE source = 'eigen' LIMIT 1");

if ($vorhanden !== null) {
    echo "\n  Es gibt die eigene Website schon (Projekt " . (int) $vorhanden['id'] . ").\n";
    echo "  Ein zweiter Durchlauf würde alles doppelt anlegen. Abbruch.\n\n";
    exit(1);
}

$thema = Theme::build([
    'primary' => '#2b1b9e',
    'secondary' => '#17c8c8',
    'accent' => '#0a0a1f',
    'mode' => 'dunkel',
], 'technical');

$projekt = Db::insert('projects', [
    'name' => 'WebAtze',
    'slug' => 'webatze',
    'source' => 'eigen',
    'status' => 'done',
    'locale' => 'de',
    'locales' => 'de,en',
    'domain' => parse_url((string) \WebAtze\Core\Config::get('app_url', ''), PHP_URL_HOST) ?: '',
    'style_pack' => 'webatze',
    'brief' => ['company' => 'WebAtze'],
    'theme' => $thema,
    'created_at' => Db::now(),
    'updated_at' => Db::now(),
]);

foreach ($seitenDe as $platz => $seite) {
    $en = $seitenEn[$platz] ?? $seite;

    $seiteId = Db::insert('project_pages', [
        'project_id' => $projekt,
        'path' => $seite['path'],
        'route_key' => $seite['route_key'],
        'layout' => 'flach',
        'title' => $seite['title'],
        'meta_description' => $seite['meta_description'],
        'in_navigation' => $seite['in_navigation'],
        'sort_order' => $platz,
        'translations' => ['en' => [
            'title' => $en['title'],
            'meta_description' => $en['meta_description'],
        ]],
        'created_at' => Db::now(),
        'updated_at' => Db::now(),
    ]);

    foreach ($seite['sections'] as $nummer => [$typ, $variante, $inhalt, $ueber]) {
        // Gibt es die Variante nicht, wird die erste des Typs genommen.
        // Ein Abschnitt mit einer erfundenen Variante würde sonst still
        // anders aussehen als gedacht.
        if (!Catalog::exists($typ, $variante)) {
            $variante = Catalog::defaultKey($typ);
        }

        $enInhalt = $en['sections'][$nummer][2] ?? null;

        Db::insert('project_sections', [
            'project_id' => $projekt,
            'page_id' => $seiteId,
            'type' => $typ,
            'template_key' => $variante,
            'content' => $inhalt,
            'overrides' => $ueber,
            'effects' => [],
            'translations' => is_array($enInhalt) ? ['en' => $enInhalt] : null,
            'hidden' => 0,
            'sort_order' => $nummer,
            'created_at' => Db::now(),
            'updated_at' => Db::now(),
        ]);
    }
}

echo "\n  Angelegt als Projekt " . $projekt . ".\n";
echo "  Zum Vergleichen: eine Seite mit ?stil=daten aufrufen, angemeldet.\n\n";
