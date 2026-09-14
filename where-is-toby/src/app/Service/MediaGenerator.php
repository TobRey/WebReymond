<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\HttpException;
use App\Core\Logger;

/**
 * Erzeugt aus einem hochgeladenen Portrait automatisch alle Fall-Grafiken
 * im einheitlichen FBI-Stil: Vermisstenplakat, Aktenkarte, Profilbild,
 * Fall-Cover, Kontaktbild und Schwarz-Weiss-Dokumentversion.
 *
 * Nutzt PHP-GD (falls vorhanden Imagick als Rueckfall fuer das Einlesen).
 */
final class MediaGenerator
{
    private string $fontRegular;
    private string $fontBold;
    private string $fontMono;

    public function __construct(private string $uploadRoot)
    {
        $this->fontRegular = WIT_PUBLIC_ASSETS . '/fonts/DejaVuSans.ttf';
        $this->fontBold = WIT_PUBLIC_ASSETS . '/fonts/DejaVuSans-Bold.ttf';
        $this->fontMono = WIT_PUBLIC_ASSETS . '/fonts/DejaVuSansMono.ttf';
    }

    public function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    public function hasFonts(): bool
    {
        return is_file($this->fontRegular) && is_file($this->fontBold) && function_exists('imagettftext');
    }

    /**
     * @param array<string,string> $data name, age, last_seen, case_code, location, contact, height, clothing
     * @return array<string,string> Liste der erzeugten Dateien (Variante => Dateiname)
     */
    public function generateSet(string $sourceFile, array $data, string $prefix = ''): array
    {
        if (!$this->available()) {
            throw HttpException::badRequest('Fuer die Bildgenerierung wird die PHP-Erweiterung GD benoetigt.');
        }
        $sourcePath = $this->uploadRoot . '/media/' . basename($sourceFile);
        if (!is_file($sourcePath)) {
            throw HttpException::notFound('Quellbild nicht gefunden.');
        }
        $portrait = $this->load($sourcePath);
        if ($portrait === null) {
            throw HttpException::badRequest('Das Bild konnte nicht gelesen werden (nur JPG, PNG, GIF, WEBP).');
        }

        $prefix = $prefix !== '' ? preg_replace('~[^a-zA-Z0-9_\-]~', '', $prefix) : 'gen_' . bin2hex(random_bytes(4));
        $outDir = $this->uploadRoot . '/media';
        if (!is_dir($outDir)) {
            @mkdir($outDir, 0750, true);
        }

        $files = [];
        $files['poster'] = $this->save($this->buildPoster($portrait, $data), $outDir . '/' . $prefix . '_poster.jpg');
        $files['case_card'] = $this->save($this->buildCaseCard($portrait, $data), $outDir . '/' . $prefix . '_card.jpg');
        $files['profile'] = $this->save($this->buildProfile($portrait, 480), $outDir . '/' . $prefix . '_profile.jpg');
        $files['contact'] = $this->save($this->buildProfile($portrait, 160), $outDir . '/' . $prefix . '_contact.jpg');
        $files['cover'] = $this->save($this->buildCover($portrait, $data), $outDir . '/' . $prefix . '_cover.jpg');
        $files['document'] = $this->save($this->buildDocument($portrait, $data), $outDir . '/' . $prefix . '_doc.jpg');

        imagedestroy($portrait);
        Logger::info('Fallgrafiken erzeugt', ['prefix' => $prefix, 'count' => count($files)]);
        return $files;
    }

    /* ======================= Varianten ======================= */

    private function buildPoster(\GdImage $portrait, array $data): \GdImage
    {
        $width = 820;
        $height = 1140;
        $canvas = imagecreatetruecolor($width, $height);
        $this->paperBackground($canvas, $width, $height, [17, 19, 23], [9, 10, 12]);

        $red = imagecolorallocate($canvas, 150, 32, 36);
        $white = imagecolorallocate($canvas, 232, 231, 226);
        $grey = imagecolorallocate($canvas, 150, 155, 163);
        $line = imagecolorallocate($canvas, 58, 63, 71);

        imagefilledrectangle($canvas, 0, 0, $width, 18, $red);
        $this->text($canvas, 'FEDERAL BUREAU OF INVESTIGATION', 15, 44, 70, $grey, $this->fontMono, 6);
        $this->text($canvas, 'MISSING', 74, 40, 160, $white, $this->fontBold, 10);
        $this->text($canvas, 'HAVE YOU SEEN THIS PERSON?', 17, 44, 196, $red, $this->fontBold, 4);

        // Portrait mit Rahmen
        $frameX = 60;
        $frameY = 230;
        $frameW = $width - 120;
        $frameH = 560;
        imagefilledrectangle($canvas, $frameX - 6, $frameY - 6, $frameX + $frameW + 6, $frameY + $frameH + 6, $line);
        $this->drawCover($canvas, $portrait, $frameX, $frameY, $frameW, $frameH);
        $this->scanlines($canvas, $frameX, $frameY, $frameW, $frameH, 0.05);

        $name = mb_strtoupper((string)($data['name'] ?? 'UNBEKANNT'));
        $this->text($canvas, $name, 40, 50, $frameY + $frameH + 78, $white, $this->fontBold, 6);

        $rows = [
            'ALTER'        => (string)($data['age'] ?? '--'),
            'ZULETZT GESEHEN' => (string)($data['last_seen'] ?? '--'),
            'ORT'          => (string)($data['location'] ?? '--'),
            'GROESSE'      => (string)($data['height'] ?? '--'),
            'KLEIDUNG'     => (string)($data['clothing'] ?? '--'),
        ];
        $y = $frameY + $frameH + 130;
        foreach ($rows as $label => $value) {
            $this->text($canvas, $label, 13, 52, $y, $grey, $this->fontMono, 3);
            $this->text($canvas, mb_substr($value, 0, 46), 16, 250, $y, $white, $this->fontRegular, 0);
            imageline($canvas, 50, $y + 14, $width - 50, $y + 14, $line);
            $y += 44;
        }

        imagefilledrectangle($canvas, 0, $height - 92, $width, $height, $red);
        $this->text($canvas, 'HINWEISE: ' . (string)($data['contact'] ?? '1-800-CALL-FBI'), 20, 50, $height - 52, $white, $this->fontBold, 3);
        $this->text($canvas, 'FALL ' . (string)($data['case_code'] ?? '--'), 13, 50, $height - 24, imagecolorallocate($canvas, 240, 200, 200), $this->fontMono, 3);
        $this->grain($canvas, $width, $height, 5200);
        return $canvas;
    }

    private function buildCaseCard(\GdImage $portrait, array $data): \GdImage
    {
        $width = 980;
        $height = 600;
        $canvas = imagecreatetruecolor($width, $height);
        $this->paperBackground($canvas, $width, $height, [22, 25, 30], [13, 15, 18]);

        $white = imagecolorallocate($canvas, 230, 232, 236);
        $grey = imagecolorallocate($canvas, 140, 148, 158);
        $blue = imagecolorallocate($canvas, 84, 124, 160);
        $line = imagecolorallocate($canvas, 52, 58, 66);
        $red = imagecolorallocate($canvas, 150, 32, 36);

        imagefilledrectangle($canvas, 0, 0, $width, 64, imagecolorallocate($canvas, 16, 18, 22));
        imageline($canvas, 0, 64, $width, 64, $blue);
        $this->text($canvas, 'FBI // FIELD CASE FILE', 20, 34, 42, $white, $this->fontBold, 5);
        $this->text($canvas, (string)($data['case_code'] ?? ''), 15, $width - 260, 42, $red, $this->fontMono, 4);

        $this->drawCover($canvas, $portrait, 34, 96, 300, 380);
        imagerectangle($canvas, 34, 96, 334, 476, $line);
        $this->text($canvas, 'SUBJECT PHOTO', 11, 34, 500, $grey, $this->fontMono, 3);

        $rows = [
            'NAME'      => (string)($data['name'] ?? ''),
            'ALTER'     => (string)($data['age'] ?? ''),
            'STATUS'    => (string)($data['status'] ?? 'VERMISST'),
            'ZULETZT'   => (string)($data['last_seen'] ?? ''),
            'ORT'       => (string)($data['location'] ?? ''),
            'GROESSE'   => (string)($data['height'] ?? ''),
            'KLEIDUNG'  => (string)($data['clothing'] ?? ''),
            'ERMITTLER' => (string)($data['agent'] ?? 'SPECIAL AGENT'),
        ];
        $y = 130;
        foreach ($rows as $label => $value) {
            $this->text($canvas, $label, 12, 380, $y, $grey, $this->fontMono, 3);
            $this->text($canvas, mb_substr($value, 0, 52), 17, 530, $y, $white, $this->fontRegular, 0);
            imageline($canvas, 378, $y + 12, $width - 40, $y + 12, $line);
            $y += 44;
        }
        $this->text($canvas, 'VERTRAULICH - NUR FUER DIENSTGEBRAUCH', 12, 380, $height - 40, $red, $this->fontMono, 3);
        $this->grain($canvas, $width, $height, 3600);
        return $canvas;
    }

    private function buildProfile(\GdImage $portrait, int $size): \GdImage
    {
        $canvas = imagecreatetruecolor($size, $size);
        $this->paperBackground($canvas, $size, $size, [26, 29, 34], [15, 17, 20]);
        $this->drawCover($canvas, $portrait, 0, 0, $size, $size);
        $frame = imagecolorallocate($canvas, 70, 78, 88);
        imagerectangle($canvas, 0, 0, $size - 1, $size - 1, $frame);
        $this->grain($canvas, $size, $size, (int)($size * 2));
        return $canvas;
    }

    private function buildCover(\GdImage $portrait, array $data): \GdImage
    {
        $width = 1280;
        $height = 720;
        $canvas = imagecreatetruecolor($width, $height);
        $this->paperBackground($canvas, $width, $height, [15, 17, 21], [8, 9, 11]);
        $this->drawCover($canvas, $portrait, 0, 0, $width, $height);

        // Dunkle Vignette und Farbstich
        for ($y = 0; $y < $height; $y++) {
            $alpha = (int)round(110 - (60 * ($y / $height)));
            $shade = imagecolorallocatealpha($canvas, 6, 8, 12, max(20, min(120, 127 - $alpha)));
            imageline($canvas, 0, $y, $width, $y, $shade);
        }
        $white = imagecolorallocate($canvas, 236, 236, 232);
        $red = imagecolorallocate($canvas, 154, 34, 38);
        imagefilledrectangle($canvas, 0, $height - 210, $width, $height, imagecolorallocatealpha($canvas, 8, 9, 12, 35));
        imagefilledrectangle($canvas, 64, $height - 176, 70, $height - 60, $red);
        $this->text($canvas, mb_strtoupper((string)($data['title'] ?? 'WHERE IS TOBY?')), 44, 96, $height - 118, $white, $this->fontBold, 8);
        $this->text($canvas, mb_strtoupper((string)($data['subtitle'] ?? 'FBI FIELD INVESTIGATION')), 15, 98, $height - 78, imagecolorallocate($canvas, 150, 157, 166), $this->fontMono, 6);
        $this->grain($canvas, $width, $height, 9000);
        $this->scanlines($canvas, 0, 0, $width, $height, 0.035);
        return $canvas;
    }

    private function buildDocument(\GdImage $portrait, array $data): \GdImage
    {
        $width = 760;
        $height = 980;
        $canvas = imagecreatetruecolor($width, $height);
        $paper = imagecolorallocate($canvas, 222, 220, 213);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $paper);

        $ink = imagecolorallocate($canvas, 28, 28, 30);
        $soft = imagecolorallocate($canvas, 92, 92, 96);

        $this->text($canvas, 'FBI - VERMISSTENAKTE (KOPIE)', 18, 40, 62, $ink, $this->fontBold, 4);
        imageline($canvas, 40, 78, $width - 40, 78, $soft);

        $photo = $this->buildProfile($portrait, 320);
        imagefilter($photo, IMG_FILTER_GRAYSCALE);
        imagefilter($photo, IMG_FILTER_CONTRAST, -18);
        imagefilter($photo, IMG_FILTER_BRIGHTNESS, 18);
        imagecopyresampled($canvas, $photo, 40, 110, 0, 0, 300, 300, 320, 320);
        imagedestroy($photo);
        imagerectangle($canvas, 40, 110, 340, 410, $soft);

        $rows = [
            'NAME:'     => (string)($data['name'] ?? ''),
            'ALTER:'    => (string)($data['age'] ?? ''),
            'FALL:'     => (string)($data['case_code'] ?? ''),
            'ZULETZT:'  => (string)($data['last_seen'] ?? ''),
            'ORT:'      => (string)($data['location'] ?? ''),
        ];
        $y = 140;
        foreach ($rows as $label => $value) {
            $this->text($canvas, $label, 13, 370, $y, $soft, $this->fontMono, 3);
            $this->text($canvas, mb_substr($value, 0, 30), 15, 470, $y, $ink, $this->fontRegular, 0);
            $y += 40;
        }

        $this->text($canvas, 'AKTENVERMERK', 13, 40, 470, $soft, $this->fontMono, 3);
        $note = (string)($data['note'] ?? 'Ermittlungsakte. Alle Angaben vorlaeufig. Kopie fuer den Dienstgebrauch.');
        $this->wrapText($canvas, $note, 14, 40, 500, $width - 80, 24, $ink, $this->fontRegular);

        // Kopierer-Anmutung: Rauschen und leichte Schraeglage
        $this->grain($canvas, $width, $height, 7000, 235);
        imagefilter($canvas, IMG_FILTER_GRAYSCALE);
        imagefilter($canvas, IMG_FILTER_CONTRAST, -12);
        $rotated = imagerotate($canvas, 0.7, $paper);
        if ($rotated !== false) {
            imagedestroy($canvas);
            return $rotated;
        }
        return $canvas;
    }

    /* ======================= Werkzeuge ======================= */

    private function load(string $path): ?\GdImage
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            return null;
        }
        $image = @imagecreatefromstring($data);
        return $image === false ? null : $image;
    }

    private function save(\GdImage $image, string $path): string
    {
        imagejpeg($image, $path, 90);
        imagedestroy($image);
        @chmod($path, 0640);
        return basename($path);
    }

    /** Fuellt einen Bereich formatfuellend (wie CSS object-fit: cover). */
    private function drawCover(\GdImage $canvas, \GdImage $source, int $x, int $y, int $width, int $height): void
    {
        $sw = imagesx($source);
        $sh = imagesy($source);
        $scale = max($width / $sw, $height / $sh);
        $cropW = (int)round($width / $scale);
        $cropH = (int)round($height / $scale);
        $srcX = (int)round(($sw - $cropW) / 2);
        $srcY = (int)round(($sh - $cropH) / 2.6); // Portraits leicht nach oben ausrichten
        imagecopyresampled($canvas, $source, $x, $y, max(0, $srcX), max(0, $srcY), $width, $height, $cropW, $cropH);
    }

    private function paperBackground(\GdImage $canvas, int $width, int $height, array $top, array $bottom): void
    {
        for ($y = 0; $y < $height; $y++) {
            $ratio = $y / max(1, $height - 1);
            $color = imagecolorallocate(
                $canvas,
                (int)round($top[0] + ($bottom[0] - $top[0]) * $ratio),
                (int)round($top[1] + ($bottom[1] - $top[1]) * $ratio),
                (int)round($top[2] + ($bottom[2] - $top[2]) * $ratio)
            );
            imageline($canvas, 0, $y, $width, $y, $color);
        }
    }

    private function grain(\GdImage $canvas, int $width, int $height, int $amount, int $brightness = 255): void
    {
        for ($i = 0; $i < $amount; $i++) {
            $x = random_int(0, max(0, $width - 1));
            $y = random_int(0, max(0, $height - 1));
            $value = random_int(0, 40);
            $color = imagecolorallocatealpha($canvas, min(255, $brightness - $value), min(255, $brightness - $value), min(255, $brightness - $value), 105);
            imagesetpixel($canvas, $x, $y, $color);
        }
    }

    private function scanlines(\GdImage $canvas, int $x, int $y, int $width, int $height, float $strength): void
    {
        $alpha = (int)round(127 - (127 * $strength));
        $color = imagecolorallocatealpha($canvas, 0, 0, 0, max(90, $alpha));
        for ($line = $y; $line < $y + $height; $line += 3) {
            imageline($canvas, $x, $line, $x + $width, $line, $color);
        }
    }

    private function text(\GdImage $canvas, string $text, int $size, int $x, int $y, int $color, string $font, int $spacing = 0): void
    {
        if ($this->hasFonts() && is_file($font)) {
            if ($spacing > 0) {
                $cursor = $x;
                foreach (preg_split('~~u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
                    imagettftext($canvas, $size, 0, $cursor, $y, $color, $font, $char);
                    $box = imagettfbbox($size, 0, $font, $char);
                    $cursor += ($box[2] - $box[0]) + $spacing;
                }
                return;
            }
            imagettftext($canvas, $size, 0, $x, $y, $color, $font, $text);
            return;
        }
        // Rueckfall ohne TTF-Unterstuetzung
        imagestring($canvas, 5, $x, $y - 14, $this->asciiFallback($text), $color);
    }

    private function wrapText(\GdImage $canvas, string $text, int $size, int $x, int $y, int $maxWidth, int $lineHeight, int $color, string $font): void
    {
        $words = preg_split('~\s+~u', $text) ?: [];
        $line = '';
        foreach ($words as $word) {
            $test = $line === '' ? $word : $line . ' ' . $word;
            $boxWidth = $this->hasFonts()
                ? (($box = imagettfbbox($size, 0, $font, $test)) ? $box[2] - $box[0] : 0)
                : strlen($test) * 8;
            if ($boxWidth > $maxWidth && $line !== '') {
                $this->text($canvas, $line, $size, $x, $y, $color, $font);
                $y += $lineHeight;
                $line = $word;
            } else {
                $line = $test;
            }
        }
        if ($line !== '') {
            $this->text($canvas, $line, $size, $x, $y, $color, $font);
        }
    }

    private function asciiFallback(string $text): string
    {
        $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'AE', 'Ö' => 'OE', 'Ü' => 'UE']);
        return (string)preg_replace('~[^\x20-\x7E]~', '', $text);
    }
}
