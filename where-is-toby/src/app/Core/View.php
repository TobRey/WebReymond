<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Schlanke Template-Engine auf PHP-Basis mit automatischem Escaping-Helfer.
 */
final class View
{
    public function __construct(private string $viewPath)
    {
    }

    public function render(string $template, array $data = []): string
    {
        $file = $this->viewPath . '/' . str_replace(['..', "\0"], '', $template) . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Template nicht gefunden: ' . $template);
        }
        $data['view'] = $this;
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string)ob_get_clean();
    }

    public function layout(string $layout, string $content, array $data = []): string
    {
        $data['content'] = $content;
        return $this->render($layout, $data);
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Sichere Einbettung von Daten in JavaScript (JSON in <script>). */
    public static function js(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
        return $json === false ? 'null' : $json;
    }

    public static function url(string $path = '/'): string
    {
        $base = defined('WIT_BASE_PATH') ? WIT_BASE_PATH : '';
        return $base . $path;
    }

    public static function asset(string $path): string
    {
        $base = defined('WIT_BASE_PATH') ? WIT_BASE_PATH : '';
        $file = WIT_ROOT . '/assets/' . ltrim($path, '/');
        $version = is_file($file) ? substr((string)filemtime($file), -6) : WIT_VERSION;
        return $base . '/assets/' . ltrim($path, '/') . '?v=' . $version;
    }
}
