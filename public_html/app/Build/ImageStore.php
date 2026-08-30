<?php

declare(strict_types=1);

namespace WebAtze\Build;

use WebAtze\Core\Logger;

/**
 * Bilder sicher entgegennehmen und ablegen.
 *
 * Ein hochgeladenes oder heruntergeladenes Bild wird nie so gespeichert,
 * wie es ankommt. Es wird geöffnet, geprüft, verkleinert und neu
 * geschrieben. Damit fällt alles weg, was nicht Bild ist – auch der
 * bekannte Trick, PHP-Code in den Zusatzdaten einer JPG-Datei zu
 * verstecken.
 *
 * Ausserdem bekommt jede Datei einen zufälligen Namen. Der ursprüngliche
 * Name landet nur als Angabe in der Datenbank, nie im Dateisystem.
 */
final class ImageStore
{
    private const MAX_WIDTH = 2000;
    private const MAX_HEIGHT = 2000;
    private const QUALITY = 82;

    /** Erlaubte Arten. SVG bewusst nur für Logos, siehe storeSvg(). */
    private const TYPES = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF => 'gif',
    ];

    /**
     * Bild aus einer hochgeladenen Datei übernehmen.
     *
     * @return array{name:string,width:int,height:int,bytes:int}|null
     */
    public static function storeUpload(array $file, string $targetDir): ?array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return null;
        }

        $tmp = (string) ($file['tmp_name'] ?? '');

        // Ohne diese Prüfung liesse sich jede beliebige Datei des Servers
        // als "Upload" ausgeben.
        if ($tmp === '' || (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp))) {
            return null;
        }

        $binary = @file_get_contents($tmp);
        if ($binary === false) {
            return null;
        }

        // SVG ist kein Rasterbild und geht einen eigenen, strengeren Weg.
        if (self::looksLikeSvg($binary)) {
            return self::storeSvg($binary, $targetDir);
        }

        return self::storeBinary($binary, $targetDir, '');
    }

    /**
     * Bild aus rohen Daten übernehmen.
     *
     * @return array{name:string,width:int,height:int,bytes:int}|null
     */
    public static function storeBinary(string $binary, string $targetDir, string $alt = ''): ?array
    {
        if (strlen($binary) < 64) {
            return null;
        }

        if (self::looksLikeSvg($binary)) {
            return self::storeSvg($binary, $targetDir);
        }

        $info = @getimagesizefromstring($binary);
        if ($info === false) {
            return null;
        }

        $type = (int) ($info[2] ?? 0);
        if (!isset(self::TYPES[$type])) {
            return null;
        }

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        // Sehr kleine Bilder sind meist Sinnbilder oder Zählpixel.
        if ($width < 80 || $height < 60) {
            imagedestroy($image);
            return null;
        }

        // Verkleinern, wenn zu gross. Nichts bremst eine Website so sehr
        // wie ein 5000 Pixel breites Foto aus der Kamera.
        if ($width > self::MAX_WIDTH || $height > self::MAX_HEIGHT) {
            $scale = min(self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height);
            $newWidth = (int) round($width * $scale);
            $newHeight = (int) round($height * $scale);

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            if ($resized === false) {
                imagedestroy($image);
                return null;
            }

            self::keepTransparency($resized, $type);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            imagedestroy($image);
            $image = $resized;
            $width = $newWidth;
            $height = $newHeight;
        }

        ensure_dir($targetDir);
        $name = self::randomName(function_exists('imagewebp') ? 'webp' : self::TYPES[$type]);
        $path = $targetDir . '/' . $name;

        // WebP, wo möglich: deutlich kleiner bei gleicher Wirkung.
        $written = function_exists('imagewebp')
            ? @imagewebp($image, $path, self::QUALITY)
            : self::writeOriginalFormat($image, $path, $type);

        imagedestroy($image);

        if (!$written || !is_file($path)) {
            return null;
        }

        @chmod($path, 0644);

        return [
            'name' => $name,
            'width' => $width,
            'height' => $height,
            'bytes' => (int) filesize($path),
        ];
    }

    /**
     * SVG übernehmen – aber nur nach gründlicher Reinigung.
     *
     * Eine SVG-Datei ist XML und darf Skripte und Verweise enthalten.
     * Weil sie sich nicht neu kodieren lässt wie ein Foto, wird alles
     * Gefährliche gezielt entfernt. Bleibt danach nichts Brauchbares
     * übrig, wird die Datei abgelehnt.
     */
    public static function storeSvg(string $binary, string $targetDir): ?array
    {
        if (strlen($binary) > 500_000) {
            return null;
        }

        $svg = $binary;

        // Alles entfernen, was ausgeführt werden oder nachladen könnte
        $svg = preg_replace('#<\s*script\b.*?<\s*/\s*script\s*>#is', '', $svg) ?? $svg;
        $svg = preg_replace('#<\s*(foreignObject|iframe|embed|object|animate|set|handler)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $svg) ?? $svg;
        $svg = preg_replace('#<\s*(script|foreignObject|iframe|embed|object|use)\b[^>]*/?>#is', '', $svg) ?? $svg;
        $svg = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $svg) ?? $svg;
        $svg = preg_replace('/(href|xlink:href|src)\s*=\s*("|\')\s*(javascript|data|file):[^"\']*\2/i', '', $svg) ?? $svg;
        $svg = preg_replace('/<!ENTITY[^>]*>/i', '', $svg) ?? $svg;
        $svg = preg_replace('/<!DOCTYPE[^>]*>/i', '', $svg) ?? $svg;

        if (!preg_match('/<svg[\s>]/i', $svg)) {
            return null;
        }

        // Grösse aus viewBox oder den Attributen lesen
        $width = 0;
        $height = 0;
        if (preg_match('/viewBox\s*=\s*["\']\s*[\d.-]+\s+[\d.-]+\s+([\d.]+)\s+([\d.]+)/i', $svg, $m)) {
            $width = (int) round((float) $m[1]);
            $height = (int) round((float) $m[2]);
        }

        ensure_dir($targetDir);
        $name = self::randomName('svg');
        $path = $targetDir . '/' . $name;

        write_file_atomic($path, $svg);

        return [
            'name' => $name,
            'width' => $width,
            'height' => $height,
            'bytes' => (int) filesize($path),
        ];
    }

    private static function looksLikeSvg(string $binary): bool
    {
        $head = ltrim(substr($binary, 0, 400));
        return stripos($head, '<svg') !== false
            || (stripos($head, '<?xml') === 0 && stripos(substr($binary, 0, 2000), '<svg') !== false);
    }

    private static function keepTransparency(\GdImage $image, int $type): void
    {
        if ($type !== IMAGETYPE_PNG && $type !== IMAGETYPE_WEBP && $type !== IMAGETYPE_GIF) {
            return;
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        if ($transparent !== false) {
            imagefill($image, 0, 0, $transparent);
        }
    }

    private static function writeOriginalFormat(\GdImage $image, string $path, int $type): bool
    {
        return match ($type) {
            IMAGETYPE_PNG => @imagepng($image, $path, 7),
            IMAGETYPE_GIF => @imagegif($image, $path),
            default => @imagejpeg($image, $path, self::QUALITY),
        };
    }

    /**
     * Zufälliger Dateiname.
     *
     * Ein hochgeladener Name könnte Pfadangaben oder eine zweite Endung
     * enthalten. Ein selbst vergebener Name schliesst das aus.
     */
    private static function randomName(string $extension): string
    {
        return bin2hex(random_bytes(10)) . '.' . preg_replace('/[^a-z0-9]/', '', strtolower($extension));
    }
}
