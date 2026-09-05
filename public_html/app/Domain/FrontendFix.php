<?php

declare(strict_types=1);

namespace WebAtze\Domain;

use WebAtze\Ai\ClaudeClient;
use WebAtze\Core\{Config, Db, Logger};

/**
 * Die CSS-Notfallebene für die eigene Website.
 *
 * Sie hiess einmal "Frontend Fix" und hatte einen eigenen Menüpunkt.
 * Der ist weg: Was sie leistete, leistet jetzt der Editor, und zwar
 * besser - er zeigt, was er ändert, statt es zu beschreiben.
 *
 * Geblieben ist sie trotzdem, und aus einem Grund, der nicht sofort
 * einleuchtet: Sie ist der einzige Weg, das Aussehen der eigenen
 * Website zu ändern, OHNE etwas auszuliefern. Wenn nach einer
 * Aktualisierung etwas verrutscht ist und niemand gerade ein ZIP
 * hochladen kann, ist das der Rettungsweg. Ein Rettungsweg, den man
 * abbaut, weil er selten benutzt wird, ist keiner.
 *
 * Erreichbar bleibt sie über ihre Adresse; im Menü steht sie nicht mehr,
 * weil sie kein Werkzeug für den Alltag ist.
 *
 * Ursprüngliche Beschreibung:
 * Die eigene Website per Textbefehl anpassen.
 *
 * «Mach die Section Leistungen mit einem anderen Hintergrund» – der Satz
 * geht an die Schnittstelle, zurück kommt CSS, und das liegt eine
 * Sekunde später auf dem Server. Kein Hochladen, kein Bauen, kein
 * Dateimanager.
 *
 * Warum ausschliesslich CSS:
 *
 * Das sichtbare Frontend ist ein gebautes Paket. Es entsteht aus den
 * Quelldateien mit einem Bauwerkzeug, das auf einem gemieteten Hosting
 * nicht läuft. Eine Änderung am Quelltext wäre also erst nach dem
 * nächsten Bauen zu sehen – und das findet hier nicht statt.
 *
 * Eine Ebene mit eigenen Regeln, die nach dem Stylesheet geladen wird,
 * kann dagegen alles am Aussehen ändern: Farben, Abstände, Schriften,
 * Hintergründe, Sichtbarkeit. Sie kann nichts ausführen und nichts
 * kaputt machen, was sich nicht mit einem Klick zurücknehmen liesse.
 *
 * Was sie nicht kann, steht ehrlich in der Oberfläche: neue Texte, neue
 * Abschnitte, neue Seiten. Dafür gibt es den Weg über den Quelltext.
 */
final class FrontendFix
{
    /** So gross darf eine einzelne Anpassung höchstens werden. */
    private const MAX_CSS = 12000;

    /** Die Datei, die die öffentliche Website lädt. */
    private const DATEI = '/frontend-fix.css';

    /**
     * Was in einer Regel nichts zu suchen hat.
     *
     * CSS führt nichts aus – aber es lädt. Ein @import oder ein url()
     * auf einen fremden Server holt sich beim ersten Besucher etwas von
     * dort, und dann steht in der Datenschutzerklärung etwas Falsches.
     * Der Rest sind alte Browser-Eigenheiten, über die sich früher
     * tatsächlich Code einschleusen liess.
     */
    private const VERBOTEN = [
        '@import',
        'javascript:',
        'expression(',
        'behavior:',
        '-moz-binding',
        '<script',
        '</style',
    ];

    // ------------------------------------------------------------------
    // Lesen
    // ------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        return Db::all('SELECT * FROM frontend_fixes ORDER BY id DESC LIMIT 200');
    }

    public static function find(int $id): ?array
    {
        return Db::first('SELECT * FROM frontend_fixes WHERE id = :id', ['id' => $id]);
    }

    public static function activeCount(): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM frontend_fixes WHERE active = 1', [], 0);
    }

    /**
     * Alle eingeschalteten Anpassungen als eine Datei.
     *
     * Die Reihenfolge ist die des Anlegens: Was später kam, gewinnt bei
     * gleicher Gewichtung. Das entspricht dem, was man erwartet – die
     * jüngste Änderung ist die, die gelten soll.
     */
    public static function css(): string
    {
        $teile = [];

        foreach (Db::all('SELECT * FROM frontend_fixes WHERE active = 1 ORDER BY id ASC') as $zeile) {
            $css = trim((string) $zeile['css']);

            if ($css === '') {
                continue;
            }

            $teile[] = '/* --- #' . (int) $zeile['id'] . ' · '
                . str_replace('*/', '', (string) $zeile['summary']) . " --- */\n" . $css;
        }

        if ($teile === []) {
            return '';
        }

        return "/* Anpassungen aus dem Frontend-Fix.\n"
            . "   Erzeugt von WebAtze, nicht von Hand geschrieben.\n"
            . "   Jede Anpassung lässt sich im Adminbereich einzeln abschalten. */\n\n"
            . implode("\n\n", $teile) . "\n";
    }

    /**
     * Eine Kennung, die sich bei jeder Änderung ändert.
     *
     * Sie hängt an der Adresse des Stylesheets. Ohne sie zeigte der
     * Browser tagelang die alte Fassung – und man sässe davor und
     * wunderte sich, warum nichts passiert.
     */
    public static function version(): string
    {
        $stand = (string) Db::value(
            'SELECT MAX(updated_at) FROM frontend_fixes WHERE active = 1', [], ''
        );

        return substr(md5($stand . '|' . self::activeCount()), 0, 8);
    }

    /** Die Adresse für das öffentliche Stylesheet. */
    public static function url(): string
    {
        return self::DATEI . '?v=' . self::version();
    }

    // ------------------------------------------------------------------
    // Anlegen
    // ------------------------------------------------------------------

    /**
     * Einen Befehl ausführen.
     *
     * @return array{ok:bool, meldung:string, id:int, css:string}
     */
    public static function apply(string $befehl): array
    {
        $befehl = trim(preg_replace('/[^\P{C}\n]/u', '', $befehl) ?? '');
        $befehl = mb_substr($befehl, 0, 2000);

        if (mb_strlen($befehl) < 8) {
            return [
                'ok' => false,
                'meldung' => 'Schreib etwas mehr – aus drei Wörtern lässt sich nicht ablesen, was du willst.',
                'id' => 0,
                'css' => '',
            ];
        }

        if (!ClaudeClient::isConfigured()) {
            return [
                'ok' => false,
                'meldung' => 'Es ist kein Schlüssel für die Schnittstelle hinterlegt. '
                    . 'Er gehört in app/config.php unter anthropic.api_key.',
                'id' => 0,
                'css' => '',
            ];
        }

        try {
            $antwort = (new ClaudeClient())->text(
                'frontend-fix',
                self::systemPrompt(),
                self::frage($befehl),
                ['max_tokens' => 3000, 'effort' => 'medium']
            );
        } catch (\Throwable $e) {
            Logger::exception($e);

            return [
                'ok' => false,
                'meldung' => 'Die Schnittstelle hat nicht geantwortet: ' . $e->getMessage(),
                'id' => 0,
                'css' => '',
            ];
        }

        [$css, $zusammenfassung] = self::auspacken($antwort);

        $geprueft = self::sauber($css);

        if ($geprueft === '') {
            return [
                'ok' => false,
                'meldung' => 'Zurück kam nichts Brauchbares. Formulier den Befehl anders – '
                    . 'am besten mit dem Namen des Abschnitts und dem, was sich ändern soll.',
                'id' => 0,
                'css' => '',
            ];
        }

        $id = Db::insert('frontend_fixes', [
            'prompt' => $befehl,
            'css' => $geprueft,
            'summary' => mb_substr($zusammenfassung !== '' ? $zusammenfassung : $befehl, 0, 250),
            'scope' => '',
            'active' => 1,
            'created_at' => Db::now(),
            'updated_at' => Db::now(),
        ]);

        self::schreiben();

        Logger::info('Frontend angepasst.', ['id' => $id, 'befehl' => mb_substr($befehl, 0, 80)]);

        return [
            'ok' => true,
            'meldung' => 'Die Änderung ist auf der Website. Sieh sie dir an – '
                . 'wenn sie nicht passt, schalte sie einfach wieder ab.',
            'id' => $id,
            'css' => $geprueft,
        ];
    }

    // ------------------------------------------------------------------
    // Ändern
    // ------------------------------------------------------------------

    public static function toggle(int $id, bool $an): bool
    {
        $ok = Db::update('frontend_fixes', [
            'active' => $an ? 1 : 0,
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => $id]) > 0;

        if ($ok) {
            self::schreiben();
        }

        return $ok;
    }

    public static function remove(int $id): bool
    {
        $ok = Db::delete('frontend_fixes', 'id = :id', ['id' => $id]) > 0;

        if ($ok) {
            self::schreiben();
        }

        return $ok;
    }

    /** Alles zurück auf Anfang. */
    public static function clear(): int
    {
        $weg = Db::delete('frontend_fixes', '1 = 1');

        self::schreiben();

        return $weg;
    }

    /**
     * Die Datei neu schreiben.
     *
     * Sie liegt unter storage/ und wird über eine eigene Adresse
     * ausgeliefert - nicht direkt aus dem Web-Ordner. So kann kein
     * Besucher sie ueberschreiben, und der Server muss nirgends
     * Schreibrechte haben, wo er sie sonst nicht braucht.
     */
    public static function schreiben(): void
    {
        $datei = STORAGE_DIR . '/frontend-fix.css';

        try {
            write_file_atomic($datei, self::css());
        } catch (\Throwable $e) {
            Logger::exception($e);
        }
    }

    /** Der zuletzt geschriebene Stand – für die Auslieferung. */
    public static function gespeichert(): string
    {
        $datei = STORAGE_DIR . '/frontend-fix.css';

        if (!is_file($datei)) {
            // Beim ersten Aufruf nach einer Aktualisierung ist die Datei
            // noch nicht da. Dann eben aus der Datenbank.
            self::schreiben();
        }

        return is_file($datei) ? (string) file_get_contents($datei) : '';
    }

    // ------------------------------------------------------------------
    // Prüfen
    // ------------------------------------------------------------------

    /**
     * Eine Regel auf das Nötige zusammenstreichen.
     *
     * CSS führt nichts aus, aber es lädt: Ein @import oder ein url() auf
     * einen fremden Server holt beim ersten Besucher etwas von dort - und
     * dann stimmt die Datenschutzerklärung nicht mehr. Was hier
     * durchkommt, holt nichts von aussen.
     */
    public static function sauber(string $css): string
    {
        $css = trim($css);

        if ($css === '' || mb_strlen($css) > self::MAX_CSS) {
            return '';
        }

        $klein = mb_strtolower($css);

        foreach (self::VERBOTEN as $wort) {
            if (str_contains($klein, $wort)) {
                Logger::warning('Frontend-Fix abgewiesen.', ['grund' => $wort]);

                return '';
            }
        }

        // url() nur auf die eigene Seite oder als eingebettete Daten.
        if (preg_match_all('/url\(\s*([^)]*)\)/i', $css, $treffer) > 0) {
            foreach ($treffer[1] as $ziel) {
                $ziel = trim($ziel, " \t\n\r\0\x0B\"'");

                if ($ziel === '' || str_starts_with($ziel, 'data:image/')) {
                    continue;
                }

                if (str_starts_with($ziel, '/') && !str_starts_with($ziel, '//')) {
                    continue;
                }

                Logger::warning('Frontend-Fix abgewiesen: fremde Adresse.', ['ziel' => $ziel]);

                return '';
            }
        }

        // Geschweifte Klammern müssen aufgehen. Eine offene Klammer
        // verschluckt alles, was danach im Stylesheet steht - und das
        // ist hier nichts mehr, aber im Browser der halbe Rest.
        if (substr_count($css, '{') !== substr_count($css, '}')) {
            Logger::warning('Frontend-Fix abgewiesen: Klammern gehen nicht auf.');

            return '';
        }

        return $css;
    }

    // ------------------------------------------------------------------
    // Was die Schnittstelle bekommt
    // ------------------------------------------------------------------

    private static function systemPrompt(): string
    {
        return <<<'TEXT'
        Du passt das Aussehen einer bestehenden Website an. Du bekommst
        einen Wunsch in einem Satz und antwortest mit CSS.

        ## Was du zurückgibst

        Nur zwei Dinge, in genau dieser Form:

        ```
        ZUSAMMENFASSUNG: <ein kurzer Satz, was du geändert hast>
        ```
        ```css
        <die Regeln>
        ```

        Keine Erklärung davor, keine Alternativen, keine Rückfrage. Wenn
        der Wunsch unklar ist, entscheide dich für die naheliegendste
        Auslegung und schreib sie in die Zusammenfassung.

        ## Regeln für das CSS

        - Es wird NACH dem eigentlichen Stylesheet geladen. Bei gleicher
          Gewichtung gewinnst du also ohnehin. Benutze `!important` nur
          dort, wo es ohne nicht geht.
        - Schreib so wenig wie möglich. Eine Regel, die eine Farbe ändert,
          ist eine Zeile - keine dreissig.
        - Kein `@import`, kein `url()` auf einen fremden Server. Erlaubt
          sind nur eigene Pfade (`/assets/...`) und `data:image/...`.
        - Achte auf den Kontrast: Text auf Fläche muss 4.5:1 erreichen.
          Änderst du einen Hintergrund, prüf die Schrift darauf mit.
        - Denk an den Hellmodus. Die Seite schaltet über
          `:root[data-theme='light']` um; wenn deine Änderung dort falsch
          aussieht, schreib die zweite Regel gleich dazu.
        - Denk an schmale Bildschirme. Feste Breiten in Pixeln sind fast
          immer falsch.
        - Respektiere `@media (prefers-reduced-motion: reduce)`, wenn du
          etwas bewegst.

        ## Die Farbwelt der Marke

        --wa-indigo-700: #2B1B9E    tiefes Indigo, die Hauptfarbe
        --wa-indigo-400: #8B85FF    helles Indigo, für Text auf Dunkel
        --wa-teal-400:   #17C8C8    Türkis, nur für Flächen und Verläufe
        --wa-ink-950:    #0A0A1F    Near-Black, der Grund
        --wa-ink-0:      #FFFFFF

        Benutze die Variablen, wo es geht (`var(--wa-accent)` und so
        weiter) - dann bleibt die Änderung auch im Hellmodus stimmig.

        ## Was du NICHT kannst

        Texte ändern, Abschnitte hinzufügen, Seiten anlegen, Bilder
        austauschen. Das geht über diese Ebene nicht. Wird so etwas
        gewünscht, gib eine leere CSS-Antwort und schreib in die
        Zusammenfassung, dass es hier nicht geht und warum.
        TEXT;
    }

    private static function frage(string $befehl): string
    {
        return "## Die Abschnitte dieser Website\n\n"
            . self::karte()
            . "\n\n## Was geändert werden soll\n\n"
            . $befehl;
    }

    /**
     * Eine Karte der Abschnitte, aus den Ansichten gelesen.
     *
     * Nicht aufgeschrieben, sondern nachgesehen: Eine Liste von Hand
     * wäre nach der ersten Änderung an einer Ansicht falsch, und dann
     * bekommt die Schnittstelle Namen, die es nicht gibt.
     */
    public static function karte(): string
    {
        // Die Ueberschriften kommen aus den Sprachdateien. Ist noch
        // keine Sprache gesetzt - etwa beim Aufruf von der
        // Kommandozeile -, kaeme sonst der Schluessel statt des Titels.
        if (\WebAtze\Core\I18n::get('services.title') === 'services.title') {
            \WebAtze\Core\I18n::setLocale((string) Config::get('default_locale', 'de'));
        }

        $seiten = [
            'pages/home.php' => ['Startseite', '/'],
            'pages/services.php' => ['Leistungen', '/leistungen'],
            'pages/process.php' => ['So läufts ab', '/ablauf'],
            'pages/work.php' => ['Referenzen', '/referenzen'],
            'pages/contact.php' => ['Kontakt', '/kontakt'],
            'partials/header.php' => ['Kopfzeile (auf jeder Seite)', ''],
            'partials/footer.php' => ['Fusszeile (auf jeder Seite)', ''],
        ];

        $bloecke = [];

        foreach ($seiten as $datei => [$titel, $pfad]) {
            $voll = APP_DIR . '/Views/' . $datei;

            if (!is_file($voll)) {
                continue;
            }

            $abschnitte = self::abschnitte((string) file_get_contents($voll));

            if ($abschnitte === []) {
                continue;
            }

            $bloecke[] = $titel . ($pfad !== '' ? '  ' . $pfad : '') . ":\n"
                . implode("\n", array_slice($abschnitte, 0, 16));
        }

        if ($bloecke === []) {
            return "(Die Abschnitte liessen sich nicht auslesen. Arbeite mit den\n"
                . "üblichen Klassennamen: .wa-hero, .wa-section, .wa-card, .wa-btn.)";
        }

        return implode("\n\n", $bloecke)
            . "\n\nDie üblichen Bausteine: .wa-section (ein Abschnitt), .wa-container\n"
            . "(die Breitenbegrenzung), .wa-card (eine Karte), .wa-btn (eine\n"
            . "Schaltfläche), .wa-section__head (Kopf eines Abschnitts),\n"
            . ".wa-hero (der Auftakt der Startseite).";
    }

    /**
     * Die Abschnitte einer Ansicht: Kennung, Klassen, Überschrift.
     *
     * Die Überschrift ist der Grund, warum das hier steht. Ohne sie
     * bekäme die Schnittstelle eine Liste aus ".wa-section, .wa-section,
     * .wa-section" - und bei "mach die Leistungen anders" wüsste sie
     * nicht, welche davon gemeint ist.
     *
     * @return array<int, string>
     */
    private static function abschnitte(string $html): array
    {
        // Jeden Abschnitt vom oeffnenden Tag bis zum naechsten nehmen -
        // grob, aber es geht nur darum, die Ueberschrift darin zu finden.
        $treffer = preg_split(
            '/(?=<(?:section|header|footer)\b)/i',
            $html,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        if ($treffer === false) {
            return [];
        }

        $liste = [];

        foreach ($treffer as $stueck) {
            if (preg_match('/^<(section|header|footer)\b([^>]*)>/i', $stueck, $kopf) !== 1) {
                continue;
            }

            $attribute = $kopf[2];

            $kennung = '';
            if (preg_match('/\bid="([a-z0-9_-]+)"/i', $attribute, $m) === 1) {
                $kennung = '#' . $m[1];
            }

            $klassen = [];
            if (preg_match('/\bclass="([^"]*)"/i', $attribute, $m) === 1) {
                foreach (explode(' ', $m[1]) as $klasse) {
                    $klasse = trim($klasse);

                    if ($klasse !== '' && str_starts_with($klasse, 'wa-') && !str_contains($klasse, '<')) {
                        $klassen[] = '.' . $klasse;
                    }
                }
            }

            if ($kennung === '' && $klassen === []) {
                continue;
            }

            // Die erste Ueberschrift im Abschnitt - sie sagt, worum es
            // geht. Ohne sie bekaeme die Schnittstelle eine Liste aus
            // lauter ".wa-section" und wuesste bei "mach die Leistungen
            // anders" nicht, welche gemeint ist.
            //
            // Die Ansichten schreiben ihre Ueberschriften ueber
            // Sprachschluessel. Steht dort __t('services.title'), wird
            // der Schluessel hier aufgeloest - sonst staende in der
            // Karte der Schluessel statt des Titels.
            $titel = '';

            if (preg_match('/<h[123][^>]*>(.{0,200}?)<\/h[123]>/su', $stueck, $m) === 1) {
                $roh = $m[1];

                if (preg_match("/__t\\(\\s*'([a-z0-9_.]+)'/i", $roh, $k) === 1) {
                    $titel = \WebAtze\Core\I18n::get($k[1]);

                    // Ein Schluessel, den es nicht gibt, kommt als
                    // Schluessel zurueck. Der hilft niemandem.
                    if ($titel === $k[1]) {
                        $titel = '';
                    }
                } else {
                    $titel = trim(strip_tags($roh));
                }

                $titel = trim(preg_replace('/\s+/', ' ', $titel) ?? '');

                if (str_contains($titel, '<?') || mb_strlen($titel) > 70) {
                    $titel = '';
                }
            }

            $zeile = '  ' . ($kennung !== '' ? $kennung : implode('', array_slice($klassen, 0, 2)));

            if ($kennung !== '' && $klassen !== []) {
                $zeile .= '  ' . implode(' ', array_slice($klassen, 0, 3));
            }

            if ($titel !== '') {
                $zeile .= '  – „' . $titel . '“';
            }

            $liste[] = $zeile;
        }

        return $liste;
    }

    /**
     * Zusammenfassung und CSS aus der Antwort holen.
     *
     * @return array{0:string, 1:string} CSS, Zusammenfassung
     */
    private static function auspacken(string $antwort): array
    {
        $zusammenfassung = '';

        if (preg_match('/ZUSAMMENFASSUNG:\s*(.+)/u', $antwort, $treffer) === 1) {
            $zusammenfassung = trim(strip_tags($treffer[1]));
            $zusammenfassung = trim($zusammenfassung, " \t\n\r`");
        }

        // Der Codeblock mit dem CSS. Notfalls der letzte Block überhaupt.
        if (preg_match('/```css\s*(.+?)```/su', $antwort, $treffer) === 1) {
            return [trim($treffer[1]), $zusammenfassung];
        }

        if (preg_match_all('/```(?:[a-z]*)\s*(.+?)```/su', $antwort, $alle) > 0) {
            $letzter = trim((string) end($alle[1]));

            // Ein Block ohne geschweifte Klammern ist kein CSS - das ist
            // dann die Zusammenfassung, die versehentlich im Block steht.
            if (str_contains($letzter, '{')) {
                return [$letzter, $zusammenfassung];
            }
        }

        return ['', $zusammenfassung];
    }
}
