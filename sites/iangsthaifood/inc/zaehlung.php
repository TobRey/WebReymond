<?php
/**
 * Eigene Besucherzählung.
 *
 * Gespeichert wird ausschliesslich: welcher Seitenpfad wurde an welchem Tag
 * wie oft aufgerufen. Keine IP-Adresse, kein Cookie, keine Kennung, keine
 * Weitergabe an Dritte. Eine Zeile je Tag in einer Datei unter data/stats/.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** Pfad auf ein unbedenkliches Format bringen. */
function zaehlung_pfad_saeubern(string $pfad): string
{
    $pfad = (string) parse_url($pfad, PHP_URL_PATH);
    $pfad = preg_replace('#[^A-Za-z0-9/._-]#', '', $pfad) ?? '';
    $pfad = preg_replace('#/+#', '/', $pfad) ?? '';
    $pfad = str_replace('..', '', $pfad);

    if ($pfad === '' || $pfad[0] !== '/') {
        $pfad = '/' . $pfad;
    }
    if (substr($pfad, -1) === '/') {
        $pfad .= 'index.html';
    }

    return substr($pfad, 0, 120);
}

/** Erkennt offensichtliche Automaten. Die Angabe wird geprüft, nie gespeichert. */
function zaehlung_ist_automat(): bool
{
    $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') {
        return true;
    }
    foreach (['bot', 'crawl', 'spider', 'slurp', 'preview', 'monitor', 'headless', 'curl/', 'wget'] as $wort) {
        if (strpos($ua, $wort) !== false) {
            return true;
        }
    }
    return false;
}

/** Einen Aufruf buchen. */
function zaehlung_buchen(string $pfad): void
{
    $pfad = zaehlung_pfad_saeubern($pfad);
    $ordner = datenordner('stats');
    $datei = $ordner . '/' . gmdate('Y-m-d') . '.json';

    $griff = @fopen($datei, 'c+');
    if ($griff === false) {
        return;
    }

    if (flock($griff, LOCK_EX)) {
        $inhalt = (string) stream_get_contents($griff);
        $zahlen = json_decode($inhalt, true);
        if (!is_array($zahlen)) {
            $zahlen = [];
        }
        if (count($zahlen) < 400 || isset($zahlen[$pfad])) {
            $zahlen[$pfad] = (int) ($zahlen[$pfad] ?? 0) + 1;
        }
        ftruncate($griff, 0);
        rewind($griff);
        fwrite($griff, (string) json_encode($zahlen, JSON_UNESCAPED_SLASHES));
        fflush($griff);
        flock($griff, LOCK_UN);
    }

    fclose($griff);
    @chmod($datei, 0660);
}

/** Zahlen der letzten Tage lesen: [ 'YYYY-MM-DD' => [pfad => anzahl] ]. */
function zaehlung_lesen(int $tage = 30, ?string $bis = null): array
{
    $ende = $bis !== null ? strtotime($bis . ' UTC') : time();
    $ordner = IANG_WURZEL . '/data/stats';
    $ergebnis = [];

    for ($i = 0; $i < $tage; $i++) {
        $tag = gmdate('Y-m-d', $ende - $i * 86400);
        $datei = $ordner . '/' . $tag . '.json';
        if (is_readable($datei)) {
            $zahlen = json_decode((string) file_get_contents($datei), true);
            if (is_array($zahlen)) {
                arsort($zahlen);
                $ergebnis[$tag] = $zahlen;
            }
        }
    }

    return $ergebnis;
}

/** Text des Wochenberichts über die vergangenen sieben Tage. */
function wochenbericht_text(): string
{
    $tage = zaehlung_lesen(7, gmdate('Y-m-d', time() - 86400));
    $summeProSeite = [];
    $gesamt = 0;

    foreach ($tage as $zahlen) {
        foreach ($zahlen as $pfad => $anzahl) {
            $summeProSeite[$pfad] = ($summeProSeite[$pfad] ?? 0) + (int) $anzahl;
            $gesamt += (int) $anzahl;
        }
    }

    arsort($summeProSeite);
    $von = gmdate('d.m.Y', time() - 7 * 86400);
    $bis = gmdate('d.m.Y', time() - 86400);

    $zeilen = [];
    $zeilen[] = 'Besucherzahlen iangsthaifood.com';
    $zeilen[] = 'Zeitraum: ' . $von . ' bis ' . $bis;
    $zeilen[] = '';
    $zeilen[] = 'Seitenaufrufe gesamt: ' . $gesamt;
    $zeilen[] = '';

    if ($summeProSeite === []) {
        $zeilen[] = 'In diesem Zeitraum wurde nichts gezählt.';
    } else {
        $zeilen[] = 'Je Seite:';
        foreach ($summeProSeite as $pfad => $anzahl) {
            $zeilen[] = '  ' . str_pad((string) $anzahl, 6, ' ', STR_PAD_LEFT) . '  ' . $pfad;
        }
        $zeilen[] = '';
        $zeilen[] = 'Je Tag:';
        foreach (array_reverse($tage, true) as $tag => $zahlen) {
            $zeilen[] = '  ' . $tag . '  ' . array_sum($zahlen);
        }
    }

    $zeilen[] = '';
    $zeilen[] = 'Gezählt wird ohne IP-Adressen, ohne Cookies und ohne Kennungen.';

    return implode("\n", $zeilen) . "\n";
}

/** Bericht verschicken und den Versand vermerken. */
function wochenbericht_senden(bool $erzwingen = false): bool
{
    $ordner = datenordner('stats');
    $marke = $ordner . '/bericht-' . gmdate('o-\WW') . '.txt';

    if (!$erzwingen && file_exists($marke)) {
        return false;
    }

    $empfaenger = cfg('support_email', 'support@web-atze.com');
    $ok = mail_senden(
        $empfaenger,
        'Wochenbericht Besucherzahlen — iangsthaifood.com',
        wochenbericht_text()
    );

    @file_put_contents($marke, gmdate('c') . ' ' . ($ok ? 'gesendet' : 'Versand fehlgeschlagen') . "\n");
    @chmod($marke, 0660);

    return $ok;
}

/**
 * Montags einmal den Bericht auslösen. Wird bei jeder Zählung geprüft,
 * damit die Website auch ohne eingerichteten Cron-Auftrag funktioniert.
 */
function wochenbericht_wenn_faellig(): void
{
    if (gmdate('N') !== '1') {
        return;
    }
    $marke = IANG_WURZEL . '/data/stats/bericht-' . gmdate('o-\WW') . '.txt';
    if (file_exists($marke)) {
        return;
    }
    wochenbericht_senden();
}
