<?php
/**
 * Gemeinsame Bausteine fuer die Grafikerzeugung (SVG).
 */
declare(strict_types=1);

/* ---------------------------------------------------------------
 |  Bausteine
 --------------------------------------------------------------- */

function svg(int $width, int $height, string $body, string $extraDefs = ''): string
{
    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$width} {$height}" width="{$width}" height="{$height}" role="img">
<defs>
<filter id="grain"><feTurbulence type="fractalNoise" baseFrequency="0.9" numOctaves="3" stitchTiles="stitch"/><feColorMatrix type="saturate" values="0"/></filter>
<radialGradient id="vig" cx="50%" cy="45%" r="75%"><stop offset="55%" stop-color="#000" stop-opacity="0"/><stop offset="100%" stop-color="#000" stop-opacity=".78"/></radialGradient>
{$extraDefs}
</defs>
{$body}
<rect width="{$width}" height="{$height}" fill="url(#vig)"/>
<rect width="{$width}" height="{$height}" filter="url(#grain)" opacity=".07"/>
</svg>
SVG;
}

function gradient(string $id, string $from, string $to, string $direction = 'v'): string
{
    $coords = $direction === 'v' ? 'x1="0" y1="0" x2="0" y2="1"' : 'x1="0" y1="0" x2="1" y2="0";';
    return "<linearGradient id=\"{$id}\" {$coords}><stop offset=\"0\" stop-color=\"{$from}\"/><stop offset=\"1\" stop-color=\"{$to}\"/></linearGradient>";
}

function text(float $x, float $y, string|int $value, float $size = 14, string $color = '#d7dbe2', string $anchor = 'start', string $family = 'monospace', float $spacing = 0, string $weight = 'normal'): string
{
    $safe = htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    return sprintf(
        '<text x="%s" y="%s" font-family="%s" font-size="%s" fill="%s" text-anchor="%s" letter-spacing="%s" font-weight="%s">%s</text>',
        $x, $y, $family, $size, $color, $anchor, $spacing, $weight, $safe
    );
}

function scanlines(int $width, int $height, float $opacity = 0.12, int $step = 3): string
{
    $out = '<g opacity="' . $opacity . '">';
    for ($y = 0; $y < $height; $y += $step) {
        $out .= '<rect x="0" y="' . $y . '" width="' . $width . '" height="1" fill="#ffffff" opacity=".35"/>';
    }
    return $out . '</g>';
}

function write(string $path, string $contents): void
{
    file_put_contents($path, $contents);
    echo 'erzeugt: ' . str_replace(dirname(__DIR__) . '/', '', $path) . ' (' . strlen($contents) . " Bytes)\n";
}

