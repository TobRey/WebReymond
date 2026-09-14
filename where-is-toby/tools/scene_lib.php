<?php
/**
 * Wiederverwendbare Bildbausteine (Silhouetten, Fahrzeuge, Overlays).
 */
declare(strict_types=1);

function timestampBar(int $width, int $height, string $left, string $right, string $color = '#d7dde5'): string
{
    return '<rect x="0" y="' . ($height - 34) . '" width="' . $width . '" height="34" fill="#05070a" opacity=".72"/>'
        . text(16, $height - 12, $left, 15, $color, 'start', 'monospace', 1)
        . text($width - 16, $height - 12, $right, 15, $color, 'end', 'monospace', 1);
}

/**
 * Kamera-Look fuer Einzelbilder.
 *
 * Nur Bildrauschen und Zeilen - Kamerabezeichnung, REC-Anzeige und Zeitstempel
 * zeichnet der Player im Browser live ueber das Bild, damit die Uhrzeit immer
 * zur Position auf der Zeitleiste passt.
 */
function camOverlay(int $width, int $height, string $cam, string $stamp, bool $rec = true): string
{
    return scanlines($width, $height, 0.09, 3);
}

function personSilhouette(float $x, float $y, float $scale = 1, string $fill = '#05070a'): string
{
    return '<g transform="translate(' . $x . ',' . $y . ') scale(' . $scale . ')" fill="' . $fill . '">'
        . '<circle cx="0" cy="-58" r="17"/>'
        . '<path d="M-22 -40 q22 -12 44 0 l8 58 l-14 4 l-6 44 h-20 l-6 -44 l-14 -4z"/>'
        . '</g>';
}

function van(float $x, float $y, float $scale, string $plate, string $body = '#c9ccd0'): string
{
    return '<g transform="translate(' . $x . ',' . $y . ') scale(' . $scale . ')">'
        . '<path d="M0 0 h150 l26 26 v46 h-176z" fill="' . $body . '" stroke="#6d7075"/>'
        . '<rect x="120" y="8" width="44" height="26" rx="3" fill="#2b3b47"/>'
        . '<rect x="10" y="12" width="96" height="30" fill="#b9bcc0" stroke="#8d9095"/>'
        . text(58, 32, 'MILLBROOK', 11, '#3d4a55', 'middle', 'monospace', 1)
        . text(58, 42, 'WATER WORKS', 7, '#55626e', 'middle', 'monospace', 1)
        . '<circle cx="36" cy="76" r="14" fill="#14181c"/><circle cx="140" cy="76" r="14" fill="#14181c"/>'
        . '<rect x="52" y="56" width="52" height="16" rx="2" fill="#e8e6de" stroke="#8d9095"/>'
        . text(78, 68, $plate, 11, '#1b1f24', 'middle', 'monospace', 1)
        . '</g>';
}
