<?php

declare(strict_types=1);

namespace WebAtze\Core;

/**
 * Protokoll aller Verwaltungsvorgänge.
 *
 * Jede Aktion im Adminbereich hinterlässt eine Zeile: wer, was, wann, woher.
 * Das ist die Grundlage, um im Zweifel nachvollziehen zu können, was passiert ist.
 */
final class Audit
{
    public static function log(string $action, string $subject = '', array $meta = [], ?Request $request = null): void
    {
        $user = Session::user();

        try {
            Db::insert('audit_log', [
                'user_id' => $user['id'] ?? null,
                'actor' => $user['username'] ?? 'system',
                'action' => mb_substr($action, 0, 80),
                'subject' => mb_substr($subject, 0, 190),
                'meta' => self::scrub($meta),
                'ip' => $request?->ip() ?? ($_SERVER['REMOTE_ADDR'] ?? ''),
                'created_at' => Db::now(),
            ]);
        } catch (\Throwable $e) {
            // Ein fehlendes Protokoll darf niemals die eigentliche Aktion verhindern.
            Logger::warning('Audit-Eintrag fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public static function recent(int $limit = 100, int $offset = 0): array
    {
        return Db::all(
            'SELECT * FROM audit_log ORDER BY id DESC LIMIT :lim OFFSET :off',
            ['lim' => max(1, min(500, $limit)), 'off' => max(0, $offset)]
        );
    }

    /** Geheimnisse gehören nicht ins Protokoll. */
    private static function scrub(array $meta): array
    {
        $clean = [];
        foreach ($meta as $key => $value) {
            $lower = strtolower((string) $key);
            if (preg_match('/pass|secret|token|key|hash/', $lower)) {
                $clean[$key] = '***';
            } elseif (is_array($value)) {
                $clean[$key] = self::scrub($value);
            } elseif (is_string($value)) {
                $clean[$key] = mb_substr($value, 0, 500);
            } else {
                $clean[$key] = $value;
            }
        }
        return $clean;
    }
}
