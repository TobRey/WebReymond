<?php

declare(strict_types=1);

namespace WebAtze\Domain;

use WebAtze\Core\{Db, Logger};

/**
 * Die Kundensuche.
 *
 * Eine ehrliche Vorbemerkung, damit später niemand rätselt: Dieser Server
 * durchsucht Google nicht selbst. Das wäre technisch wacklig – Google
 * beantwortet Anfragen von Mietservern oft gar nicht – und es verstösst
 * gegen die Nutzungsbedingungen. Was hier stattdessen passiert:
 *
 *   1. WebAtze schreibt den Suchauftrag. Vollständig, mit Gegend, Branche
 *      und dem genauen Format der Antwort.
 *   2. Der Auftrag wird kopiert und dort eingefügt, wo eine KI mit
 *      Websuche läuft.
 *   3. Die Antwort kommt zurück ins Feld darunter, und WebAtze legt daraus
 *      die Firmen an.
 *
 * Das kostet keinen Rappen Guthaben, funktioniert auf jedem Hosting und
 * liefert bessere Ergebnisse als eine selbstgebaute Suche. Danach übernimmt
 * wieder das Programm: durchblättern, annehmen, ablehnen, anrufen.
 */
final class Prospects
{
    /** Wo eine Firma gerade steht. */
    public const STATUS = [
        'neu' => 'Noch nicht angesehen',
        'vorgemerkt' => 'Vorgemerkt',
        'kontaktiert' => 'Kontaktiert',
        'offerte' => 'Offerte draussen',
        'gewonnen' => 'Gewonnen',
        'verloren' => 'Verloren',
        'abgelehnt' => 'Abgelehnt',
        'nie' => 'Nie wieder zeigen',
        'kunde' => 'Ist jetzt Kunde',
    ];

    /** Diese Zustände stehen in der Liste der potenziellen Kunden. */
    public const IN_LIST = ['vorgemerkt', 'kontaktiert', 'offerte', 'gewonnen', 'verloren'];

    /** Was mit der Website der Firma los ist – der eigentliche Aufhänger. */
    public const SITE_STATES = [
        '' => 'unbekannt',
        'keine' => 'Hat gar keine Website',
        'veraltet' => 'Website wirkt veraltet',
        'nicht_mobil' => 'Auf dem Handy unbrauchbar',
        'langsam' => 'Lädt sehr langsam',
        'baukasten' => 'Billiger Baukasten',
        'gut' => 'Website ist bereits gut',
    ];

    // ------------------------------------------------------------------
    // Lesen
    // ------------------------------------------------------------------

    /** Die nächste Firma zum Ansehen – eine, nicht zwanzig. */
    public static function next(): ?array
    {
        return Db::first(
            "SELECT * FROM prospects WHERE status = 'neu' ORDER BY score DESC, id ASC LIMIT 1"
        );
    }

    /** Wie viele noch im Stapel liegen. */
    public static function waiting(): int
    {
        return (int) Db::value("SELECT COUNT(*) FROM prospects WHERE status = 'neu'", [], 0);
    }

    /** Die Liste der potenziellen Kunden. */
    public static function shortlist(): array
    {
        $platzhalter = [];
        $werte = [];

        foreach (self::IN_LIST as $i => $status) {
            $platzhalter[] = ':s' . $i;
            $werte['s' . $i] = $status;
        }

        return Db::all(
            'SELECT * FROM prospects WHERE status IN (' . implode(', ', $platzhalter) . ')
             ORDER BY updated_at DESC, id DESC',
            $werte
        );
    }

    /** Die abgelehnten – damit ein Fehlgriff zurückgeholt werden kann. */
    public static function discarded(int $limit = 60): array
    {
        return Db::all(
            "SELECT * FROM prospects WHERE status IN ('abgelehnt', 'nie')
             ORDER BY decided_at DESC, id DESC LIMIT " . max(1, $limit)
        );
    }

    public static function find(int $id): ?array
    {
        return Db::first('SELECT * FROM prospects WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string, int> */
    public static function counts(): array
    {
        $zahlen = ['neu' => 0, 'liste' => 0, 'abgelehnt' => 0, 'kunde' => 0];

        foreach (Db::all('SELECT status, COUNT(*) AS anzahl FROM prospects GROUP BY status') as $zeile) {
            $status = (string) $zeile['status'];
            $anzahl = (int) $zeile['anzahl'];

            if ($status === 'neu') {
                $zahlen['neu'] += $anzahl;
            } elseif ($status === 'kunde') {
                $zahlen['kunde'] += $anzahl;
            } elseif (in_array($status, self::IN_LIST, true)) {
                $zahlen['liste'] += $anzahl;
            } else {
                $zahlen['abgelehnt'] += $anzahl;
            }
        }

        return $zahlen;
    }

    // ------------------------------------------------------------------
    // Entscheiden
    // ------------------------------------------------------------------

    /**
     * Annehmen, ablehnen, oder für immer wegräumen.
     *
     * 'nie' ist der Grund, warum abgelehnte Firmen nicht gelöscht werden:
     * Eine gelöschte Firma taucht beim nächsten Suchlauf wieder auf. Eine
     * mit 'nie' vermerkte wird beim Einlesen übersprungen.
     */
    public static function decide(int $id, string $status): bool
    {
        if (!isset(self::STATUS[$status])) {
            return false;
        }

        return Db::update('prospects', [
            'status' => $status,
            'decided_at' => Db::now(),
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => $id]) > 0;
    }

    public static function note(int $id, string $note): bool
    {
        return Db::update('prospects', [
            'note' => $note,
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => $id]) > 0;
    }

    public static function remove(int $id): bool
    {
        return Db::delete('prospects', 'id = :id', ['id' => $id]) > 0;
    }

    /**
     * Aus einer vorgemerkten Firma einen richtigen Kunden machen.
     *
     * Alles Recherchierte wandert in die Notizen des Kunden mit. Was
     * mühsam zusammengetragen wurde, soll beim Übertragen nicht verloren
     * gehen.
     */
    public static function toCustomer(int $id): ?int
    {
        $firma = self::find($id);

        if ($firma === null) {
            return null;
        }

        if ((int) ($firma['customer_id'] ?? 0) > 0) {
            return (int) $firma['customer_id'];
        }

        $notizen = array_filter([
            trim((string) ($firma['reason'] ?? '')),
            trim((string) ($firma['research'] ?? '')),
            trim((string) ($firma['note'] ?? '')),
        ]);

        $kundeId = Db::insert('customers', [
            'name' => (string) $firma['name'],
            'contact_name' => (string) $firma['contact_name'],
            'email' => (string) $firma['email'],
            'phone' => (string) $firma['phone'],
            'address' => (string) $firma['address'],
            'website' => (string) $firma['website'],
            'status' => 'interessent',
            'notes' => implode("\n\n", $notizen),
            'created_at' => Db::now(),
            'updated_at' => Db::now(),
        ]);

        Db::update('prospects', [
            'status' => 'kunde',
            'customer_id' => $kundeId,
            'decided_at' => Db::now(),
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => $id]);

        Logger::info('Potenzieller Kunde übernommen.', ['prospect' => $id, 'customer' => $kundeId]);

        return $kundeId;
    }

    // ------------------------------------------------------------------
    // Nachschlagen
    // ------------------------------------------------------------------

    /**
     * Der Verweis auf die Google-Suche nach dieser Firma.
     *
     * Kein Abruf durch den Server, nur ein Verweis – der Browser
     * durchsucht, wie jeder andere Besucher auch.
     */
    public static function googleUrl(array $firma): string
    {
        $begriffe = array_filter([
            (string) ($firma['name'] ?? ''),
            (string) ($firma['place'] ?? ''),
        ]);

        return 'https://www.google.com/search?q=' . rawurlencode(implode(' ', $begriffe));
    }

    /** Und die Karte, wenn eine Adresse bekannt ist. */
    public static function mapsUrl(array $firma): string
    {
        $begriffe = array_filter([
            (string) ($firma['name'] ?? ''),
            (string) ($firma['address'] ?? ''),
            (string) ($firma['place'] ?? ''),
        ]);

        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(implode(' ', $begriffe));
    }

    // ------------------------------------------------------------------
    // Anlegen
    // ------------------------------------------------------------------

    /**
     * Eine Firma anlegen oder ändern.
     *
     * @param array<string, mixed> $daten
     */
    public static function save(array $daten, ?int $id = null): int
    {
        $satz = [
            'name' => self::kurz($daten['name'] ?? '', 191),
            'branch' => self::kurz($daten['branch'] ?? '', 120),
            'place' => self::kurz($daten['place'] ?? '', 120),
            'website' => self::url($daten['website'] ?? ''),
            'email' => self::kurz($daten['email'] ?? '', 191),
            'phone' => self::kurz($daten['phone'] ?? '', 60),
            'address' => self::kurz($daten['address'] ?? '', 255),
            'contact_name' => self::kurz($daten['contact_name'] ?? '', 191),
            'site_state' => isset(self::SITE_STATES[(string) ($daten['site_state'] ?? '')])
                ? (string) $daten['site_state']
                : '',
            'reason' => self::lang($daten['reason'] ?? '', 1200),
            'research' => self::lang($daten['research'] ?? '', 8000),
            'score' => max(0, min(100, (int) ($daten['score'] ?? 0))),
            'source' => self::kurz($daten['source'] ?? 'auftrag', 20),
            'updated_at' => Db::now(),
        ];

        if ($id !== null) {
            Db::update('prospects', $satz, 'id = :id', ['id' => $id]);

            return $id;
        }

        $satz['status'] = isset(self::STATUS[(string) ($daten['status'] ?? '')])
            ? (string) $daten['status']
            : 'neu';
        $satz['created_at'] = Db::now();

        return Db::insert('prospects', $satz);
    }

    /**
     * Die Antwort des Suchauftrags einlesen.
     *
     * Was hier ankommt, ist von Hand eingefügt – also alles Mögliche.
     * Deshalb wird grosszügig gelesen: mit oder ohne Code-Rahmen, als
     * Liste oder als Objekt mit einer Liste darin.
     *
     * @return array{ok:bool, meldung:string, neu:int, bekannt:int}
     */
    public static function import(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return self::bericht(false, 'Da war nichts zum Einlesen.');
        }

        // Ein Code-Rahmen wie ```json ... ``` wird gern mitkopiert.
        if (preg_match('/```(?:json)?\s*(.+?)```/s', $text, $treffer) === 1) {
            $text = trim($treffer[1]);
        }

        // Und manchmal steht ein einleitender Satz davor.
        $start = strcspn($text, '[{');
        if ($start > 0 && $start < strlen($text)) {
            $text = substr($text, $start);
        }

        $daten = json_decode($text, true);

        if (!is_array($daten)) {
            return self::bericht(
                false,
                'Das liess sich nicht lesen. Erwartet wird die Liste aus dem Suchauftrag – '
                . 'am einfachsten alles von der ersten eckigen Klammer bis zur letzten kopieren.'
            );
        }

        // Entweder direkt die Liste, oder ein Objekt mit einer darin.
        if (!array_is_list($daten)) {
            foreach (['firmen', 'leads', 'companies', 'ergebnisse', 'data'] as $schluessel) {
                if (isset($daten[$schluessel]) && is_array($daten[$schluessel])) {
                    $daten = $daten[$schluessel];
                    break;
                }
            }
        }

        if (!array_is_list($daten)) {
            $daten = [$daten];
        }

        $neu = 0;
        $bekannt = 0;

        foreach ($daten as $eintrag) {
            if (!is_array($eintrag)) {
                continue;
            }

            $eintrag = self::vereinheitlichen($eintrag);
            $name = self::kurz($eintrag['name'] ?? '', 191);

            if ($name === '') {
                continue;
            }

            if (self::kennenWirSchon($name, self::url($eintrag['website'] ?? ''))) {
                $bekannt++;
                continue;
            }

            self::save($eintrag);
            $neu++;
        }

        if ($neu === 0 && $bekannt === 0) {
            return self::bericht(false, 'In der Antwort stand keine einzige Firma mit Namen.');
        }

        Logger::info('Firmen aus Suchauftrag eingelesen.', ['neu' => $neu, 'bekannt' => $bekannt]);

        return self::bericht(
            true,
            $neu . ' ' . ($neu === 1 ? 'Firma' : 'Firmen') . ' übernommen'
            . ($bekannt > 0 ? ', ' . $bekannt . ' schon bekannt oder früher weggelegt' : '')
            . '.',
            $neu,
            $bekannt
        );
    }

    /**
     * Schon einmal dagewesen?
     *
     * Auch abgelehnte zählen – sonst kommt beim nächsten Suchlauf genau
     * die Firma wieder, die eben weggeschoben wurde.
     */
    private static function kennenWirSchon(string $name, string $website): bool
    {
        $treffer = Db::value(
            'SELECT id FROM prospects WHERE LOWER(name) = :name LIMIT 1',
            ['name' => mb_strtolower($name)]
        );

        if ($treffer) {
            return true;
        }

        if ($website !== '') {
            $host = self::host($website);

            if ($host !== '' && Db::value(
                'SELECT id FROM prospects WHERE website <> :leer AND LOWER(website) LIKE :muster LIMIT 1',
                ['leer' => '', 'muster' => '%' . mb_strtolower($host) . '%']
            )) {
                return true;
            }
        }

        // Und wer schon Kunde ist, braucht nicht gesucht zu werden.
        return (bool) Db::value(
            'SELECT id FROM customers WHERE LOWER(name) = :name LIMIT 1',
            ['name' => mb_strtolower($name)]
        );
    }

    /**
     * Fremde Feldnamen auf die eigenen bringen.
     *
     * Eine KI hält sich meist an die Vorgabe, aber nicht immer – und ein
     * englisches 'city' statt 'place' soll nicht dazu führen, dass der Ort
     * fehlt.
     *
     * @param array<string, mixed> $eintrag
     * @return array<string, mixed>
     */
    private static function vereinheitlichen(array $eintrag): array
    {
        $karte = [
            'firma' => 'name', 'company' => 'name', 'firmenname' => 'name',
            'branche' => 'branch', 'industry' => 'branch', 'bereich' => 'branch',
            'ort' => 'place', 'city' => 'place', 'stadt' => 'place', 'gemeinde' => 'place',
            'url' => 'website', 'webseite' => 'website', 'homepage' => 'website',
            'mail' => 'email', 'e_mail' => 'email', 'e-mail' => 'email',
            'telefon' => 'phone', 'tel' => 'phone', 'telephone' => 'phone',
            'adresse' => 'address', 'strasse' => 'address',
            'kontakt' => 'contact_name', 'ansprechpartner' => 'contact_name',
            'contact' => 'contact_name', 'inhaber' => 'contact_name',
            'website_zustand' => 'site_state', 'zustand' => 'site_state', 'site' => 'site_state',
            'begruendung' => 'reason', 'grund' => 'reason', 'warum' => 'reason',
            'recherche' => 'research', 'infos' => 'research', 'details' => 'research',
            'notes' => 'research', 'beschreibung' => 'research',
            'punkte' => 'score', 'bewertung' => 'score', 'rating' => 'score',
        ];

        $sauber = [];

        foreach ($eintrag as $schluessel => $wert) {
            $klein = strtolower(trim((string) $schluessel));
            $ziel = $karte[$klein] ?? $klein;

            // Verschachteltes wird zu lesbarem Text statt verloren zu gehen.
            if (is_array($wert)) {
                $wert = self::flach($wert);
            }

            $sauber[$ziel] = is_scalar($wert) ? (string) $wert : '';
        }

        // Ein Zustand kommt oft als Satz statt als Stichwort zurück.
        if (isset($sauber['site_state']) && !isset(self::SITE_STATES[$sauber['site_state']])) {
            $sauber['research'] = trim(($sauber['research'] ?? '') . "\n" . $sauber['site_state']);
            $sauber['site_state'] = self::zustandRaten($sauber['site_state']);
        }

        return $sauber;
    }

    /** Aus verschachtelten Angaben lesbare Zeilen machen. */
    private static function flach(array $wert): string
    {
        $teile = [];

        foreach ($wert as $schluessel => $inhalt) {
            if (is_array($inhalt)) {
                $inhalt = self::flach($inhalt);
            }

            if (!is_scalar($inhalt) || (string) $inhalt === '') {
                continue;
            }

            $teile[] = is_int($schluessel) ? (string) $inhalt : $schluessel . ': ' . $inhalt;
        }

        return implode("\n", $teile);
    }

    private static function zustandRaten(string $text): string
    {
        $klein = mb_strtolower($text);

        foreach ([
            'keine' => ['keine website', 'no website', 'nicht vorhanden', 'gibt es nicht', 'keine seite'],
            'nicht_mobil' => ['nicht mobil', 'nicht responsiv', 'handy', 'mobile'],
            'langsam' => ['langsam', 'slow', 'ladezeit'],
            'baukasten' => ['baukasten', 'wix', 'jimdo', 'squarespace', 'homepage-baukasten'],
            'veraltet' => ['veraltet', 'alt', 'outdated', 'jahre'],
            'gut' => ['modern', 'gut', 'aktuell', 'gepflegt'],
        ] as $zustand => $woerter) {
            foreach ($woerter as $wort) {
                if (str_contains($klein, $wort)) {
                    return $zustand;
                }
            }
        }

        return '';
    }

    // ------------------------------------------------------------------
    // Kleinkram
    // ------------------------------------------------------------------

    private static function kurz(mixed $wert, int $laenge): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $wert) ?? '');

        return mb_substr($text, 0, $laenge);
    }

    private static function lang(mixed $wert, int $laenge): string
    {
        $text = trim(preg_replace('/[ \t]+/', ' ', (string) $wert) ?? '');

        return mb_substr($text, 0, $laenge);
    }

    private static function url(mixed $wert): string
    {
        $text = self::kurz($wert, 191);

        if ($text === '' || str_starts_with($text, 'http://') || str_starts_with($text, 'https://')) {
            return $text;
        }

        return 'https://' . ltrim($text, '/');
    }

    private static function host(string $url): string
    {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');

        return $host === '' ? '' : preg_replace('/^www\./i', '', $host) ?? $host;
    }

    /** @return array{ok:bool, meldung:string, neu:int, bekannt:int} */
    private static function bericht(bool $ok, string $meldung, int $neu = 0, int $bekannt = 0): array
    {
        return ['ok' => $ok, 'meldung' => $meldung, 'neu' => $neu, 'bekannt' => $bekannt];
    }
}
