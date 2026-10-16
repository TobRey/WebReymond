<?php
declare(strict_types=1);

namespace SwipeGroove\Core;

/**
 * Zentrale Konfiguration (config/config.php). Secrets bleiben ausschliesslich serverseitig.
 */
final class Config
{
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function load(string $root): ?self
    {
        $file = $root . '/config/config.php';
        if (!is_file($file)) {
            return null;
        }
        $data = require $file;
        if (!is_array($data)) {
            return null;
        }
        return new self($data);
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $cur = $this->data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return $default;
            }
            $cur = $cur[$part];
        }
        return $cur;
    }

    public function has(string $path): bool
    {
        return $this->get($path, "\0") !== "\0";
    }

    public function string(string $path, string $default = ''): string
    {
        $v = $this->get($path, $default);
        return is_scalar($v) ? (string) $v : $default;
    }

    public function bool(string $path, bool $default = false): bool
    {
        $v = $this->get($path, $default);
        return (bool) $v;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Schreibt eine Konfigurationsdatei. Wird vom Installer und vom Adminbereich genutzt.
     * @param array<string,mixed> $data
     */
    public static function write(string $root, array $data): void
    {
        $dir = $root . '/config';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $export = var_export($data, true);
        $php = "<?php\n// SwipeGroove Konfiguration – automatisch erzeugt. Enthält Geheimnisse, nicht weitergeben.\n"
            . "declare(strict_types=1);\n\nreturn " . $export . ";\n";
        $tmp = $dir . '/config.php.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, $php, LOCK_EX);
        @chmod($tmp, 0640);
        rename($tmp, $dir . '/config.php');
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($dir . '/config.php', true);
        }
    }
}
