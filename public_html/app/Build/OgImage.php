<?php

declare(strict_types=1);

namespace WebAtze\Build;

use WebAtze\Core\{Db, Logger};

/**
 * Das Vorschaubild für geteilte Verweise.
 *
 * Wer die Adresse der Website in WhatsApp, LinkedIn oder Slack einfügt,
 * bekommt dort eine Karte zu sehen. Ohne eigenes Bild bleibt sie leer
 * oder zeigt irgendein Bruchstück der Seite – beides sieht nach
 * Versehen aus.
 *
 * Gezeichnet wird 1200 × 630, weil das seit Jahren das Mass ist, mit
 * dem alle Dienste rechnen.
 *
 * Zur Schrift, ehrlich gesagt: Ein Schriftzug braucht eine TTF-Datei,
 * und die liegt hier nicht bei – die Website benutzt WOFF2, damit kann
 * GD nichts anfangen. Findet sich auf dem Server eine (viele haben
 * DejaVu), wird der Firmenname gesetzt. Sonst bleibt das Bild rein
 * grafisch: Verlauf in den Firmenfarben, dazu das Logo, wenn eines da
 * ist. Das ist kein Notbehelf – der Name der Firma steht in der
 * Vorschaukarte ohnehin daneben, jedes Mal. Ein Bild, das ihn
 * wiederholt, sagt nichts Neues; eines mit schlecht gesetzter Schrift
 * sagt etwas Falsches.
 *
 * Eigene Schrift gewünscht? Eine TTF nach storage/fonts/ legen.
 */
final class OgImage
{
    private const WIDTH = 1200;
    private const HEIGHT = 630;

    /** Dort wird zuerst nach einer Schrift gesucht. */
    private const FONT_PATHS = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        '/usr/share/fonts/truetype/noto/NotoSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/liberation-sans/LiberationSans-Bold.ttf',
        '/Library/Fonts/Arial Bold.ttf',
    ];

    /**
     * Das Bild schreiben.
     *
     * @return int Anzahl geschriebener Dateien (0 oder 1)
     */
    public static function write(array $project, array $theme, string $outputDir): int
    {
        if (!function_exists('imagecreatetruecolor')) {
            Logger::info('Kein Vorschaubild: die PHP-Erweiterung "gd" fehlt.');
            return 0;
        }

        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

        if ($image === false) {
            return 0;
        }

        $colors = (array) ($theme['colors'] ?? []);

        $from = self::rgb((string) ($colors['primary'] ?? '#1f2937'));
        $to = self::rgb((string) ($colors['secondary'] ?? $colors['accent'] ?? '#0f172a'));

        self::gradient($image, $from, $to);
        self::texture($image);

        $placed = self::placeLogo($image, $project, $outputDir);

        if (!$placed) {
            self::placeName($image, $project, $colors);
        }

        $target = rtrim($outputDir, '/') . '/assets/img/vorschau.png';
        ensure_dir(dirname($target));

        $ok = @imagepng($image, $target, 6);
        imagedestroy($image);

        return $ok ? 1 : 0;
    }

    // ------------------------------------------------------------ Zeichnen

    /**
     * Ein Verlauf über die Diagonale.
     *
     * Zeile für Zeile gezeichnet – GD kennt keine Verläufe. Bei 630
     * Zeilen ist das schnell genug, um niemandem aufzufallen.
     */
    private static function gradient($image, array $from, array $to): void
    {
        $steps = self::HEIGHT;

        for ($y = 0; $y < $steps; $y++) {
            $t = $y / max(1, $steps - 1);

            $color = imagecolorallocate(
                $image,
                (int) round($from[0] + ($to[0] - $from[0]) * $t),
                (int) round($from[1] + ($to[1] - $from[1]) * $t),
                (int) round($from[2] + ($to[2] - $from[2]) * $t)
            );

            // Leicht schräg, damit es nicht nach Farbverlauf-Vorlage aussieht.
            imageline($image, 0, $y, self::WIDTH, $y - 60, $color);
        }
    }

    /**
     * Ein paar Kreise darüber.
     *
     * Sehr zurückhaltend: Sie sollen dem Bild Tiefe geben, nicht
     * auffallen. Immer dieselben Stellen – ein Vorschaubild, das sich
     * bei jedem Bauen ändert, verwirrt nur.
     */
    private static function texture($image): void
    {
        $light = imagecolorallocatealpha($image, 255, 255, 255, 112);
        $dark = imagecolorallocatealpha($image, 0, 0, 0, 116);

        imagefilledellipse($image, 1010, 120, 420, 420, $light);
        imagefilledellipse($image, 150, 560, 340, 340, $dark);
        imagefilledellipse($image, 640, 700, 700, 420, $dark);
    }

    /** Das hochgeladene Logo in die Mitte. */
    private static function placeLogo($image, array $project, string $outputDir): bool
    {
        $asset = Db::first(
            "SELECT * FROM project_assets WHERE project_id = :p AND kind = 'logo' ORDER BY id DESC LIMIT 1",
            ['p' => (int) $project['id']]
        );

        if ($asset === null) {
            return false;
        }

        $path = rtrim($outputDir, '/') . '/assets/img/' . basename((string) $asset['path']);

        if (!is_file($path)) {
            return false;
        }

        $logo = @imagecreatefromstring((string) file_get_contents($path));

        if ($logo === false) {
            return false;
        }

        $lw = imagesx($logo);
        $lh = imagesy($logo);

        // Höchstens die halbe Breite, höchstens ein Drittel der Höhe.
        $scale = min(600 / max(1, $lw), 210 / max(1, $lh), 3.0);

        $tw = (int) round($lw * $scale);
        $th = (int) round($lh * $scale);

        imagealphablending($image, true);
        imagecopyresampled(
            $image,
            $logo,
            (int) ((self::WIDTH - $tw) / 2),
            (int) ((self::HEIGHT - $th) / 2),
            0,
            0,
            $tw,
            $th,
            $lw,
            $lh
        );

        imagedestroy($logo);

        return true;
    }

    /**
     * Ohne Logo: ein Schild mit dem Anfangsbuchstaben, und – wenn eine
     * Schrift da ist – der Firmenname darunter.
     */
    private static function placeName($image, array $project, array $colors): void
    {
        $name = trim((string) ($project['name'] ?? ''));
        $font = self::findFont();

        $badge = self::rgb((string) ($colors['accent'] ?? '#ffffff'));
        $onBadge = self::rgb((string) ($colors['on_accent'] ?? '#0b0b12'));

        $size = 220;
        $x = (int) ((self::WIDTH - $size) / 2);
        $y = $font !== '' ? 150 : (int) ((self::HEIGHT - $size) / 2);

        $badgeColor = imagecolorallocate($image, $badge[0], $badge[1], $badge[2]);
        self::roundedRect($image, $x, $y, $size, $size, 48, $badgeColor);

        if ($font === '') {
            // Ohne Schrift bleibt es beim Schild. Ein leeres Feld sieht
            // ruhig aus; ein verzogener Buchstabe sähe kaputt aus.
            return;
        }

        $initial = mb_strtoupper(mb_substr($name, 0, 1));
        $textColor = imagecolorallocate($image, $onBadge[0], $onBadge[1], $onBadge[2]);

        self::centeredText($image, $font, 120, $textColor, $x + $size / 2, $y + $size / 2 + 44, $initial);

        $white = imagecolorallocate($image, 255, 255, 255);
        self::centeredText($image, $font, 58, $white, self::WIDTH / 2, 470, mb_substr($name, 0, 40));
    }

    /** Text um seinen Mittelpunkt herum setzen. */
    private static function centeredText($image, string $font, int $size, int $color, float $cx, float $cy, string $text): void
    {
        $box = @imagettfbbox($size, 0, $font, $text);

        if ($box === false) {
            return;
        }

        $width = $box[2] - $box[0];

        @imagettftext($image, $size, 0, (int) round($cx - $width / 2), (int) round($cy), $color, $font, $text);
    }

    /** Ein Rechteck mit runden Ecken – GD kann das nicht von sich aus. */
    private static function roundedRect($image, int $x, int $y, int $w, int $h, int $r, int $color): void
    {
        imagefilledrectangle($image, $x + $r, $y, $x + $w - $r, $y + $h, $color);
        imagefilledrectangle($image, $x, $y + $r, $x + $w, $y + $h - $r, $color);

        imagefilledellipse($image, $x + $r, $y + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($image, $x + $w - $r, $y + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($image, $x + $r, $y + $h - $r, $r * 2, $r * 2, $color);
        imagefilledellipse($image, $x + $w - $r, $y + $h - $r, $r * 2, $r * 2, $color);
    }

    // ------------------------------------------------------------ Kleinkram

    /** Eine Schrift, mit der GD umgehen kann – oder ''. */
    public static function findFont(): string
    {
        foreach (glob(STORAGE_DIR . '/fonts/*.ttf') ?: [] as $own) {
            return $own;
        }

        foreach (self::FONT_PATHS as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return '';
    }

    /** '#2b1b9e' -> [43, 27, 158] */
    private static function rgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return [31, 41, 55];
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
