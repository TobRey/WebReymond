<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\HttpException;
use App\Core\Json;
use App\Core\Logger;
use App\Core\Validator;

/**
 * Sichere Medien-Uploads.
 *
 * Schutzmassnahmen:
 *  - feste Erlaubnisliste fuer Dateitypen (Endung + echter MIME-Typ)
 *  - Groessenbegrenzung
 *  - zufaellige Dateinamen (keine Uebernahme des Originalnamens)
 *  - Bilder werden neu berechnet (entfernt eingebettete Skripte und Metadaten)
 *  - Uploads liegen ausserhalb der direkt ausfuehrbaren Pfade (.htaccess + PHP-Endpunkt)
 */
final class UploadService
{
    private const MAX_BYTES = 12582912; // 12 MB

    private const ALLOWED = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'svg'  => ['image/svg+xml', 'text/plain', 'text/xml', 'application/xml'],
        'mp3'  => ['audio/mpeg', 'audio/mp3'],
        'wav'  => ['audio/wav', 'audio/x-wav', 'audio/wave'],
        'ogg'  => ['audio/ogg', 'application/ogg'],
        'mp4'  => ['video/mp4'],
        'webm' => ['video/webm'],
        'pdf'  => ['application/pdf'],
    ];

    public function __construct(private string $uploadRoot)
    {
    }

    /**
     * @param array $file  Eintrag aus $_FILES
     * @return array<string,mixed> Medien-Datensatz
     */
    public function store(array $file, string $title = '', string $category = 'bild', bool $rightsConfirmed = false): array
    {
        if (!$rightsConfirmed) {
            throw HttpException::badRequest('Bitte bestaetigen, dass die Datei verwendet werden darf.');
        }
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw HttpException::badRequest($this->uploadErrorMessage($error));
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || (!is_uploaded_file($tmp) && !is_file($tmp))) {
            throw HttpException::badRequest('Keine gueltige Uploaddatei.');
        }
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw HttpException::badRequest('Die Datei ist zu gross (maximal 12 MB).');
        }

        $original = (string)($file['name'] ?? 'datei');
        $extension = strtolower((string)pathinfo($original, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED[$extension])) {
            throw HttpException::badRequest('Dieser Dateityp ist nicht erlaubt. Zulaessig: ' . implode(', ', array_keys(self::ALLOWED)));
        }

        $mime = $this->detectMime($tmp);
        if (!in_array($mime, self::ALLOWED[$extension], true)) {
            Logger::security('Upload mit unpassendem MIME-Typ abgelehnt', ['ext' => $extension, 'mime' => $mime]);
            throw HttpException::badRequest('Der Inhalt der Datei passt nicht zur Endung (' . $mime . ').');
        }

        // Ausfuehrbare Inhalte in vermeintlichen Medien erkennen
        $head = (string)@file_get_contents($tmp, false, null, 0, 4096);
        if (preg_match('~<\?php|<\?=|<script|__halt_compiler~i', $head) && $extension !== 'svg') {
            Logger::security('Upload mit ausfuehrbarem Inhalt abgelehnt', ['ext' => $extension]);
            throw HttpException::badRequest('Die Datei enthaelt unzulaessigen Code.');
        }

        $id = 'm_' . bin2hex(random_bytes(8));
        $filename = $id . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
        $targetDir = $this->uploadRoot . '/media';
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0750, true);
        }
        $target = $targetDir . '/' . $filename;

        $isImage = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
        if ($isImage && function_exists('imagecreatefromstring')) {
            $this->reencodeImage($tmp, $target, $extension);
        } elseif ($extension === 'svg') {
            $this->storeSanitizedSvg($tmp, $target);
        } else {
            if (!@move_uploaded_file($tmp, $target) && !@copy($tmp, $target)) {
                throw HttpException::badRequest('Die Datei konnte nicht gespeichert werden.');
            }
        }
        @chmod($target, 0640);

        $record = [
            'id'        => $id,
            'file'      => $filename,
            'title'     => Validator::text($title !== '' ? $title : pathinfo($original, PATHINFO_FILENAME), 140),
            'category'  => Validator::text($category, 40),
            'mime'      => $mime,
            'extension' => $extension,
            'size'      => is_file($target) ? (int)filesize($target) : $size,
            'uploaded'  => gmdate('c'),
            'thumb'     => null,
            'width'     => null,
            'height'    => null,
        ];

        if ($isImage) {
            $info = @getimagesize($target);
            if (is_array($info)) {
                $record['width'] = (int)$info[0];
                $record['height'] = (int)$info[1];
            }
            $record['thumb'] = $this->createThumbnail($target, $id);
        }

        Logger::info('Medium hochgeladen', ['id' => $id, 'ext' => $extension, 'size' => $record['size']]);
        return $record;
    }

    private function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = (string)finfo_file($finfo, $path);
                finfo_close($finfo);
                if ($mime !== '') {
                    return $mime;
                }
            }
        }
        $info = @getimagesize($path);
        if (is_array($info) && isset($info['mime'])) {
            return (string)$info['mime'];
        }
        return 'application/octet-stream';
    }

    private function reencodeImage(string $source, string $target, string $extension): void
    {
        $data = (string)file_get_contents($source);
        $image = @imagecreatefromstring($data);
        if ($image === false) {
            throw HttpException::badRequest('Das Bild konnte nicht gelesen werden.');
        }
        // Sehr grosse Bilder verkleinern (Speicher und Ladezeit)
        $width = imagesx($image);
        $height = imagesy($image);
        $maxSide = 2200;
        if ($width > $maxSide || $height > $maxSide) {
            $scale = $maxSide / max($width, $height);
            $resized = imagescale($image, (int)round($width * $scale), (int)round($height * $scale));
            if ($resized !== false) {
                imagedestroy($image);
                $image = $resized;
            }
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $ok = match ($extension) {
            'png'  => imagepng($image, $target, 6),
            'gif'  => imagegif($image, $target),
            'webp' => function_exists('imagewebp') ? imagewebp($image, $target, 88) : imagejpeg($image, $target, 88),
            default=> imagejpeg($image, $target, 88),
        };
        imagedestroy($image);
        if ($ok === false) {
            throw HttpException::badRequest('Das Bild konnte nicht gespeichert werden.');
        }
    }

    /** Entfernt Skripte und Ereignis-Attribute aus SVG-Dateien. */
    private function storeSanitizedSvg(string $source, string $target): void
    {
        $svg = (string)file_get_contents($source);
        $svg = preg_replace('~<script.*?</script>~is', '', $svg) ?? '';
        $svg = preg_replace('~\son\w+\s*=\s*"[^"]*"~i', '', $svg) ?? '';
        $svg = preg_replace("~\son\w+\s*=\s*'[^']*'~i", '', $svg) ?? '';
        $svg = preg_replace('~(href|xlink:href)\s*=\s*([\'"])\s*javascript:[^\'"]*\2~i', '', $svg) ?? '';
        $svg = preg_replace('~<foreignObject.*?</foreignObject>~is', '', $svg) ?? '';
        if (!str_contains($svg, '<svg')) {
            throw HttpException::badRequest('Die SVG-Datei ist ungueltig.');
        }
        file_put_contents($target, $svg);
    }

    private function createThumbnail(string $source, string $id): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $data = (string)file_get_contents($source);
        $image = @imagecreatefromstring($data);
        if ($image === false) {
            return null;
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $side = 320;
        $scale = $side / max(1, max($width, $height));
        $thumb = imagescale($image, max(1, (int)round($width * $scale)), max(1, (int)round($height * $scale)));
        imagedestroy($image);
        if ($thumb === false) {
            return null;
        }
        $dir = $this->uploadRoot . '/thumbs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $name = $id . '_thumb.jpg';
        imagejpeg($thumb, $dir . '/' . $name, 82);
        imagedestroy($thumb);
        @chmod($dir . '/' . $name, 0640);
        return $name;
    }

    public function delete(string $file, ?string $thumb = null): void
    {
        $file = Validator::filename($file);
        $path = $this->uploadRoot . '/media/' . $file;
        if (is_file($path)) {
            @unlink($path);
        }
        if ($thumb !== null && $thumb !== '') {
            $thumbPath = $this->uploadRoot . '/thumbs/' . Validator::filename($thumb);
            if (is_file($thumbPath)) {
                @unlink($thumbPath);
            }
        }
    }

    public function pathFor(string $file, bool $thumb = false): string
    {
        $file = Validator::filename($file);
        $path = $this->uploadRoot . '/' . ($thumb ? 'thumbs' : 'media') . '/' . $file;
        return Validator::withinDirectory($path, $this->uploadRoot);
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die Datei ist groesser als vom Server erlaubt.',
            UPLOAD_ERR_PARTIAL => 'Der Upload wurde abgebrochen.',
            UPLOAD_ERR_NO_FILE => 'Es wurde keine Datei ausgewaehlt.',
            UPLOAD_ERR_NO_TMP_DIR => 'Auf dem Server fehlt ein temporaeres Verzeichnis.',
            UPLOAD_ERR_CANT_WRITE => 'Der Server kann die Datei nicht schreiben.',
            UPLOAD_ERR_EXTENSION => 'Eine PHP-Erweiterung hat den Upload gestoppt.',
            default => 'Unbekannter Uploadfehler.',
        };
    }

    /** Erlaubte Endungen fuer die Oberflaeche. */
    public static function allowedExtensions(): array
    {
        return array_keys(self::ALLOWED);
    }

    public static function maxBytes(): int
    {
        return self::MAX_BYTES;
    }
}
