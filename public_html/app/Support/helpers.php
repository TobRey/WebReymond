<?php

/**
 * Kleine Helfer, die überall gebraucht werden.
 * Bewusst global, damit Templates kurz und lesbar bleiben.
 */

declare(strict_types=1);

use WebAtze\Core\I18n;

if (!function_exists('e')) {
    /**
     * Text für die Ausgabe in HTML entschärfen.
     * ALLES, was aus Datenbank, Formular oder KI kommt, läuft hier durch.
     */
    function e(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('eattr')) {
    /** Wie e(), aber für Werte in HTML-Attributen ohne Anführungszeichen. */
    function eattr(mixed $value): string
    {
        return preg_replace('/[^\w\-. :\/#(),%]/u', '', (string) $value) ?? '';
    }
}

if (!function_exists('__t')) {
    /** Übersetzten Text holen: __t('nav.contact') */
    function __t(string $key, array $replace = []): string
    {
        return I18n::get($key, $replace);
    }
}

if (!function_exists('json_out')) {
    /** JSON-Ausgabe, die keine HTML-Zeichen durchlässt (schützt bei Einbettung). */
    function json_out(mixed $data): string
    {
        return json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG
                | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
        );
    }
}

if (!function_exists('str_slug')) {
    /** Aus beliebigem Text eine saubere URL-Kennung machen. */
    function str_slug(string $text, int $maxLength = 80): string
    {
        $map = [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'ý' => 'y',
        ];
        $text = strtr($text, $map);
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^a-z0-9]+/u', '-', $text) ?? '';
        $text = trim($text, '-');

        if ($text === '') {
            $text = 'seite';
        }
        if (mb_strlen($text) > $maxLength) {
            $text = rtrim(mb_substr($text, 0, $maxLength), '-');
        }
        return $text;
    }
}

if (!function_exists('random_token')) {
    /** Zufälliges, URL-taugliches Kennwort für Vorschau-Links und Ähnliches. */
    function random_token(int $bytes = 24): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}

if (!function_exists('array_get')) {
    /** Verschachtelten Wert holen: array_get($data, 'theme.colors.primary', '#000') */
    function array_get(array $array, string $path, mixed $default = null): mixed
    {
        $current = $array;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }
        return $current;
    }
}

if (!function_exists('ensure_dir')) {
    /** Verzeichnis anlegen, falls es fehlt. Gibt den Pfad zurück. */
    function ensure_dir(string $path): string
    {
        if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('Verzeichnis konnte nicht angelegt werden: ' . $path);
        }
        return $path;
    }
}

if (!function_exists('write_file_atomic')) {
    /**
     * Datei sicher schreiben: erst daneben, dann umbenennen.
     * Verhindert halb geschriebene Dateien, wenn der Server mittendrin abbricht.
     */
    function write_file_atomic(string $path, string $contents): void
    {
        ensure_dir(dirname($path));
        $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Datei konnte nicht geschrieben werden: ' . $path);
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Datei konnte nicht ersetzt werden: ' . $path);
        }
        @chmod($path, 0644);
    }
}

if (!function_exists('delete_tree')) {
    /** Verzeichnis samt Inhalt löschen. Folgt bewusst keinen Symlinks. */
    function delete_tree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
            } elseif ($item->isDir()) {
                @rmdir($item->getPathname());
            }
        }
        @rmdir($path);
    }
}

if (!function_exists('dir_size')) {
    /** Grösse eines Verzeichnisses in Bytes. */
    function dir_size(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }
        $total = 0;
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            if ($item->isFile()) {
                $total += $item->getSize();
            }
        }
        return $total;
    }
}

if (!function_exists('format_bytes')) {
    /** 1536 -> "1.5 KB" */
    function format_bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) (int) $value : number_format($value, 1)) . ' ' . $units[$i];
    }
}

if (!function_exists('View_partial')) {
    /** Vorlage innerhalb einer anderen Vorlage einbinden. */
    function View_partial(string $template, array $data = []): string
    {
        return \WebAtze\Core\View::partial($template, $data);
    }
}

if (!function_exists('wa_url')) {
    /** Adresse in der aktuellen Sprache: wa_url('contact') */
    function wa_url(string $routeKey = '', ?string $locale = null): string
    {
        return \WebAtze\Core\I18n::url($routeKey, $locale);
    }
}

if (!function_exists('is_current')) {
    /** Ist diese Adresse gerade offen? Für aria-current in der Navigation. */
    function is_current(string $path, string $currentPath): bool
    {
        return rtrim($path, '/') === rtrim($currentPath, '/');
    }
}
