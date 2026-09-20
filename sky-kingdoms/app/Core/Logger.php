<?php

declare(strict_types=1);

namespace SkyKingdoms\Core;

/**
 * Einfaches Protokoll in storage/logs. Bewusst unabhängig vom Datenspeicher,
 * damit auch Fehler vor oder während der Installation festgehalten werden.
 */
final class Logger
{
    public static function error(string $message, array $context = []): void
    {
        self::write('FEHLER', $message, $context);
    }

    public static function warn(string $message, array $context = []): void
    {
        self::write('WARNUNG', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    /** Verdächtige Aktionen landen zusätzlich im Audit-Log des Adminbereichs. */
    public static function suspicious(string $message, array $context = []): void
    {
        self::write('VERDACHT', $message, $context);
        try {
            Audit::log('security.suspicious', $message, $context);
        } catch (\Throwable) {
            // Protokollierung darf niemals die Anfrage abbrechen.
        }
    }

    private static function write(string $level, string $message, array $context): void
    {
        try {
            $dir = SK_ROOT . '/storage/logs';
            if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
                return;
            }

            $line = sprintf(
                "[%s] %s %s%s\n",
                date('Y-m-d H:i:s'),
                $level,
                str_replace(["\n", "\r"], ' ', $message),
                $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );

            // Endung .php plus exit-Wächter: Protokolle sind auch ohne .htaccess
            // nicht über den Browser lesbar.
            $file = $dir . '/app-' . date('Y-m-d') . '.log.php';
            if (!is_file($file)) {
                @file_put_contents($file, "<?php exit; /* Sky Kingdoms Protokoll */ ?>\n", LOCK_EX);
            }
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
            @chmod($file, 0640);
        } catch (\Throwable) {
            // Niemals eine Ausnahme aus dem Protokoll heraus werfen.
        }
    }
}
