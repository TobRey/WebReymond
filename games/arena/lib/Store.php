<?php
declare(strict_types=1);

/**
 * Persistenz ohne Datenbank: der komplette Spielinhalt liegt in einer Datei.
 *
 * Die Datei heißt .php und beginnt mit einem Abbruchbefehl. Ruft jemand sie
 * direkt im Browser auf, kommt nichts zurück - auch ohne .htaccess. Das ist
 * wichtig, weil viele Upload-Werkzeuge Punkt-Dateien stillschweigend
 * überspringen.
 *
 * Schreibzugriffe laufen über eine exklusive Sperre, gespeichert wird atomar
 * über eine temporäre Datei.
 */
final class Store
{
    /** Steht vor den Daten und verhindert das direkte Auslesen im Browser. */
    public const GUARD = "<?php exit; ?>\n";

    private string $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?? dirname(__DIR__) . '/data/content.php';
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        // Ältere Installationen haben die Daten noch als content.json.
        $legacy = dirname($this->file) . '/content.json';
        if (!is_file($this->file) && is_file($legacy)) {
            $old = json_decode((string) @file_get_contents($legacy), true);
            if (is_array($old)) {
                $this->write($old);
                @unlink($legacy);
            }
        }
        if (!is_file($this->file)) {
            $seed = Defaults::content();
            $this->write($seed);
            return $seed;
        }
        $raw = self::strip(@file_get_contents($this->file));
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return Defaults::content();
        }
        return Defaults::migrate($data);
    }

    /** @param array<string, mixed> $data */
    public function write(array $data): bool
    {
        $data['updatedAt'] = time();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            return false;
        }
        $tmp = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp.php';
        if (@file_put_contents($tmp, self::GUARD . $json, LOCK_EX) === false) {
            return false;
        }
        if (!@rename($tmp, $this->file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /** Entfernt die Schutzzeile vor den eigentlichen Daten. */
    public static function strip(string|false $raw): string
    {
        if (!is_string($raw)) {
            return '';
        }
        if (str_starts_with($raw, '<?php')) {
            $pos = strpos($raw, "\n");
            return $pos === false ? '' : substr($raw, $pos + 1);
        }
        return $raw;
    }

    /**
     * Liest, verändert und speichert unter Sperre.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $fn
     * @return array<string, mixed>
     */
    public function mutate(callable $fn): array
    {
        $lock = fopen($this->file . '.lock', 'c');
        if ($lock !== false) {
            flock($lock, LOCK_EX);
        }
        try {
            $data = $fn($this->read());
            $this->write($data);
            return $data;
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }
}
