<?php

declare(strict_types=1);

namespace WebAtze\Domain;

use WebAtze\Core\{Logger, Settings};

/**
 * Der Briefkopf für Offerten und Rechnungen.
 *
 * Hier steckt eine unangenehme Wahrheit. Eine Word-Vorlage lässt sich
 * auf geteiltem Hosting nicht in ein PDF verwandeln – dafür bräuchte es
 * LibreOffice auf dem Server, und das gibt es dort nicht. Wer etwas
 * anderes verspricht, liefert am Ende eine ungefähre Nachbildung, die
 * bei der ersten ungewöhnlichen Vorlage auseinanderfällt.
 *
 * Also der ehrliche Weg: Die hochgeladene .docx wird ausgelesen –
 * Absenderzeilen und das Logo – und daraus wird der Briefkopf
 * vorbefüllt. Was dabei nicht stimmt, lässt sich von Hand korrigieren.
 * Danach bleibt der Briefkopf stehen, bis jemand ihn ändert.
 *
 * Das Ergebnis ist ein PDF, das WebAtze selbst setzt: verlässlich,
 * gleich aussehend, und ohne Abhängigkeit von etwas, das auf dem Server
 * fehlen könnte.
 */
final class Letterhead
{
    /** Wo das Logo liegt, nachdem es umgewandelt wurde. */
    private const LOGO = 'briefkopf/logo.jpg';

    /** Und die Vorlage selbst, damit sie sich wieder herunterladen lässt. */
    private const TEMPLATE = 'briefkopf/vorlage.docx';

    /**
     * Der Briefkopf, wie er beim Setzen des PDF gebraucht wird.
     *
     * @return array{logo:string, logo_breite:int, zeilen:array<int,string>, name:string}
     */
    public static function current(): array
    {
        $zeilen = array_values(array_filter(array_map(
            'trim',
            preg_split('/\r?\n/', (string) Settings::get('letterhead_lines', '')) ?: []
        ), static fn (string $z): bool => $z !== ''));

        return [
            'logo' => self::logoData(),
            'logo_breite' => max(40, min(220, (int) Settings::get('letterhead_logo_width', '120'))),
            'zeilen' => $zeilen,
            'name' => (string) Settings::get('company_name', ''),
        ];
    }

    /** Die Logodaten, oder ein leerer Text, wenn es keines gibt. */
    public static function logoData(): string
    {
        $pfad = STORAGE_DIR . '/' . self::LOGO;

        return is_file($pfad) ? (string) file_get_contents($pfad) : '';
    }

    public static function hasLogo(): bool
    {
        return is_file(STORAGE_DIR . '/' . self::LOGO);
    }

    public static function templatePath(): string
    {
        return STORAGE_DIR . '/' . self::TEMPLATE;
    }

    public static function hasTemplate(): bool
    {
        return is_file(self::templatePath());
    }

    /**
     * Ein Bild als Logo übernehmen.
     *
     * Umgewandelt wird immer nach JPEG. Der Grund steht in Pdf::image():
     * JPEG-Daten wandern unverändert ins PDF, alles andere müsste hier
     * nachgebaut werden. GD kann die Umwandlung, und was GD nicht lesen
     * kann, war ohnehin kein brauchbares Bild.
     *
     * @return string leerer Text bei Erfolg, sonst der Grund
     */
    public static function setLogo(string $daten): string
    {
        if ($daten === '') {
            return 'Die Datei ist leer.';
        }

        if (strlen($daten) > 8 * 1024 * 1024) {
            return 'Das Bild ist grösser als 8 MB.';
        }

        $bild = @imagecreatefromstring($daten);

        if ($bild === false) {
            return 'Das ist kein Bild, das sich lesen lässt. PNG oder JPEG gehen.';
        }

        $breite = imagesx($bild);
        $hoehe = imagesy($bild);

        // Ein Logo im Briefkopf ist selten breiter als 1200 Punkte. Alles
        // darüber macht das PDF nur schwer, ohne besser auszusehen.
        if ($breite > 1200) {
            $neu = imagescale($bild, 1200);

            if ($neu !== false) {
                imagedestroy($bild);
                $bild = $neu;
                $breite = imagesx($bild);
                $hoehe = imagesy($bild);
            }
        }

        // Durchsichtigkeit gibt es in JPEG nicht. Ohne weissen Grund
        // würde aus Transparenz Schwarz – ein schwarzer Kasten im
        // Briefkopf, und niemand wüsste warum.
        $flach = imagecreatetruecolor($breite, $hoehe);
        $weiss = imagecolorallocate($flach, 255, 255, 255);
        imagefilledrectangle($flach, 0, 0, $breite, $hoehe, $weiss);
        imagecopy($flach, $bild, 0, 0, 0, 0, $breite, $hoehe);

        ensure_dir(dirname(STORAGE_DIR . '/' . self::LOGO));

        ob_start();
        imagejpeg($flach, null, 92);
        $jpeg = (string) ob_get_clean();

        imagedestroy($bild);
        imagedestroy($flach);

        return file_put_contents(STORAGE_DIR . '/' . self::LOGO, $jpeg) !== false
            ? ''
            : 'Das Logo liess sich nicht ablegen.';
    }

    public static function removeLogo(): void
    {
        @unlink(STORAGE_DIR . '/' . self::LOGO);
    }

    /**
     * Eine Word-Vorlage übernehmen und auslesen, was darin steht.
     *
     * Eine .docx ist ein ZIP-Archiv: word/document.xml enthält den Text,
     * word/media/ die Bilder. Beides lässt sich ohne Fremdbibliothek
     * herausholen.
     *
     * Was hier NICHT passiert: das Layout übernehmen. Schriftgrössen,
     * Abstände und Tabellen einer Word-Vorlage nachzubilden, ergäbe eine
     * Näherung, die bei der ersten ungewöhnlichen Vorlage bricht. Der
     * Briefkopf wird deshalb aus den ausgelesenen Angaben neu gesetzt.
     *
     * @return array{ok:bool, meldung:string, zeilen:array<int,string>, logo:bool}
     */
    public static function importTemplate(string $tempPfad, string $dateiname): array
    {
        if (!is_file($tempPfad)) {
            return self::ergebnis(false, 'Die Datei ist nicht angekommen.');
        }

        if (!str_ends_with(mb_strtolower($dateiname), '.docx')) {
            return self::ergebnis(false, 'Es muss eine .docx sein – .doc aus alten Word-Fassungen '
                . 'lässt sich nicht lesen. In Word einmal als .docx speichern.');
        }

        if (!class_exists(\ZipArchive::class)) {
            return self::ergebnis(false, 'Auf diesem Server fehlt die ZIP-Erweiterung.');
        }

        $zip = new \ZipArchive();

        if ($zip->open($tempPfad) !== true) {
            return self::ergebnis(false, 'Die Datei liess sich nicht öffnen. Ist sie beschädigt?');
        }

        $xml = (string) $zip->getFromName('word/document.xml');
        $zeilen = self::linesFromXml($xml);

        // Das grösste Bild ist fast immer das Logo.
        $logo = '';
        $groesse = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (!str_starts_with($name, 'word/media/')) {
                continue;
            }

            $daten = (string) $zip->getFromIndex($i);

            if (strlen($daten) > $groesse) {
                $groesse = strlen($daten);
                $logo = $daten;
            }
        }

        $zip->close();

        // Die Vorlage selbst aufbewahren – sie bleibt hinterlegt, bis
        // jemand eine andere hochlädt.
        ensure_dir(dirname(self::templatePath()));
        @copy($tempPfad, self::templatePath());

        $logoOk = false;

        if ($logo !== '' && self::setLogo($logo) === '') {
            $logoOk = true;
        }

        if ($zeilen !== []) {
            Settings::put('letterhead_lines', implode("\n", array_slice($zeilen, 0, 8)));
        }

        Logger::info('Briefkopf aus Vorlage übernommen.', [
            'zeilen' => count($zeilen),
            'logo' => $logoOk,
        ]);

        return self::ergebnis(
            true,
            'Vorlage übernommen: ' . count($zeilen) . ' Zeilen'
            . ($logoOk ? ' und das Logo' : ', kein brauchbares Logo gefunden')
            . '. Bitte unten nachsehen, ob es stimmt.',
            $zeilen,
            $logoOk
        );
    }

    public static function removeTemplate(): void
    {
        @unlink(self::templatePath());
    }

    /**
     * Die Textzeilen aus dem Word-XML.
     *
     * Word zerlegt einen Satz gern in mehrere Abschnitte – deshalb wird
     * je Absatz zusammengesetzt, nicht je Textstück.
     *
     * @return array<int, string>
     */
    private static function linesFromXml(string $xml): array
    {
        if ($xml === '') {
            return [];
        }

        $zeilen = [];

        // Je Absatz (w:p) alle Textstücke (w:t) zusammenziehen.
        if (preg_match_all('#<w:p[ >].*?</w:p>#s', $xml, $absaetze) === false) {
            return [];
        }

        foreach ($absaetze[0] ?? [] as $absatz) {
            preg_match_all('#<w:t[^>]*>(.*?)</w:t>#s', (string) $absatz, $stuecke);

            $text = trim(html_entity_decode(
                implode('', $stuecke[1] ?? []),
                ENT_QUOTES | ENT_XML1,
                'UTF-8'
            ));

            // Nur der Kopf ist interessant, und dort stehen kurze Zeilen.
            if ($text !== '' && mb_strlen($text) <= 120) {
                $zeilen[] = $text;
            }

            if (count($zeilen) >= 12) {
                break;
            }
        }

        return $zeilen;
    }

    /**
     * @param array<int, string> $zeilen
     * @return array{ok:bool, meldung:string, zeilen:array<int,string>, logo:bool}
     */
    private static function ergebnis(bool $ok, string $meldung, array $zeilen = [], bool $logo = false): array
    {
        return ['ok' => $ok, 'meldung' => $meldung, 'zeilen' => $zeilen, 'logo' => $logo];
    }
}
