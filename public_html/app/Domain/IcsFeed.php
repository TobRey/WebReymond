<?php

declare(strict_types=1);

namespace WebAtze\Domain;

use DateTimeImmutable;
use DateTimeZone;
use WebAtze\Core\{Db, Settings};

/**
 * Der Kalender fürs Telefon.
 *
 * Eine App braucht es dafür nicht. Jedes iPhone kann einen Kalender
 * abonnieren – es holt sich die Termine dann selbst und zeigt sie
 * zwischen allen anderen an, mit den Erinnerungen des Telefons.
 *
 * Was dabei zu wissen ist, damit hinterher niemand rätselt:
 *
 * - Die Verbindung geht nur in eine Richtung. Das Telefon liest; ein
 *   Termin, der dort eingetragen wird, kommt nicht hierher zurück.
 *   Zweiwege-Abgleich bräuchte CalDAV, also einen zweiten Serverdienst,
 *   den geteiltes Hosting nicht kann.
 *
 * - Das iPhone entscheidet selbst, wann es nachsieht. Meist im
 *   Stundentakt. Ein Termin, der eben eingetragen wurde, ist nicht in
 *   derselben Sekunde auf dem Telefon.
 *
 * - Erinnerungen sind eingebaut (VALARM). iOS wirft sie bei abonnierten
 *   Kalendern aber standardmässig weg. In den Einstellungen des
 *   Abonnements muss «Erinnerungen entfernen» ausgeschaltet werden –
 *   das steht so auch in der Anleitung im Adminbereich.
 *
 * Die Adresse enthält ein langes Zufallswort. Wer sie hat, sieht die
 * Termine. Deshalb: nicht weitergeben, und bei Verdacht neu erzeugen.
 */
final class IcsFeed
{
    private const TZ = 'Europe/Zurich';

    /** Wie viele Minuten vorher das Telefon klingelt. */
    private const ALARM_MINUTES = 30;

    /** So weit zurück wird geliefert – ältere Termine interessieren nicht mehr. */
    private const PAST_DAYS = 60;

    private const FUTURE_DAYS = 400;

    // ------------------------------------------------------------------
    // Die Adresse
    // ------------------------------------------------------------------

    /** Das Zufallswort in der Adresse; wird beim ersten Aufruf erzeugt. */
    public static function token(): string
    {
        $token = trim(Settings::get('calendar_token', ''));

        if (strlen($token) < 32) {
            $token = self::newToken();
        }

        return $token;
    }

    public static function newToken(): string
    {
        $token = bin2hex(random_bytes(20));
        Settings::put('calendar_token', $token);

        return $token;
    }

    public static function tokenMatches(string $kandidat): bool
    {
        $echt = trim(Settings::get('calendar_token', ''));

        return strlen($echt) >= 32 && hash_equals($echt, $kandidat);
    }

    /** Die vollständige Adresse zum Abonnieren. */
    public static function url(string $basis): string
    {
        return rtrim($basis, '/') . '/kalender/' . self::token() . '.ics';
    }

    /** Dieselbe Adresse, wie iOS sie direkt annimmt (ein Tippen genügt). */
    public static function webcalUrl(string $basis): string
    {
        return preg_replace('#^https?://#i', 'webcal://', self::url($basis)) ?? self::url($basis);
    }

    // ------------------------------------------------------------------
    // Der Kalender selbst
    // ------------------------------------------------------------------

    public static function build(): string
    {
        $zone = new DateTimeZone(self::TZ);
        $von = date('Y-m-d', time() - self::PAST_DAYS * 86400);
        $bis = date('Y-m-d', time() + self::FUTURE_DAYS * 86400);

        $zeilen = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//WebAtze//Kalender//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:WebAtze',
            'X-WR-TIMEZONE:' . self::TZ,
            'X-WR-CALDESC:Termine und Aufgaben aus der WebAtze-Verwaltung',
            // Damit das Telefon weiss, dass stündliches Nachsehen genügt.
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
            'X-PUBLISHED-TTL:PT1H',
        ];

        foreach (Db::all(
            'SELECT a.*, c.name AS kunde FROM appointments a
             LEFT JOIN customers c ON c.id = a.customer_id
             WHERE a.starts_at >= :von AND a.starts_at <= :bis
             ORDER BY a.starts_at ASC',
            ['von' => $von, 'bis' => $bis . ' 23:59']
        ) as $termin) {
            $zeilen = array_merge($zeilen, self::appointment($termin, $zone));
        }

        foreach (Db::all(
            'SELECT t.*, c.name AS kunde FROM todos t
             LEFT JOIN customers c ON c.id = t.customer_id
             WHERE t.due_on <> :leer AND t.due_on >= :von AND t.due_on <= :bis
             ORDER BY t.due_on ASC',
            ['leer' => '', 'von' => $von, 'bis' => $bis]
        ) as $aufgabe) {
            $zeilen = array_merge($zeilen, self::todo($aufgabe));
        }

        $zeilen[] = 'END:VCALENDAR';

        // Kalenderdateien werden mit CRLF getrennt; manche Programme
        // stolpern sonst über die Datei.
        return implode("\r\n", array_map(self::fold(...), $zeilen)) . "\r\n";
    }

    /**
     * @param array<string, mixed> $termin
     * @return array<int, string>
     */
    private static function appointment(array $termin, DateTimeZone $zone): array
    {
        $start = self::moment((string) $termin['starts_at'], $zone);

        if ($start === null) {
            return [];
        }

        $ende = self::moment((string) ($termin['ends_at'] ?? ''), $zone)
            ?? $start->modify('+1 hour');

        if ($ende <= $start) {
            $ende = $start->modify('+1 hour');
        }

        $titel = (string) $termin['title'];
        $kunde = trim((string) ($termin['kunde'] ?? ''));

        $beschreibung = array_filter([
            $kunde !== '' ? 'Kunde: ' . $kunde : '',
            trim((string) ($termin['note'] ?? '')),
        ]);

        $zeilen = [
            'BEGIN:VEVENT',
            'UID:termin-' . (int) $termin['id'] . '@webatze',
            'DTSTAMP:' . self::utc(new DateTimeImmutable('now')),
            'DTSTART:' . self::utc($start),
            'DTEND:' . self::utc($ende),
            'SUMMARY:' . self::escape($kunde !== '' ? $titel . ' · ' . $kunde : $titel),
        ];

        if ($beschreibung !== []) {
            $zeilen[] = 'DESCRIPTION:' . self::escape(implode("\n", $beschreibung));
        }

        $ort = trim((string) ($termin['place'] ?? ''));

        if ($ort !== '') {
            $zeilen[] = 'LOCATION:' . self::escape($ort);
        }

        $zeilen[] = 'LAST-MODIFIED:' . self::utc(
            self::moment((string) ($termin['updated_at'] ?? ''), $zone) ?? $start
        );

        // Die Erinnerung. Ohne sie meldet sich das Telefon gar nicht.
        $zeilen[] = 'BEGIN:VALARM';
        $zeilen[] = 'ACTION:DISPLAY';
        $zeilen[] = 'DESCRIPTION:' . self::escape($titel);
        $zeilen[] = 'TRIGGER:-PT' . self::ALARM_MINUTES . 'M';
        $zeilen[] = 'END:VALARM';
        $zeilen[] = 'END:VEVENT';

        return $zeilen;
    }

    /**
     * Eine Aufgabe mit Frist.
     *
     * Bewusst als ganztägiger Termin und nicht als VTODO: Aufgabenlisten
     * aus abonnierten Kalendern zeigt iOS nicht an. Ein ganztägiger
     * Eintrag steht sichtbar im Tag – und darum geht es.
     *
     * @param array<string, mixed> $aufgabe
     * @return array<int, string>
     */
    private static function todo(array $aufgabe): array
    {
        $tag = (string) $aufgabe['due_on'];

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tag)) {
            return [];
        }

        $erledigt = trim((string) ($aufgabe['done_at'] ?? '')) !== '';
        $kunde = trim((string) ($aufgabe['kunde'] ?? ''));
        $titel = ($erledigt ? '✓ ' : '') . (string) $aufgabe['title'];

        $zeilen = [
            'BEGIN:VEVENT',
            'UID:aufgabe-' . (int) $aufgabe['id'] . '@webatze',
            'DTSTAMP:' . self::utc(new DateTimeImmutable('now')),
            'DTSTART;VALUE=DATE:' . str_replace('-', '', $tag),
            'DTEND;VALUE=DATE:' . date('Ymd', (strtotime($tag) ?: time()) + 86400),
            'SUMMARY:' . self::escape($kunde !== '' ? $titel . ' · ' . $kunde : $titel),
            'TRANSP:TRANSPARENT',
        ];

        $text = trim((string) ($aufgabe['note'] ?? ''));

        if ($text !== '') {
            $zeilen[] = 'DESCRIPTION:' . self::escape($text);
        }

        // Für Offenes am Morgen des Tages erinnern, für Erledigtes nicht.
        if (!$erledigt) {
            $zeilen[] = 'BEGIN:VALARM';
            $zeilen[] = 'ACTION:DISPLAY';
            $zeilen[] = 'DESCRIPTION:' . self::escape((string) $aufgabe['title']);
            $zeilen[] = 'TRIGGER;RELATED=START:PT8H';
            $zeilen[] = 'END:VALARM';
        }

        $zeilen[] = 'END:VEVENT';

        return $zeilen;
    }

    // ------------------------------------------------------------------
    // Kleinkram nach Norm
    // ------------------------------------------------------------------

    private static function moment(string $wert, DateTimeZone $zone): ?DateTimeImmutable
    {
        $wert = trim($wert);

        if ($wert === '') {
            return null;
        }

        // Aus dem Formular kommt '2026-09-01T14:30', aus der Datenbank
        // '2026-09-01 14:30:00'. Beides muss durchgehen.
        $wert = str_replace('T', ' ', $wert);

        try {
            return new DateTimeImmutable($wert, $zone);
        } catch (\Exception) {
            return null;
        }
    }

    /** Zeitangaben in UTC – dann braucht es keinen VTIMEZONE-Block. */
    private static function utc(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    private static function escape(string $text): string
    {
        return str_replace(
            ["\\", "\r\n", "\n", "\r", ';', ','],
            ['\\\\', '\\n', '\\n', '\\n', '\;', '\\,'],
            $text
        );
    }

    /**
     * Zeilen umbrechen, wie es die Norm verlangt: höchstens 75 Zeichen,
     * Fortsetzung mit einem Leerzeichen davor.
     *
     * Gezählt wird in Bytes, nicht in Buchstaben – aber ein Umbruch
     * mitten in einem Umlaut ergäbe Zeichensalat. Deshalb wird an
     * Zeichengrenzen getrennt.
     */
    private static function fold(string $zeile): string
    {
        if (strlen($zeile) <= 75) {
            return $zeile;
        }

        $teile = [];
        $rest = $zeile;
        $grenze = 74;

        while (strlen($rest) > $grenze) {
            $stueck = mb_strcut($rest, 0, $grenze, 'UTF-8');
            $teile[] = $stueck;
            $rest = substr($rest, strlen($stueck));
            $grenze = 73; // Fortsetzungszeilen haben ein Leerzeichen vorn.
        }

        if ($rest !== '') {
            $teile[] = $rest;
        }

        return implode("\r\n ", $teile);
    }
}
