<?php
declare(strict_types=1);

namespace SwipeGroove\Users;

use SwipeGroove\Core\App;
use SwipeGroove\Core\HttpException;

/**
 * Sichere Bildverarbeitung: Validierung, Neucodierung mit GD (entfernt Metadaten und
 * eingebettete Inhalte), Zuschnitt, Grössenbegrenzung. Es werden nie Originaldateien gespeichert.
 */
final class ImageService
{
    public const MAX_UPLOAD_BYTES = 12 * 1024 * 1024;
    private const KINDS = [
        'avatar' => ['w' => 512, 'h' => 512, 'dir' => 'avatars'],
        'banner' => ['w' => 1500, 'h' => 500, 'dir' => 'banners'],
        'background' => ['w' => 1600, 'h' => 1000, 'dir' => 'backgrounds'],
    ];

    public function __construct(private App $app)
    {
    }

    /**
     * Dekodiert Upload-Bytes sicher in eine GD-Ressource.
     */
    public function decode(string $bytes): \GdImage
    {
        if (strlen($bytes) > self::MAX_UPLOAD_BYTES) {
            throw HttpException::badRequest('image.too_large');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            throw HttpException::badRequest('image.invalid');
        }
        $mime = $info['mime'] ?? '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            throw HttpException::badRequest('image.unsupported');
        }
        if ($info[0] * $info[1] > 40_000_000) {
            throw HttpException::badRequest('image.too_large');
        }
        $img = @imagecreatefromstring($bytes);
        if (!$img instanceof \GdImage) {
            throw HttpException::badRequest('image.invalid');
        }
        // EXIF-Orientierung bei JPEG berücksichtigen
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
            $o = (int) ($exif['Orientation'] ?? 1);
            if ($o === 3) {
                $img = imagerotate($img, 180, 0) ?: $img;
            } elseif ($o === 6) {
                $img = imagerotate($img, -90, 0) ?: $img;
            } elseif ($o === 8) {
                $img = imagerotate($img, 90, 0) ?: $img;
            }
        }
        return $img;
    }

    /**
     * Zuschnitt + Skalierung auf Zielgrösse (Cover-Fit).
     * @param array{x:float,y:float,w:float,h:float}|null $crop relativ (0..1) zum Quellbild
     */
    public function cropResize(\GdImage $src, int $tw, int $th, ?array $crop): \GdImage
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($crop && $crop['w'] > 0 && $crop['h'] > 0) {
            $cx = (int) max(0, min($sw - 1, round($crop['x'] * $sw)));
            $cy = (int) max(0, min($sh - 1, round($crop['y'] * $sh)));
            $cw = (int) max(1, min($sw - $cx, round($crop['w'] * $sw)));
            $ch = (int) max(1, min($sh - $cy, round($crop['h'] * $sh)));
        } else {
            // zentrierter Zuschnitt im Zielverhältnis
            $ratio = $tw / $th;
            if ($sw / $sh > $ratio) {
                $ch = $sh;
                $cw = (int) round($sh * $ratio);
            } else {
                $cw = $sw;
                $ch = (int) round($sw / $ratio);
            }
            $cx = (int) (($sw - $cw) / 2);
            $cy = (int) (($sh - $ch) / 2);
        }
        // Nicht hochskalieren über das Quellmaterial hinaus
        $scale = min(1.0, $cw / $tw, $ch / $th);
        $ow = max(1, (int) round($tw * $scale));
        $oh = max(1, (int) round($th * $scale));
        $dst = imagecreatetruecolor($ow, $oh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 18, 20, 30));
        imagecopyresampled($dst, $src, 0, 0, $cx, $cy, $ow, $oh, $cw, $ch);
        return $dst;
    }

    /**
     * Speichert ein Profilbild (avatar/banner/background) und gibt den relativen Pfad zurück.
     */
    public function storeUserImage(int $userId, string $kind, string $bytes, ?array $crop): string
    {
        if (!isset(self::KINDS[$kind])) {
            throw HttpException::badRequest('image.invalid_kind');
        }
        $spec = self::KINDS[$kind];
        $src = $this->decode($bytes);
        $img = $this->cropResize($src, $spec['w'], $spec['h'], $crop);
        imagedestroy($src);
        $dir = $this->app->root . '/uploads/' . $spec['dir'];
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            @chmod($dir, 0755);
        }
        $name = $userId . '-' . bin2hex(random_bytes(8)) . '.webp';
        $path = $dir . '/' . $name;
        if (!imagewebp($img, $path, 84)) {
            imagedestroy($img);
            throw new \RuntimeException('Bild konnte nicht gespeichert werden');
        }
        $w = imagesx($img);
        $h = imagesy($img);
        imagedestroy($img);
        // Der Webserver (oft ein anderer Systembenutzer als PHP) muss die Datei lesen können
        @chmod($path, 0644);
        $db = $this->app->db();
        $rel = $spec['dir'] . '/' . $name;
        $db->insert('user_images', ['user_id' => $userId, 'kind' => $kind, 'path' => $rel, 'width' => $w, 'height' => $h, 'bytes' => (int) filesize($path), 'created_at' => $db->now()]);
        $col = $kind . '_path';
        $old = $db->value('SELECT ' . $col . ' FROM users WHERE id = ?', [$userId]);
        $db->update('users', [$col => $rel, 'updated_at' => $db->now()], 'id = ?', [$userId]);
        if ($old && $old !== $rel) {
            $this->deleteFile((string) $old);
        }
        return $rel;
    }

    public function deleteUserImage(int $userId, string $kind): void
    {
        if (!isset(self::KINDS[$kind])) {
            throw HttpException::badRequest('image.invalid_kind');
        }
        $db = $this->app->db();
        $col = $kind . '_path';
        $old = $db->value('SELECT ' . $col . ' FROM users WHERE id = ?', [$userId]);
        $db->update('users', [$col => null, 'updated_at' => $db->now()], 'id = ?', [$userId]);
        if ($old) {
            $this->deleteFile((string) $old);
        }
    }

    public function deleteAllForUser(int $userId): void
    {
        foreach ($this->app->db()->column('SELECT path FROM user_images WHERE user_id = ?', [$userId]) as $p) {
            $this->deleteFile((string) $p);
        }
    }

    private function deleteFile(string $rel): void
    {
        $rel = str_replace(['..', "\0"], '', $rel);
        $file = $this->app->root . '/uploads/' . ltrim($rel, '/');
        if (is_file($file)) {
            @unlink($file);
        }
        try {
            $this->app->db()->run('DELETE FROM user_images WHERE path = ?', [$rel]);
        } catch (\Throwable) {
        }
    }

    /**
     * Bereitet ein Bild als Spotify-Playlist-Cover auf: quadratisches JPEG, Base64 ≤ 256 KB.
     */
    /**
     * Setzt lesbare Rechte für alle Upload-Verzeichnisse (0755) und Bilder (0644).
     * Ältere Versionen speicherten mit 0750/0640, was auf Shared-Hosting zu 403 führte.
     */
    public static function repairPermissions(string $uploadsDir): int
    {
        if (!is_dir($uploadsDir)) {
            return 0;
        }
        $fixed = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($uploadsDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            $path = $f->getPathname();
            if ($f->isDir()) {
                if ((fileperms($path) & 0777) !== 0755 && @chmod($path, 0755)) {
                    $fixed++;
                }
            } elseif (preg_match('/\.(webp|jpe?g|png)$/i', $path) && (fileperms($path) & 0777) !== 0644 && @chmod($path, 0644)) {
                $fixed++;
            }
        }
        return $fixed;
    }

    public function toSpotifyCoverJpeg(string $bytes, ?array $crop = null): string
    {
        $src = $this->decode($bytes);
        foreach ([800, 640, 500, 400, 320] as $size) {
            $img = $this->cropResize($src, $size, $size, $crop);
            for ($q = 88; $q >= 40; $q -= 8) {
                ob_start();
                imagejpeg($img, null, $q);
                $jpeg = (string) ob_get_clean();
                if (strlen(base64_encode($jpeg)) <= 256 * 1024) {
                    imagedestroy($img);
                    imagedestroy($src);
                    return $jpeg;
                }
            }
            imagedestroy($img);
        }
        imagedestroy($src);
        throw HttpException::badRequest('image.cover_too_large');
    }

    /** Liest die hochgeladene Datei aus $_FILES oder einem Base64-Feld. */
    public static function uploadedBytes(string $field = 'image'): string
    {
        if (isset($_FILES[$field]) && is_array($_FILES[$field])) {
            $f = $_FILES[$field];
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw HttpException::badRequest(($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($f['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE ? 'image.too_large' : 'image.upload_failed');
            }
            if (!is_uploaded_file((string) $f['tmp_name'])) {
                throw HttpException::badRequest('image.upload_failed');
            }
            return (string) file_get_contents((string) $f['tmp_name']);
        }
        $b64 = $_POST[$field . '_base64'] ?? null;
        if (is_string($b64) && $b64 !== '') {
            $b64 = preg_replace('#^data:image/\w+;base64,#', '', $b64) ?? $b64;
            $bytes = base64_decode($b64, true);
            if ($bytes !== false) {
                return $bytes;
            }
        }
        throw HttpException::badRequest('image.missing');
    }

    /** @return array{x:float,y:float,w:float,h:float}|null */
    public static function cropFromInput(mixed $raw): ?array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return null;
        }
        $vals = [];
        foreach (['x', 'y', 'w', 'h'] as $k) {
            if (!isset($raw[$k]) || !is_numeric($raw[$k])) {
                return null;
            }
            $vals[$k] = max(0.0, min(1.0, (float) $raw[$k]));
        }
        return $vals;
    }
}
