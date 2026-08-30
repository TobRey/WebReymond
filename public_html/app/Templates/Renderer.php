<?php

declare(strict_types=1);

namespace WebAtze\Templates;

use RuntimeException;

/**
 * Macht aus einem Abschnitt fertiges HTML.
 *
 * Je Typ gibt es genau eine Vorlage. Wie sie aussieht, entscheiden die
 * Merkmale der gewählten Variante – sie werden zu CSS-Klassen.
 *
 * Sicherheit: Inhalte stammen von der KI oder vom Kunden und gelten
 * grundsätzlich als unsicher. Jede Ausgabe läuft durch e(); nur bei
 * Fliesstext ist eine kleine, fest umrissene Auszeichnung erlaubt
 * (Absätze, fett, kursiv, Listen, Verweise) – alles andere wird entfernt.
 */
final class Renderer
{
    /**
     * Einen Abschnitt rendern.
     *
     * @param array $section  type, template_key, content, overrides
     * @param array $context  Angaben zur Website: locale, nav, brand, assets
     */
    public static function section(array $section, array $context = []): string
    {
        $type = (string) ($section['type'] ?? '');
        $key = (string) ($section['template_key'] ?? '');

        if (!Schema::exists($type)) {
            return '';
        }
        if (!Catalog::exists($type, $key)) {
            $key = Catalog::defaultKey($type);
        }

        $file = __DIR__ . '/sections/' . $type . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Keine Vorlage für den Abschnittstyp: ' . $type);
        }

        $content = is_array($section['content'] ?? null) ? $section['content'] : [];
        $overrides = is_array($section['overrides'] ?? null) ? $section['overrides'] : [];

        $classes = self::classes($type, $key, $overrides);
        $id = self::anchorId($type, (int) ($section['id'] ?? 0), $content);
        $style = self::inlineStyle($overrides);

        $render = static function (string $__file, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            include $__file;
            return (string) ob_get_clean();
        };

        return $render($file, [
            'c' => $content,
            'ctx' => $context,
            'classes' => $classes,
            'sectionId' => $id,
            'style' => $style,
            'variant' => $key,
            'sectionDbId' => (int) ($section['id'] ?? 0),
        ]);
    }

    /** Alle CSS-Klassen eines Abschnitts. */
    public static function classes(string $type, string $key, array $overrides = []): string
    {
        $base = 's-' . $type;
        $mods = Catalog::modifiers($type, $key);

        $classes = [$base];

        // Jedes Merkmal erscheint zweimal:
        //   s-hero--dark  für Feinheiten, die nur diesen Typ betreffen
        //   m-dark        für alles, was in jedem Abschnitt gleich wirkt
        //
        // Dadurch braucht "dunkle Fläche" genau eine CSS-Regel statt
        // sechzehn – und typabhängige Ausnahmen bleiben trotzdem möglich.
        foreach (preg_split('/\s+/', trim($mods)) ?: [] as $mod) {
            if ($mod === '') {
                continue;
            }
            $classes[] = $base . '--' . $mod;
            $classes[] = 'm-' . $mod;
        }

        // Abstände lassen sich je Abschnitt einstellen.
        $spacing = (string) ($overrides['spacing'] ?? '');
        if (in_array($spacing, ['tight', 'normal', 'loose'], true)) {
            $classes[] = 'sp-' . $spacing;
        }

        if (!empty($overrides['invert'])) {
            $classes[] = 'is-inverted';
        }

        return implode(' ', $classes);
    }

    /**
     * Werte, die je Bildschirmgrösse anders sein dürfen.
     *
     * Sie kommen als CSS-Variablen ins style-Attribut. Die Vererbung
     * Desktop → Tablet → Mobil macht das Stylesheet: kleinere Bildschirme
     * überschreiben nur, was ausdrücklich für sie gesetzt wurde.
     */
    public static function inlineStyle(array $overrides): string
    {
        $parts = [];

        $allowed = [
            'align' => '--s-align',
            'maxWidth' => '--s-max-width',
            'gap' => '--s-gap',
            'columns' => '--s-cols',
            'radius' => '--s-radius',
            'titleSize' => '--s-title-size',
            'ctaScale' => '--s-cta-scale',
            'padding' => '--s-pad',
            'imageRatio' => '--s-ratio',
        ];

        foreach (['desktop' => '', 'tablet' => '-t', 'mobile' => '-m'] as $breakpoint => $suffix) {
            $values = $overrides[$breakpoint] ?? [];
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $name => $value) {
                if (!isset($allowed[$name])) {
                    continue;
                }
                $clean = self::cssValue((string) $value);
                if ($clean !== '') {
                    $parts[] = $allowed[$name] . $suffix . ':' . $clean;
                }
            }
        }

        return $parts === [] ? '' : implode(';', $parts);
    }

    /**
     * CSS-Wert entschärfen.
     *
     * Nur Zahlen, Einheiten, Farben und einfache Schlüsselwörter. Alles,
     * was eine Adresse aufrufen oder Code einschleusen könnte, fällt weg.
     */
    public static function cssValue(string $value): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 60) {
            return '';
        }
        if (preg_match('/[;{}()<>"\'\\\\]|url|expression|javascript|@import/i', $value)) {
            return '';
        }
        if (!preg_match('/^[a-zA-Z0-9#%.,\/ _-]+$/', $value)) {
            return '';
        }
        return $value;
    }

    /** Sprungmarke für die Navigation und den Bearbeitungsbereich. */
    private static function anchorId(string $type, int $id, array $content): string
    {
        $title = (string) ($content['title'] ?? '');
        if ($title !== '') {
            return str_slug($title, 40);
        }
        return $type . ($id > 0 ? '-' . $id : '');
    }

    // ------------------------------------------------------------------
    // Helfer für die Vorlagen
    // ------------------------------------------------------------------

    /**
     * Ein Bild ausgeben – immer mit Grössenangabe, damit beim Laden
     * nichts springt, und immer mit Beschreibung für Screenreader.
     */
    public static function image(mixed $image, string $class = '', array $options = []): string
    {
        $data = is_array($image) ? $image : ['src' => (string) $image];

        $src = (string) ($data['src'] ?? '');
        if ($src === '') {
            return self::placeholder($class, $options);
        }

        $alt = (string) ($data['alt'] ?? '');
        $width = (int) ($data['width'] ?? 0);
        $height = (int) ($data['height'] ?? 0);
        $lazy = $options['eager'] ?? false ? 'eager' : 'lazy';

        $attributes = [
            'src="' . e($src) . '"',
            'alt="' . e($alt) . '"',
            'loading="' . $lazy . '"',
            'decoding="async"',
        ];

        if ($width > 0 && $height > 0) {
            $attributes[] = 'width="' . $width . '"';
            $attributes[] = 'height="' . $height . '"';
        }
        if ($class !== '') {
            $attributes[] = 'class="' . e($class) . '"';
        }
        if (($options['eager'] ?? false) === true) {
            $attributes[] = 'fetchpriority="high"';
        }

        return '<img ' . implode(' ', $attributes) . '>';
    }

    /** Platzhalter, solange kein Bild hinterlegt ist. */
    public static function placeholder(string $class = '', array $options = []): string
    {
        $label = (string) ($options['label'] ?? '');

        return '<div class="s-media-placeholder ' . e($class) . '" aria-hidden="true">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">'
            . '<rect x="3" y="3" width="18" height="18" rx="2"/>'
            . '<circle cx="8.5" cy="8.5" r="1.6"/>'
            . '<path d="m21 15-4.5-4.5L7 20"/>'
            . '</svg>'
            . ($label !== '' ? '<span>' . e($label) . '</span>' : '')
            . '</div>';
    }

    /** Eine Schaltfläche oder ein Verweis. */
    public static function link(mixed $link, string $class = 's-btn'): string
    {
        if (!is_array($link)) {
            return '';
        }

        $label = trim((string) ($link['label'] ?? ''));
        $url = trim((string) ($link['url'] ?? ''));

        if ($label === '') {
            return '';
        }
        if ($url === '') {
            $url = '#';
        }

        $external = (bool) preg_match('#^https?://#i', $url);
        $rel = $external ? ' target="_blank" rel="noopener noreferrer"' : '';

        return '<a class="' . e($class) . '" href="' . e(self::safeUrl($url)) . '"' . $rel . '>'
            . e($label) . '</a>';
    }

    /**
     * Adresse entschärfen.
     * javascript:, data: und Ähnliches wird nie ausgegeben.
     */
    public static function safeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '#';
        }
        if (preg_match('#^(https?:|mailto:|tel:|/|\#|\./)#i', $url)) {
            return $url;
        }
        // Ohne erkennbares Schema ein seiteninterner Verweis – und der
        // bleibt relativ. Eine erzeugte Website besteht aus flachen
        // Dateien in einem Verzeichnis; relative Verweise funktionieren
        // deshalb von jeder Seite aus. Ein vorangestelltes "/" würde sie
        // an die Wurzel der Domain binden und damit überall dort brechen,
        // wo die Seite nicht dort liegt: in der Vorschau unter
        // /vorschau/<kennung>/ und bei jeder Installation im Unterordner.
        if (preg_match('/^[a-zA-Z0-9._~\/-]+$/', $url)) {
            return ltrim($url, '/');
        }
        return '#';
    }

    /**
     * Fliesstext mit einfacher Auszeichnung ausgeben.
     *
     * Erlaubt sind nur Absätze, Zeilenumbrüche, fett, kursiv, Listen,
     * Überschriften und Verweise. Alles andere wird zu reinem Text –
     * so kann selbst ein manipulierter Inhalt kein Skript einschleusen.
     */
    public static function richtext(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $allowed = '<p><br><strong><b><em><i><ul><ol><li><h3><h4><a>';
        $clean = strip_tags($text, $allowed);

        // Ereignis-Attribute und gefährliche Adressen entfernen
        $clean = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? $clean;
        $clean = preg_replace_callback(
            '/href\s*=\s*("([^"]*)"|\'([^\']*)\')/i',
            static function (array $m): string {
                $url = $m[2] !== '' ? $m[2] : ($m[3] ?? '');
                return 'href="' . htmlspecialchars(self::safeUrl($url), ENT_QUOTES, 'UTF-8') . '"';
            },
            $clean
        ) ?? $clean;

        // Reiner Text ohne Auszeichnung: Absätze aus Leerzeilen bilden
        if (!str_contains($clean, '<')) {
            $paragraphs = preg_split('/\n{2,}/', $clean) ?: [];
            $html = '';
            foreach ($paragraphs as $paragraph) {
                $paragraph = trim($paragraph);
                if ($paragraph !== '') {
                    $html .= '<p>' . nl2br(e($paragraph)) . '</p>';
                }
            }
            return $html;
        }

        return $clean;
    }

    /** Sinnbild aus dem festen Vorrat. Unbekannte Namen liefern nichts. */
    public static function icon(string $name, string $class = 's-icon'): string
    {
        $paths = Icons::paths();
        $path = $paths[$name] ?? '';

        if ($path === '') {
            return '';
        }

        return '<svg class="' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . $path . '</svg>';
    }

    /**
     * Der wiederkehrende Kopf eines Abschnitts: Überzeile, Überschrift,
     * Einleitung. Steht in fast jedem Abschnitt und wird deshalb hier
     * einmal gebaut statt sechzehnmal abgeschrieben.
     */
    public static function head(array $content): string
    {
        $eyebrow = self::value($content, 'eyebrow');
        $title = self::value($content, 'title');
        $lead = self::value($content, 'lead');

        if ($eyebrow === '' && $title === '' && $lead === '') {
            return '';
        }

        $html = '<div class="s-head">';
        if ($eyebrow !== '') {
            $html .= '<p class="s-eyebrow">' . e($eyebrow) . '</p>';
        }
        if ($title !== '') {
            $html .= '<h2 class="s-title">' . e($title) . '</h2>';
        }
        if ($lead !== '') {
            $html .= '<p class="s-lead">' . e($lead) . '</p>';
        }
        return $html . '</div>';
    }

    /** Eine Liste aus dem Inhalt holen, immer als Array. */
    public static function items(array $content, string $key = 'items'): array
    {
        $items = $content[$key] ?? [];
        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    /** Einen Textwert holen. */
    public static function value(array $content, string $key, string $default = ''): string
    {
        $value = $content[$key] ?? $default;
        return is_scalar($value) ? (string) $value : $default;
    }
}
