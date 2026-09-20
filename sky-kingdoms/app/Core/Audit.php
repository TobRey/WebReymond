<?php

declare(strict_types=1);

namespace SkyKingdoms\Core;

/**
 * Revisionssicheres Protokoll aller administrativen und sicherheitsrelevanten
 * Vorgänge. Eine Zeile JSON je Eintrag, tageweise Dateien.
 */
final class Audit
{
    public static function log(string $action, string $message, array $context = [], ?string $actorId = null): void
    {
        if (!App::isInstalled()) {
            return;
        }

        $actorId ??= Session::userId();

        App::store()->append('logs/audit-' . date('Y-m-d') . '.jsonl', [
            'ts'      => time(),
            'action'  => $action,
            'message' => $message,
            'actor'   => $actorId,
            'ip'      => self::clientIp(),
            'context' => $context,
        ]);
    }

    /** @return array<int,array<mixed>> Neueste Einträge zuerst. */
    public static function recent(int $limit = 100, int $offset = 0, ?string $day = null): array
    {
        $day ??= date('Y-m-d');

        return App::store()->tail('logs/audit-' . $day . '.jsonl', $limit, $offset);
    }

    /** @return string[] Verfügbare Protokolltage, neueste zuerst. */
    public static function days(): array
    {
        $files = App::store()->listFiles('logs', '.jsonl');
        $days  = [];
        foreach ($files as $file) {
            if (preg_match('/audit-(\d{4}-\d{2}-\d{2})\.jsonl$/', $file, $m)) {
                $days[] = $m[1];
            }
        }
        rsort($days);

        return $days;
    }

    /** IP-Adresse der Anfrage – nur zum Protokollieren, nie als Vertrauensanker. */
    public static function clientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unbekannt';
    }
}
