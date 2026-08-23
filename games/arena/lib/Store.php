<?php
declare(strict_types=1);

/**
 * Persistenz ohne Datenbank: der komplette Spielinhalt liegt als eine
 * JSON-Datei. Schreibzugriffe laufen ueber eine exklusive Sperre,
 * gespeichert wird atomar ueber eine temporaere Datei.
 */
final class Store
{
    private string $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?? dirname(__DIR__) . '/data/content.json';
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        if (!is_file($this->file)) {
            $seed = Defaults::content();
            $this->write($seed);
            return $seed;
        }
        $raw = @file_get_contents($this->file);
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
        $tmp = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }
        if (!@rename($tmp, $this->file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Liest, veraendert und speichert unter Sperre.
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
