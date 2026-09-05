<?php

/**
 * Der Editor im Kundenbackend.
 *
 * Dasselbe JavaScript-Bündel wie bei WebAtze, dieselben Adressen,
 * dieselben Antworten – nur liegen die Daten hier in data/site.php statt
 * in einer Datenbank. Genau darum ist diese Datei so kurz: Sie ist eine
 * Übersetzung, kein zweiter Editor.
 *
 * Warum das die Mühe wert ist: Ein zweiter Editor wäre nach der ersten
 * Änderung am ersten schon nicht mehr derselbe. Der Kunde bekäme mit
 * jedem Update eine andere Bedienung als die, die ihm gezeigt wurde, und
 * jede Verbesserung müsste zweimal gebaut werden.
 *
 * Der Unterschied zum Editor bei WebAtze ist einer und er ist
 * beabsichtigt: Hier gibt es kein Promptfeld. Die Anweisung an ein
 * Sprachmodell kostet Geld, das WebAtze bezahlt – dafür gibt es den
 * Assistenten mit seiner eigenen Begrenzung, und der läuft über das
 * Relais.
 */

declare(strict_types=1);

defined('WEBATZE') || exit;

/** @var WebAtzeKit\Store $store */
/** @var WebAtzeKit\Publisher $publisher */

/**
 * Die Antwort einer Editoraktion.
 *
 * Gleiche Form wie bei WebAtze: ok, error, und – wo es etwas zu zeigen
 * gibt – das fertige HTML des einen betroffenen Abschnitts. Daran hängt,
 * dass die Seite nicht neu geladen werden muss.
 */
function kit_editor_antwort(array $daten, int $status = 200): never
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Einen einzelnen Abschnitt rendern – das Gegenstück zu abschnittHtml(). */
function kit_editor_html(array $site, array $seite, array $abschnitt): string
{
    $kontext = WebAtze\Templates\Page::context($site, $seite, (string) ($site['locale'] ?? 'de'));

    return WebAtze\Templates\Renderer::section($abschnitt, $kontext);
}

/** Die Seite finden, auf der ein Abschnitt liegt. */
function kit_editor_seite(array $site, int $abschnittId): ?array
{
    foreach ((array) ($site['pages'] ?? []) as $seite) {
        foreach ((array) ($seite['sections'] ?? []) as $abschnitt) {
            if ((int) ($abschnitt['id'] ?? 0) === $abschnittId) {
                return $seite;
            }
        }
    }

    return null;
}

/**
 * Die Abschnitte einer Seite neu ordnen.
 *
 * Wie bei WebAtze: Kopf zuerst, Fuss zuletzt, und das wird bei jedem
 * Schreiben durchgesetzt statt angenommen. Zwei Fassungen derselben
 * Regel wären zwei Gelegenheiten, dass eine davon irgendwann anders
 * entscheidet.
 */
function kit_editor_ordnen(array $abschnitte): array
{
    $kopf = [];
    $mitte = [];
    $fuss = [];

    foreach ($abschnitte as $a) {
        match ((string) ($a['type'] ?? '')) {
            'header' => $kopf[] = $a,
            'footer' => $fuss[] = $a,
            default => $mitte[] = $a,
        };
    }

    $geordnet = array_merge($kopf, $mitte, $fuss);

    foreach ($geordnet as $platz => $a) {
        $geordnet[$platz]['sort_order'] = $platz;
    }

    return $geordnet;
}

// ------------------------------------------------------------------

$aktion = (string) ($_POST['was'] ?? '');

// Aus dem Store und nicht aus einer Variablen der aufrufenden Datei: An
// dieser Stelle im Ablauf gibt es die dort noch nicht, und sich darauf
// zu verlassen hiesse, dass diese Datei nur an genau einer Stelle
// eingebunden werden darf.
$site = $store->all();

// ---------------------------------------------------------- Ziehen

if ($aktion === 'ziehen') {
    $abschnittId = (int) ($_POST['abschnitt'] ?? 0);
    $ziel = (int) ($_POST['position'] ?? 0);

    $seite = kit_editor_seite($site, $abschnittId);

    if ($seite === null) {
        kit_editor_antwort(['ok' => false, 'error' => 'Diesen Abschnitt gibt es nicht.'], 404);
    }

    $ok = $store->updatePage((string) $seite['path'], function (array $s) use ($abschnittId, $ziel): array {
        $abschnitte = kit_editor_ordnen((array) ($s['sections'] ?? []));

        $von = null;

        foreach ($abschnitte as $platz => $a) {
            if ((int) ($a['id'] ?? 0) === $abschnittId) {
                $von = $platz;
            }
        }

        if ($von === null) {
            return $s;
        }

        // Kopf und Fuss bleiben, wo sie sind – erkannt am Typ und nicht
        // an der Stelle. An der Stelle zu erkennen hat genau so lange
        // funktioniert, bis einmal etwas hinter dem Fuss lag.
        if (in_array((string) $abschnitte[$von]['type'], ['header', 'footer'], true)) {
            return $s;
        }

        $bewegt = $abschnitte[$von];
        array_splice($abschnitte, $von, 1);
        array_splice($abschnitte, max(0, min(count($abschnitte), $ziel)), 0, [$bewegt]);

        $s['sections'] = kit_editor_ordnen($abschnitte);

        return $s;
    });

    if (!$ok) {
        kit_editor_antwort(['ok' => false, 'error' => 'Das liess sich nicht speichern.'], 500);
    }

    republish($publisher);

    $neu = $store->page((string) $seite['path']);

    kit_editor_antwort([
        'ok' => true,
        'error' => '',
        'order' => array_map(
            static fn (array $a): int => (int) ($a['id'] ?? 0),
            (array) ($neu['sections'] ?? [])
        ),
    ]);
}

// ------------------------------------------------------- Feld ändern

if ($aktion === 'feld') {
    $abschnittId = (int) ($_POST['abschnitt'] ?? 0);
    $pfad = (string) ($_POST['feld'] ?? '');
    $wert = (string) ($_POST['wert'] ?? '');

    $abschnitt = $store->section($abschnittId);

    if ($abschnitt === null) {
        kit_editor_antwort(['ok' => false, 'error' => 'Diesen Abschnitt gibt es nicht.'], 404);
    }

    $ok = $store->updateSection($abschnittId, function (array $a) use ($pfad, $wert): array {
        $teile = array_filter(explode('.', $pfad), static fn (string $t): bool => $t !== '');

        if ($teile === []) {
            return $a;
        }

        $inhalt = (array) ($a['content'] ?? []);
        $zeiger = &$inhalt;

        foreach ($teile as $platz => $teil) {
            if ($platz === count($teile) - 1) {
                $zeiger[$teil] = $wert;
                break;
            }

            if (!isset($zeiger[$teil]) || !is_array($zeiger[$teil])) {
                $zeiger[$teil] = [];
            }

            $zeiger = &$zeiger[$teil];
        }

        // Geprüft wird gegen dasselbe Schema wie überall sonst. Ohne
        // diesen Schritt wäre das Textfeld im Editor der einzige Ort in
        // der ganzen Anwendung, an dem ungeprüfte Werte in die Website
        // gelangen.
        $a['content'] = WebAtzeKit\Fields::merge(
            (string) $a['type'],
            (array) ($a['content'] ?? []),
            $inhalt
        );

        return $a;
    });

    if (!$ok) {
        kit_editor_antwort(['ok' => false, 'error' => 'Das liess sich nicht speichern.'], 500);
    }

    republish($publisher);

    $frisch = $store->section($abschnittId);
    $seite = kit_editor_seite($store->all(), $abschnittId);

    kit_editor_antwort([
        'ok' => true,
        'error' => '',
        'html' => kit_editor_html($store->all(), $seite ?? [], $frisch ?? []),
    ]);
}

// ----------------------------------------------------- Vorlage wechseln

if ($aktion === 'vorlage') {
    $abschnittId = (int) ($_POST['abschnitt'] ?? 0);
    $variante = (string) ($_POST['variante'] ?? '');

    $abschnitt = $store->section($abschnittId);

    if ($abschnitt === null) {
        kit_editor_antwort(['ok' => false, 'error' => 'Diesen Abschnitt gibt es nicht.'], 404);
    }

    if (!WebAtze\Templates\Catalog::exists((string) $abschnitt['type'], $variante)) {
        kit_editor_antwort(['ok' => false, 'error' => 'Diese Vorlage gibt es nicht.'], 422);
    }

    $store->updateSection($abschnittId, static function (array $a) use ($variante): array {
        $a['template_key'] = $variante;

        return $a;
    });

    republish($publisher);

    $frisch = $store->section($abschnittId);
    $seite = kit_editor_seite($store->all(), $abschnittId);

    kit_editor_antwort([
        'ok' => true,
        'error' => '',
        'html' => kit_editor_html($store->all(), $seite ?? [], $frisch ?? []),
    ]);
}

// -------------------------------------------------------- Sichtbarkeit

if ($aktion === 'sichtbar') {
    $abschnittId = (int) ($_POST['abschnitt'] ?? 0);
    $versteckt = false;

    $ok = $store->updateSection($abschnittId, static function (array $a) use (&$versteckt): array {
        $versteckt = empty($a['hidden']);
        $a['hidden'] = $versteckt ? 1 : 0;

        return $a;
    });

    if (!$ok) {
        kit_editor_antwort(['ok' => false, 'error' => 'Diesen Abschnitt gibt es nicht.'], 404);
    }

    republish($publisher);

    kit_editor_antwort(['ok' => true, 'error' => '', 'hidden' => $versteckt]);
}

// --------------------------------------------------------- Listen

if ($aktion === 'eintrag') {
    $abschnittId = (int) ($_POST['abschnitt'] ?? 0);
    $liste = (string) ($_POST['liste'] ?? 'items');
    $unteraktion = (string) ($_POST['unteraktion'] ?? 'ziehen');

    $ok = $store->updateSection(
        $abschnittId,
        static function (array $a) use ($liste, $unteraktion): array {
            $inhalt = (array) ($a['content'] ?? []);
            $eintraege = array_values((array) ($inhalt[$liste] ?? []));

            if ($unteraktion === 'ziehen') {
                $von = (int) ($_POST['von'] ?? 0);
                $nach = (int) ($_POST['nach'] ?? 0);

                if ($von >= 0 && $von < count($eintraege)) {
                    $nach = max(0, min(count($eintraege) - 1, $nach));
                    $bewegt = $eintraege[$von];
                    array_splice($eintraege, $von, 1);
                    array_splice($eintraege, $nach, 0, [$bewegt]);
                }
            }

            $inhalt[$liste] = $eintraege;
            $a['content'] = $inhalt;

            return $a;
        }
    );

    if (!$ok) {
        kit_editor_antwort(['ok' => false, 'error' => 'Diesen Abschnitt gibt es nicht.'], 404);
    }

    republish($publisher);

    $frisch = $store->section($abschnittId);
    $seite = kit_editor_seite($store->all(), $abschnittId);

    kit_editor_antwort([
        'ok' => true,
        'error' => '',
        'html' => kit_editor_html($store->all(), $seite ?? [], $frisch ?? []),
    ]);
}

kit_editor_antwort(['ok' => false, 'error' => 'Unbekannte Aktion.'], 400);
