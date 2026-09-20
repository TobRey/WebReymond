<?php

declare(strict_types=1);

namespace SkyKingdoms\Game\Combat;

use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Audit;
use SkyKingdoms\Core\Ids;
use SkyKingdoms\Core\Logger;
use SkyKingdoms\Core\Num;
use SkyKingdoms\Core\RateLimit;
use SkyKingdoms\Game\Alliance;
use SkyKingdoms\Game\Economy;
use SkyKingdoms\Game\Logistics;
use SkyKingdoms\Game\Player;
use SkyKingdoms\Game\Ranking;
use SkyKingdoms\Game\World;

/**
 * Angriffe: Gegnersuche, Missionsstart, serverseitige Auswertung, Berichte.
 *
 * Wichtig für die Fairness:
 *  - Der Browser schickt nur das Protokoll der Entscheidungen. Das Ergebnis
 *    rechnet ausschliesslich der Server – gemeldete Werte werden ignoriert und
 *    bei starker Abweichung protokolliert.
 *  - Kein Konto kann dauerhaft zerstört werden: Gebäude werden nur beschädigt
 *    und lassen sich sofort gegen Rohstoffe reparieren.
 *  - Neulinge, frisch Angegriffene und Allianzmitglieder sind geschützt.
 */
final class Battle
{
    private static function key(string $id): string
    {
        return 'attacks/' . preg_replace('/[^a-f0-9]/', '', $id) . '.json';
    }

    // =================================================================
    // Gegnersuche
    // =================================================================

    /** @return array<int,array<mixed>> */
    public static function targets(string $uid): array
    {
        $card    = Player::publicCard($uid);
        $score   = (int) ($card['score'] ?? 0);
        $targets = Ranking::targetsFor($uid, $score);

        $out = [];
        foreach ($targets as $target) {
            if (Alliance::sameAlliance($uid, (string) $target['id'])) {
                continue;
            }
            $out[] = [
                'id'      => (string) $target['id'],
                'name'    => (string) $target['name'],
                'score'   => (int) $target['score'],
                'level'   => (int) $target['level'],
                'islands' => (int) ($target['islands'] ?? 0),
                'army'    => (int) ($target['army'] ?? 0),
                'last_seen' => (int) ($target['last_seen'] ?? 0),
            ];
        }

        return $out;
    }

    // =================================================================
    // Angriff starten
    // =================================================================

    /**
     * Mission vorbereiten. Gibt den Aufbau für die Darstellung zurück.
     *
     * @param array<string,int> $squad
     */
    public static function start(string $uid, string $targetId, string $missionKey, array $squad): array
    {
        if ($uid === $targetId) {
            return ['ok' => false, 'error' => 'Du kannst dich nicht selbst angreifen.'];
        }
        if (!is_array(App::balance('missions.' . $missionKey))) {
            return ['ok' => false, 'error' => 'Dieses Missionsziel gibt es nicht.'];
        }
        if (!Player::exists($targetId)) {
            return ['ok' => false, 'error' => 'Dieses Königreich gibt es nicht.'];
        }
        if (Alliance::sameAlliance($uid, $targetId)) {
            return ['ok' => false, 'error' => 'Allianzmitglieder greift man nicht an.'];
        }

        $limit = RateLimit::attempt('attack', RateLimit::identity($uid));
        if (!$limit['allowed']) {
            return ['ok' => false, 'error' => 'Deine Truppen brauchen eine Pause. Versuch es in ' . Num::duration($limit['retry_after']) . ' erneut.'];
        }

        $defenderAccount = Player::account($targetId);
        $defenderWorld   = Player::world($targetId);
        if ($defenderAccount === null || $defenderWorld === null) {
            return ['ok' => false, 'error' => 'Dieses Königreich gibt es nicht.'];
        }
        if (Ranking::isProtected($defenderAccount, $defenderWorld)) {
            return ['ok' => false, 'error' => 'Dieses Königreich steht unter Schutz.'];
        }

        $maxRaids = max(1, (int) App::config('attacks_per_defender', 3));
        if (self::raidsToday($defenderWorld, $uid) >= $maxRaids) {
            return ['ok' => false, 'error' => 'Du hast dieses Königreich heute schon ' . $maxRaids . '-mal angegriffen.'];
        }

        $attackerWorld = Player::world($uid);
        if ($attackerWorld === null) {
            return ['ok' => false, 'error' => 'Dein Königreich fehlt.'];
        }

        $units = Mission::buildSquad($attackerWorld, $squad);
        if ($units === []) {
            return ['ok' => false, 'error' => 'Wähle mindestens eine Einheit für den Angriff.'];
        }

        $seed  = random_int(1, 2147483646);
        $setup = Mission::setup($defenderWorld, $missionKey, $seed);
        $id    = Ids::generate(6);

        App::store()->write(self::key($id), [
            'id'        => $id,
            'attacker'  => $uid,
            'defender'  => $targetId,
            'mission'   => $missionKey,
            'seed'      => $seed,
            'setup'     => $setup,
            'squad'     => self::squadCounts($units),
            'units'     => $units,
            'created'   => time(),
            'status'    => 'open',
        ]);

        return [
            'ok'       => true,
            'attack'   => $id,
            'seed'     => $seed,
            'setup'    => self::publicSetup($setup),
            'units'    => $units,
            'mission'  => (array) App::balance('missions.' . $missionKey),
            'defender' => [
                'id'   => $targetId,
                'name' => (string) $defenderAccount['username'],
            ],
        ];
    }

    // =================================================================
    // Angriff auswerten
    // =================================================================

    /**
     * Mission abschliessen: Der Server rechnet die Entscheidungen nach.
     *
     * @param array<int,array<mixed>> $actions
     */
    public static function finish(string $uid, string $attackId, array $actions, ?array $clientResult = null): array
    {
        $attack = App::store()->read(self::key($attackId));
        if ($attack === null || (string) $attack['attacker'] !== $uid) {
            return ['ok' => false, 'error' => 'Diesen Angriff gibt es nicht.'];
        }
        if (($attack['status'] ?? '') !== 'open') {
            return ['ok' => false, 'error' => 'Dieser Angriff wurde bereits ausgewertet.'];
        }
        if (time() - (int) $attack['created'] > 1800) {
            App::store()->update(self::key($attackId), static function (array $doc): array {
                $doc['status'] = 'expired';

                return $doc;
            });

            return ['ok' => false, 'error' => 'Der Angriff ist verfallen – er dauerte zu lange.'];
        }

        // Nur ein einziges Mal auswerten (Sperre gegen Doppelabgabe).
        $claimed = App::store()->update(self::key($attackId), static function (array $doc): ?array {
            if (($doc['status'] ?? '') !== 'open') {
                return null;
            }
            $doc['status'] = 'resolving';

            return $doc;
        });
        if ($claimed === null) {
            return ['ok' => false, 'error' => 'Dieser Angriff wird bereits ausgewertet.'];
        }

        // --- Nachrechnen ------------------------------------------------
        $result = Mission::run((array) $attack['setup'], (array) $attack['units'], $actions);

        if ($clientResult !== null) {
            $claimedProgress = (float) ($clientResult['progress'] ?? 0);
            if (abs($claimedProgress - $result['progress']) > 0.25) {
                Logger::suspicious('Kampfergebnis weicht stark ab', [
                    'attack'   => $attackId,
                    'server'   => $result['progress'],
                    'client'   => $claimedProgress,
                ]);
            }
        }

        $defenderId = (string) $attack['defender'];
        $missionKey = (string) $attack['mission'];
        $mission    = (array) App::balance('missions.' . $missionKey, []);

        // --- Wirkung beim Verteidiger ------------------------------------
        $loot    = [];
        $damaged = [];

        $defenderOutcome = Player::withWorld($defenderId, static function (array $world) use ($result, $mission, $missionKey, $uid, &$loot, &$damaged): array {
            // Angriffszähler führen (Schutz vor Dauerbeschuss)
            $raids = (array) ($world['flags']['raids'] ?? []);
            $raids[$uid][] = time();
            foreach ($raids as $attacker => $times) {
                $raids[$attacker] = array_values(array_filter($times, static fn ($t): bool => (int) $t > time() - 86400));
                if ($raids[$attacker] === []) {
                    unset($raids[$attacker]);
                }
            }
            $world['flags']['raids'] = $raids;

            if (!$result['success']) {
                $world['stats']['defenses_won'] = (int) ($world['stats']['defenses_won'] ?? 0) + 1;

                return ['ok' => true, 'world' => $world];
            }

            $world['stats']['defenses_lost'] = (int) ($world['stats']['defenses_lost'] ?? 0) + 1;
            $world['flags']['shield_until']  = time() + max(600, (int) App::config('shield_after_loss', 10800));

            $ratio = (float) ($mission['loot'] ?? 0.1) * (float) $result['progress'];
            $cap   = (float) App::balance('combat.loot_cap_ratio', 0.25);
            $ratio = $ratio > $cap ? $cap : $ratio;

            // --- Beute je nach Missionsziel -------------------------------
            $target = (string) ($mission['target'] ?? 'storage');

            if ($target === 'storage' || $target === 'intel') {
                foreach ((array) ($world['store'] ?? []) as $resource => $amount) {
                    $take = (int) floor((float) $amount * $ratio);
                    if ($take > 0) {
                        $loot[$resource] = $take;
                    }
                }
            } elseif ($target === 'route') {
                // Konvoi überfallen: Beute aus dem Warenfluss der letzten Minuten
                $plan = Logistics::plan($world);
                foreach ($plan['routes'] as $route) {
                    if (!$route['ok'] || $route['flow'] <= 0) {
                        continue;
                    }
                    $resource = (string) $route['resource'];
                    $take = (int) floor($route['flow'] * 600.0 * $ratio);
                    $have = (int) ($world['store'][$resource] ?? 0);
                    $take = $take > $have ? $have : $take;
                    if ($take > 0) {
                        $loot[$resource] = ($loot[$resource] ?? 0) + $take;
                    }
                }
            } elseif ($target === 'building') {
                // Mine besetzen / Aussenposten erobern: Puffer leeren und beschädigen
                $role = (string) ($mission['role'] ?? 'producer');
                foreach ($world['buildings'] as $id => $building) {
                    $def = World::buildingDef((string) $building['type']);
                    if (($def['role'] ?? '') !== $role) {
                        continue;
                    }
                    foreach ((array) ($building['out'] ?? []) as $resource => $amount) {
                        $take = (int) floor((float) $amount * 0.8);
                        if ($take > 0) {
                            $loot[$resource] = ($loot[$resource] ?? 0) + $take;
                            $world['buildings'][$id]['out'][$resource] = (float) $amount - $take;
                        }
                    }
                    $world['buildings'][$id]['damage'] = min(0.75, (float) ($building['damage'] ?? 0) + 0.3 * (float) $result['progress']);
                    $damaged[] = (string) $id;
                    break;
                }
            } elseif ($target === 'bridge') {
                $strength = (float) ($mission['damage'] ?? 0.45) * (float) $result['progress'];
                foreach ($world['bridges'] as $id => $bridge) {
                    $world['bridges'][$id]['damage'] = min(0.8, (float) ($bridge['damage'] ?? 0) + $strength);
                    $damaged[] = (string) $id;
                    break;
                }
            }

            if ($loot !== []) {
                Economy::take($world, $loot);
                $world['stats']['lost_to_raids'] = (int) ($world['stats']['lost_to_raids'] ?? 0) + array_sum($loot);
            }

            return ['ok' => true, 'world' => $world];
        });

        unset($defenderOutcome);

        // --- Beute begrenzen: Tragfähigkeit der überlebenden Truppe -------
        $carry = (float) $result['carry'];
        $total = array_sum($loot);
        if ($total > $carry && $total > 0) {
            $factor = $carry / $total;
            foreach ($loot as $resource => $amount) {
                $loot[$resource] = (int) floor($amount * $factor);
            }
            $loot = array_filter($loot, static fn (int $v): bool => $v > 0);
        }

        // --- Wirkung beim Angreifer ---------------------------------------
        $attackerOutcome = Player::withWorld($uid, static function (array $world) use ($result, $loot): array {
            foreach ((array) $result['losses'] as $type => $count) {
                $have = (int) ($world['units'][$type] ?? 0);
                $world['units'][$type] = max(0, $have - (int) $count);
                if ($world['units'][$type] === 0) {
                    unset($world['units'][$type]);
                }
            }

            $credited = ['stored' => [], 'lost' => []];
            if ($result['success'] && $loot !== []) {
                $credited = Economy::credit($world, $loot);
                $world['stats']['attacks_won'] = (int) ($world['stats']['attacks_won'] ?? 0) + 1;
                $world['stats']['looted'] = (int) ($world['stats']['looted'] ?? 0) + array_sum($credited['stored']);
            } elseif ($result['success']) {
                $world['stats']['attacks_won'] = (int) ($world['stats']['attacks_won'] ?? 0) + 1;
            } else {
                $world['stats']['attacks_lost'] = (int) ($world['stats']['attacks_lost'] ?? 0) + 1;
            }

            return ['ok' => true, 'world' => $world, 'credited' => $credited];
        });

        $credited = (array) ($attackerOutcome['credited'] ?? ['stored' => [], 'lost' => []]);

        // --- Aufklärung ----------------------------------------------------
        $intel = null;
        if ((string) ($mission['target'] ?? '') === 'intel' && $result['success']) {
            $intel = self::intel($defenderId);
            $loot  = [];
        }

        // --- Bericht ---------------------------------------------------------
        $report = [
            'id'        => $attackId,
            'ts'        => time(),
            'mission'   => $missionKey,
            'mission_name' => (string) ($mission['name'] ?? $missionKey),
            'attacker'  => ['id' => $uid, 'name' => (string) (Player::publicCard($uid)['name'] ?? '')],
            'defender'  => ['id' => $defenderId, 'name' => (string) (Player::publicCard($defenderId)['name'] ?? '')],
            'success'   => (bool) $result['success'],
            'progress'  => (float) $result['progress'],
            'survivors' => (int) $result['survivors'],
            'losses'    => (array) $result['losses'],
            'loot'      => (array) ($credited['stored'] ?? []),
            'lost_loot' => (array) ($credited['lost'] ?? []),
            'damaged'   => $damaged,
            'intel'     => $intel,
            'replay'    => [
                'seed'    => (int) $attack['seed'],
                'setup'   => self::publicSetup((array) $attack['setup']),
                'units'   => array_map(static fn (array $u): array => ['type' => $u['type'], 'maxHp' => $u['maxHp']], (array) $attack['units']),
                'actions' => array_slice($actions, 0, 200),
            ],
        ];

        self::saveReport($uid, $report);
        self::saveReport($defenderId, $report);

        Player::notify(
            $defenderId,
            'attack',
            $result['success'] ? 'Dein Königreich wurde überfallen' : 'Angriff abgewehrt',
            ($report['attacker']['name'] ?: 'Ein Angreifer') . ' – ' . (string) ($mission['name'] ?? ''),
            ['report' => $attackId]
        );

        App::store()->update(self::key($attackId), static function (array $doc) use ($result): array {
            $doc['status'] = 'done';
            $doc['result'] = ['success' => $result['success'], 'progress' => $result['progress']];
            $doc['closed'] = time();
            unset($doc['units']);

            return $doc;
        });

        Audit::log('battle.finish', 'Angriff ausgewertet', [
            'attack'   => $attackId,
            'defender' => $defenderId,
            'success'  => $result['success'],
        ], $uid);

        return [
            'ok'     => true,
            'result' => [
                'success'   => (bool) $result['success'],
                'reason'    => (string) $result['reason'],
                'progress'  => (float) $result['progress'],
                'survivors' => (int) $result['survivors'],
                'losses'    => (array) $result['losses'],
                'ticks'     => (int) $result['ticks'],
            ],
            'loot'   => (array) ($credited['stored'] ?? []),
            'lost'   => (array) ($credited['lost'] ?? []),
            'intel'  => $intel,
            'report' => $attackId,
        ];
    }

    // =================================================================
    // Berichte
    // =================================================================

    private static function saveReport(string $uid, array $report): void
    {
        $dir = Player::dir($uid) . '/reports';
        App::store()->write($dir . '/' . $report['id'] . '.json', $report);

        // Nur die letzten N Berichte behalten
        $max   = max(5, (int) App::config('max_reports', 50));
        $files = App::store()->listFiles($dir);
        if (count($files) <= $max) {
            return;
        }

        $entries = [];
        foreach ($files as $file) {
            $path = App::store()->path($dir . '/' . $file);
            $entries[$file] = is_file($path) ? (int) filemtime($path) : 0;
        }
        asort($entries);
        $remove = array_slice(array_keys($entries), 0, count($entries) - $max);
        foreach ($remove as $file) {
            App::store()->delete($dir . '/' . $file);
        }
    }

    /** @return array<int,array<mixed>> */
    public static function reports(string $uid, int $limit = 20, int $offset = 0): array
    {
        $dir   = Player::dir($uid) . '/reports';
        $files = App::store()->listFiles($dir);

        $entries = [];
        foreach ($files as $file) {
            $report = App::store()->read($dir . '/' . $file);
            if ($report === null) {
                continue;
            }
            unset($report['replay']);
            $entries[] = $report;
        }

        usort($entries, static fn (array $a, array $b): int => (int) $b['ts'] <=> (int) $a['ts']);

        return array_slice($entries, $offset, $limit);
    }

    public static function report(string $uid, string $id): ?array
    {
        if (!Ids::isValid($id)) {
            return null;
        }

        return App::store()->read(Player::dir($uid) . '/reports/' . $id . '.json');
    }

    // =================================================================
    // Hilfen
    // =================================================================

    /** Aufbau ohne interne Werte (für den Browser). */
    private static function publicSetup(array $setup): array
    {
        return [
            'mission'  => (string) $setup['mission'],
            'duration' => (int) $setup['duration'],
            'towers'   => array_map(static fn (array $t): array => [
                'lane' => (int) $t['lane'], 'x' => round((float) $t['x'], 3), 'y' => round((float) $t['y'], 3),
                'range' => round((float) $t['range'], 3), 'reload' => (int) $t['reload'],
            ], (array) $setup['towers']),
            'walls'    => array_map(static fn (array $w): array => [
                'lane' => (int) $w['lane'], 'x' => round((float) $w['x'], 3), 'hp' => round((float) $w['hp'], 1),
            ], (array) $setup['walls']),
            'patrols'  => array_map(static fn (array $p): array => [
                'lane' => (int) $p['lane'], 'x' => round((float) $p['x'], 3), 'range' => (float) $p['range'],
                'speed' => (float) $p['speed'], 'dir' => (float) $p['dir'], 'hp' => round((float) $p['hp'], 1),
            ], (array) $setup['patrols']),
        ];
    }

    private static function squadCounts(array $units): array
    {
        $counts = [];
        foreach ($units as $unit) {
            $counts[$unit['type']] = ($counts[$unit['type']] ?? 0) + 1;
        }

        return $counts;
    }

    private static function raidsToday(array $defenderWorld, string $attackerId): int
    {
        $raids = (array) ($defenderWorld['flags']['raids'][$attackerId] ?? []);
        $count = 0;
        foreach ($raids as $ts) {
            if ((int) $ts > time() - 86400) {
                $count++;
            }
        }

        return $count;
    }

    /** Ergebnis einer Spionagemission. */
    private static function intel(string $defenderId): array
    {
        $world = Player::world($defenderId) ?? [];
        $defense = 0;
        $towers  = 0;
        foreach ((array) ($world['buildings'] ?? []) as $building) {
            $def = World::buildingDef((string) $building['type']);
            if (($def['role'] ?? '') === 'defense') {
                $defense++;
                if ($building['type'] === 'tower') {
                    $towers++;
                }
            }
        }

        return [
            'store'   => array_map('intval', (array) ($world['store'] ?? [])),
            'units'   => array_map('intval', (array) ($world['units'] ?? [])),
            'defense' => ['buildings' => $defense, 'towers' => $towers],
            'islands' => count((array) ($world['islands'] ?? [])),
            'routes'  => count((array) ($world['routes'] ?? [])),
        ];
    }
}
