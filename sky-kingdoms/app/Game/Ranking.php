<?php

declare(strict_types=1);

namespace SkyKingdoms\Game;

use SkyKingdoms\Core\App;
use SkyKingdoms\Store\Lock;

/**
 * Rangliste und Gegnersuche ohne Datenbank.
 *
 * Statt bei jedem Aufruf alle Spielstände zu lesen, entsteht in Abständen eine
 * kompakte Momentaufnahme aus den kleinen public.json-Dateien. Genau ein
 * Prozess baut sie neu (nicht blockierende Sperre), alle anderen lesen
 * währenddessen die vorhandene Fassung weiter.
 */
final class Ranking
{
    private const KEY   = 'index/ranking.json';
    private const LIMIT = 2000;

    /** Momentaufnahme holen und bei Bedarf erneuern. */
    public static function snapshot(bool $force = false): array
    {
        $store = App::store();
        $data  = $store->read(self::KEY, ['ts' => 0, 'entries' => [], 'total' => 0]) ?? [];
        $ttl   = max(30, (int) App::config('ranking_ttl', 600));

        if (!$force && (int) ($data['ts'] ?? 0) + $ttl > time()) {
            return $data;
        }

        $lock = Lock::acquire($store->path(self::KEY) . '.rebuild', 50);
        if ($lock === null) {
            return $data; // jemand anderes baut gerade – vorhandene Liste genügt
        }

        try {
            $fresh = $store->read(self::KEY, ['ts' => 0]) ?? [];
            if (!$force && (int) ($fresh['ts'] ?? 0) + $ttl > time()) {
                return $fresh;
            }

            $entries = [];
            foreach (Player::allIds() as $uid) {
                $card = Player::publicCard($uid);
                if ($card === null || !empty($card['banned'])) {
                    continue;
                }
                $entries[] = [
                    'id'       => (string) $card['id'],
                    'name'     => (string) $card['name'],
                    'kingdom'  => (string) ($card['kingdom'] ?? ''),
                    'score'    => (int) $card['score'],
                    'level'    => (int) $card['level'],
                    'alliance' => $card['alliance'] ?? null,
                    'islands'  => (int) ($card['islands'] ?? 0),
                    'army'     => (int) ($card['army'] ?? 0),
                    'last_seen'=> (int) ($card['last_seen'] ?? 0),
                    'shield_until' => (int) ($card['shield_until'] ?? 0),
                    'created_at'   => (int) ($card['created_at'] ?? 0),
                ];
            }

            usort($entries, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
            $total   = count($entries);
            $entries = array_slice($entries, 0, self::LIMIT);

            $data = ['ts' => time(), 'total' => $total, 'entries' => $entries];
            $store->write(self::KEY, $data);

            return $data;
        } finally {
            $lock->release();
        }
    }

    public static function invalidate(): void
    {
        try {
            App::store()->update(self::KEY, static function (array $current): array {
                $current['ts'] = 0;

                return $current;
            }, ['ts' => 0, 'entries' => [], 'total' => 0]);
        } catch (\Throwable) {
            // Halb so wild: die Liste erneuert sich ohnehin nach Ablauf.
        }
    }

    /**
     * Seite der Rangliste.
     *
     * @return array{entries:array<int,array>,page:int,pages:int,total:int,ts:int}
     */
    public static function page(int $page = 1, ?int $size = null): array
    {
        $size     = max(5, min(100, $size ?? (int) App::config('ranking_page_size', 25)));
        $snapshot = self::snapshot();
        $entries  = (array) ($snapshot['entries'] ?? []);
        $pages    = max(1, (int) ceil(count($entries) / $size));
        $page     = max(1, min($page, $pages));

        $slice = array_slice($entries, ($page - 1) * $size, $size);
        foreach ($slice as $index => $entry) {
            $slice[$index]['rank'] = ($page - 1) * $size + $index + 1;
        }

        return [
            'entries' => $slice,
            'page'    => $page,
            'pages'   => $pages,
            'total'   => (int) ($snapshot['total'] ?? count($entries)),
            'ts'      => (int) ($snapshot['ts'] ?? 0),
        ];
    }

    /** Platz eines Spielers (0 = nicht in der Liste). */
    public static function rankOf(string $uid): int
    {
        foreach ((array) (self::snapshot()['entries'] ?? []) as $index => $entry) {
            if (($entry['id'] ?? '') === $uid) {
                return $index + 1;
            }
        }

        return 0;
    }

    /**
     * Passende Angriffsziele finden: ähnlicher Punktestand, kein Schild,
     * kein Neuling, nicht man selbst.
     *
     * @return array<int,array>
     */
    public static function targetsFor(string $uid, int $score, int $limit = 12): array
    {
        $spread    = max(0.05, (float) App::config('matchmaking_spread', 0.4));
        $newbie    = max(0, (int) App::config('newbie_protection', 259200));
        $entries   = (array) (self::snapshot()['entries'] ?? []);
        $now       = time();
        $min       = (int) max(0, $score * (1 - $spread));
        $max       = (int) max(50, $score * (1 + $spread));
        $candidates = [];

        foreach ($entries as $entry) {
            if (($entry['id'] ?? '') === $uid) {
                continue;
            }
            if ((int) ($entry['shield_until'] ?? 0) > $now) {
                continue;
            }
            if ((int) ($entry['created_at'] ?? 0) + $newbie > $now) {
                continue;
            }
            $entryScore = (int) ($entry['score'] ?? 0);
            if ($entryScore < $min || $entryScore > $max) {
                continue;
            }
            $entry['distance'] = abs($entryScore - $score);
            $candidates[] = $entry;
        }

        usort($candidates, static fn (array $a, array $b): int => $a['distance'] <=> $b['distance']);

        return array_slice($candidates, 0, $limit);
    }

    /** Ist dieser Spieler noch durch den Neulingsschutz gedeckt? */
    public static function isProtected(array $account, array $world): bool
    {
        $newbie = max(0, (int) App::config('newbie_protection', 259200));
        if ((int) ($account['created_at'] ?? 0) + $newbie > time()) {
            return true;
        }

        return (int) ($world['flags']['shield_until'] ?? 0) > time();
    }
}
