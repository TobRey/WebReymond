<?php
/**
 * Kopf und Fuss für die PHP-Seiten. Erzeugt dieselbe Umgebung wie die
 * statischen Seiten, damit support.php, statistik.php und formular.php
 * nicht aus dem Rahmen fallen.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const IANG_NAV = [
    'de' => [
        ['index.html', 'Start', 'start'],
        ['speisekarte.html', 'Speisekarte', 'speisekarte'],
        ['ueber-uns.html', 'Über uns', 'ueber'],
        ['kontakt.html', 'Kontakt', 'kontakt'],
    ],
    'en' => [
        ['en/index.html', 'Home', 'start'],
        ['en/menu.html', 'Menu', 'speisekarte'],
        ['en/about.html', 'About', 'ueber'],
        ['en/contact.html', 'Contact', 'kontakt'],
    ],
];

const IANG_WOCHE = [
    'de' => [['Montag', '11:00–14:00'], ['Dienstag', '11:00–14:00'], ['Mittwoch', '11:00–14:00'],
             ['Donnerstag', '11:00–14:00'], ['Freitag', '11:00–14:00'],
             ['Samstag', 'Geschlossen'], ['Sonntag', 'Geschlossen']],
    'en' => [['Monday', '11:00–14:00'], ['Tuesday', '11:00–14:00'], ['Wednesday', '11:00–14:00'],
             ['Thursday', '11:00–14:00'], ['Friday', '11:00–14:00'],
             ['Saturday', 'Closed'], ['Sunday', 'Closed']],
];

function seite_kopf(array $o): void
{
    $lang = ($o['lang'] ?? 'de') === 'en' ? 'en' : 'de';
    $titel = (string) ($o['titel'] ?? 'Iang’s Thai Food');
    $beschreibung = (string) ($o['beschreibung'] ?? '');
    $kopfId = (int) ($o['kopf_id'] ?? 0);
    $aktiv = (string) ($o['aktiv'] ?? '');
    $noindex = !empty($o['noindex']);
    $skip = $lang === 'en' ? 'Skip to content' : 'Direkt zum Inhalt';
    $menu = $lang === 'en' ? 'Menu' : 'Menü';
    $navlabel = $lang === 'en' ? 'Main navigation' : 'Hauptnavigation';
    $langlabel = $lang === 'en' ? 'Choose language' : 'Sprache wählen';

    if ($noindex) {
        header('X-Robots-Tag: noindex, nofollow');
    }
    header('Content-Type: text/html; charset=UTF-8');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Content-Type-Options: nosniff');

    echo '<!doctype html>' . "\n";
    echo '<html lang="' . $lang . '">' . "\n<head>\n";
    echo '  <meta charset="utf-8">' . "\n";
    echo '  <meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
    echo '  <title>' . h($titel) . "</title>\n";
    if ($beschreibung !== '') {
        echo '  <meta name="description" content="' . h($beschreibung) . '">' . "\n";
    }
    if ($noindex) {
        echo '  <meta name="robots" content="noindex, nofollow">' . "\n";
    }
    echo '  <meta name="theme-color" content="#0a0a1f">' . "\n";
    echo '  <link rel="icon" href="favicon.svg" type="image/svg+xml">' . "\n";
    echo '  <link rel="stylesheet" href="assets/css/style.css">' . "\n";
    echo "</head>\n<body>\n";
    echo '  <a class="skip-link" href="#inhalt">' . $skip . "</a>\n";

    echo '  <header class="site-header" id="seitenkopf" data-section="header" data-section-id="' . $kopfId . '">' . "\n";
    echo '    <div class="wrap site-header__inner">' . "\n";
    echo '      <a class="brand" href="' . ($lang === 'en' ? 'en/index.html' : 'index.html') . '">' . "\n";
    echo '        <span class="brand__name">Iang’s Thai Food</span>' . "\n";
    echo '        <span class="brand__tag">Imbiss + Take Away</span>' . "\n";
    echo "      </a>\n";
    echo '      <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="hauptnavigation">' . "\n";
    echo '        <span class="nav-toggle__bars" aria-hidden="true"></span>' . $menu . "\n";
    echo "      </button>\n";
    echo '      <nav class="nav" id="hauptnavigation" aria-label="' . $navlabel . '">' . "\n";
    echo '        <ul class="nav__list">' . "\n";
    foreach (IANG_NAV[$lang] as [$href, $label, $key]) {
        $cur = $key === $aktiv ? ' aria-current="page"' : '';
        echo '          <li><a class="nav__link" href="' . $href . '"' . $cur . '>' . h($label) . "</a></li>\n";
    }
    echo "        </ul>\n";
    echo '        <div class="lang">' . "\n";
    echo '          <span class="visually-hidden">' . $langlabel . '</span>' . "\n";
    echo '          <a href="index.html" hreflang="de" lang="de"' . ($lang === 'de' ? ' aria-current="true"' : '') . ">DE</a>\n";
    echo '          <span aria-hidden="true">·</span>' . "\n";
    echo '          <a href="en/index.html" hreflang="en" lang="en"' . ($lang === 'en' ? ' aria-current="true"' : '') . ">EN</a>\n";
    echo "        </div>\n";
    echo "      </nav>\n";
    $rufen = $lang === 'en' ? 'Call us' : 'Anrufen';
    echo '      <a class="btn btn--primary btn--call" href="tel:+41764427044">' . "\n";
    echo '        <span class="only-wide">076 442 70 44</span>' . "\n";
    echo '        <span class="only-narrow">' . $rufen . '</span>' . "\n";
    echo "      </a>\n";
    echo "    </div>\n";
    echo "  </header>\n";
    echo '  <main id="inhalt">' . "\n";
}

function seite_fuss(array $o): void
{
    $lang = ($o['lang'] ?? 'de') === 'en' ? 'en' : 'de';
    $fussId = (int) ($o['fuss_id'] ?? 0);
    $t = $lang === 'en'
        ? ['hours' => 'Opening hours', 'pages' => 'Pages', 'legal' => 'Legal',
           'imprint' => 'Imprint', 'privacy' => 'Privacy',
           'rights' => 'All rights reserved.', 'note' => 'Take away and catering in Bubendorf.']
        : ['hours' => 'Öffnungszeiten', 'pages' => 'Seiten', 'legal' => 'Rechtliches',
           'imprint' => 'Impressum', 'privacy' => 'Datenschutz',
           'rights' => 'Alle Rechte vorbehalten.', 'note' => 'Take Away und Catering in Bubendorf.'];

    echo "  </main>\n";
    echo '  <footer class="site-footer" id="seitenfuss" data-section="footer" data-section-id="' . $fussId . '">' . "\n";
    echo '    <div class="wrap">' . "\n      <div class=\"footer-grid\">\n";
    echo "        <div>\n          <h2>Iang’s Thai Food</h2>\n";
    echo "          <address>\n            Hauptstrasse 142<br>\n            4416 Bubendorf<br>\n";
    echo '            <a href="tel:+41764427044">076 442 70 44</a>' . "\n          </address>\n        </div>\n";
    echo '        <div>' . "\n          <h3>" . $t['hours'] . "</h3>\n          <ul>\n";
    foreach (IANG_WOCHE[$lang] as [$tag, $zeit]) {
        echo '            <li>' . $tag . ' <span aria-hidden="true">·</span> ' . $zeit . "</li>\n";
    }
    echo "          </ul>\n        </div>\n";
    echo '        <div>' . "\n          <h3>" . $t['pages'] . "</h3>\n          <ul>\n";
    foreach (IANG_NAV[$lang] as [$href, $label, $key]) {
        echo '            <li><a href="' . $href . '">' . h($label) . "</a></li>\n";
    }
    echo "          </ul>\n        </div>\n";
    echo '        <div>' . "\n          <h3>" . $t['legal'] . "</h3>\n          <ul>\n";
    echo '            <li><a href="' . ($lang === 'en' ? 'en/imprint.html' : 'impressum.html') . '">' . $t['imprint'] . "</a></li>\n";
    echo '            <li><a href="' . ($lang === 'en' ? 'en/privacy.html' : 'datenschutz.html') . '">' . $t['privacy'] . "</a></li>\n";
    echo '            <li><a href="doc.html">Anleitung</a></li>' . "\n";
    echo '            <li><a href="support.php">Support</a></li>' . "\n";
    echo "          </ul>\n        </div>\n      </div>\n";
    echo '      <div class="footer-bottom">' . "\n";
    echo '        <p class="muted">© Iang’s Thai Food, Bubendorf. ' . $t['rights'] . "</p>\n";
    echo "        <ul>\n          <li>" . $t['note'] . "</li>\n        </ul>\n";
    echo "      </div>\n    </div>\n  </footer>\n";
    echo '  <script defer src="assets/js/site.js"></script>' . "\n";
    echo '  <script defer src="https://web-atze.com/z.js?k=7dfc6a4f873afc53"></script>' . "\n";
    echo "</body>\n</html>\n";
}

/** Öffnungszeiten-Tabelle, gleiche Gestalt wie auf den statischen Seiten. */
function zeiten_tabelle(string $lang = 'de'): string
{
    $lang = $lang === 'en' ? 'en' : 'de';
    $cap = $lang === 'en' ? 'Opening hours' : 'Öffnungszeiten';
    $zeilen = '';
    foreach (IANG_WOCHE[$lang] as [$tag, $zeit]) {
        $zu = in_array($zeit, ['Geschlossen', 'Closed'], true) ? ' class="is-closed"' : '';
        $zeilen .= '          <tr><th scope="row">' . $tag . '</th><td' . $zu . '>' . $zeit . "</td></tr>\n";
    }
    return "      <table class=\"hours\">\n        <caption>{$cap}</caption>\n        <tbody>\n{$zeilen}        </tbody>\n      </table>";
}
