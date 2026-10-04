<?php
// Erzeugt die PNG-App-Icons (Homescreen iPhone/Android) aus derselben Form wie icon.svg.
foreach ([180, 512] as $size) {
    $s = 4; $W = $size * $s;
    $im = imagecreatetruecolor($W, $W);
    imagesavealpha($im, true);
    imagealphablending($im, true);
    $bg = imagecolorallocate($im, 0x0F, 0x1F, 0x1A);
    $mint = imagecolorallocate($im, 0x5E, 0xE6, 0xB0);
    imagefill($im, 0, 0, $bg);
    $k = $W / 512;
    $cx = 256 * $k; $cy = 256 * $k; $r = 110 * $k; $t = 44 * $k;
    // Bogen (C) – von 40° bis 320° gegen den Uhrzeigersinn: als dicke Kreisfläche minus Innenkreis
    for ($a = 38; $a <= 322; $a += 0.25) {
        $rad = deg2rad($a);
        $x = $cx + cos($rad) * $r * -1; $y = $cy + sin($rad) * $r;
        imagefilledellipse($im, (int)($cx - cos(deg2rad($a)) * -$r), (int)$y, (int)$t, (int)$t, $mint);
    }
    imagefilledellipse($im, (int)(330 * $k), (int)(256 * $k), (int)(52 * $k), (int)(52 * $k), $mint);
    $out = imagecreatetruecolor($size, $size);
    imagecopyresampled($out, $im, 0, 0, 0, 0, $size, $size, $W, $W);
    imagepng($out, __DIR__ . "/../src/assets/icon-$size.png", 9);
}
echo "ok\n";
