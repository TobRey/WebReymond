<?php
declare(strict_types=1);

/**
 * Dateibasierte JSON-Ablage mit Dateisperren und atomaren Schreibvorgängen.
 *
 * - Jede Datei beginnt mit einer PHP-Sperrzeile; selbst wenn .htaccess ignoriert
 *   würde, liefert ein direkter Aufruf nur einen leeren 404.
 * - Lesen: geteilte Sperre (LOCK_SH) auf <datei>.lock
 * - Schreiben: exklusive Sperre (LOCK_EX), temporäre Datei, fsync, rename().
 */
final class Store
{
    public const GUARD = "<?php http_response_code(404); exit; ?>\n";

    /** @return resource */
    private static function lock(string $file, int $mode)
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Verzeichnis kann nicht erstellt werden.');
        }
        $h = @fopen($file . '.lock', 'c');
        if ($h === false) {
            throw new RuntimeException('Sperrdatei kann nicht geöffnet werden.');
        }
        if (!flock($h, $mode)) {
            fclose($h);
            throw new RuntimeException('Datei konnte nicht gesperrt werden.');
        }
        return $h;
    }

    private static function unlock($h): void
    {
        flock($h, LOCK_UN);
        fclose($h);
    }

    private static function load(string $file, array $default): array
    {
        clearstatcache(true, $file);
        if (!is_file($file)) {
            return $default;
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException('Datei kann nicht gelesen werden.');
        }
        return self::decode($raw);
    }

    public static function decode(string $raw): array
    {
        if (str_starts_with($raw, '<?php')) {
            $nl = strpos($raw, "\n");
            $raw = $nl === false ? '' : substr($raw, $nl + 1);
        }
        if (trim($raw) === '') {
            throw new RuntimeException('Datei ist leer oder beschädigt.');
        }
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Datei hat ein ungültiges Format.');
        }
        return $data;
    }

    public static function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function write(string $file, array $data): void
    {
        $payload = self::GUARD . self::encode($data);
        $tmp = $file . '.tmp-' . bin2hex(random_bytes(6));
        $h = @fopen($tmp, 'xb');
        if ($h === false) {
            throw new RuntimeException('Temporäre Datei kann nicht erstellt werden.');
        }
        $len = strlen($payload);
        $written = 0;
        while ($written < $len) {
            $n = fwrite($h, substr($payload, $written));
            if ($n === false || $n === 0) {
                fclose($h);
                @unlink($tmp);
                throw new RuntimeException('Schreiben fehlgeschlagen (Speicherplatz?).');
            }
            $written += $n;
        }
        fflush($h);
        if (function_exists('fsync')) {
            @fsync($h);
        }
        fclose($h);
        @chmod($tmp, 0600);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('Datei konnte nicht ersetzt werden.');
        }
    }

    public static function read(string $file, array $default = []): array
    {
        $h = self::lock($file, LOCK_SH);
        try {
            return self::load($file, $default);
        } finally {
            self::unlock($h);
        }
    }

    /**
     * Liest, verändert und schreibt eine Datei unter exklusiver Sperre.
     * $fn erhält die Daten per Referenz; geschrieben wird nur bei Änderungen.
     */
    public static function mutate(string $file, callable $fn, array $default = []): mixed
    {
        $h = self::lock($file, LOCK_EX);
        try {
            $data = self::load($file, $default);
            $before = $data;
            $result = $fn($data);
            if ($data !== $before || !is_file($file)) {
                self::write($file, $data);
            }
            return $result;
        } finally {
            self::unlock($h);
        }
    }

    /** Schreibt eine Datei vollständig neu (unter exklusiver Sperre). */
    public static function replace(string $file, array $data): void
    {
        $h = self::lock($file, LOCK_EX);
        try {
            self::write($file, $data);
        } finally {
            self::unlock($h);
        }
    }
}
