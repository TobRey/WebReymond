<?php

declare(strict_types=1);

namespace WebAtze\Build;

/**
 * Macht aus den Wunschfarben des Kunden ein vollständiges, lesbares
 * Farbsystem.
 *
 * Der wichtigste Teil ist die Kontrastprüfung. Ein Kunde wählt eine
 * Farbe, weil sie ihm gefällt – nicht, weil weisse Schrift darauf
 * lesbar wäre. Deshalb wird für jede Fläche ausgerechnet, ob heller
 * oder dunkler Text darauf besser ankommt, und die Farbe notfalls so
 * weit abgedunkelt, dass sie als Textfarbe taugt.
 *
 * So entsteht auch aus einem gelben Firmenlogo eine Website, die man
 * lesen kann.
 */
final class Theme
{
    /** WCAG: 4.5:1 für Fliesstext, 3:1 für grosse Schrift. */
    private const MIN_TEXT = 4.5;
    private const MIN_LARGE = 3.0;

    /**
     * Vollständiges Farb- und Schriftsystem erzeugen.
     *
     * @param array $wishes primary, secondary, accent (jeweils #rrggbb, dürfen fehlen)
     */
    public static function build(array $wishes, string $style = 'clean'): array
    {
        $primary = self::sanitise($wishes['primary'] ?? '') ?: self::styleDefault($style, 'primary');
        $secondary = self::sanitise($wishes['secondary'] ?? '') ?: self::derive($primary, 'secondary');
        $ink = self::sanitise($wishes['accent'] ?? '') ?: self::derive($primary, 'ink');

        // Die Hauptfarbe trägt Schaltflächen und Verweise. Ist sie auf
        // Weiss zu hell, wird sie so weit abgedunkelt, bis sie lesbar ist.
        $primaryText = self::ensureContrastOnWhite($primary, self::MIN_TEXT);

        // Und umgekehrt: welche Textfarbe kommt auf der Fläche an?
        $onPrimary = self::bestTextOn($primary);
        $onSecondary = self::bestTextOn($secondary);

        $fonts = self::fonts($style);

        return [
            'mode' => ($wishes['mode'] ?? '') === 'dunkel' ? 'dunkel' : 'hell',
            'colors' => [
                'primary' => $primaryText,
                'primary_raw' => $primary,
                'primary_dark' => self::shade($primaryText, -0.22),
                'primary_light' => self::shade($primaryText, 0.28),
                'secondary' => $secondary,
                'secondary_dark' => self::ensureContrastOnWhite($secondary, self::MIN_LARGE),
                'ink' => $ink,
                'on_primary' => $onPrimary,
                'on_secondary' => $onSecondary,
                'bg' => '#ffffff',
                'bg_soft' => self::tint($primary, 0.965),
                'surface' => '#ffffff',
                'surface_2' => self::tint($primary, 0.945),
                'text' => self::shade($ink, 0.06),
                'text_muted' => self::mix($ink, '#ffffff', 0.38),
                'text_faint' => self::mix($ink, '#ffffff', 0.55),
                'border' => self::rgba($ink, 0.11),
                'border_strong' => self::rgba($ink, 0.22),
                'dark_bg' => self::shade($ink, -0.35),
                'dark_surface' => self::shade($ink, -0.18),
                'dark_text' => self::tint($primary, 0.94),
                'dark_muted' => self::mix($ink, '#ffffff', 0.62),
                'dark_border' => 'rgb(255 255 255 / 0.12)',
            ],
            'fonts' => $fonts,
            'report' => self::report($primary, $primaryText, $secondary, $onPrimary),
        ];
    }

    /**
     * Aus dem Farbsystem das CSS mit den Token-Werten machen.
     * Das ist die einzige Datei einer Kundenwebsite mit festen Farbwerten.
     */
    public static function toCss(array $theme): string
    {
        $c = $theme['colors'];
        $f = $theme['fonts'];

        // Hell oder dunkel. Kundenwebsites sind hell, solange niemand
        // etwas anderes wählt; die eigene Website ist dunkel. Das ist
        // eine Angabe am Thema und keine zweite Farbtabelle - sonst
        // gäbe es zwei Orte, an denen eine Farbe steht.
        $dunkel = (string) ($theme['mode'] ?? 'hell') === 'dunkel';

        $lines = [
            '/**',
            ' * Farben und Schriften dieser Website.',
            ' *',
            ' * Erzeugt beim Bauen aus den gewählten Markenfarben. Die',
            ' * Kontraste wurden geprüft: Text auf Fläche erreicht überall',
            ' * mindestens das geforderte Verhältnis.',
            ' *',
            ' * Wer die Farben ändern will, ändert sie hier – der Rest der',
            ' * Website zieht automatisch nach.',
            ' */',
            '',
            ':root {',
            '  color-scheme: ' . ($dunkel ? 'dark' : 'light') . ';',
            '',
        ];

        $map = [
            'primary' => '--c-primary',
            'primary_dark' => '--c-primary-dark',
            'primary_light' => '--c-primary-light',
            'secondary' => '--c-secondary',
            'secondary_dark' => '--c-secondary-dark',
            'ink' => '--c-ink',
            'on_primary' => '--c-on-primary',
            'on_secondary' => '--c-on-secondary',
            'bg' => '--c-bg',
            'bg_soft' => '--c-bg-soft',
            'surface' => '--c-surface',
            'surface_2' => '--c-surface-2',
            'border' => '--c-border',
            'border_strong' => '--c-border-strong',
            'text' => '--c-text',
            'text_muted' => '--c-text-muted',
            'text_faint' => '--c-text-faint',
            'dark_bg' => '--c-dark-bg',
            'dark_surface' => '--c-dark-surface',
            'dark_text' => '--c-dark-text',
            'dark_muted' => '--c-dark-muted',
            'dark_border' => '--c-dark-border',
        ];

        foreach ($map as $key => $token) {
            if (isset($c[$key])) {
                $lines[] = sprintf('  %s: %s;', $token, $c[$key]);
            }
        }

        // Auf einer dunklen Website tauschen die Flächen ihre Rollen.
        // Die Farben selbst bleiben dieselben - was sich ändert, ist,
        // welche davon der Grund ist und welche der Text.
        if ($dunkel) {
            foreach ([
                '--c-bg' => 'dark_bg',
                '--c-bg-soft' => 'dark_surface',
                '--c-surface' => 'dark_surface',
                '--c-surface-2' => 'dark_bg',
                '--c-border' => 'dark_border',
                '--c-text' => 'dark_text',
                '--c-text-muted' => 'dark_muted',
            ] as $token => $key) {
                if (isset($c[$key])) {
                    $lines[] = sprintf('  %s: %s;', $token, $c[$key]);
                }
            }

            $lines[] = '  --c-text-faint: color-mix(in srgb, var(--c-text) 55%, transparent);';
            $lines[] = '  --c-border-strong: rgb(255 255 255 / 0.24);';
            $lines[] = '  --c-primary: var(--c-primary-light);';
        }

        // Flächen und Verläufe, die ein Stilpaket benutzt. Sie stehen
        // hier, damit ein Milchglaskasten oder ein Leuchten die Farben
        // der Marke trägt statt selbst erfundener.
        $lines[] = '';
        $lines[] = '  --c-surface-glass: color-mix(in srgb, var(--c-text) 6%, transparent);';
        $lines[] = '  --c-gradient-brand: linear-gradient(112deg, '
            . 'var(--c-primary-light), var(--c-secondary));';
        $lines[] = '  --c-gradient-soft: linear-gradient(160deg, '
            . 'var(--c-bg-soft), var(--c-surface));';
        $lines[] = '  --c-glow-primary: radial-gradient(closest-side, '
            . 'color-mix(in srgb, var(--c-primary) 55%, transparent), transparent 72%);';
        $lines[] = '  --c-glow-secondary: radial-gradient(closest-side, '
            . 'color-mix(in srgb, var(--c-secondary) 45%, transparent), transparent 72%);';
        $lines[] = '  --c-shadow-glow: 0 18px 60px -20px '
            . 'color-mix(in srgb, var(--c-primary) 55%, transparent);';

        $lines[] = '';
        $lines[] = sprintf('  --c-font-display: %s;', $f['display']);
        $lines[] = sprintf('  --c-font-body: %s;', $f['body']);
        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    // ------------------------------------------------------------------
    // Kontrast
    // ------------------------------------------------------------------

    /** Kontrastverhältnis zweier Farben nach WCAG. */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        $light = max($la, $lb);
        $dark = min($la, $lb);

        return ($light + 0.05) / ($dark + 0.05);
    }

    /** Relative Helligkeit einer Farbe (0 = schwarz, 1 = weiss). */
    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);

        $channel = static function (int $value): float {
            $c = $value / 255;
            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
    }

    /** Welche Textfarbe kommt auf dieser Fläche besser an? */
    public static function bestTextOn(string $background): string
    {
        $onWhite = self::contrast($background, '#ffffff');
        $onDark = self::contrast($background, '#111111');

        return $onWhite >= $onDark ? '#ffffff' : '#111111';
    }

    /**
     * Farbe so weit abdunkeln, bis sie auf Weiss lesbar ist.
     *
     * Schrittweise statt in einem Sprung – so bleibt der Farbton
     * erkennbar und die Marke wiedererkennbar.
     */
    public static function ensureContrastOnWhite(string $hex, float $minimum): string
    {
        $current = $hex;

        for ($i = 0; $i < 24; $i++) {
            if (self::contrast($current, '#ffffff') >= $minimum) {
                return $current;
            }
            $current = self::shade($current, -0.06);
        }

        return $current;
    }

    // ------------------------------------------------------------------
    // Farbrechnen
    // ------------------------------------------------------------------

    /** @return array{0:int,1:int,2:int} */
    public static function rgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return [0, 0, 0];
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    public static function hex(int $r, int $g, int $b): string
    {
        return sprintf(
            '#%02x%02x%02x',
            max(0, min(255, $r)),
            max(0, min(255, $g)),
            max(0, min(255, $b))
        );
    }

    /** Aufhellen (positiv) oder abdunkeln (negativ). */
    public static function shade(string $hex, float $amount): string
    {
        [$r, $g, $b] = self::rgb($hex);

        if ($amount >= 0) {
            return self::hex(
                (int) round($r + (255 - $r) * $amount),
                (int) round($g + (255 - $g) * $amount),
                (int) round($b + (255 - $b) * $amount)
            );
        }

        $factor = 1 + $amount;
        return self::hex((int) round($r * $factor), (int) round($g * $factor), (int) round($b * $factor));
    }

    /** Zwei Farben mischen. $weight = Anteil der zweiten Farbe. */
    public static function mix(string $a, string $b, float $weight): string
    {
        [$r1, $g1, $b1] = self::rgb($a);
        [$r2, $g2, $b2] = self::rgb($b);

        return self::hex(
            (int) round($r1 + ($r2 - $r1) * $weight),
            (int) round($g1 + ($g2 - $g1) * $weight),
            (int) round($b1 + ($b2 - $b1) * $weight)
        );
    }

    /** Ein Hauch Farbe in einem fast weissen Ton. */
    public static function tint(string $hex, float $lightness): string
    {
        return self::mix($hex, '#ffffff', $lightness);
    }

    public static function rgba(string $hex, float $alpha): string
    {
        [$r, $g, $b] = self::rgb($hex);
        return sprintf('rgb(%d %d %d / %.2f)', $r, $g, $b, $alpha);
    }

    // ------------------------------------------------------------------

    private static function sanitise(string $hex): string
    {
        $hex = strtolower(trim($hex));
        if ($hex === '') {
            return '';
        }
        if (!str_starts_with($hex, '#')) {
            $hex = '#' . $hex;
        }
        if (preg_match('/^#([0-9a-f]{3})$/', $hex, $m)) {
            $hex = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }
        return preg_match('/^#[0-9a-f]{6}$/', $hex) ? $hex : '';
    }

    /** Passende Voreinstellung, wenn keine Farbe gewählt wurde. */
    private static function styleDefault(string $style, string $role): string
    {
        $palettes = [
            'clean' => ['primary' => '#2f4fd8', 'secondary' => '#12b3a8'],
            'bold' => ['primary' => '#e0342c', 'secondary' => '#f5a524'],
            'elegant' => ['primary' => '#1f2937', 'secondary' => '#b08d57'],
            'warm' => ['primary' => '#c2410c', 'secondary' => '#f59e0b'],
            'technical' => ['primary' => '#0f5fa6', 'secondary' => '#22d3ee'],
            'playful' => ['primary' => '#7c3aed', 'secondary' => '#ec4899'],
            'natural' => ['primary' => '#3f6212', 'secondary' => '#84cc16'],
            'luxury' => ['primary' => '#111827', 'secondary' => '#a98342'],
        ];

        $palette = $palettes[$style] ?? $palettes['clean'];
        return $palette[$role] ?? $palette['primary'];
    }

    /** Zweitfarbe oder Dunkelton aus der Hauptfarbe ableiten. */
    private static function derive(string $primary, string $role): string
    {
        [$r, $g, $b] = self::rgb($primary);

        if ($role === 'ink') {
            // Dunkler Neutralton mit einem Hauch der Marke darin
            return self::hex(
                (int) round(14 + $r * 0.06),
                (int) round(15 + $g * 0.06),
                (int) round(26 + $b * 0.07)
            );
        }

        // Zweitfarbe: um 150 Grad im Farbkreis gedreht
        [$h, $s, $l] = self::toHsl($r, $g, $b);
        return self::fromHsl(fmod($h + 150, 360), min(1.0, $s * 1.05), min(0.62, max(0.38, $l)));
    }

    /** @return array{0:float,1:float,2:float} */
    private static function toHsl(int $r, int $g, int $b): array
    {
        $rf = $r / 255;
        $gf = $g / 255;
        $bf = $b / 255;

        $max = max($rf, $gf, $bf);
        $min = min($rf, $gf, $bf);
        $l = ($max + $min) / 2;

        if ($max === $min) {
            return [0.0, 0.0, $l];
        }

        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

        $h = match ($max) {
            $rf => (($gf - $bf) / $d) + ($gf < $bf ? 6 : 0),
            $gf => (($bf - $rf) / $d) + 2,
            default => (($rf - $gf) / $d) + 4,
        };

        return [$h * 60, $s, $l];
    }

    private static function fromHsl(float $h, float $s, float $l): string
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;

        [$r, $g, $b] = match (true) {
            $h < 60 => [$c, $x, 0.0],
            $h < 120 => [$x, $c, 0.0],
            $h < 180 => [0.0, $c, $x],
            $h < 240 => [0.0, $x, $c],
            $h < 300 => [$x, 0.0, $c],
            default => [$c, 0.0, $x],
        };

        return self::hex(
            (int) round(($r + $m) * 255),
            (int) round(($g + $m) * 255),
            (int) round(($b + $m) * 255)
        );
    }

    /** Was wurde angepasst? Erscheint im Adminbereich als Hinweis. */
    private static function report(string $wish, string $used, string $secondary, string $onPrimary): array
    {
        $notes = [];

        if ($wish !== $used) {
            $notes[] = sprintf(
                'Die Hauptfarbe %s erreicht auf Weiss nur %.1f:1 und wäre als Text schlecht lesbar. '
                . 'Für Text und Verweise wird deshalb %s verwendet – derselbe Farbton, nur dunkler. '
                . 'Flächen behalten die ursprüngliche Farbe.',
                $wish,
                self::contrast($wish, '#ffffff'),
                $used
            );
        }

        if ($onPrimary !== '#ffffff') {
            $notes[] = sprintf(
                'Auf der Hauptfarbe steht dunkle statt weisser Schrift – weiss käme nur auf %.1f:1.',
                self::contrast($wish, '#ffffff')
            );
        }

        if (self::contrast($secondary, '#ffffff') < self::MIN_LARGE) {
            $notes[] = sprintf(
                'Die Zweitfarbe %s ist auf Weiss zu hell für Text (%.1f:1). Sie wird nur für Flächen, '
                . 'Verläufe und Grafik eingesetzt; für Beschriftungen kommt eine dunklere Stufe zum Zug.',
                $secondary,
                self::contrast($secondary, '#ffffff')
            );
        }

        return $notes;
    }

    /** Schriftpaar passend zur gewünschten Stimmung. */
    private static function fonts(string $style): array
    {
        $system = "system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";
        $serif = "Georgia, 'Iowan Old Style', 'Times New Roman', serif";

        return match ($style) {
            'elegant', 'luxury' => ['display' => $serif, 'body' => $system, 'name' => 'Serif für Überschriften'],
            'technical' => [
                'display' => "'SF Mono', ui-monospace, 'Cascadia Mono', Consolas, monospace",
                'body' => $system,
                'name' => 'Technische Überschriften',
            ],
            default => ['display' => $system, 'body' => $system, 'name' => 'Systemschrift'],
        };
    }
}
