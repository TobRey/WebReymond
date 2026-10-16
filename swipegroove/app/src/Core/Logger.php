<?php
declare(strict_types=1);

namespace SwipeGroove\Core;

/**
 * Einfaches Datei-Log. Es werden niemals Tokens oder Secrets geloggt.
 */
final class Logger
{
    public function __construct(private string $dir)
    {
    }

    public function log(string $level, string $message, array $context = []): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0750, true);
        }
        $line = sprintf(
            "[%s] %s: %s %s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            strtoupper($level),
            $message,
            $context ? json_encode(self::scrub($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        @file_put_contents($this->dir . '/app-' . gmdate('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** Entfernt alles, was wie ein Token oder Schlüssel aussieht. */
    public static function scrub(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $key = strtolower((string) $k);
            if (preg_match('/token|secret|password|authorization|api_key|apikey|cookie|code_verifier/', $key)) {
                $out[$k] = '[redacted]';
            } elseif (is_array($v)) {
                $out[$k] = self::scrub($v);
            } elseif (is_string($v)) {
                $out[$k] = preg_replace('/(Bearer\s+)[A-Za-z0-9\-_\.]+/', '$1[redacted]', mb_substr($v, 0, 2000));
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
