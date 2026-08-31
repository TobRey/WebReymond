<?php

declare(strict_types=1);

namespace WebAtze\Build;

use WebAtze\Domain\Brief;

/**
 * Das Buchungssystem – gebaut, wenn es gebraucht wird.
 *
 * Die Regel aus dem Bauplan gilt unverändert: Eine Kundenwebsite
 * bekommt nichts Bewegliches, solange nichts Bewegliches beschrieben
 * wurde. Steht in den Zusatzinfos etwas von Terminen, Buchungen oder
 * Reservationen, entsteht dieses Modul – sonst nicht eine Datei davon.
 *
 * Eine Datenbank ist auch dann nicht dabei. Buchungen liegen als Datei
 * neben der Website, wie die Anfragen aus dem Kontaktformular. Für
 * einen Betrieb mit ein paar Terminen am Tag ist das nicht die
 * schlechtere Lösung, sondern die richtige: nichts einzurichten,
 * nichts, das ausfallen kann, und die Sicherung ist eine Kopie.
 *
 * Die Öffnungszeiten werden aus dem Formular gelesen, so gut das geht.
 * Was nicht erkannt wird, wird zu Montag bis Freitag, 9 bis 17 Uhr –
 * und der Kunde stellt es in seinem Bearbeitungsbereich um. Eine
 * geratene Zeit, die er ändern kann, ist besser als ein leeres Feld,
 * vor dem er steht.
 */
final class BookingKit
{
    /** @return int Anzahl geschriebener Dateien */
    public static function install(array $project, array $brief, string $outputDir): int
    {
        if (!self::wanted($brief)) {
            return 0;
        }

        $kit = APP_DIR . '/Kit/site/php';
        $written = 0;

        foreach (['buchen.php', 'buchen-zeiten.php', 'buchen-ansicht.php'] as $name) {
            $source = $kit . '/' . $name;

            if (is_file($source)) {
                write_file_atomic(rtrim($outputDir, '/') . '/' . $name, (string) file_get_contents($source));
                $written++;
            }
        }

        $dataDir = rtrim($outputDir, '/') . '/data';
        ensure_dir($dataDir);

        // Die Einstellungen nur beim ersten Mal: Was der Kunde in seinem
        // Bereich geändert hat, darf ein erneutes Bauen nicht zurücksetzen.
        if (!is_file($dataDir . '/buchung.php')) {
            write_file_atomic($dataDir . '/buchung.php', self::config($project, $brief));
            $written++;
        }

        if (!is_file($dataDir . '/buchungen.php')) {
            write_file_atomic($dataDir . '/buchungen.php', "<?php exit; ?>\n[]\n");
            $written++;
        }

        return $written;
    }

    /** Steht in den Angaben etwas, das nach Terminen klingt? */
    public static function wanted(array $brief): bool
    {
        $text = mb_strtolower(
            (string) ($brief['extra_notes'] ?? '') . ' ' . (string) ($brief['description'] ?? '')
        );

        foreach (['buchung', 'buchen', 'termin', 'reservation', 'reservierung',
                  'terminvereinbarung', 'kalender', 'sprechstunde'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Die Einstellungsdatei. */
    private static function config(array $project, array $brief): string
    {
        $values = [
            'titel' => 'Termin buchen',
            'einleitung' => 'Wählen Sie einen Tag und eine Zeit, die Ihnen passt. '
                . 'Die Bestätigung kommt sofort per E-Mail.',
            'empfaenger' => (string) ($brief['contact_email'] ?? ''),
            'leistungen' => self::services($brief),
            'zeiten' => self::hours((string) ($brief['opening_hours'] ?? '')),
            'geschlossen' => [],
            'takt' => 30,
            'vorlauf_stunden' => 24,
            'horizont_tage' => 60,
            'telefon_pflicht' => false,
        ];

        return "<?php\n\n"
            . "// Einstellungen der Terminbuchung.\n"
            . "//\n"
            . "// Alles hier lässt sich im Bearbeitungsbereich unter\n"
            . "// \"Buchungen\" ändern – von Hand ist nicht nötig.\n"
            . "//\n"
            . "// \"zeiten\" steht nach Wochentagen: 1 ist Montag, 7 ist Sonntag.\n"
            . "// Ein Tag darf mehrere Fenster haben, etwa vormittags und\n"
            . "// nachmittags. Ein Tag ohne Fenster ist ein geschlossener Tag.\n\n"
            . 'return ' . var_export($values, true) . ";\n";
    }

    /**
     * Die buchbaren Leistungen.
     *
     * Steht nichts Erkennbares in den Angaben, gibt es eine einzige,
     * schlicht "Termin" genannt. Der Kunde benennt sie um – das ist
     * ehrlicher, als aus dem Freitext etwas zu raten, das dann falsch
     * auf seiner Website steht.
     *
     * @return array<int, array{name:string, dauer:int}>
     */
    private static function services(array $brief): array
    {
        $notes = (string) ($brief['extra_notes'] ?? '');
        $out = [];

        // Zeilen, die wie eine Aufzählung aussehen und eine Dauer nennen:
        // "Haarschnitt 45 Min" oder "- Beratung, 30 Minuten"
        foreach (explode("\n", str_replace("\r\n", "\n", $notes)) as $line) {
            $line = trim($line, " \t-–•*");

            if ($line === '' || mb_strlen($line) > 80) {
                continue;
            }

            if (preg_match('/^(.{2,60}?)[\s,:]+(\d{1,3})\s*(?:min|minuten)\b/iu', $line, $m) !== 1) {
                continue;
            }

            $out[] = [
                'name' => trim($m[1], " \t,:."),
                'dauer' => max(5, min(480, (int) $m[2])),
            ];

            if (count($out) >= 8) {
                break;
            }
        }

        return $out !== [] ? $out : [['name' => 'Termin', 'dauer' => 30]];
    }

    /**
     * Öffnungszeiten aus dem Freitext.
     *
     * Erkannt wird die übliche Schreibweise: "Mo–Fr 09:00–17:00",
     * "Sa 9-13". Was nicht passt, wird nicht geraten – dann greift die
     * Vorgabe, und der Kunde stellt sie um.
     *
     * @return array<int, array<int, string>>
     */
    public static function hours(string $text): array
    {
        $days = [
            'mo' => 1, 'montag' => 1,
            'di' => 2, 'dienstag' => 2,
            'mi' => 3, 'mittwoch' => 3,
            'do' => 4, 'donnerstag' => 4,
            'fr' => 5, 'freitag' => 5,
            'sa' => 6, 'samstag' => 6,
            'so' => 7, 'sonntag' => 7,
        ];

        $found = [];

        $text = str_replace(['–', '—', 'bis', 'Uhr'], ['-', '-', '-', ''], $text);

        foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $line) {
            $line = mb_strtolower(trim($line));

            if ($line === '') {
                continue;
            }

            // Welche Tage nennt die Zeile?
            if (preg_match('/^([a-zäöü]{2,10})\s*-\s*([a-zäöü]{2,10})/u', $line, $m) === 1
                && isset($days[$m[1]], $days[$m[2]])) {
                $range = range($days[$m[1]], max($days[$m[1]], $days[$m[2]]));
            } else {
                $range = [];

                foreach ($days as $name => $number) {
                    if (preg_match('/\b' . preg_quote($name, '/') . '\b/u', $line) === 1) {
                        $range[] = $number;
                    }
                }

                $range = array_values(array_unique($range));
            }

            if ($range === []) {
                continue;
            }

            // Und welche Zeiten?
            preg_match_all('/(\d{1,2})(?::(\d{2}))?\s*-\s*(\d{1,2})(?::(\d{2}))?/', $line, $times, PREG_SET_ORDER);

            $windows = [];

            foreach ($times as $time) {
                $from = sprintf('%02d:%02d', (int) $time[1], (int) ($time[2] ?? 0));
                $till = sprintf('%02d:%02d', (int) $time[3], (int) ($time[4] ?? 0));

                if ((int) $time[1] > 23 || (int) $time[3] > 24 || $till <= $from) {
                    continue;
                }

                $windows[] = $from . '-' . $till;
            }

            if ($windows === []) {
                continue;
            }

            foreach ($range as $number) {
                $found[$number] = $windows;
            }
        }

        if ($found !== []) {
            ksort($found);
            return $found;
        }

        // Die Vorgabe: Montag bis Freitag, 9 bis 17 Uhr.
        return [1 => ['09:00-17:00'], 2 => ['09:00-17:00'], 3 => ['09:00-17:00'],
                4 => ['09:00-17:00'], 5 => ['09:00-17:00']];
    }
}
