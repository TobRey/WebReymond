<?php
declare(strict_types=1);

/**
 * Dateibasierter Speicher für Räume - bewusst ohne Datenbank.
 * Jeder Raum liegt als JSON-Datei unter data/rooms/CODE.json.
 * Schreibzugriffe laufen immer über eine exklusive Dateisperre.
 */
final class Store
{
    private string $dir;

    /** Räume, die länger als diese Zeit inaktiv sind, werden aufgeräumt. */
    private const TTL_SECONDS = 6 * 3600;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? dirname(__DIR__) . '/data/rooms';
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    public function exists(string $code): bool
    {
        return is_file($this->path($code));
    }

    /** @return array<string, mixed>|null */
    public function read(string $code): ?array
    {
        $file = $this->path($code);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Liest den Raum, übergibt ihn an $fn und speichert das Ergebnis atomar.
     * $fn gibt entweder den neuen Raum-Zustand oder null (keine Aenderung) zurück.
     *
     * @param callable(array<string, mixed>): (array<string, mixed>|null) $fn
     * @return array<string, mixed>|null Der gespeicherte bzw. unveränderte Raum.
     */
    public function mutate(string $code, callable $fn): ?array
    {
        $file = $this->path($code);
        if (!is_file($file)) {
            return null;
        }
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            return null;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return null;
            }
            $raw = stream_get_contents($handle);
            $room = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            if (!is_array($room)) {
                return null;
            }
            $updated = $fn($room);
            if ($updated === null) {
                return $room;
            }
            $updated['version'] = (int) ($room['version'] ?? 0) + 1;
            $updated['updatedAt'] = time();
            $json = json_encode($updated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return $room;
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $json);
            fflush($handle);
            return $updated;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param array<string, mixed> $room */
    public function create(array $room): bool
    {
        $file = $this->path((string) $room['code']);
        if (is_file($file)) {
            return false;
        }
        $json = json_encode($room, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /** Entfernt abgelaufene Räume (wird gelegentlich beim Erstellen aufgerufen). */
    public function gc(): void
    {
        $files = glob($this->dir . '/*.json') ?: [];
        $limit = time() - self::TTL_SECONDS;
        foreach ($files as $file) {
            if (@filemtime($file) < $limit) {
                @unlink($file);
            }
        }
        foreach (glob($this->dir . '/*.tmp') ?: [] as $tmp) {
            if (@filemtime($tmp) < time() - 300) {
                @unlink($tmp);
            }
        }
    }

    private function path(string $code): string
    {
        return $this->dir . '/' . strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? '') . '.json';
    }
}
