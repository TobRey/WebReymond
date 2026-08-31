<?php

declare(strict_types=1);

namespace WebAtzeKit;

/**
 * Bilder, die der Kunde austauscht.
 *
 * Auf die Endung ist kein Verlass, auf den vom Browser gemeldeten Typ
 * genauso wenig – beides bestimmt der Absender. Geprüft wird deshalb der
 * Inhalt: getimagesize() liest die ersten Bytes und sagt, was wirklich
 * da ist. Anschliessend wird das Bild neu erzeugt. Damit ist alles weg,
 * was sich sonst noch in einer Bilddatei verstecken lässt.
 */
final class Uploads
{
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const MAX_EDGE = 2400;

    private const ALLOWED = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF => 'gif',
    ];

    private string $imageDir;

    public function __construct(string $imageDir)
    {
        $this->imageDir = rtrim($imageDir, '/');
    }

    /**
     * Ein hochgeladenes Bild übernehmen.
     *
     * @return array{ok:bool, error:string, src:string, width:int, height:int}
     */
    public function accept(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_NO_FILE) {
            return self::fail('Es wurde keine Datei gewählt.');
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return self::fail('Das Bild ist zu gross für diesen Server.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            return self::fail('Das Hochladen hat nicht geklappt.');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) {
            return self::fail('Das Hochladen hat nicht geklappt.');
        }
        if (filesize($tmp) > self::MAX_BYTES) {
            return self::fail('Das Bild ist grösser als 8 MB.');
        }

        $info = @getimagesize($tmp);
        if ($info === false || !isset(self::ALLOWED[$info[2]])) {
            return self::fail('Das ist kein Bild, das sich verwenden lässt. '
                . 'Möglich sind JPG, PNG, WebP und GIF.');
        }

        [$width, $height, $type] = $info;

        if (!function_exists('imagecreatefromstring')) {
            return self::fail('Auf diesem Server fehlt die Bildbearbeitung (GD).');
        }

        $image = @imagecreatefromstring((string) file_get_contents($tmp));
        if ($image === false) {
            return self::fail('Das Bild liess sich nicht lesen.');
        }

        // Zu grosse Bilder verkleinern – sie machen die Website langsam,
        // ohne besser auszusehen.
        $longest = max($width, $height);
        if ($longest > self::MAX_EDGE) {
            $scale = self::MAX_EDGE / $longest;
            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            imagedestroy($image);
            $image = $resized;
            $width = $newWidth;
            $height = $newHeight;
        }

        $extension = self::ALLOWED[$type];
        $name = 'bild-' . date('Ymd') . '-' . bin2hex(random_bytes(5)) . '.' . $extension;
        $target = $this->imageDir . '/' . $name;

        if (!is_dir($this->imageDir) && !@mkdir($this->imageDir, 0755, true) && !is_dir($this->imageDir)) {
            imagedestroy($image);
            return self::fail('Der Bilderordner lässt sich nicht anlegen.');
        }

        $ok = match ($extension) {
            'jpg' => imagejpeg($image, $target, 82),
            'png' => imagepng($image, $target, 6),
            'webp' => function_exists('imagewebp') ? imagewebp($image, $target, 82) : imagejpeg($image, $target, 82),
            'gif' => imagegif($image, $target),
            default => false,
        };

        imagedestroy($image);

        if (!$ok) {
            return self::fail('Das Bild liess sich nicht speichern.');
        }

        @chmod($target, 0644);

        return [
            'ok' => true,
            'error' => '',
            'src' => 'assets/img/' . $name,
            'width' => $width,
            'height' => $height,
        ];
    }

    private static function fail(string $message): array
    {
        return ['ok' => false, 'error' => $message, 'src' => '', 'width' => 0, 'height' => 0];
    }
}
