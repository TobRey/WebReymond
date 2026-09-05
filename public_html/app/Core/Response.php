<?php

declare(strict_types=1);

namespace WebAtze\Core;

/**
 * Die Antwort an den Browser.
 *
 * Sicherheitsheader werden hier zentral gesetzt – nicht verstreut im Code,
 * damit keine Seite versehentlich ohne sie ausgeliefert wird.
 */
final class Response
{
    private int $status = 200;
    private array $headers = [];
    private string $body = '';
    /** @var list<array{0:string,1:string,2:array}> */
    private array $cookies = [];

    /**
     * Eine Datei statt eines Rumpfs.
     *
     * Sie wird beim Senden in Blöcken gelesen und nicht vorher in den
     * Speicher geholt: Ein Live-Stand einer Website sprengt sonst das
     * memory_limit von geteiltem Hosting.
     */
    private ?string $filePath = null;

    public static function make(string $body = '', int $status = 200): self
    {
        $response = new self();
        $response->body = $body;
        $response->status = $status;
        return $response;
    }

    public static function html(string $html, int $status = 200): self
    {
        return self::make($html, $status)->header('Content-Type', 'text/html; charset=utf-8');
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return self::make(json_out($data), $status)
            ->header('Content-Type', 'application/json; charset=utf-8')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public static function text(string $text, int $status = 200): self
    {
        return self::make($text, $status)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return self::make('', $status)->header('Location', self::safeLocation($location));
    }

    public static function notFound(string $message = 'Seite nicht gefunden'): self
    {
        return self::make($message, 404)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * Eine Datei ausliefern.
     *
     * Der Inhalt landet bewusst nicht im Rumpf: Ein Live-Stand einer
     * Website ist leicht achtzig Megabyte, und file_get_contents()
     * darauf ist auf geteiltem Hosting mit 128 MB memory_limit das Ende
     * des Requests – ohne Fehlermeldung, die jemandem hülfe. Gesendet
     * wird stattdessen in Blöcken, direkt von der Platte.
     */
    public static function file(string $path, string $contentType, bool $download = false, string $name = ''): self
    {
        $response = self::make('', 200)
            ->header('Content-Type', $contentType)
            ->header('Content-Length', (string) (filesize($path) ?: 0));

        if ($download) {
            $safeName = preg_replace('/[^A-Za-z0-9._\-]/', '_', $name !== '' ? $name : basename($path)) ?? 'download';
            $response->header('Content-Disposition', 'attachment; filename="' . $safeName . '"');
        }

        $response->filePath = $path;

        return $response;
    }

    public function header(string $name, string $value): self
    {
        // Header-Injection verhindern: keine Zeilenumbrüche in Namen oder Werten
        $name = preg_replace('/[^A-Za-z0-9\-]/', '', $name) ?? '';
        $value = str_replace(["\r", "\n", "\0"], '', $value);
        if ($name !== '') {
            $this->headers[$name] = $value;
        }
        return $this;
    }

    public function status(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Der Rumpf.
     *
     * Bei einer Dateiantwort steht er nicht im Speicher – dann wird er
     * hier gelesen. Das ist genau der Fall, den send() vermeidet, aber
     * getBody() heisst nun einmal "gib mir den Inhalt", und ein leerer
     * String wäre eine Lüge.
     */
    public function getBody(): string
    {
        if ($this->filePath !== null && $this->body === '') {
            return (string) @file_get_contents($this->filePath);
        }

        return $this->body;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function cookie(string $name, string $value, array $options = []): self
    {
        $this->cookies[] = [$name, $value, $options];
        return $this;
    }

    /** Keine Zwischenspeicherung – für alles hinter dem Login. */
    public function noCache(): self
    {
        return $this->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
    }

    /** Nicht in Suchmaschinen aufnehmen – für /create und /vorschau. */
    public function noIndex(): self
    {
        return $this->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function send(): void
    {
        if (headers_sent()) {
            echo $this->body;
            return;
        }

        http_response_code($this->status);

        foreach (Security::baseHeaders() as $name => $value) {
            if (!isset($this->headers[$name])) {
                $this->headers[$name] = $value;
            }
        }

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }

        foreach ($this->cookies as [$name, $value, $options]) {
            setcookie($name, $value, array_merge([
                'expires' => 0,
                'path' => '/',
                'secure' => Session::cookiesShouldBeSecure(),
                'httponly' => true,
                'samesite' => 'Lax',
            ], $options));
        }

        if ($this->filePath !== null) {
            $this->stream();

            return;
        }

        echo $this->body;
    }

    /**
     * Die Datei in Blöcken hinausschicken.
     *
     * readfile() täte es auch, puffert aber je nach Konfiguration
     * ebenfalls. Acht Kilobyte am Stück kommen überall durch, und
     * zwischendurch wird geleert, damit der Browser den Fortschritt
     * sieht statt am Ende alles auf einmal.
     */
    private function stream(): void
    {
        $handle = @fopen((string) $this->filePath, 'rb');

        if ($handle === false) {
            return;
        }

        // Was noch in einem Puffer liegt, muss vorher raus - sonst
        // steht es mitten in der ZIP-Datei.
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        while (!feof($handle)) {
            $stueck = fread($handle, 8192);

            if ($stueck === false) {
                break;
            }

            echo $stueck;
            flush();
        }

        fclose($handle);
    }

    /**
     * Weiterleitungen dürfen nur innerhalb der eigenen Seite landen.
     * Sonst könnte ein Angreifer einen Link bauen, der über die eigene
     * Domain auf eine gefälschte Seite umleitet.
     */
    public static function safeLocation(string $location): string
    {
        $location = str_replace(["\r", "\n", "\0"], '', $location);

        if ($location === '' || str_starts_with($location, '//') || str_starts_with($location, '/\\')) {
            return '/';
        }
        if (str_starts_with($location, '/')) {
            return $location;
        }

        $base = rtrim((string) Config::get('app_url', ''), '/');
        if ($base !== '' && str_starts_with($location, $base)) {
            return $location;
        }
        return '/';
    }
}
