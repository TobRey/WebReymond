<?php

declare(strict_types=1);

namespace WebAtze\Domain;

use WebAtze\Core\Settings;

/**
 * Der Suchauftrag zum Kopieren.
 *
 * Warum ein Auftrag zum Kopieren und keine Suche im Programm: Siehe die
 * Vorbemerkung in Prospects. Kurz – dieser Server darf Google nicht
 * durchsuchen, und wer es trotzdem versucht, bekommt an manchen Tagen
 * Ergebnisse und an anderen eine Sperrseite.
 *
 * Der Auftrag hier ist deshalb bewusst vollständig: Gegend, Branche,
 * Merkmale, Anzahl, und vor allem das genaue Format der Antwort. Je
 * genauer die Vorgabe, desto weniger muss hinterher von Hand
 * nachgebessert werden.
 */
final class SearchOrder
{
    /** Was eine Firma für WebAtze interessant macht. */
    public const CRITERIA = [
        'keine' => 'Hat gar keine Website',
        'veraltet' => 'Website sieht veraltet aus',
        'nicht_mobil' => 'Website funktioniert am Handy schlecht',
        'baukasten' => 'Billiger Baukasten (Wix, Jimdo und ähnliche)',
        'langsam' => 'Website lädt sehr langsam',
        'wachsend' => 'Firma wächst gerade oder ist neu gegründet',
    ];

    /**
     * @param array<string, mixed> $filter
     */
    public static function build(array $filter): string
    {
        $gegend = self::text($filter['region'] ?? '', 'in der Deutschschweiz');
        $branche = self::text($filter['branch'] ?? '', '');
        $anzahl = max(1, min(25, (int) ($filter['count'] ?? 10)));

        $merkmale = [];
        foreach ((array) ($filter['criteria'] ?? []) as $schluessel) {
            if (isset(self::CRITERIA[(string) $schluessel])) {
                $merkmale[] = self::CRITERIA[(string) $schluessel];
            }
        }

        if ($merkmale === []) {
            $merkmale = [self::CRITERIA['keine'], self::CRITERIA['veraltet'], self::CRITERIA['nicht_mobil']];
        }

        $zusatz = trim((string) ($filter['extra'] ?? ''));
        $bekannt = self::alreadyKnown();

        $zeilen = [];

        $zeilen[] = 'AUFTRAG';
        $zeilen[] = '';
        $zeilen[] = 'Suche im Internet nach ' . $anzahl . ' echten, heute existierenden Firmen '
            . $gegend . ($branche !== '' ? ' aus dieser Branche: ' . $branche : '')
            . ', die eine neue Website gebrauchen könnten.';
        $zeilen[] = '';

        $zeilen[] = 'WORAUF ES ANKOMMT';
        $zeilen[] = '';
        foreach ($merkmale as $merkmal) {
            $zeilen[] = '- ' . $merkmal;
        }
        $zeilen[] = '- Die Firma ist klein oder mittelgross. Konzerne haben eine eigene Abteilung dafür.';
        $zeilen[] = '- Es gibt einen erreichbaren Kontakt: Telefonnummer oder E-Mail-Adresse.';

        if ($zusatz !== '') {
            $zeilen[] = '- ' . $zusatz;
        }

        $zeilen[] = '';
        $zeilen[] = 'WIE DU VORGEHST';
        $zeilen[] = '';
        $zeilen[] = 'Suche im Web. Sieh dir zu jeder Firma an, was öffentlich zu finden ist: '
            . 'die eigene Website, Branchenverzeichnisse, Kartendienste, Bewertungen. '
            . 'Ruf die Website tatsächlich auf und beurteile sie – Alter, Handytauglichkeit, Tempo.';
        $zeilen[] = '';
        $zeilen[] = 'Erfinde nichts. Was du nicht findest, bleibt leer. Eine erfundene '
            . 'Telefonnummer ist schlimmer als gar keine, weil sie erst beim Anrufen auffällt.';

        if ($bekannt !== '') {
            $zeilen[] = '';
            $zeilen[] = 'ÜBERSPRINGEN (schon bekannt)';
            $zeilen[] = '';
            $zeilen[] = $bekannt;
        }

        $zeilen[] = '';
        $zeilen[] = 'ANTWORTFORMAT';
        $zeilen[] = '';
        $zeilen[] = 'Antworte ausschliesslich mit dieser Liste in JSON, ohne einleitenden Satz '
            . 'und ohne Schlusswort:';
        $zeilen[] = '';
        $zeilen[] = self::example();
        $zeilen[] = '';
        $zeilen[] = 'Zu den Feldern:';
        $zeilen[] = '';
        $zeilen[] = '- site_state ist genau eines von: '
            . implode(', ', array_keys(array_filter(Prospects::SITE_STATES, static fn (string $k): bool => $k !== '', ARRAY_FILTER_USE_KEY)));
        $zeilen[] = '- score ist eine Zahl von 0 bis 100: Wie sehr lohnt sich der Anruf?';
        $zeilen[] = '- reason ist ein Satz: Warum gerade diese Firma?';
        $zeilen[] = '- research ist alles Weitere, was beim Anrufen hilft – was die Firma macht, '
            . 'wie gross sie ist, wer sie führt, was an der jetzigen Website auffällt, '
            . 'seit wann es sie gibt. Ruhig ausführlich.';
        $zeilen[] = '';
        $zeilen[] = 'Schreibe auf Deutsch, mit ss statt ß.';

        return implode("\n", $zeilen);
    }

    /** Ein einzelnes Beispiel – kurz genug zum Lesen, vollständig genug als Vorlage. */
    private static function example(): string
    {
        return json_encode(
            [[
                'name' => 'Muster Schreinerei GmbH',
                'branch' => 'Schreinerei',
                'place' => 'Winterthur',
                'website' => 'https://beispiel.ch',
                'email' => 'info@beispiel.ch',
                'phone' => '052 000 00 00',
                'address' => 'Musterweg 1, 8400 Winterthur',
                'contact_name' => 'Hans Muster',
                'site_state' => 'veraltet',
                'score' => 80,
                'reason' => 'Die Website stammt sichtbar aus 2011 und ist am Handy unlesbar.',
                'research' => 'Familienbetrieb seit 1978, sieben Mitarbeitende, Innenausbau und Küchen. '
                    . 'Sehr gute Bewertungen auf Google. Kein Kontaktformular, nur eine Telefonnummer.',
            ]],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?: '[]';
    }

    /**
     * Die Namen, die schon in der Datenbank stehen.
     *
     * Damit derselbe Betrieb nicht bei jedem Suchlauf wieder vorgeschlagen
     * wird. Die Liste bleibt kurz, sonst wird der Auftrag unlesbar.
     */
    private static function alreadyKnown(int $limit = 60): string
    {
        $namen = [];

        foreach (\WebAtze\Core\Db::all(
            'SELECT name FROM prospects ORDER BY id DESC LIMIT ' . $limit
        ) as $zeile) {
            $namen[] = (string) $zeile['name'];
        }

        foreach (\WebAtze\Core\Db::all(
            'SELECT name FROM customers ORDER BY id DESC LIMIT ' . $limit
        ) as $zeile) {
            $namen[] = (string) $zeile['name'];
        }

        $namen = array_values(array_unique(array_filter($namen)));

        return $namen === [] ? '' : implode(', ', array_slice($namen, 0, $limit));
    }

    private static function text(mixed $wert, string $ersatz): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $wert) ?? '');

        return $text === '' ? $ersatz : mb_substr($text, 0, 200);
    }

    /** Die zuletzt benutzten Einstellungen, damit nicht jedes Mal alles neu getippt wird. */
    public static function lastUsed(): array
    {
        return [
            'region' => Settings::get('search_region', ''),
            'branch' => Settings::get('search_branch', ''),
            'count' => (int) Settings::get('search_count', '10'),
            'criteria' => array_values(array_filter(
                explode(',', Settings::get('search_criteria', 'keine,veraltet,nicht_mobil'))
            )),
            'extra' => Settings::get('search_extra', ''),
        ];
    }

    /** @param array<string, mixed> $filter */
    public static function remember(array $filter): void
    {
        Settings::putMany([
            'search_region' => mb_substr(trim((string) ($filter['region'] ?? '')), 0, 200),
            'search_branch' => mb_substr(trim((string) ($filter['branch'] ?? '')), 0, 200),
            'search_count' => (string) max(1, min(25, (int) ($filter['count'] ?? 10))),
            'search_criteria' => implode(',', array_filter(
                (array) ($filter['criteria'] ?? []),
                static fn (mixed $k): bool => isset(self::CRITERIA[(string) $k])
            )),
            'search_extra' => mb_substr(trim((string) ($filter['extra'] ?? '')), 0, 500),
        ]);
    }
}
