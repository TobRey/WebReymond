<?php

declare(strict_types=1);

namespace WebAtze\Domain;

use WebAtze\Core\{Db, Settings};

/**
 * Das Intranet: kurze Beiträge, die nur ich sehe.
 *
 * Kein Kunde, kein Besucher, keine Schnittstelle – was hier steht, liegt
 * hinter der Anmeldung und geht nirgendwo hin. Deshalb dürfen hier auch
 * Dinge stehen, die auf der Website nie stehen dürfen: Preise, Abläufe,
 * was ich mir merken will.
 *
 * Bewusst schlicht. Es ist kein Wiki und soll keines werden: Titel,
 * Text, ein Stichwort, angeheftet ja oder nein. Wer eine Gliederung
 * braucht, schreibt sie in den Text.
 */
final class Notes
{
    /** Stichwörter zum Einordnen – Vorschläge, eintippen geht auch. */
    public const TAGS = [
        'Ablauf',
        'Preise',
        'Technik',
        'Vorlagen',
        'Ideen',
        'Merken',
    ];

    // ------------------------------------------------------------------
    // Lesen
    // ------------------------------------------------------------------

    /**
     * Alle Beiträge – angeheftete zuerst, dann die zuletzt geänderten.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(string $suche = '', string $stichwort = ''): array
    {
        $sql = 'SELECT * FROM notes';
        $wo = [];
        $werte = [];

        $suche = trim($suche);

        if ($suche !== '') {
            $wo[] = '(LOWER(title) LIKE :suche OR LOWER(body) LIKE :suche)';
            $werte['suche'] = '%' . mb_strtolower($suche) . '%';
        }

        if (trim($stichwort) !== '') {
            $wo[] = 'tag = :stichwort';
            $werte['stichwort'] = trim($stichwort);
        }

        if ($wo !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $wo);
        }

        // Angeheftetes zuerst: Was ich oben festgemacht habe, brauche ich
        // haeufig - sonst haette ich es nicht festgemacht.
        $sql .= ' ORDER BY pinned DESC, updated_at DESC, id DESC LIMIT 300';

        return Db::all($sql, $werte);
    }

    public static function find(int $id): ?array
    {
        return Db::first('SELECT * FROM notes WHERE id = :id', ['id' => $id]);
    }

    /**
     * Welche Stichwörter kommen vor, und wie oft?
     *
     * @return array<string, int>
     */
    public static function tags(): array
    {
        $liste = [];

        foreach (Db::all(
            'SELECT tag, COUNT(*) AS anzahl FROM notes WHERE tag <> :leer GROUP BY tag ORDER BY tag ASC',
            ['leer' => '']
        ) as $zeile) {
            $liste[(string) $zeile['tag']] = (int) $zeile['anzahl'];
        }

        return $liste;
    }

    // ------------------------------------------------------------------
    // Schreiben
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $daten
     * @return array{ok:bool, meldung:string, id:int}
     */
    public static function save(array $daten, ?int $id = null): array
    {
        $titel = mb_substr(trim((string) ($daten['title'] ?? '')), 0, 191);

        if ($titel === '') {
            return ['ok' => false, 'meldung' => 'Ohne Titel findest du den Beitrag später nicht wieder.', 'id' => 0];
        }

        $satz = [
            'title' => $titel,
            'body' => mb_substr(self::clean((string) ($daten['body'] ?? '')), 0, 60000),
            'tag' => mb_substr(trim((string) ($daten['tag'] ?? '')), 0, 40),
            'pinned' => !empty($daten['pinned']) ? 1 : 0,
            'updated_at' => Db::now(),
        ];

        if ($id !== null && self::find($id) !== null) {
            Db::update('notes', $satz, 'id = :id', ['id' => $id]);

            return ['ok' => true, 'meldung' => 'Gespeichert.', 'id' => $id];
        }

        $satz['created_at'] = Db::now();

        return ['ok' => true, 'meldung' => 'Der Beitrag steht im Intranet.', 'id' => Db::insert('notes', $satz)];
    }

    public static function remove(int $id): bool
    {
        return Db::delete('notes', 'id = :id', ['id' => $id]) > 0;
    }

    public static function pin(int $id, bool $an): bool
    {
        return Db::update('notes', ['pinned' => $an ? 1 : 0, 'updated_at' => Db::now()],
            'id = :id', ['id' => $id]) > 0;
    }

    // ------------------------------------------------------------------
    // Anzeigen
    // ------------------------------------------------------------------

    /**
     * Einen Beitrag als HTML darstellen.
     *
     * Ein winziger Teil von Markdown, mehr nicht: Ueberschriften,
     * Listen, Tabellen, fett, Code. Genug, um eine Notiz lesbar zu
     * machen, und wenig genug, um in dreissig Zeilen zu passen.
     *
     * Sicherheit ist hier keine Frage der Sorgfalt, sondern der
     * Reihenfolge: Es wird ZUERST alles maskiert und ERST DANACH
     * umgeformt. Damit kann aus dem Text prinzipiell kein Auszeichnung
     * entstehen, die ich nicht selbst erzeuge - auch dann nicht, wenn
     * ich beim Schreiben einen spitzen Klammer-Ausdruck einfuege.
     */
    public static function render(string $text): string
    {
        $zeilen = explode("\n", htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        $aus = [];
        $liste = null;      // 'ul' oder 'ol', solange eine laeuft
        $tabelle = false;
        $absatz = [];

        // Erst am Ende umformen, nicht Zeile fuer Zeile: Ein **fett**,
        // das ueber einen Zeilenumbruch laeuft, ist sonst zwei halbe
        // Sternchenpaare und bleibt als Rohtext stehen.
        $absatzSchliessen = static function () use (&$absatz, &$aus): void {
            if ($absatz !== []) {
                $aus[] = '<p>' . self::inline(implode('<br>', $absatz)) . '</p>';
                $absatz = [];
            }
        };

        $punkt = null;      // Der offene Listenpunkt, noch als Rohtext

        $punktSchliessen = static function () use (&$punkt, &$aus): void {
            if ($punkt !== null) {
                $aus[] = '<li>' . self::inline($punkt) . '</li>';
                $punkt = null;
            }
        };

        $listeSchliessen = static function () use (&$liste, &$aus, &$punkt, $punktSchliessen): void {
            $punktSchliessen();

            if ($liste !== null) {
                $aus[] = '</' . $liste . '>';
                $liste = null;
            }
        };

        $tabelleSchliessen = static function () use (&$tabelle, &$aus): void {
            if ($tabelle) {
                $aus[] = '</tbody></table></div>';
                $tabelle = false;
            }
        };

        foreach ($zeilen as $zeile) {
            $roh = rtrim($zeile);
            $inhalt = trim($roh);

            // Leere Zeile: alles Offene zumachen.
            if ($inhalt === '') {
                $absatzSchliessen();
                $listeSchliessen();
                $tabelleSchliessen();
                continue;
            }

            // Trennlinie einer Tabelle - sie wird nicht ausgegeben.
            if ($tabelle && preg_match('/^\|[\s:|-]+\|$/', $inhalt) === 1) {
                continue;
            }

            // Tabellenzeile
            if (str_starts_with($inhalt, '|') && str_ends_with($inhalt, '|')) {
                $absatzSchliessen();
                $listeSchliessen();

                $zellen = array_map('trim', explode('|', trim($inhalt, '|')));

                if (!$tabelle) {
                    $aus[] = '<div class="wa-table-wrap"><table class="wa-table"><thead><tr>';
                    foreach ($zellen as $zelle) {
                        $aus[] = '<th>' . self::inline($zelle) . '</th>';
                    }
                    $aus[] = '</tr></thead><tbody>';
                    $tabelle = true;
                    continue;
                }

                $aus[] = '<tr>';
                foreach ($zellen as $zelle) {
                    $aus[] = '<td>' . self::inline($zelle) . '</td>';
                }
                $aus[] = '</tr>';
                continue;
            }

            $tabelleSchliessen();

            // Ueberschrift
            if (preg_match('/^(#{2,4})\s+(.+)$/', $inhalt, $treffer) === 1) {
                $absatzSchliessen();
                $listeSchliessen();

                $stufe = min(4, strlen($treffer[1]) + 1);
                $aus[] = '<h' . $stufe . '>' . self::inline($treffer[2]) . '</h' . $stufe . '>';
                continue;
            }

            // Aufzaehlung
            if (preg_match('/^[*-]\s+(.+)$/', $inhalt, $treffer) === 1) {
                $absatzSchliessen();
                if ($liste !== 'ul') {
                    $listeSchliessen();
                    $aus[] = '<ul>';
                    $liste = 'ul';
                }
                $punktSchliessen();
                $punkt = $treffer[1];
                continue;
            }

            // Nummerierte Liste
            if (preg_match('/^\d+[.)]\s+(.+)$/', $inhalt, $treffer) === 1) {
                $absatzSchliessen();
                if ($liste !== 'ol') {
                    $listeSchliessen();
                    $aus[] = '<ol>';
                    $liste = 'ol';
                }
                $punktSchliessen();
                $punkt = $treffer[1];
                continue;
            }

            // Fortsetzung einer Listenzeile (eingerueckt)
            if ($punkt !== null && preg_match('/^\s{2,}\S/', $roh) === 1) {
                $punkt .= ' ' . $inhalt;
                continue;
            }

            $listeSchliessen();
            $absatz[] = self::inline($inhalt);
        }

        $absatzSchliessen();
        $listeSchliessen();
        $tabelleSchliessen();

        return implode("\n", $aus);
    }

    /** Fett, Code und Verweise innerhalb einer Zeile. */
    private static function inline(string $text): string
    {
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text) ?? $text;
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<![*\w])\*([^*]+)\*(?![*\w])/', '<em>$1</em>', $text) ?? $text;

        // Adressen anklickbar machen. Der Text ist an dieser Stelle
        // bereits maskiert, deshalb steht dort &amp; statt &.
        return preg_replace(
            '~(?<![">])(https?://[^\s<]+[^\s<.,;:!?)])~',
            '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
            $text
        ) ?? $text;
    }

    /** Steuerzeichen raus, Zeilenenden vereinheitlichen. */
    private static function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return trim(preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? '');
    }

    // ------------------------------------------------------------------
    // Die zwei Beiträge, die von Anfang an dastehen
    // ------------------------------------------------------------------

    /**
     * Einmalig die beiden Grundbeiträge anlegen.
     *
     * Über einen Merker in den Einstellungen, nicht über «Tabelle leer»:
     * Wer sie löscht, will sie los sein und nicht beim nächsten Aufruf
     * wieder vorfinden.
     */
    public static function seed(): int
    {
        if (Settings::get('notes_seeded', '') !== '') {
            return 0;
        }

        Settings::put('notes_seeded', date('c'));

        $angelegt = 0;

        foreach ([self::ablauf(), self::preise()] as $beitrag) {
            $ergebnis = self::save($beitrag);

            if ($ergebnis['ok']) {
                $angelegt++;
            }
        }

        return $angelegt;
    }

    /** @return array<string, mixed> */
    private static function ablauf(): array
    {
        return [
            'title' => 'Von der ersten Anfrage bis zur bezahlten Rechnung',
            'tag' => 'Ablauf',
            'pinned' => true,
            'body' => <<<'TEXT'
Der ganze Weg, von null bis hundert. Wenn ich mitten drin nicht mehr
weiss, was als Nächstes kommt, steht es hier.

## 1. Der Kunde meldet sich

Anfragen kommen über das Kontaktformular (Menüpunkt «Anfragen»), über
die Kundensuche oder direkt. In jedem Fall:

* Unter «Kunden» anlegen, sobald es ernst wird. Alles Weitere hängt
  daran – Offerte, Rechnung, Website, Passwörter.
* Ein Termin für das erste Gespräch in den Kalender. Er landet über das
  Abonnement von selbst auf dem Telefon.

## 2. Klären, was er will

Den Fragebogen schicken (Menüpunkt «Fragebögen»). Der Verweis läuft
nach 30 Tagen ab, der Kunde braucht kein Konto.

Was zurückkommt, ist die Grundlage für alles Weitere. Wenn Felder leer
bleiben: nachfragen, nicht raten. Was ich rate, muss ich später
zweimal machen.

## 3. Offerte

Menüpunkt «Rechnungen» → Art «Offerte». Betrag nach der
Preisorientierung (der andere Beitrag hier). Als PDF verschicken.

Nichts anfangen, bevor die Offerte angenommen ist. Nicht einmal
«schnell etwas zeigen» – daraus wird sonst die Arbeit ohne Auftrag.

## 4. Bauen

Menüpunkt «Neue Website» → Formular ausfüllen → Auftrag erzeugen
lassen → kopieren und bauen lassen. Der Auftrag ist vollständig; was
nicht drinsteht, wird erfunden.

Immer dabei, ohne dass ich daran denken muss:

* `/doc` – die Anleitung für den Kunden
* `/support` – der Draht zu mir, hinter einem Zugangscode

## 5. Zeigen und ändern

Vorschau öffnen, mit dem Kunden durchgehen. Änderungswünsche notieren,
sammeln, in einem Durchgang machen. Nicht fünfmal für je eine
Kleinigkeit – das kostet mehr Zeit als die Änderungen selbst.

## 6. Live

* Domain: **zahlt der Kunde selbst**, immer. Sie gehört ihm, nicht
  mir. Wenn er später weggeht, nimmt er sie mit – das ist richtig so
  und erspart Streit.
* Hochladen (FTP im Formular oder von Hand), danach prüfen: Läuft
  jede Seite? Kommt eine Testanfrage aus dem Formular an? Ist HTTPS an?
* Unter «Websites» eintragen, falls noch nicht geschehen. Dann wird
  sie automatisch alle 15 Minuten geprüft und gezählt.
* Zugangscode für `/support` dem Kunden geben. Er steht auf der
  Projektseite.
* Passwörter in den Tresor, nicht in eine Notiz und nicht in eine
  E-Mail.

## 7. Rechnung

Menüpunkt «Rechnungen» → aus der Offerte eine Rechnung machen.
Zahlungsfrist 30 Tage.

Überfällige Rechnungen zeigt das Menü von selbst an – ich muss nicht
daran denken. Nach 30 Tagen freundlich erinnern, nach 45 deutlich.

## 8. Wartung

Wenn der Kunde Wartung will: Menüpunkt «Verträge» → Vertrag anlegen.
Die Rechnung entsteht am Fälligkeitstag von selbst als Entwurf;
verschickt wird sie von Hand.

Wartung ist **freiwillig**. Wer sie nicht will, hostet woanders – das
ist in Ordnung und muss ich nicht schlechtreden. Ohne Wartung gibt es
aber auch keinen gratis Support; dann ist jede Änderung Aufwand.

## 9. Danach

* Referenz eintragen (mit Einverständnis des Kunden).
* Nach zwei Wochen kurz nachfragen, ob alles läuft. Das kostet fünf
  Minuten und bringt die meisten Weiterempfehlungen.
TEXT,
        ];
    }

    /** @return array<string, mixed> */
    private static function preise(): array
    {
        return [
            'title' => 'Preisorientierung',
            'tag' => 'Preise',
            'pinned' => true,
            'body' => <<<'TEXT'
Richtwerte, keine Preisliste. Sie stehen hier und **nirgendwo auf der
Website** – dort steht nie eine Zahl.

## Aufbau, einmalig

| Was | Richtwert |
|---|---|
| Normale Website | ca. **120 CHF** |
| Online-Shop | ca. **250 CHF** |

Ein Shop ist teurer, weil mehr dahintersteckt: Produkte, Warenkorb,
Bezahlung, Bestellungen. Das ist kein Aufschlag, sondern Arbeit.

## Wartung, monatlich

| Was | Preis |
|---|---|
| Normale Website | **25 CHF im Monat** |
| Online-Shop | **50 CHF im Monat** |

Im Wartungspreis ist der Support **gratis** enthalten: Fragen,
kleine Textänderungen, ein ausgetauschtes Bild, ein neuer
Öffnungszeit-Eintrag. Alles, was in ein paar Minuten erledigt ist.

Wartung ist **freiwillig**. Wer sie nicht nimmt, kann die Website
irgendwo anders hosten – die Dateien gehören ihm. Ohne Wartung gibt es
dann aber auch keinen gratis Support.

## Grössere Arbeiten

Ein Redesign, eine neue Sprache, ein zusätzlicher Bereich, ein
Buchungssystem: **nach Aufwand, pro Stunde**. Vorher schätzen und die
Schätzung schriftlich geben – sonst diskutiert man am Ende über die
Zahl statt über die Arbeit.

Die Grenze zwischen «gratis Support» und «Aufwand» ist einfach: Was
ich in ein paar Minuten mache, ist Support. Was ich planen muss, ist
Aufwand.

## Domain

Zahlt **immer der Kunde selbst**, direkt beim Anbieter. Sie läuft auf
seinen Namen. Das ist sauberer für ihn und für mich: Er ist nie von mir
abhängig, und ich verwalte keine fremden Verlängerungen.

## Was ich mir merken muss

* Ich bin günstig, das ist der Punkt. Aber günstig heisst nicht gratis
  – bei jeder Zusatzarbeit sage ich vorher, dass sie etwas kostet.
* Keine Rabatte im Gespräch. Wenn ich nachlasse, dann in der Offerte
  und mit einem Grund.
* Kein Preis auf der Website. Wer fragt, bekommt eine Offerte – und
  dann rede ich über die Arbeit statt über eine Zahl aus dem Netz.
TEXT,
        ];
    }
}
