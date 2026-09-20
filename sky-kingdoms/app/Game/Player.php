<?php

declare(strict_types=1);

namespace SkyKingdoms\Game;

use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Ids;
use SkyKingdoms\Store\Lock;
use SkyKingdoms\Store\Store;
use SkyKingdoms\Store\StoreException;

/**
 * Zugriff auf alles, was zu einem Spieler gehört.
 *
 * Ablage (unterhalb von storage/data):
 *   users/<ab>/<uid>/account.json        Konto, Rollen, Einstellungen
 *   users/<ab>/<uid>/world.json          Königreich
 *   users/<ab>/<uid>/public.json         kleine öffentliche Karte (Rangliste)
 *   users/<ab>/<uid>/notifications.json  Benachrichtigungen
 *   users/<ab>/<uid>/reports/<id>.json   Kampfberichte
 *
 * Zentral ist withWorld(): jede Änderung am Königreich läuft unter
 * exklusiver Dateisperre, rechnet zuerst die vergangene Zeit nach und
 * schreibt danach atomar zurück.
 */
final class Player
{
    public const ROLE_PLAYER = 'player';
    public const ROLE_ADMIN  = 'admin';
    public const ROLE_MOD    = 'moderator';

    private static function store(): Store
    {
        return App::store();
    }

    /** Basisordner eines Spielers. */
    public static function dir(string $uid): string
    {
        if (!Ids::isValid($uid)) {
            throw new StoreException('Ungültige Spielerkennung.');
        }

        return 'users/' . Ids::shard($uid) . '/' . $uid;
    }

    // =================================================================
    // Konto
    // =================================================================

    public static function account(string $uid): ?array
    {
        return self::store()->read(self::dir($uid) . '/account.json');
    }

    public static function saveAccount(string $uid, array $account): void
    {
        self::store()->write(self::dir($uid) . '/account.json', $account);
    }

    public static function updateAccount(string $uid, callable $mutator): ?array
    {
        return self::store()->update(self::dir($uid) . '/account.json', $mutator);
    }

    public static function exists(string $uid): bool
    {
        return Ids::isValid($uid) && self::store()->exists(self::dir($uid) . '/account.json');
    }

    public static function isAdmin(?array $account): bool
    {
        return $account !== null && in_array(self::ROLE_ADMIN, (array) ($account['roles'] ?? []), true);
    }

    public static function hasRole(?array $account, string $role): bool
    {
        if ($account === null) {
            return false;
        }
        $roles = (array) ($account['roles'] ?? []);

        return in_array($role, $roles, true) || in_array(self::ROLE_ADMIN, $roles, true);
    }

    /** Ist das Konto gesperrt? Gibt den Grund zurück oder einen leeren Text. */
    public static function banReason(array $account): string
    {
        if (($account['status'] ?? 'active') !== 'banned') {
            return '';
        }
        $until = (int) ($account['banned_until'] ?? 0);
        if ($until > 0 && $until < time()) {
            return '';
        }

        return (string) ($account['ban_reason'] ?? 'Dieses Konto wurde gesperrt.');
    }

    // =================================================================
    // Namens- und E-Mail-Register (Eindeutigkeit ohne Datenbank)
    // =================================================================

    public static function usernameKey(string $username): string
    {
        return 'index/username/' . hash('sha256', mb_strtolower(trim($username))) . '.json';
    }

    public static function emailKey(string $email): string
    {
        return 'index/email/' . hash('sha256', mb_strtolower(trim($email))) . '.json';
    }

    public static function findByUsername(string $username): ?string
    {
        $entry = self::store()->read(self::usernameKey($username));

        return is_array($entry) ? (string) ($entry['uid'] ?? '') ?: null : null;
    }

    public static function findByEmail(string $email): ?string
    {
        $entry = self::store()->read(self::emailKey($email));

        return is_array($entry) ? (string) ($entry['uid'] ?? '') ?: null : null;
    }

    /** Beide Ansprüche belegen. Gibt bei Kollision den Feldnamen zurück. */
    public static function claimIdentity(string $uid, string $username, string $email): ?string
    {
        $store = self::store();

        if (!$store->claim(self::usernameKey($username), ['uid' => $uid, 'name' => $username, 'ts' => time()])) {
            return 'username';
        }
        if (!$store->claim(self::emailKey($email), ['uid' => $uid, 'ts' => time()])) {
            $store->delete(self::usernameKey($username));

            return 'email';
        }

        return null;
    }

    public static function releaseIdentity(string $username, string $email): void
    {
        $store = self::store();
        $store->delete(self::usernameKey($username));
        $store->delete(self::emailKey($email));
    }

    // =================================================================
    // Königreich
    // =================================================================

    public static function world(string $uid): ?array
    {
        $world = self::store()->read(self::dir($uid) . '/world.json');

        return $world === null ? null : self::migrate($world);
    }

    public static function saveWorld(string $uid, array $world): void
    {
        self::store()->write(self::dir($uid) . '/world.json', $world);
        self::refreshPublic($uid, $world);
    }

    /**
     * Zentrale Transaktion: Welt sperren, Zeit nachrechnen, ändern, speichern.
     *
     * Der Rückgabewert von $mutator wird durchgereicht. Gibt der Mutator
     * ['ok' => false, ...] zurück, wird die Welt NICHT gespeichert.
     *
     * @param callable(array,array):array $mutator  (welt, zusammenfassung) => ergebnis
     */
    public static function withWorld(string $uid, callable $mutator, bool $tick = true): array
    {
        $key  = self::dir($uid) . '/world.json';
        $lock = self::store()->lock($key);
        if ($lock === null) {
            return ['ok' => false, 'error' => 'Dein Königreich wird gerade bearbeitet. Bitte kurz warten.'];
        }

        try {
            self::store()->clearCache($key);
            $world = self::world($uid);
            if ($world === null) {
                return ['ok' => false, 'error' => 'Zu diesem Konto gibt es kein Königreich.'];
            }

            $summary = ['elapsed' => 0, 'simulated' => 0, 'skipped' => 0, 'gained' => [], 'notes' => [], 'segments' => 0];
            if ($tick) {
                $ticked  = Simulation::tick($world);
                $world   = $ticked['world'];
                $summary = $ticked['summary'];
            }

            $result = $mutator($world, $summary);

            $newWorld = $result['world'] ?? $world;
            $changed  = ($result['ok'] ?? false) || $tick;
            if ($changed) {
                self::store()->write($key, $newWorld);
                self::refreshPublic($uid, $newWorld);
            }

            $result['summary'] = $summary;
            $result['world']   = $newWorld;

            return $result;
        } finally {
            $lock->release();
        }
    }

    /** Nur die Zeit nachrechnen (z. B. beim Anmelden oder Laden der Karte). */
    public static function tick(string $uid): array
    {
        return self::withWorld($uid, static fn (array $world): array => ['ok' => true, 'world' => $world]);
    }

    // =================================================================
    // Öffentliche Karte (Rangliste, Gegnersuche)
    // =================================================================

    public static function publicCard(string $uid): ?array
    {
        return self::store()->read(self::dir($uid) . '/public.json');
    }

    public static function refreshPublic(string $uid, ?array $world = null): array
    {
        $world ??= self::world($uid) ?? [];
        $account = self::account($uid) ?? [];
        $score   = World::score($world);

        $card = [
            'id'           => $uid,
            'name'         => (string) ($account['username'] ?? 'Unbekannt'),
            'kingdom'      => (string) ($world['name'] ?? ''),
            'score'        => $score,
            'level'        => World::level($score),
            'alliance'     => $account['alliance'] ?? null,
            'islands'      => count((array) ($world['islands'] ?? [])),
            'buildings'    => count((array) ($world['buildings'] ?? [])),
            'army'         => array_sum(array_map('intval', (array) ($world['units'] ?? []))),
            'worth'        => Economy::netWorth($world),
            'last_seen'    => (int) ($account['last_seen'] ?? 0),
            'created_at'   => (int) ($account['created_at'] ?? 0),
            'shield_until' => (int) ($world['flags']['shield_until'] ?? 0),
            'banned'       => ($account['status'] ?? 'active') === 'banned',
            'updated_at'   => time(),
        ];

        self::store()->write(self::dir($uid) . '/public.json', $card);

        return $card;
    }

    // =================================================================
    // Benachrichtigungen
    // =================================================================

    public static function notify(string $uid, string $type, string $title, string $text = '', array $data = []): void
    {
        $key = self::dir($uid) . '/notifications.json';
        self::store()->update($key, static function (array $current) use ($type, $title, $text, $data): array {
            $items = (array) ($current['items'] ?? []);
            array_unshift($items, [
                'id'    => Ids::generate(4),
                'ts'    => time(),
                'type'  => $type,
                'title' => $title,
                'text'  => $text,
                'data'  => $data,
                'read'  => false,
            ]);

            return ['items' => array_slice($items, 0, 60)];
        }, ['items' => []]);
    }

    /** @return array<int,array<mixed>> */
    public static function notifications(string $uid): array
    {
        $doc = self::store()->read(self::dir($uid) . '/notifications.json', ['items' => []]);

        return (array) ($doc['items'] ?? []);
    }

    public static function markNotificationsRead(string $uid): void
    {
        self::store()->update(self::dir($uid) . '/notifications.json', static function (array $current): array {
            $items = (array) ($current['items'] ?? []);
            foreach ($items as $index => $item) {
                $items[$index]['read'] = true;
            }

            return ['items' => $items];
        }, ['items' => []]);
    }

    // =================================================================
    // Konto löschen
    // =================================================================

    public static function delete(string $uid): void
    {
        $account = self::account($uid);
        if ($account !== null) {
            self::releaseIdentity((string) ($account['username'] ?? ''), (string) ($account['email'] ?? ''));
        }
        self::store()->deleteTree(self::dir($uid));
        Ranking::invalidate();
    }

    // =================================================================
    // Auflisten (Adminbereich, Rangliste)
    // =================================================================

    /** @return string[] Alle Spielerkennungen (für Adminbereich und Rangliste). */
    public static function allIds(int $limit = 5000): array
    {
        $store = self::store();
        $ids   = [];

        foreach ($store->listDirs('users') as $shard) {
            foreach ($store->listDirs('users/' . $shard) as $uid) {
                $ids[] = $uid;
                if (count($ids) >= $limit) {
                    return $ids;
                }
            }
        }

        return $ids;
    }

    /** Ältere Spielstände an neue Formate anpassen. */
    private static function migrate(array $world): array
    {
        $version = (int) ($world['v'] ?? 1);

        // Fehlende Felder ergänzen (robust gegen manuelle Eingriffe).
        $world['islands']   = (array) ($world['islands'] ?? []);
        $world['buildings'] = (array) ($world['buildings'] ?? []);
        $world['bridges']   = (array) ($world['bridges'] ?? []);
        $world['routes']    = (array) ($world['routes'] ?? []);
        $world['store']     = (array) ($world['store'] ?? []);
        $world['units']     = (array) ($world['units'] ?? []);
        $world['research']  = (array) ($world['research'] ?? []);
        $world['stats']     = (array) ($world['stats'] ?? []);
        $world['flags']     = (array) ($world['flags'] ?? []);
        $world['modes']     = (array) ($world['modes'] ?? ['foot' => true]);
        $world['prestige']  = (array) ($world['prestige'] ?? ['points' => 0, 'runs' => 0]);
        $world['v']         = max(1, $version);

        return $world;
    }

    /** Sperre über mehrere Dokumente hinweg (z. B. Angriff auf einen anderen Spieler). */
    public static function lockWorld(string $uid): ?Lock
    {
        return self::store()->lock(self::dir($uid) . '/world.json');
    }
}
