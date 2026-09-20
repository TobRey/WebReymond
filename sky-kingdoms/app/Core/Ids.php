<?php

declare(strict_types=1);

namespace SkyKingdoms\Core;

/** Erzeugt zufällige, kollisionsarme Kennungen. */
final class Ids
{
    /** 12 Zeichen aus dem Hex-Alphabet – als Spieler-, Allianz- oder Objektkennung. */
    public static function generate(int $bytes = 6): string
    {
        return bin2hex(random_bytes(max(3, $bytes)));
    }

    /** Ordner-Aufteilung, damit kein Verzeichnis tausende Einträge bekommt. */
    public static function shard(string $id): string
    {
        return substr($id, 0, 2);
    }

    /** Kennung auf Gültigkeit prüfen (verhindert Pfadmanipulation). */
    public static function isValid(string $id, int $maxLength = 32): bool
    {
        return $id !== '' && strlen($id) <= $maxLength && (bool) preg_match('/^[a-f0-9]+$/', $id);
    }

    /** Kurze, gut lesbare Kennung für Berichte und Handel (z. B. "K7F2QD"). */
    public static function readable(int $length = 6): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out      = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }
}
