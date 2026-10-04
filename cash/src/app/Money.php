<?php
declare(strict_types=1);

/**
 * Geldbeträge werden intern immer als ganze Rappen (int) geführt.
 * Wechselkurse als ganze Millionstel ("micro"), z. B. 38.5 THB/CHF = 38500000.
 */
final class Money
{
    public const MAX = 100_000_000_000; // CHF 1 Mrd. in Rappen

    /** Wandelt eine Eingabe wie "1'234.50", "1234,5" oder "12" in Rappen um. */
    public static function parse(mixed $value, bool $allowZero = false, bool $allowNegative = false): int
    {
        if (is_int($value)) {
            $value = (string)$value;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Bitte einen gültigen Betrag eingeben.');
        }
        $s = str_replace(["\u{2019}", "'", ' ', "\u{00A0}", "\u{202F}"], '', trim($value));
        $s = str_replace(',', '.', $s);
        if (str_starts_with($s, 'CHF')) {
            $s = trim(substr($s, 3));
        }
        if (!preg_match('/^(-)?(\d{1,10})(?:\.(\d{0,2}))?$/', $s, $m)) {
            throw new InvalidArgumentException('Bitte einen gültigen Betrag eingeben (z. B. 12.50).');
        }
        $neg = $m[1] === '-';
        $frac = str_pad($m[3] ?? '', 2, '0');
        $cents = (int)$m[2] * 100 + (int)$frac;
        if ($neg) {
            $cents = -$cents;
        }
        if ($cents === 0 && !$allowZero) {
            throw new InvalidArgumentException('Der Betrag muss grösser als 0 sein.');
        }
        if ($cents < 0 && !$allowNegative) {
            throw new InvalidArgumentException('Der Betrag darf nicht negativ sein.');
        }
        if (abs($cents) > self::MAX) {
            throw new InvalidArgumentException('Der Betrag ist zu gross.');
        }
        return $cents;
    }

    /** Kurs "THB pro CHF" → [Mikro-Ganzzahl, normalisierte Zeichenkette]. */
    public static function parseRate(mixed $value): array
    {
        if (is_int($value) || is_float($value)) {
            $value = (string)$value;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Bitte einen gültigen Wechselkurs eingeben.');
        }
        $s = str_replace(["\u{2019}", "'", ' '], '', trim($value));
        $s = str_replace(',', '.', $s);
        if (!preg_match('/^(\d{1,6})(?:\.(\d{0,6}))?$/', $s, $m)) {
            throw new InvalidArgumentException('Bitte einen gültigen Wechselkurs eingeben (z. B. 38.25).');
        }
        $frac = str_pad($m[2] ?? '', 6, '0');
        $micro = (int)$m[1] * 1_000_000 + (int)$frac;
        if ($micro <= 0) {
            throw new InvalidArgumentException('Der Wechselkurs muss grösser als 0 sein.');
        }
        return [$micro, self::rateToString($micro)];
    }

    public static function rateToString(int $micro): string
    {
        $int = intdiv($micro, 1_000_000);
        $frac = rtrim(str_pad((string)($micro % 1_000_000), 6, '0', STR_PAD_LEFT), '0');
        return $frac === '' ? (string)$int : $int . '.' . $frac;
    }

    /**
     * Fremdwährungs-Kleinsteinheiten (z. B. Satang) mit Kurs "Fremd pro CHF" in Rappen.
     * Kaufmännische Rundung (half up) auf ganze Rappen, rein ganzzahlig.
     */
    public static function fxToChf(int $minor, int $rateMicro): int
    {
        if ($minor <= 0 || $rateMicro <= 0) {
            throw new InvalidArgumentException('Ungültige Umrechnung.');
        }
        // rappen = minor / rate = minor * 1e6 / rateMicro
        $num = $minor * 1_000_000;
        return intdiv(2 * $num + $rateMicro, 2 * $rateMicro);
    }

    public static function format(int $cents): string
    {
        $neg = $cents < 0;
        $abs = abs($cents);
        $int = number_format(intdiv($abs, 100), 0, '.', "\u{2019}");
        return ($neg ? '-' : '') . $int . '.' . str_pad((string)($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Für CSV: ohne Tausendertrennzeichen, Punkt als Dezimalzeichen. */
    public static function plain(int $cents): string
    {
        $neg = $cents < 0;
        $abs = abs($cents);
        return ($neg ? '-' : '') . intdiv($abs, 100) . '.' . str_pad((string)($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
