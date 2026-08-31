<?php

/**
 * Die Rechnerei der Terminbuchung: Welche Zeiten sind frei?
 *
 * Getrennt von buchen.php, damit sie sich prüfen lässt, ohne dass beim
 * Einbinden gleich eine ganze Seite ausgeliefert wird. Hier steht keine
 * Ausgabe und kein Zugriff auf $_POST – nur Rechnen.
 */

declare(strict_types=1);

/**
 * Die freien Zeiten eines Tages.
 *
 * Der Tag wird in Takte zerlegt. Ein Takt fällt weg, wenn er zu nah
 * liegt, wenn er über das Ende der Öffnungszeit hinausreicht, oder wenn
 * er sich mit einer bestehenden Buchung überschneidet.
 *
 * Wichtig ist der Überschneidungstest: Eine Buchung von 14:00 bis 14:45
 * blockiert nicht nur 14:00, sondern jeden Takt, dessen eigene Dauer
 * hineinragt – bei 45 Minuten also auch 13:30 und 13:45. Wer nur den
 * Anfangszeitpunkt vergleicht, baut sich Doppelbelegungen.
 *
 * @return array<int, string>
 */
function freieZeitenAus(
    array $bookings,
    string $day,
    int $duration,
    int $slotMinutes,
    int $leadHours,
    array $hours,
    array $closed,
    ?int $now = null
): array {
    if ($day === '' || istGeschlossen($day, $hours, $closed)) {
        return [];
    }

    $windows = fenster($day, $hours);

    if ($windows === []) {
        return [];
    }

    // Belegte Zeiträume dieses Tages, in Minuten seit Mitternacht.
    $busy = [];

    foreach ($bookings as $booking) {
        if ((string) ($booking['tag'] ?? '') !== $day
            || (string) ($booking['zustand'] ?? 'offen') === 'abgesagt') {
            continue;
        }

        $start = minuten((string) ($booking['zeit'] ?? '00:00'));
        $busy[] = [$start, $start + max(5, (int) ($booking['dauer'] ?? $slotMinutes))];
    }

    $earliest = ($now ?? time()) + $leadHours * 3600;
    $free = [];

    foreach ($windows as [$from, $till]) {
        for ($start = $from; $start + $duration <= $till; $start += $slotMinutes) {
            $clock = sprintf('%02d:%02d', intdiv($start, 60), $start % 60);
            $stamp = strtotime($day . ' ' . $clock);

            if ($stamp === false || $stamp < $earliest) {
                continue;
            }

            $collides = false;

            foreach ($busy as [$busyFrom, $busyTill]) {
                if ($start < $busyTill && $start + $duration > $busyFrom) {
                    $collides = true;
                    break;
                }
            }

            if (!$collides) {
                $free[] = $clock;
            }
        }
    }

    return $free;
}

/**
 * Die Öffnungsfenster eines Tages, in Minuten seit Mitternacht.
 *
 * @return array<int, array{0:int, 1:int}>
 */
function fenster(string $day, array $hours): array
{
    $stamp = strtotime($day);

    if ($stamp === false) {
        return [];
    }

    $weekday = (int) date('N', $stamp);
    $ranges = (array) ($hours[$weekday] ?? $hours[(string) $weekday] ?? []);

    $out = [];

    foreach ($ranges as $range) {
        $parts = explode('-', (string) $range);

        if (count($parts) !== 2) {
            continue;
        }

        $from = minuten(trim($parts[0]));
        $till = minuten(trim($parts[1]));

        if ($till > $from) {
            $out[] = [$from, $till];
        }
    }

    return $out;
}

function istGeschlossen(string $day, array $hours, array $closed): bool
{
    if (in_array($day, array_map('strval', $closed), true)) {
        return true;
    }

    return fenster($day, $hours) === [];
}

/**
 * Die nächsten Tage, an denen überhaupt offen ist.
 *
 * @return array<int, string>
 */
function naechsteTage(int $leadHours, int $horizonDays, array $hours, array $closed, ?int $now = null): array
{
    $now ??= time();
    $out = [];
    $start = (int) floor($leadHours / 24);

    for ($i = $start; $i <= $horizonDays && count($out) < 45; $i++) {
        $day = date('Y-m-d', $now + $i * 86400);

        if (!istGeschlossen($day, $hours, $closed)) {
            $out[] = $day;
        }
    }

    return $out;
}

/** Ein Datum, das im erlaubten Bereich liegt – sonst ''. */
function erlaubterTag(string $value, int $leadHours, int $horizonDays, ?int $now = null): string
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return '';
    }

    $now ??= time();

    $stamp = strtotime($value);
    $earliest = strtotime(date('Y-m-d', $now + $leadHours * 3600));
    $latest = strtotime(date('Y-m-d', $now + $horizonDays * 86400));

    return $stamp !== false && $stamp >= $earliest && $stamp <= $latest ? $value : '';
}

/** "13:45" -> 825 */
function minuten(string $time): int
{
    $parts = explode(':', $time);

    return (int) ($parts[0] ?? 0) * 60 + (int) ($parts[1] ?? 0);
}

/** "2026-09-01" -> "Dienstag, 01.09.2026" */
function datum(string $day): string
{
    static $namen = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];

    $stamp = strtotime($day);

    if ($stamp === false) {
        return $day;
    }

    return $namen[(int) date('N', $stamp) - 1] . ', ' . date('d.m.Y', $stamp);
}
