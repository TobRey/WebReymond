<?php
/**
 * Was laesst sich im Bearbeitungsbereich aendern?
 *
 * Zwei Arten von Feldern:
 *   fields  – einzelne Texte (Überschrift, Fliesstext, Verweis)
 *   lists   – Listen mit beliebig vielen Eintraegen (Stationen, Projekte …)
 *
 * Jeder Listeneintrag darf einen eigenen Verweis bekommen: link_text ist die
 * Beschriftung, link_url das Ziel. Bleibt eines der beiden leer, erscheint auf
 * der Website kein Knopf.
 *
 * Die Grundtexte stehen in lib/defaults.php. Wird ein Feld hier leer
 * gespeichert, zeigt die Website automatisch wieder den Grundtext.
 */

/** Beschriftungen fuer den Verweis eines Eintrags. */
function rt_link_fields($hint = '')
{
    return array(
        array('key' => 'link_text', 'label' => 'Verweis — Beschriftung', 'type' => 'text', 'max' => 60,
              'hint' => 'Was auf dem Knopf steht, zum Beispiel „Projekt ansehen". Leer lassen: kein Knopf.'),
        array('key' => 'link_url', 'label' => 'Verweis — Adresse', 'type' => 'url', 'max' => 300,
              'hint' => $hint !== '' ? $hint : 'Zum Beispiel https://github.com/… , eine Sprungmarke wie #kontakt oder mailto:name@beispiel.ch'),
    );
}

function rt_schema()
{
    return array(

        /* ---------------------------------------------------------------- */
        array(
            'title' => 'Aufmacher (ganz oben)',
            'note'  => 'Der grosse Name „Reymond Tobias" ist fest eingebaut. Alles andere auf dem Aufmacher änderst du hier.',
            'fields' => array(
                array('key' => 'hero_kicker', 'label' => 'Kleine Zeile über dem Namen', 'type' => 'text', 'max' => 90),
                array('key' => 'hero_text', 'label' => 'Einleitungssatz', 'type' => 'textarea', 'max' => 400),
                array('key' => 'hero_cta1_text', 'label' => 'Erster Knopf — Beschriftung', 'type' => 'text', 'max' => 40),
                array('key' => 'hero_cta1_url', 'label' => 'Erster Knopf — Ziel', 'type' => 'url', 'max' => 300,
                      'hint' => 'Eine Stelle auf dieser Seite (#kontakt, #projekte) oder eine ganze Adresse.'),
                array('key' => 'hero_cta2_text', 'label' => 'Zweiter Knopf — Beschriftung', 'type' => 'text', 'max' => 40),
                array('key' => 'hero_cta2_url', 'label' => 'Zweiter Knopf — Ziel', 'type' => 'url', 'max' => 300),
            ),
            'lists' => array(
                array(
                    'key' => 'hero_fakten',
                    'label' => 'Eckdaten unter dem Einleitungssatz',
                    'item' => 'Eckdatum',
                    'add' => 'Eckdatum hinzufügen',
                    'title_field' => 'label',
                    'max' => 6,
                    'note' => 'Kurze Angaben nebeneinander, zum Beispiel Beruf, Standort, E-Mail.',
                    'fields' => array(
                        array('key' => 'label', 'label' => 'Bezeichnung', 'type' => 'text', 'max' => 30),
                        array('key' => 'wert', 'label' => 'Angabe', 'type' => 'text', 'max' => 90),
                        array('key' => 'link_url', 'label' => 'Verweis (freiwillig)', 'type' => 'url', 'max' => 300,
                              'hint' => 'Wenn hier etwas steht, wird die Angabe anklickbar.'),
                    ),
                ),
            ),
        ),

        /* ---------------------------------------------------------------- */
        array(
            'title' => 'Über mich',
            'fields' => array(
                array('key' => 'ueber_eyebrow', 'label' => 'Kleine Zeile über der Überschrift', 'type' => 'text', 'max' => 40),
                array('key' => 'ueber_titel', 'label' => 'Überschrift', 'type' => 'text', 'max' => 80),
                array('key' => 'ueber_lede', 'label' => 'Hervorgehobener Satz', 'type' => 'text', 'max' => 220),
                array('key' => 'ueber_text', 'label' => 'Fliesstext', 'type' => 'textarea', 'max' => 2500, 'tall' => true,
                      'hint' => 'Eine Leerzeile zwischen zwei Absätzen erzeugt einen neuen Absatz.'),
                array('key' => 'ueber_bild_alt', 'label' => 'Bildbeschreibung für Screenreader', 'type' => 'text', 'max' => 160,
                      'hint' => 'Beschreibt, was auf dem Porträt zu sehen ist. Wird nicht angezeigt, aber vorgelesen.'),
            ),
            'lists' => array(
                array(
                    'key' => 'ueber_fakten',
                    'label' => 'Drei Felder unter dem Text',
                    'item' => 'Feld',
                    'add' => 'Feld hinzufügen',
                    'title_field' => 'titel',
                    'max' => 8,
                    'fields' => array(
                        array('key' => 'titel', 'label' => 'Grosse Zeile', 'type' => 'text', 'max' => 40),
                        array('key' => 'text', 'label' => 'Kleine Zeile', 'type' => 'text', 'max' => 90),
                    ),
                ),
            ),
        ),

        /* ---------------------------------------------------------------- */
        array(
            'title' => 'Was ich mache',
            'note'  => 'Oben die Schwerpunkte als Karten, darunter die Kenntnisse als Felder. Beide Listen lassen sich beliebig erweitern.',
            'fields' => array(
                array('key' => 'arbeit_eyebrow', 'label' => 'Kleine Zeile über der Überschrift', 'type' => 'text', 'max' => 40),
                array('key' => 'arbeit_titel', 'label' => 'Überschrift', 'type' => 'text', 'max' => 80),
                array('key' => 'arbeit_lede', 'label' => 'Satz darunter', 'type' => 'text', 'max' => 220),
            ),
            'lists' => array(
                array(
                    'key' => 'schwerpunkte',
                    'label' => 'Schwerpunkte',
                    'item' => 'Schwerpunkt',
                    'add' => 'Schwerpunkt hinzufügen',
                    'title_field' => 'titel',
                    'max' => 12,
                    'note' => 'Die Nummern 01, 02, 03 setzt die Website selbst.',
                    'fields' => array_merge(array(
                        array('key' => 'titel', 'label' => 'Titel', 'type' => 'text', 'max' => 60),
                        array('key' => 'text', 'label' => 'Beschreibung', 'type' => 'textarea', 'max' => 500),
                    ), rt_link_fields()),
                ),
            ),
            'fields_after' => array(
                array('key' => 'kenntnisse_titel', 'label' => 'Überschrift über den Kenntnissen', 'type' => 'text', 'max' => 80),
            ),
            'lists_after' => array(
                array(
                    'key' => 'kenntnisse',
                    'label' => 'Kenntnisse',
                    'item' => 'Bereich',
                    'add' => 'Bereich hinzufügen',
                    'title_field' => 'titel',
                    'max' => 12,
                    'note' => 'Zum Beispiel Programmiersprachen, Werkzeuge, Sprachen, Zertifikate.',
                    'fields' => array_merge(array(
                        array('key' => 'titel', 'label' => 'Überschrift', 'type' => 'text', 'max' => 60),
                        array('key' => 'text', 'label' => 'Aufzählung oder Satz', 'type' => 'textarea', 'max' => 400),
                    ), rt_link_fields('Zum Beispiel ein Zertifikat als PDF oder ein Profil.')),
                ),
            ),
        ),

        /* ---------------------------------------------------------------- */
        array(
            'title' => 'Projekte',
            'note'  => 'Hier zeigst du, was du gebaut hast. Jedes Projekt darf einen eigenen Verweis haben — auf GitHub, auf eine Website, auf ein Video.',
            'fields' => array(
                array('key' => 'projekte_eyebrow', 'label' => 'Kleine Zeile über der Überschrift', 'type' => 'text', 'max' => 40),
                array('key' => 'projekte_titel', 'label' => 'Überschrift', 'type' => 'text', 'max' => 80),
                array('key' => 'projekte_lede', 'label' => 'Satz darunter', 'type' => 'text', 'max' => 220),
            ),
            'lists' => array(
                array(
                    'key' => 'projekte',
                    'label' => 'Projekte',
                    'item' => 'Projekt',
                    'add' => 'Projekt hinzufügen',
                    'title_field' => 'titel',
                    'max' => 24,
                    'note' => 'Löschst du alle Projekte, verschwindet der ganze Abschnitt von der Website.',
                    'fields' => array_merge(array(
                        array('key' => 'meta', 'label' => 'Kleine Zeile oben', 'type' => 'text', 'max' => 30,
                              'hint' => 'Zum Beispiel Website, Spiel, Werkzeug, Schulprojekt.'),
                        array('key' => 'titel', 'label' => 'Name des Projekts', 'type' => 'text', 'max' => 80),
                        array('key' => 'text', 'label' => 'Was ist es?', 'type' => 'textarea', 'max' => 500),
                    ), rt_link_fields('Zum Beispiel https://github.com/dein-name/projekt')),
                ),
            ),
        ),

        /* ---------------------------------------------------------------- */
        array(
            'title' => 'Werdegang',
            'note'  => 'Die Stationen laufen waagrecht durch, sobald man scrollt. Die neueste steht zuoberst.',
            'fields' => array(
                array('key' => 'werdegang_eyebrow', 'label' => 'Kleine Zeile über der Überschrift', 'type' => 'text', 'max' => 40),
                array('key' => 'werdegang_titel', 'label' => 'Überschrift', 'type' => 'text', 'max' => 80),
                array('key' => 'werdegang_lede', 'label' => 'Satz darunter', 'type' => 'text', 'max' => 220),
            ),
            'lists' => array(
                array(
                    'key' => 'werdegang',
                    'label' => 'Stationen',
                    'item' => 'Station',
                    'add' => 'Station hinzufügen',
                    'title_field' => 'titel',
                    'max' => 24,
                    'fields' => array_merge(array(
                        array('key' => 'zeit', 'label' => 'Zeitraum', 'type' => 'text', 'max' => 40,
                              'hint' => 'Zum Beispiel 2020 – 2025 oder seit 2026.'),
                        array('key' => 'titel', 'label' => 'Bezeichnung', 'type' => 'text', 'max' => 90),
                        array('key' => 'ort', 'label' => 'Betrieb, Schule oder Ort', 'type' => 'text', 'max' => 140),
                        array('key' => 'text', 'label' => 'Beschreibung', 'type' => 'textarea', 'max' => 500),
                    ), rt_link_fields('Zum Beispiel die Website des Betriebs oder der Schule.')),
                ),
            ),
            'fields_after' => array(
                array('key' => 'werdegang_ende_meta', 'label' => 'Letzte Karte — kleine Zeile', 'type' => 'text', 'max' => 30),
                array('key' => 'werdegang_ende_titel', 'label' => 'Letzte Karte — Überschrift', 'type' => 'text', 'max' => 60),
                array('key' => 'werdegang_ende_text', 'label' => 'Letzte Karte — Text', 'type' => 'textarea', 'max' => 300),
                array('key' => 'werdegang_ende_link_text', 'label' => 'Letzte Karte — Beschriftung des Knopfs', 'type' => 'text', 'max' => 60),
                array('key' => 'werdegang_ende_link_url', 'label' => 'Letzte Karte — Ziel des Knopfs', 'type' => 'url', 'max' => 300),
            ),
        ),

        /* ---------------------------------------------------------------- */
        array(
            'title' => 'Neben der Arbeit',
            'fields' => array(
                array('key' => 'neben_eyebrow', 'label' => 'Kleine Zeile über der Überschrift', 'type' => 'text', 'max' => 40),
                array('key' => 'neben_titel', 'label' => 'Überschrift', 'type' => 'text', 'max' => 80),
                array('key' => 'neben_lede', 'label' => 'Satz darunter', 'type' => 'text', 'max' => 220),
            ),
            'lists' => array(
                array(
                    'key' => 'neben',
                    'label' => 'Karten',
                    'item' => 'Karte',
                    'add' => 'Karte hinzufügen',
                    'title_field' => 'titel',
                    'max' => 12,
                    'fields' => array_merge(array(
                        array('key' => 'meta', 'label' => 'Kleine Zeile oben', 'type' => 'text', 'max' => 30),
                        array('key' => 'titel', 'label' => 'Titel', 'type' => 'text', 'max' => 60),
                        array('key' => 'text', 'label' => 'Text', 'type' => 'textarea', 'max' => 500),
                    ), rt_link_fields()),
                ),
            ),
        ),

        /* ---------------------------------------------------------------- */
        array(
            'title' => 'Kontakt',
            'note'  => 'E-Mail, Telefon und Adresse stehen im Kontaktabschnitt und in der Fusszeile. Was du hier löschst, verschwindet auch dort.',
            'fields' => array(
                array('key' => 'kontakt_eyebrow', 'label' => 'Kleine Zeile über der Überschrift', 'type' => 'text', 'max' => 40),
                array('key' => 'kontakt_titel', 'label' => 'Überschrift', 'type' => 'text', 'max' => 80),
                array('key' => 'kontakt_lede', 'label' => 'Satz darunter', 'type' => 'text', 'max' => 220),
                array('key' => 'kontakt_email', 'label' => 'E-Mail-Adresse', 'type' => 'text', 'max' => 120),
                array('key' => 'kontakt_telefon', 'label' => 'Telefonnummer', 'type' => 'text', 'max' => 40,
                      'hint' => 'Leer lassen, wenn die Nummer nicht öffentlich stehen soll.'),
                array('key' => 'kontakt_adresse', 'label' => 'Adresse', 'type' => 'textarea', 'max' => 300,
                      'hint' => 'Eine Zeile pro Zeile, so wie sie auf einem Brief stehen würde.'),
            ),
            'lists' => array(
                array(
                    'key' => 'kontakt_links',
                    'label' => 'Weitere Verweise',
                    'item' => 'Verweis',
                    'add' => 'Verweis hinzufügen',
                    'title_field' => 'titel',
                    'max' => 12,
                    'note' => 'Diese Verweise stehen beim Kontakt und in der Fusszeile — zum Beispiel LinkedIn, GitHub oder deine DJ-Seite.',
                    'fields' => array(
                        array('key' => 'titel', 'label' => 'Bezeichnung', 'type' => 'text', 'max' => 40,
                              'hint' => 'Steht als Titel darüber, zum Beispiel LinkedIn.'),
                        array('key' => 'link_text', 'label' => 'Beschriftung', 'type' => 'text', 'max' => 60),
                        array('key' => 'link_url', 'label' => 'Adresse', 'type' => 'url', 'max' => 300),
                    ),
                ),
            ),
            'fields_after' => array(
                array('key' => 'cta_eyebrow', 'label' => 'Schlusskasten — kleine Zeile', 'type' => 'text', 'max' => 40),
                array('key' => 'cta_titel', 'label' => 'Schlusskasten — Überschrift', 'type' => 'text', 'max' => 80),
                array('key' => 'cta_text', 'label' => 'Schlusskasten — Text', 'type' => 'textarea', 'max' => 300),
                array('key' => 'cta_link1_text', 'label' => 'Schlusskasten — erster Knopf', 'type' => 'text', 'max' => 40),
                array('key' => 'cta_link1_url', 'label' => 'Schlusskasten — Ziel des ersten Knopfs', 'type' => 'url', 'max' => 300),
                array('key' => 'cta_link2_text', 'label' => 'Schlusskasten — zweiter Knopf', 'type' => 'text', 'max' => 40),
                array('key' => 'cta_link2_url', 'label' => 'Schlusskasten — Ziel des zweiten Knopfs', 'type' => 'url', 'max' => 300),
            ),
        ),

        /* ---------------------------------------------------------------- */
        array(
            'title' => 'Titel und Beschreibung für Suchmaschinen',
            'note'  => 'Das steht im Browsertab und in der Vorschau, wenn jemand den Verweis auf deine Seite teilt.',
            'fields' => array(
                array('key' => 'meta_titel', 'label' => 'Seitentitel', 'type' => 'text', 'max' => 70,
                      'hint' => 'Etwa 60 Zeichen sind ideal — länger schneidet Google ab.'),
                array('key' => 'meta_text', 'label' => 'Beschreibung', 'type' => 'textarea', 'max' => 300,
                      'hint' => 'Zwei Sätze, die neugierig machen. Etwa 150 Zeichen.'),
            ),
        ),
    );
}

/** Alle einzelnen Felder als flache Liste (Schluessel => Feld). */
function rt_schema_fields()
{
    $out = array();
    foreach (rt_schema() as $group) {
        foreach (array('fields', 'fields_after') as $slot) {
            if (empty($group[$slot])) { continue; }
            foreach ($group[$slot] as $field) { $out[$field['key']] = $field; }
        }
    }
    return $out;
}

/** Alle Listen als flache Liste (Schluessel => Listendefinition). */
function rt_schema_lists()
{
    $out = array();
    foreach (rt_schema() as $group) {
        foreach (array('lists', 'lists_after') as $slot) {
            if (empty($group[$slot])) { continue; }
            foreach ($group[$slot] as $list) { $out[$list['key']] = $list; }
        }
    }
    return $out;
}

/** Bildfelder der Website. */
function rt_media_slots()
{
    return array(
        'portrait' => array(
            'label' => 'Porträt (Abschnitt „Über mich")',
            'hint'  => 'Hoch- oder Querformat, mindestens 800 Pixel breit. Das Bild wird nicht beschnitten.',
        ),
    );
}
