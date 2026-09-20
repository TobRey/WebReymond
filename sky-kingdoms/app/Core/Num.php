<?php

declare(strict_types=1);

namespace SkyKingdoms\Core;

/**
 * Zahlenformatierung – identisch zur JavaScript-Fassung in assets/js/core/num.js.
 * Deutsche Schreibweise: Komma als Dezimaltrennzeichen, Punkt als Tausendertrenner.
 */
final class Num
{
    /** Kurzformen für sehr grosse Zahlen. */
    private const UNITS = ['', 'K', 'M', 'B', 'T', 'Qa', 'Qi', 'Sx', 'Sp', 'Oc', 'No', 'Dc'];

    /** Kompakte Anzeige: 942 – 12,4K – 3,2M – 1,1B */
    public static function compact(float|int $value, int $decimals = 1): string
    {
        $negative = $value < 0;
        $value    = abs((float) $value);

        if ($value < 1000) {
            $rounded = $value >= 100 || $value === floor($value)
                ? number_format($value, 0, ',', '.')
                : number_format($value, 1, ',', '.');

            return ($negative ? '-' : '') . $rounded;
        }

        $index = (int) floor(log($value, 1000));
        $index = max(1, min($index, count(self::UNITS) - 1));
        $scaled = $value / (1000 ** $index);

        if ($index >= count(self::UNITS) - 1 && $scaled >= 1000) {
            // Jenseits der benannten Stufen: wissenschaftliche Schreibweise.
            return ($negative ? '-' : '') . str_replace('.', ',', sprintf('%.2E', $value));
        }

        $dec = $scaled >= 100 ? 0 : ($scaled >= 10 ? min($decimals, 1) : $decimals);

        return ($negative ? '-' : '') . number_format($scaled, $dec, ',', '.') . self::UNITS[$index];
    }

    /** Vollständige Zahl mit Tausenderpunkten: 1.234.567 */
    public static function full(float|int $value, int $decimals = 0): string
    {
        return number_format((float) $value, $decimals, ',', '.');
    }

    /** Prozentwert: 1,5 % */
    public static function percent(float $ratio, int $decimals = 1): string
    {
        return number_format($ratio * 100, $decimals, ',', '.') . ' %';
    }

    /** Zeitspanne in verständlichem Deutsch: "3 Std. 12 Min." */
    public static function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return $seconds . ' Sek.';
        }
        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return $minutes . ' Min.';
        }
        $hours = intdiv($minutes, 60);
        $rest  = $minutes % 60;
        if ($hours < 24) {
            return $rest > 0 ? $hours . ' Std. ' . $rest . ' Min.' : $hours . ' Std.';
        }
        $days  = intdiv($hours, 24);
        $hrest = $hours % 24;

        return $hrest > 0 ? $days . ' Tage ' . $hrest . ' Std.' : $days . ' Tage';
    }

    /**
     * Auf einen sicheren Ganzzahlbereich begrenzen. Alle Rohstoffmengen laufen
     * hierüber, damit niemals kaputte oder negative Werte gespeichert werden.
     */
    public static function clampAmount(float $value): int
    {
        if (is_nan($value) || $value < 0) {
            return 0;
        }
        $max = (float) App::balance('formulas.max_amount', 9.0e17);
        if (!is_finite($value)) {
            return (int) $max;
        }

        return (int) min($value, $max);
    }

    /** Auf eine angenehme Zahl runden (Anzeigezwecke). */
    public static function pretty(float $value): float
    {
        if ($value >= 100) {
            return round($value);
        }
        if ($value >= 10) {
            return round($value, 1);
        }

        return round($value, 2);
    }
}
