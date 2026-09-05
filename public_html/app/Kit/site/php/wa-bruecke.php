<?php

/**
 * Die Brücke.
 *
 * Über diese Datei veröffentlicht WebAtze auf dieser Website. Sie ist
 * die einzige Stelle, an der ein anderer Server hier etwas schreiben
 * darf – und deshalb die Stelle, die am strengsten prüft.
 *
 * Wie sie sich ausweist:
 *
 *   Jede Anfrage trägt einen HMAC-SHA256 über Methode, Pfad,
 *   Zeitstempel, Einmalwert und den vollständigen Rumpf. Der Schlüssel
 *   steht in data/config.php und auf dem Server von WebAtze – über die
 *   Leitung geht er nie. Ein Kennwort im Kopf hätte nicht gereicht: Wer
 *   es einmal mitliest, kann damit jede Anfrage stellen, die er will.
 *
 *   Der Zeitstempel darf höchstens zwei Minuten alt sein, und der
 *   Einmalwert wird vermerkt. Eine mitgeschnittene Anfrage ist damit
 *   entweder zu alt oder schon dagewesen.
 *
 * Was sie annimmt: Daten. Nie Code. Der Datensatz geht durch dieselbe
 * Prüfung wie eine Eingabe im Bearbeitungsbereich, und geschrieben
 * werden anschliessend HTML-Dateien aus dem Vorlagenwerk, das hier
 * ohnehin liegt. Selbst eine gefälschte Anfrage könnte damit kein PHP
 * auf diesen Server bringen.
 *
 * Und sie lässt sich abschalten: bridge_secret in data/config.php leeren
 * genügt. Es ist die Website des Kunden, nicht die von WebAtze.
 */

declare(strict_types=1);

const BRUECKE_FENSTER = 120;
const BRUECKE_MAX_BYTES = 4194304;
const BRUECKE_EINMAL_BEHALTEN = 500;

$adminDir = __DIR__ . '/admin';
$dataDir = __DIR__ . '/data';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

/** Antworten und Schluss. */
function bruecke_antwort(array $daten, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Abweisen – immer mit demselben Text.
 *
 * Wer probiert, soll nicht erfahren, woran es gelegen hat. "Zeitstempel
 * zu alt" gegenüber "Unterschrift falsch" wäre schon eine Auskunft: Die
 * erste sagt, dass der Schlüssel stimmt.
 */
function bruecke_abweisen(): never
{
    bruecke_antwort(['ok' => false, 'error' => 'Kein Zugang.'], 403);
}

// ------------------------------------------------------------------
// Ausweisen
// ------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    bruecke_abweisen();
}

$configDatei = $dataDir . '/config.php';

if (!is_file($configDatei)) {
    bruecke_abweisen();
}

$config = require $configDatei;
$secret = (string) ($config['bridge_secret'] ?? '');

if ($secret === '' || strlen($secret) < 32) {
    bruecke_abweisen();
}

$rumpf = (string) file_get_contents('php://input');

if ($rumpf === '' || strlen($rumpf) > BRUECKE_MAX_BYTES) {
    bruecke_abweisen();
}

$zeit = (int) ($_SERVER['HTTP_X_WEBATZE_ZEIT'] ?? 0);
$einmal = (string) ($_SERVER['HTTP_X_WEBATZE_EINMAL'] ?? '');
$unterschrift = (string) ($_SERVER['HTTP_X_WEBATZE_UNTERSCHRIFT'] ?? '');

if ($zeit <= 0 || $einmal === '' || $unterschrift === '') {
    bruecke_abweisen();
}

// Das Fenster gilt in beide Richtungen: Eine Anfrage aus der Zukunft ist
// genauso verdächtig wie eine aus der Vorwoche.
if (abs(time() - $zeit) > BRUECKE_FENSTER) {
    bruecke_abweisen();
}

if (!preg_match('/^[a-f0-9]{16,64}$/', $einmal)) {
    bruecke_abweisen();
}

$pfad = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

$erwartet = hash_hmac('sha256', implode("\n", [
    'POST',
    $pfad,
    (string) $zeit,
    $einmal,
    hash('sha256', $rumpf),
]), $secret);

// hash_equals und nicht ===: Ein normaler Vergleich bricht beim ersten
// unterschiedlichen Zeichen ab, und aus der Dauer lässt sich die
// richtige Unterschrift Zeichen für Zeichen erraten.
if (!hash_equals($erwartet, $unterschrift)) {
    bruecke_abweisen();
}

// ------------------------------------------------------------------
// Einmalwerte
// ------------------------------------------------------------------

/** Schon dagewesen? Dann ist es eine Wiederholung. */
function bruecke_einmal_pruefen(string $dataDir, string $einmal): bool
{
    $datei = $dataDir . '/bruecke-einmal.php';
    $liste = [];

    if (is_file($datei)) {
        $inhalt = (string) file_get_contents($datei);
        $stelle = strpos($inhalt, '?>');
        $daten = json_decode($stelle === false ? $inhalt : substr($inhalt, $stelle + 2), true);
        $liste = is_array($daten) ? $daten : [];
    }

    if (in_array($einmal, $liste, true)) {
        return false;
    }

    $liste[] = $einmal;

    if (count($liste) > BRUECKE_EINMAL_BEHALTEN) {
        $liste = array_slice($liste, -BRUECKE_EINMAL_BEHALTEN);
    }

    $tmp = $datei . '.' . bin2hex(random_bytes(4)) . '.tmp';

    file_put_contents(
        $tmp,
        "<?php exit; ?>\n" . json_encode($liste, JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    @rename($tmp, $datei);
    @chmod($datei, 0640);

    return true;
}

if (!bruecke_einmal_pruefen($dataDir, $einmal)) {
    bruecke_abweisen();
}

// ------------------------------------------------------------------
// Ausführen
// ------------------------------------------------------------------

$anfrage = json_decode($rumpf, true);

if (!is_array($anfrage)) {
    bruecke_antwort(['ok' => false, 'error' => 'Unlesbare Anfrage.'], 400);
}

require $adminDir . '/lib/boot.php';

$store = new WebAtzeKit\Store($dataDir);

/** Die Prüfsumme des aktuellen Standes. */
function bruecke_stand(WebAtzeKit\Store $store): string
{
    return substr(hash('sha256', json_encode($store->all())), 0, 32);
}

$aktion = (string) ($anfrage['aktion'] ?? '');

if ($aktion === 'ping') {
    bruecke_antwort([
        'ok' => true,
        'stand' => bruecke_stand($store),
        'version' => defined('WEBATZE_KIT_VERSION') ? WEBATZE_KIT_VERSION : '1',
    ]);
}

if ($aktion === 'stand') {
    $site = $store->all();

    bruecke_antwort([
        'ok' => true,
        'stand' => bruecke_stand($store),
        'seiten' => count($site['pages'] ?? []),
        'geaendert' => (string) ($site['updated_at'] ?? ''),
    ]);
}

if ($aktion === 'veroeffentlichen') {
    $neu = $anfrage['site'] ?? null;

    if (!is_array($neu) || ($neu['pages'] ?? []) === []) {
        bruecke_antwort(['ok' => false, 'error' => 'Der Datensatz ist leer.'], 400);
    }

    // Was ankommt, wird nicht geglaubt, sondern geprüft – Feld für Feld
    // gegen dasselbe Schema, das auch der Bearbeitungsbereich benutzt.
    // Ohne diesen Schritt wäre der einzige Schutz die Unterschrift, und
    // ein Schutz allein ist keiner.
    $geprueft = bruecke_pruefen($neu, $store->all());

    if ($geprueft === null) {
        bruecke_antwort(['ok' => false, 'error' => 'Der Datensatz passt nicht zu dieser Website.'], 422);
    }

    // Store::save() legt vor jedem Schreiben eine Sicherung an. Wer sich
    // vertut, holt sich den letzten Stand über den Bearbeitungsbereich
    // zurück – ohne WebAtze zu brauchen.
    if (!$store->save($geprueft)) {
        bruecke_antwort(['ok' => false, 'error' => 'Der Stand liess sich nicht speichern.'], 500);
    }

    $publisher = new WebAtzeKit\Publisher($store, __DIR__);
    $ergebnis = $publisher->publish();

    bruecke_antwort([
        'ok' => (bool) $ergebnis['ok'],
        'error' => (string) $ergebnis['error'],
        'seiten' => (int) $ergebnis['pages'],
        'stand' => bruecke_stand($store),
    ], $ergebnis['ok'] ? 200 : 500);
}

bruecke_antwort(['ok' => false, 'error' => 'Unbekannte Aktion.'], 400);

// ------------------------------------------------------------------

/**
 * Den ankommenden Datensatz prüfen.
 *
 * Übernommen wird nur, was zu dieser Website gehört: Seiten, Abschnitte,
 * Inhalte, Thema. Alles andere – Schlüssel, Adressen, Zugangsdaten –
 * bleibt so, wie es hier steht. Eine Veröffentlichung soll Inhalt
 * bringen und nicht die Einrichtung dieser Website verändern.
 */
function bruecke_pruefen(array $neu, array $alt): ?array
{
    $erlaubt = ['brand', 'locale', 'locales', 'theme', 'pages', 'logo', 'contact',
                'critical_css', 'style_pack', 'chrome'];

    $ergebnis = $alt;

    foreach ($erlaubt as $feld) {
        if (array_key_exists($feld, $neu)) {
            $ergebnis[$feld] = $neu[$feld];
        }
    }

    $seiten = [];

    foreach ((array) ($ergebnis['pages'] ?? []) as $seite) {
        if (!is_array($seite)) {
            continue;
        }

        $abschnitte = [];

        foreach ((array) ($seite['sections'] ?? []) as $abschnitt) {
            if (!is_array($abschnitt)) {
                continue;
            }

            $typ = (string) ($abschnitt['type'] ?? '');
            $beschreibung = WebAtze\Templates\Schema::forType($typ);

            if ($beschreibung === null) {
                continue;
            }

            // Der freie Abschnitt hat kein Feldergerüst, sondern
            // Bausteine – und die prüfen sich selbst.
            $inhalt = $typ === 'frei'
                ? ['blocks' => WebAtze\Templates\Blocks::clean($abschnitt['content']['blocks'] ?? [])]
                : WebAtzeKit\Fields::merge($typ, [], (array) ($abschnitt['content'] ?? []));

            if ($inhalt === null) {
                continue;
            }

            $abschnitte[] = [
                'id' => (int) ($abschnitt['id'] ?? 0),
                'type' => $typ,
                'template_key' => WebAtze\Templates\Catalog::exists($typ, (string) ($abschnitt['template_key'] ?? ''))
                    ? (string) $abschnitt['template_key']
                    : WebAtze\Templates\Catalog::defaultKey($typ),
                'content' => $inhalt,
                'overrides' => is_array($abschnitt['overrides'] ?? null) ? $abschnitt['overrides'] : [],
                'effects' => WebAtze\Templates\Effects::clean($abschnitt['effects'] ?? []),
                'translations' => is_array($abschnitt['translations'] ?? null)
                    ? $abschnitt['translations']
                    : null,
                'hidden' => !empty($abschnitt['hidden']) ? 1 : 0,
                'sort_order' => count($abschnitte),
            ];
        }

        if ($abschnitte === []) {
            continue;
        }

        $seiten[] = [
            'id' => (int) ($seite['id'] ?? 0),
            'path' => (string) ($seite['path'] ?? '/'),
            'title' => mb_substr((string) ($seite['title'] ?? ''), 0, 190),
            'meta_description' => mb_substr((string) ($seite['meta_description'] ?? ''), 0, 300),
            'in_navigation' => !empty($seite['in_navigation']) ? 1 : 0,
            'sort_order' => count($seiten),
            'translations' => is_array($seite['translations'] ?? null) ? $seite['translations'] : null,
            'sections' => $abschnitte,
        ];
    }

    if ($seiten === []) {
        return null;
    }

    $ergebnis['pages'] = $seiten;
    $ergebnis['updated_at'] = date('c');

    return $ergebnis;
}
