<?php
/**
 * Erzeugt die PNG-Symbole für die PWA aus dem Logo-Motiv.
 * Aufruf:  php tools/make-icons.php
 *
 * Wird einmal beim Bauen der ZIP ausgeführt; auf dem Server ist es nicht nötig.
 */

declare(strict_types=1);

if (!extension_loaded('gd')) {
    exit("Die PHP-Erweiterung gd wird benötigt.\n");
}

$targets = [
    ['icons/icon-192.png', 192, false],
    ['icons/icon-512.png', 512, false],
    ['icons/icon-maskable.png', 512, true],
];

foreach ($targets as [$file, $size, $maskable]) {
    $image = draw($size, $maskable);
    $path  = dirname(__DIR__) . '/assets/' . $file;
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    imagepng($image, $path, 9);
    imagedestroy($image);
    echo 'erzeugt: assets/' . $file . ' (' . $size . 'px)' . PHP_EOL;
}

/** Zeichnet das Symbol: Himmel, Wolken, schwebende Insel, Burg. */
function draw(int $size, bool $maskable)
{
    $image = imagecreatetruecolor($size, $size);
    imagealphablending($image, true);
    imagesavealpha($image, true);

    $s = static fn (float $v): int => (int) round($v * $size / 512);
    // Bei maskable bleibt aussen Platz (safe zone), das Motiv wird kleiner.
    $inset = $maskable ? 0.62 : 0.78;
    $cx = $size / 2;
    $cy = $size * 0.56;

    // Himmelsverlauf
    for ($y = 0; $y < $size; $y++) {
        $t = $y / max(1, $size - 1);
        $color = imagecolorallocate(
            $image,
            (int) (94 + (185 - 94) * $t),
            (int) (198 + (233 - 198) * $t),
            (int) (255 + (255 - 255) * $t)
        );
        imageline($image, 0, $y, $size, $y, $color);
    }

    // Wolken
    $white = imagecolorallocatealpha($image, 255, 255, 255, 40);
    cloud($image, $cx - $s(150) * $inset, $cy - $s(150) * $inset, $s(52) * $inset, $white);
    cloud($image, $cx + $s(140) * $inset, $cy + $s(90) * $inset, $s(44) * $inset, $white);

    // Insel (Fels)
    $rock = imagecolorallocate($image, 111, 88, 66);
    $rockDark = imagecolorallocate($image, 74, 56, 42);
    $points = [
        $cx - $s(150) * $inset, $cy + $s(30) * $inset,
        $cx + $s(150) * $inset, $cy + $s(30) * $inset,
        $cx + $s(70) * $inset,  $cy + $s(130) * $inset,
        $cx,                    $cy + $s(200) * $inset,
        $cx - $s(80) * $inset,  $cy + $s(125) * $inset,
    ];
    imagefilledpolygon($image, array_map('intval', $points), $rock);
    imagefilledpolygon($image, array_map('intval', [
        $cx - $s(20) * $inset, $cy + $s(60) * $inset,
        $cx + $s(60) * $inset, $cy + $s(40) * $inset,
        $cx + $s(70) * $inset, $cy + $s(130) * $inset,
        $cx,                   $cy + $s(200) * $inset,
    ]), $rockDark);

    // Wiese
    $grass = imagecolorallocate($image, 91, 183, 95);
    imagefilledellipse($image, (int) $cx, (int) ($cy + $s(28) * $inset), (int) ($s(300) * $inset), (int) ($s(74) * $inset), $grass);

    // Burg
    $stone  = imagecolorallocate($image, 238, 229, 208);
    $shadow = imagecolorallocate($image, 205, 192, 165);
    $roof   = imagecolorallocate($image, 226, 88, 47);
    $door   = imagecolorallocate($image, 91, 68, 51);

    imagefilledrectangle($image,
        (int) ($cx - $s(84) * $inset), (int) ($cy - $s(110) * $inset),
        (int) ($cx - $s(20) * $inset), (int) ($cy + $s(20) * $inset), $stone);
    imagefilledrectangle($image,
        (int) ($cx + $s(14) * $inset), (int) ($cy - $s(150) * $inset),
        (int) ($cx + $s(82) * $inset), (int) ($cy + $s(20) * $inset), $stone);
    imagefilledrectangle($image,
        (int) ($cx - $s(22) * $inset), (int) ($cy - $s(60) * $inset),
        (int) ($cx + $s(16) * $inset), (int) ($cy + $s(20) * $inset), $shadow);

    imagefilledpolygon($image, array_map('intval', [
        $cx - $s(96) * $inset, $cy - $s(110) * $inset,
        $cx - $s(8) * $inset,  $cy - $s(110) * $inset,
        $cx - $s(52) * $inset, $cy - $s(172) * $inset,
    ]), $roof);
    imagefilledpolygon($image, array_map('intval', [
        $cx + $s(2) * $inset,  $cy - $s(150) * $inset,
        $cx + $s(94) * $inset, $cy - $s(150) * $inset,
        $cx + $s(48) * $inset, $cy - $s(216) * $inset,
    ]), $roof);

    imagefilledrectangle($image,
        (int) ($cx - $s(12) * $inset), (int) ($cy - $s(30) * $inset),
        (int) ($cx + $s(8) * $inset), (int) ($cy + $s(20) * $inset), $door);

    // Fahne
    $gold = imagecolorallocate($image, 255, 201, 74);
    $pole = imagecolorallocate($image, 107, 87, 68);
    imagefilledrectangle($image,
        (int) ($cx + $s(45) * $inset), (int) ($cy - $s(280) * $inset),
        (int) ($cx + $s(51) * $inset), (int) ($cy - $s(210) * $inset), $pole);
    imagefilledpolygon($image, array_map('intval', [
        $cx + $s(51) * $inset, $cy - $s(278) * $inset,
        $cx + $s(112) * $inset, $cy - $s(258) * $inset,
        $cx + $s(51) * $inset, $cy - $s(238) * $inset,
    ]), $gold);

    return $image;
}

function cloud($image, float $x, float $y, float $r, int $color): void
{
    imagefilledellipse($image, (int) $x, (int) $y, (int) ($r * 2.4), (int) ($r * 1.2), $color);
    imagefilledellipse($image, (int) ($x + $r * 0.7), (int) ($y + $r * 0.1), (int) ($r * 1.5), (int) ($r * 1.0), $color);
    imagefilledellipse($image, (int) ($x - $r * 0.7), (int) ($y + $r * 0.15), (int) ($r * 1.3), (int) ($r * 0.9), $color);
    imagefilledellipse($image, (int) ($x + $r * 0.1), (int) ($y - $r * 0.4), (int) ($r * 1.2), (int) ($r * 1.0), $color);
}
