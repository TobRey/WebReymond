<?php

declare(strict_types=1);

namespace WebAtze\Domain;

use WebAtze\Core\Db;

/**
 * Der Monatskalender.
 *
 * Bewusst ohne Bibliothek. Ein Monat ist ein Raster aus Wochen, und
 * jede Woche hat sieben Tage – das ist nichts, wofür man etwas
 * nachladen müsste, und es funktioniert damit auch auf einem Hosting,
 * das nichts nachladen darf.
 *
 * Die Woche beginnt am Montag. In der Schweiz beginnt sie am Montag.
 */
final class Calendar
{
    public const DAYS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

    public const MONTHS = [
        1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
        'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
    ];

    /**
     * Ein Monat als Wochen, jede Woche als sieben Tage.
     *
     * Die Tage aus dem Vor- und Folgemonat sind dabei – sonst hätte das
     * Raster Löcher. Sie sind als "fremd" markiert.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function month(string $monat): array
    {
        $erster = strtotime($monat . '-01') ?: time();
        $tageImMonat = (int) date('t', $erster);

        // Wochentag des Ersten, Montag = 0.
        $versatz = ((int) date('N', $erster)) - 1;

        $start = strtotime('-' . $versatz . ' days', $erster) ?: $erster;
        $felder = (int) (ceil(($versatz + $tageImMonat) / 7) * 7);

        $von = date('Y-m-d', $start);
        $bis = date('Y-m-d', strtotime('+' . ($felder - 1) . ' days', $start) ?: $start);

        $termine = self::between($von, $bis);
        $aufgaben = self::todosBetween($von, $bis);

        $heute = date('Y-m-d');
        $wochen = [];
        $woche = [];

        for ($i = 0; $i < $felder; $i++) {
            $zeit = strtotime('+' . $i . ' days', $start) ?: $start;
            $tag = date('Y-m-d', $zeit);

            $woche[] = [
                'datum' => $tag,
                'nummer' => (int) date('j', $zeit),
                'fremd' => date('Y-m', $zeit) !== $monat,
                'heute' => $tag === $heute,
                'wochenende' => (int) date('N', $zeit) >= 6,
                'termine' => $termine[$tag] ?? [],
                'aufgaben' => $aufgaben[$tag] ?? [],
            ];

            if (count($woche) === 7) {
                $wochen[] = $woche;
                $woche = [];
            }
        }

        return $wochen;
    }

    /**
     * Termine eines Zeitraums, nach Tag sortiert.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function between(string $von, string $bis): array
    {
        $zeilen = Db::all(
            'SELECT a.*, c.name AS kunde, e.name AS mitarbeiter
             FROM appointments a
             LEFT JOIN customers c ON c.id = a.customer_id
             LEFT JOIN employees e ON e.id = a.employee_id
             WHERE a.starts_at >= :v AND a.starts_at <= :b
             ORDER BY a.starts_at ASC',
            ['v' => $von . ' 00:00', 'b' => $bis . ' 23:59']
        );

        $nachTag = [];

        foreach ($zeilen as $zeile) {
            $nachTag[substr((string) $zeile['starts_at'], 0, 10)][] = $zeile;
        }

        return $nachTag;
    }

    /**
     * Offene Aufgaben mit Frist in diesem Zeitraum.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function todosBetween(string $von, string $bis): array
    {
        $zeilen = Db::all(
            'SELECT t.*, c.name AS kunde FROM todos t
             LEFT JOIN customers c ON c.id = t.customer_id
             WHERE t.due_on >= :v AND t.due_on <= :b AND t.done_at IS NULL
             ORDER BY t.due_on ASC',
            ['v' => $von, 'b' => $bis]
        );

        $nachTag = [];

        foreach ($zeilen as $zeile) {
            $nachTag[(string) $zeile['due_on']][] = $zeile;
        }

        return $nachTag;
    }

    /**
     * Was als Nächstes ansteht.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function upcoming(int $wieViele = 20): array
    {
        return Db::all(
            'SELECT a.*, c.name AS kunde, e.name AS mitarbeiter
             FROM appointments a
             LEFT JOIN customers c ON c.id = a.customer_id
             LEFT JOIN employees e ON e.id = a.employee_id
             WHERE a.starts_at >= :jetzt
             ORDER BY a.starts_at ASC LIMIT ' . max(1, min(100, $wieViele)),
            ['jetzt' => date('Y-m-d 00:00')]
        );
    }

    /** Der Monat davor, als "2026-08". */
    public static function previous(string $monat): string
    {
        return date('Y-m', strtotime($monat . '-01 -1 month') ?: time());
    }

    /** Der Monat danach. */
    public static function next(string $monat): string
    {
        return date('Y-m', strtotime($monat . '-01 +1 month') ?: time());
    }

    /** "2026-09" wird zu "September 2026". */
    public static function title(string $monat): string
    {
        $zeit = strtotime($monat . '-01') ?: time();

        return (self::MONTHS[(int) date('n', $zeit)] ?? '') . ' ' . date('Y', $zeit);
    }
}
