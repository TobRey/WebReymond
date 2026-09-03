<?php
/**
 * Inhalte laden und ausgeben.
 *
 * Die Website wird auf dem Server zusammengesetzt. Sie zeigt immer den Stand
 * aus content/content.json; fehlt dort ein Wert, kommt der Grundtext aus
 * lib/defaults.php. Dadurch steht auf der Seite nie ein leeres Feld und nie
 * ein Platzhalter.
 */

if (!defined('RT_STORE')) { require_once __DIR__ . '/store.php'; }
require_once __DIR__ . '/defaults.php';

define('RT_CONTENT_FILE', RT_CONTENT . '/content.json');

/** Gespeicherte Aenderungen (kann leer sein). */
function rt_content_stored()
{
    static $cache = null;
    if ($cache === null) {
        $cache = rt_read(RT_CONTENT_FILE, array());
        if (!is_array($cache)) { $cache = array(); }
    }
    return $cache;
}

/**
 * Fertiger Inhalt einer Sprache: Gespeichertes gewinnt, sonst der Grundtext.
 *
 * Texte: ein leeres Feld faellt auf den Grundtext zurueck.
 * Listen: eine gespeicherte Liste gilt vollstaendig – auch dann, wenn nur noch
 * ein Eintrag darin steht oder gar keiner mehr.
 */
function rt_content($lang)
{
    $lang = ($lang === 'en') ? 'en' : 'de';
    $defaults = rt_defaults();
    $stored   = rt_content_stored();

    $out = $defaults[$lang];
    $own = isset($stored[$lang]) && is_array($stored[$lang]) ? $stored[$lang] : array();

    foreach ($own as $key => $value) {
        if (is_array($value)) {
            $clean = array();
            foreach ($value as $row) {
                if (is_array($row)) { $clean[] = $row; }
            }
            $out[$key] = $clean;
        } elseif (is_string($value) && $value !== '') {
            $out[$key] = $value;
        }
    }

    /* Bilder gelten fuer beide Sprachen. */
    $media = isset($defaults['media']) ? $defaults['media'] : array();
    if (isset($stored['media']) && is_array($stored['media'])) {
        foreach ($stored['media'] as $slot => $file) {
            if (is_string($file) && $file !== '') { $media[$slot] = $file; }
        }
    }
    $out['_media'] = $media;

    return $out;
}

/** Ein Textfeld, fuer die Ausgabe entschaerft. */
function rt_t($c, $key)
{
    return rt_h(isset($c[$key]) && is_string($c[$key]) ? $c[$key] : '');
}

/** Ein Textfeld, unveraendert (fuer Attribute, die selbst entschaerft werden). */
function rt_raw($c, $key)
{
    return isset($c[$key]) && is_string($c[$key]) ? $c[$key] : '';
}

/** Eine Liste als Array von Eintraegen. */
function rt_list($c, $key)
{
    if (!isset($c[$key]) || !is_array($c[$key])) { return array(); }
    $out = array();
    foreach ($c[$key] as $row) {
        if (is_array($row)) { $out[] = $row; }
    }
    return $out;
}

/** Ein Feld innerhalb eines Listeneintrags, entschaerft. */
function rt_f($item, $key)
{
    return rt_h(isset($item[$key]) && is_string($item[$key]) ? $item[$key] : '');
}

/** Ein Feld innerhalb eines Listeneintrags, unveraendert. */
function rt_fr($item, $key)
{
    return isset($item[$key]) && is_string($item[$key]) ? $item[$key] : '';
}

/**
 * Verweise pruefen.
 *
 * Erlaubt sind: https://, http://, mailto:, tel:, eigene Seiten (/… oder
 * seite.html) und Sprungmarken (#…). Alles andere – vor allem javascript:
 * und data: – wird verworfen, damit ein Tippfehler im Bearbeitungsbereich
 * keine Luecke aufreisst.
 */
function rt_url($value)
{
    $value = trim((string) $value);
    if ($value === '') { return ''; }
    if (preg_match('/[\x00-\x1F\x7F]/', $value)) { return ''; }
    if (rt_len($value) > 300) { return ''; }

    if (strpos($value, '#') === 0) { return $value; }
    if (strpos($value, '/') === 0 && strpos($value, '//') !== 0) { return $value; }

    $lower = rt_lower($value);
    foreach (array('https://', 'http://', 'mailto:', 'tel:') as $scheme) {
        if (strpos($lower, $scheme) === 0) { return $value; }
    }
    /* Ein Doppelpunkt ohne erlaubtes Schema: nicht ausliefern. */
    if (strpos($value, ':') !== false) { return ''; }

    /* „beispiel.ch/seite“ ist als Adresse gemeint. */
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]*\.[A-Za-z]{2,}(\/|$|\?|#)/', $value)) {
        return 'https://' . $value;
    }
    /* Bleibt: eine Datei auf dieser Website. */
    if (preg_match('~^[A-Za-z0-9._/-]+$~', $value) && strpos($value, '..') === false) {
        return $value;
    }
    return '';
}

/** Zeigt der Verweis auf eine fremde Website? */
function rt_url_extern($url)
{
    $lower = rt_lower($url);
    return (strpos($lower, 'http://') === 0 || strpos($lower, 'https://') === 0)
        && strpos($lower, 'https://reymond-tobias.ch') !== 0;
}

/**
 * Einen Verweis ausgeben. Leerer Text oder leere Adresse: es kommt nichts.
 *
 * @param string $text   Beschriftung
 * @param string $url    Ziel
 * @param string $class  CSS-Klassen des Verweises
 * @param string $newTab Hinweis fuer Screenreader, sprachabhaengig
 * @param bool   $arrow  Pfeil anhaengen
 */
function rt_link($text, $url, $class = 'btn btn--small', $newTab = '', $arrow = true)
{
    $text = trim((string) $text);
    $url  = rt_url($url);
    if ($text === '' || $url === '') { return ''; }

    $extern = rt_url_extern($url);
    $out  = '<a class="' . rt_h($class) . '" href="' . rt_h($url) . '"';
    if ($extern) { $out .= ' target="_blank" rel="noopener"'; }
    $out .= '>' . rt_h($text);
    if ($arrow) { $out .= ' <span class="btn__arrow" aria-hidden="true">&rarr;</span>'; }
    if ($extern && $newTab !== '') {
        $out .= '<span class="visually-hidden"> (' . rt_h($newTab) . ')</span>';
    }
    $out .= '</a>';
    return $out;
}

/** Verweis eines Listeneintrags (Felder link_text und link_url). */
function rt_item_link($item, $class = 'btn btn--small', $newTab = '')
{
    return rt_link(rt_fr($item, 'link_text'), rt_fr($item, 'link_url'), $class, $newTab);
}

/** Fliesstext in Absaetze aufteilen. Eine Leerzeile trennt zwei Absaetze. */
function rt_paragraphs($text, $class = '')
{
    $text = str_replace(array("\r\n", "\r"), "\n", (string) $text);
    $blocks = preg_split('/\n{2,}/', trim($text));
    $out = '';
    foreach ($blocks as $block) {
        $block = trim(preg_replace('/\s*\n\s*/', ' ', $block));
        if ($block === '') { continue; }
        $out .= '<p' . ($class !== '' ? ' class="' . rt_h($class) . '"' : '') . '>' . rt_h($block) . "</p>\n";
    }
    return $out;
}

/**
 * Mehrzeilige Angabe (Adresse) mit Zeilenumbruechen ausgeben.
 *
 * $skip laesst eine Zeile weg, die genau so schon daneben steht – in der
 * Fusszeile zum Beispiel den Namen, der dort bereits als Ueberschrift steht.
 */
function rt_lines($text, $skip = '')
{
    $text = str_replace(array("\r\n", "\r"), "\n", (string) $text);
    $skip = rt_lower(trim($skip));
    $lines = array();
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if ($line === '') { continue; }
        if ($skip !== '' && rt_lower($line) === $skip) { continue; }
        $lines[] = rt_h($line);
    }
    return implode("<br>\n", $lines);
}

/** Telefonnummer als waehlbare Adresse. */
function rt_tel_href($number)
{
    $clean = preg_replace('/[^0-9+]/', '', (string) $number);
    return $clean === '' ? '' : 'tel:' . $clean;
}

/** Adresse eines hochgeladenen Bildes, oder '' wenn keines hinterlegt ist. */
function rt_media_src($c, $slot, $base = '')
{
    $file = isset($c['_media'][$slot]) ? $c['_media'][$slot] : '';
    if (!is_string($file) || !preg_match('/^[A-Za-z0-9._-]+$/', $file)) { return ''; }
    if (!is_file(RT_MEDIA . '/' . $file)) { return ''; }
    return $base . 'content/media/' . $file;
}
