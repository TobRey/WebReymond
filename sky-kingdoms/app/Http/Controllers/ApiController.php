<?php

declare(strict_types=1);

namespace SkyKingdoms\Http\Controllers;

use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Auth;
use SkyKingdoms\Core\Csrf;
use SkyKingdoms\Core\Logger;
use SkyKingdoms\Core\RateLimit;
use SkyKingdoms\Core\Response;
use SkyKingdoms\Core\Security;
use SkyKingdoms\Game\Actions;
use SkyKingdoms\Game\Economy;
use SkyKingdoms\Game\Formulas;
use SkyKingdoms\Game\Logistics;
use SkyKingdoms\Game\Player;
use SkyKingdoms\Game\Quests;
use SkyKingdoms\Game\Research;
use SkyKingdoms\Game\Simulation;
use SkyKingdoms\Game\World;

/**
 * Die JSON-Schnittstelle des Spiels.
 *
 * Grundsätze:
 *  - Lesende Aktionen per GET, verändernde ausschliesslich per POST mit CSRF-Token.
 *  - Jede verändernde Aktion läuft in Player::withWorld() unter Dateisperre:
 *    erst wird die vergangene Zeit nachgerechnet, dann die Handlung geprüft und
 *    ausgeführt, danach atomar gespeichert.
 *  - Antworten enthalten nur, was sich geändert hat – nicht die ganze Welt.
 */
final class ApiController
{
    /** Aktionen, die den Spielstand verändern (POST + CSRF + Rate-Limit). */
    private const MUTATIONS = [
        'build', 'upgrade', 'demolish', 'move', 'repair',
        'bridge_build', 'bridge_upgrade',
        'route_create', 'route_delete', 'route_carrier_add', 'route_carrier_remove',
        'route_upgrade', 'route_mode', 'route_toggle', 'mode_unlock',
        'research', 'island_unlock', 'train', 'unit_upgrade',
        'quest_claim', 'shop_buy', 'shop_sell', 'settings_save',
        'notifications_read', 'rename',
    ];

    public function handle(): void
    {
        App::setMode(App::MODE_JSON);
        Security::headers(false);

        if (!App::isInstalled()) {
            Response::fail('Das Spiel ist noch nicht installiert.', 503);
        }

        $action = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_GET['a'] ?? ''))) ?: '';
        if ($action === '') {
            Response::fail('Es wurde keine Aktion angegeben.', 400);
        }

        // Öffentlich ohne Anmeldung: nur die statischen Spieldaten.
        if ($action === 'static') {
            $this->cacheable();
            Response::ok(['data' => GameController::staticData()]);
        }

        $user = Auth::requireLogin();
        $uid  = (string) $user['id'];

        if (in_array($action, self::MUTATIONS, true)) {
            Security::requirePost();
            Csrf::verifyOrFail();

            $limit = RateLimit::attempt('api', RateLimit::identity($uid));
            if (!$limit['allowed']) {
                Response::fail('Zu viele Anfragen. Bitte einen Moment warten.', 429, ['retry_after' => $limit['retry_after']]);
            }
        }

        try {
            match ($action) {
                'state'        => $this->state($uid),
                'rates'        => $this->rates($uid),
                'preview'      => $this->preview($uid),
                'quests'       => $this->quests($uid),
                'research_list'=> $this->researchList($uid),
                'notifications'=> $this->notifications($uid),

                'build'        => $this->build($uid),
                'upgrade'      => $this->upgrade($uid),
                'demolish'     => $this->simple($uid, static fn (array $w): array => Actions::demolish($w, self::str('id'))),
                'move'         => $this->simple($uid, static fn (array $w): array => Actions::moveBuilding($w, self::str('id'), self::int('x'), self::int('y'))),
                'repair'       => $this->simple($uid, static fn (array $w): array => Actions::repair($w, self::str('id'))),

                'bridge_build'   => $this->simple($uid, static fn (array $w): array => Actions::buildBridge($w, self::str('a'), self::str('b'))),
                'bridge_upgrade' => $this->simple($uid, static fn (array $w): array => Actions::upgradeBridge($w, self::str('id'), self::steps())),

                'route_create'         => $this->routeCreate($uid),
                'route_delete'         => $this->simple($uid, static fn (array $w): array => Actions::deleteRoute($w, self::str('id'))),
                'route_carrier_add'    => $this->simple($uid, static fn (array $w): array => Actions::addCarrier($w, self::str('id'), self::int('count', 1))),
                'route_carrier_remove' => $this->simple($uid, static fn (array $w): array => Actions::removeCarrier($w, self::str('id'))),
                'route_upgrade'        => $this->simple($uid, static fn (array $w): array => Actions::upgradeRoute($w, self::str('id'), self::steps())),
                'route_mode'           => $this->simple($uid, static fn (array $w): array => Actions::setRouteMode($w, self::str('id'), self::str('mode'))),
                'route_toggle'         => $this->simple($uid, static fn (array $w): array => Actions::toggleRoute($w, self::str('id'), self::str('enabled') === '1')),
                'mode_unlock'          => $this->simple($uid, static fn (array $w): array => Actions::unlockMode($w, self::str('mode'))),

                'research'      => $this->simple($uid, static fn (array $w): array => Actions::research($w, self::str('key'), self::steps())),
                'island_unlock' => $this->simple($uid, static fn (array $w): array => Actions::unlockIsland($w, self::str('type'), self::str('name'))),
                'train'         => $this->simple($uid, static fn (array $w): array => Actions::trainUnits($w, self::str('unit'), self::int('count', 1))),
                'unit_upgrade'  => $this->simple($uid, static fn (array $w): array => Actions::upgradeUnit($w, self::str('unit'), self::steps())),

                'quest_claim'   => $this->simple($uid, static fn (array $w): array => Quests::claim($w, self::str('id'))),
                'shop_buy'      => $this->simple($uid, static fn (array $w): array => Actions::shopBuy($w, self::str('pack'))),
                'shop_sell'     => $this->simple($uid, static fn (array $w): array => Actions::shopSell($w, self::str('resource'), self::int('amount', 1))),
                'rename'        => $this->rename($uid),

                'settings_save' => $this->saveSettings($uid),
                'notifications_read' => $this->markRead($uid),

                default => (new ApiSocialController())->handle($action, $uid, $user),
            };
        } catch (\SkyKingdoms\Store\StoreException $e) {
            Logger::warn('Speicherfehler in der API', ['aktion' => $action, 'fehler' => $e->getMessage()]);
            Response::fail($e->getMessage(), 409);
        }

        Response::fail('Unbekannte Aktion.', 404);
    }

    // =================================================================
    // Lesen
    // =================================================================

    /** Vollständiger Spielstand – nur beim Laden und nach längerer Pause. */
    private function state(string $uid): never
    {
        $result = Player::withWorld($uid, static fn (array $world): array => ['ok' => true, 'world' => $world]);
        if (!($result['ok'] ?? false)) {
            Response::fail((string) ($result['error'] ?? 'Spielstand nicht verfügbar.'), 409);
        }

        $world   = $result['world'];
        $summary = (array) ($result['summary'] ?? []);
        $account = Player::account($uid) ?? [];

        Response::ok([
            'world'    => self::publicWorld($world),
            'effects'  => self::publicEffects($world),
            'rates'    => self::publicRates($world),
            'welcome'  => ($summary['simulated'] ?? 0) > 120 ? $summary : null,
            'profile'  => [
                'name'     => (string) ($account['username'] ?? ''),
                'score'    => World::score($world),
                'level'    => World::level(World::score($world)),
                'settings' => (array) ($account['settings'] ?? []),
                'admin'    => Player::isAdmin($account),
                'alliance' => $account['alliance'] ?? null,
            ],
            'quests'   => Quests::forPlayer($world),
            'unread'   => self::unreadCount($uid),
            'now'      => time(),
        ]);
    }

    /** Nur die aktuellen Raten und Engpässe (leichtgewichtig, für Aktualisierungen). */
    private function rates(string $uid): never
    {
        $result = Player::withWorld($uid, static fn (array $world): array => ['ok' => true, 'world' => $world]);
        $world  = $result['world'] ?? [];

        Response::ok([
            'store'   => (array) ($world['store'] ?? []),
            'effects' => self::publicEffects($world),
            'rates'   => self::publicRates($world),
            'unread'  => self::unreadCount($uid),
            'now'     => time(),
        ]);
    }

    /**
     * Vorschau einer Verbesserung: Kosten und Nutzen für +1, +10, +100 und MAX.
     * Wird vor dem Antippen angezeigt, damit klar ist, was passiert.
     */
    private function preview(string $uid): never
    {
        $world = Player::world($uid);
        if ($world === null) {
            Response::fail('Kein Königreich gefunden.', 404);
        }

        $type = self::str('type');
        $id   = self::str('id');

        $preview = match ($type) {
            'building' => self::previewBuilding($world, $id),
            'bridge'   => self::previewBridge($world, $id),
            'route'    => self::previewRoute($world, $id),
            'research' => self::previewResearch($world, $id),
            'unit'     => self::previewUnit($world, $id),
            default    => null,
        };

        if ($preview === null) {
            Response::fail('Für dieses Ziel gibt es keine Vorschau.', 404);
        }

        Response::ok(['preview' => $preview]);
    }

    private function quests(string $uid): never
    {
        $world = Player::world($uid) ?? [];

        Response::ok([
            'quests'       => Quests::forPlayer($world),
            'achievements' => Quests::achievementsForPlayer($world),
        ]);
    }

    private function researchList(string $uid): never
    {
        $world = Player::world($uid) ?? [];

        Response::ok([
            'research' => Research::overview($world),
            'modes'    => self::modeList($world),
        ]);
    }

    private function notifications(string $uid): never
    {
        Response::ok(['items' => Player::notifications($uid)]);
    }

    private function markRead(string $uid): never
    {
        Player::markNotificationsRead($uid);
        Response::ok(['unread' => 0]);
    }

    // =================================================================
    // Schreiben
    // =================================================================

    /** Gemeinsamer Ablauf aller einfachen Handlungen. */
    private function simple(string $uid, callable $action): never
    {
        $result = Player::withWorld($uid, static function (array $world) use ($action): array {
            $before  = $world;
            $outcome = $action($world);

            if (!($outcome['ok'] ?? false)) {
                return $outcome + ['world' => $before];
            }

            $newWorld = $outcome['world'];
            $check    = Quests::checkAchievements($newWorld);
            $outcome['world']    = $check['world'];
            $outcome['unlocked'] = $check['unlocked'];
            $outcome['before']   = $before;

            return $outcome;
        });

        self::respond($result);
    }

    private function build(string $uid): never
    {
        $island = self::str('island');
        $type   = self::str('type');
        $x      = self::int('x');
        $y      = self::int('y');

        $this->simple($uid, static fn (array $w): array => Actions::build($w, $island, $type, $x, $y));
    }

    private function upgrade(string $uid): never
    {
        $id    = self::str('id');
        $steps = self::steps();

        $this->simple($uid, static fn (array $w): array => Actions::upgradeBuilding($w, $id, $steps));
    }

    private function routeCreate(string $uid): never
    {
        $src      = self::endpoint('src');
        $dst      = self::endpoint('dst');
        $resource = self::str('resource');
        $mode     = self::str('mode') ?: 'foot';

        $this->simple($uid, static fn (array $w): array => Actions::createRoute($w, $src, $dst, $resource, $mode));
    }

    private function rename(string $uid): never
    {
        $name = Security::clean($_POST['name'] ?? '', 30);
        if (mb_strlen($name) < 2) {
            Response::fail('Der Name ist zu kurz.', 422);
        }

        $this->simple($uid, static function (array $w) use ($name): array {
            $w['name'] = $name;

            return ['ok' => true, 'world' => $w];
        });
    }

    private function saveSettings(string $uid): never
    {
        $settings = [
            'sound'      => (string) ($_POST['sound'] ?? '1') === '1',
            'music'      => (string) ($_POST['music'] ?? '0') === '1',
            'animations' => (string) ($_POST['animations'] ?? '1') === '1',
            'haptics'    => (string) ($_POST['haptics'] ?? '1') === '1',
            'quality'    => in_array($_POST['quality'] ?? 'auto', ['auto', 'high', 'medium', 'low'], true)
                ? (string) $_POST['quality'] : 'auto',
        ];

        Player::updateAccount($uid, static function (array $account) use ($settings): array {
            $account['settings'] = array_merge((array) ($account['settings'] ?? []), $settings);

            return $account;
        });

        Response::ok(['settings' => $settings]);
    }

    // =================================================================
    // Antwort aufbereiten
    // =================================================================

    /** Erfolgreiche Handlung beantworten – mit Unterschied statt ganzer Welt. */
    private static function respond(array $result): never
    {
        if (!($result['ok'] ?? false)) {
            Response::fail((string) ($result['error'] ?? 'Das hat nicht geklappt.'), 422, [
                'missing' => (array) ($result['missing'] ?? []),
            ]);
        }

        $world  = (array) $result['world'];
        $before = (array) ($result['before'] ?? []);

        $payload = [
            'patch'    => self::diff($before, $world),
            'effects'  => self::publicEffects($world),
            'rates'    => self::publicRates($world),
            'score'    => World::score($world),
            'cost'     => (array) ($result['cost'] ?? []),
            'unlocked' => (array) ($result['unlocked'] ?? []),
        ];

        foreach (['level', 'steps', 'building', 'route', 'bridge', 'island', 'units', 'carriers', 'tier', 'reward', 'received', 'sold', 'gold', 'refund', 'mode', 'enabled'] as $key) {
            if (isset($result[$key])) {
                $payload[$key] = $result[$key];
            }
        }

        Response::ok($payload);
    }

    /** Unterschied zweier Weltzustände – hält die Antworten klein. */
    private static function diff(array $before, array $after): array
    {
        $patch = ['store' => (array) ($after['store'] ?? [])];

        foreach (['buildings', 'routes', 'bridges', 'islands'] as $collection) {
            $old = (array) ($before[$collection] ?? []);
            $new = (array) ($after[$collection] ?? []);

            $changed = [];
            foreach ($new as $id => $entry) {
                if (!isset($old[$id]) || $old[$id] !== $entry) {
                    $changed[$id] = $entry;
                }
            }
            $removed = array_values(array_diff(array_keys($old), array_keys($new)));

            if ($changed !== []) {
                $patch[$collection] = $changed;
            }
            if ($removed !== []) {
                $patch['removed'][$collection] = $removed;
            }
        }

        foreach (['units', 'unit_levels', 'research', 'modes', 'quests', 'achievements', 'flags', 'name'] as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $patch[$key] = $after[$key] ?? null;
            }
        }

        return $patch;
    }

    /** Welt für den Browser aufbereiten (ohne interne Felder). */
    public static function publicWorld(array $world): array
    {
        return [
            'name'      => (string) ($world['name'] ?? ''),
            'islands'   => (array) ($world['islands'] ?? []),
            'buildings' => (array) ($world['buildings'] ?? []),
            'bridges'   => (array) ($world['bridges'] ?? []),
            'routes'    => (array) ($world['routes'] ?? []),
            'store'     => (array) ($world['store'] ?? []),
            'units'     => (array) ($world['units'] ?? []),
            'unit_levels' => (array) ($world['unit_levels'] ?? []),
            'research'  => (array) ($world['research'] ?? []),
            'modes'     => (array) ($world['modes'] ?? []),
            'stats'     => (array) ($world['stats'] ?? []),
            'flags'     => (array) ($world['flags'] ?? []),
            'prestige'  => (array) ($world['prestige'] ?? []),
            'last_tick' => (int) ($world['last_tick'] ?? time()),
        ];
    }

    public static function publicEffects(array $world): array
    {
        $effects = World::effects($world);
        $budget  = Logistics::routeBudget($world);

        return [
            'population'     => (int) $effects['population'],
            'workers'        => (int) $effects['workers_needed'],
            'routes'         => $budget,
            'carriers_max'   => (int) $effects['carriers_max'],
            'capacity'       => array_map('intval', $effects['capacity']),
            'stored'         => array_map('intval', World::storedByClass($world)),
            'army_capacity'  => (int) $effects['army_capacity'],
            'defense_hp'     => (int) $effects['defense_hp'],
            'defense_damage' => (int) $effects['defense_damage'],
            'trade_slots'    => (int) $effects['trade_slots'],
            'storage'        => Economy::storageOverview($world),
        ];
    }

    /** Aktuelle Flüsse in kompakter Form. */
    public static function publicRates(array $world): array
    {
        $snapshot = Simulation::snapshot($world);

        $routes = [];
        foreach ($snapshot['routes'] as $id => $route) {
            $routes[$id] = [
                // 'flow' ist der tatsächliche Durchsatz, 'nominal' das, was die
                // Träger bei voller Zulieferung schaffen würden.
                'flow'    => round((float) ($route['effective'] ?? $route['flow']) * 60, 2),
                'nominal' => round($route['nominal'] * 60, 2),
                'reason'  => (string) ($route['reason'] ?? ''),
                'jam'     => round($route['jam_factor'], 3),
                'ok'      => (bool) $route['ok'],
                'note'    => (string) $route['note'],
                'path'    => $route['path'],
                'trip'    => round($route['trip_time'], 1),
                'speed'   => round($route['speed'], 2),
                'capacity'=> round($route['capacity'], 1),
            ];
        }

        $bridges = [];
        foreach ($snapshot['bridges'] as $id => $bridge) {
            $bridges[$id] = [
                'capacity' => round($bridge['capacity'] * 60, 1),
                'demand'   => round($bridge['demand'] * 60, 1),
                'factor'   => round($bridge['factor'], 3),
            ];
        }

        return [
            'production'  => array_map(static fn ($v) => round((float) $v, 2), $snapshot['production']),
            'delivery'    => array_map(static fn ($v) => round((float) $v, 2), $snapshot['delivery']),
            'consumption' => array_map(static fn ($v) => round((float) $v, 2), $snapshot['consumption']),
            'routes'      => $routes,
            'bridges'     => $bridges,
            'pressure'    => round($snapshot['pressure'], 3),
            'hunger'      => (bool) $snapshot['hunger'],
            'notes'       => $snapshot['notes'],
        ];
    }

    // =================================================================
    // Vorschauen
    // =================================================================

    private static function previewBuilding(array $world, string $id): ?array
    {
        $building = $world['buildings'][$id] ?? null;
        if ($building === null) {
            return null;
        }

        $def    = World::buildingDef((string) $building['type']);
        $base   = (array) ($def['cost'] ?? []);
        $growth = (float) ($def['cost_growth'] ?? Formulas::defaultCostGrowth());
        $level  = (int) $building['level'];
        $effectGrowth = (float) ($def['effect_growth'] ?? Formulas::defaultEffectGrowth());

        $benefits = [];
        foreach ((array) ($def['produces'] ?? []) as $resource => $perMinute) {
            $benefits[] = self::benefit('Produktion ' . App::balance('resources.' . $resource . '.name', $resource),
                (float) $perMinute, $level, $effectGrowth, '/Min.');
        }
        foreach ((array) ($def['consumes'] ?? []) as $resource => $perMinute) {
            $benefits[] = self::benefit('Verbrauch ' . App::balance('resources.' . $resource . '.name', $resource),
                (float) $perMinute, $level, $effectGrowth, '/Min.');
        }
        if (isset($def['storage'])) {
            $benefits[] = self::benefit('Lagerplatz', (float) ($def['storage']['amount'] ?? 0), $level, $effectGrowth, '');
        }
        if (isset($def['buffer'])) {
            $benefits[] = self::benefit('Eigener Puffer', (float) $def['buffer'], $level, $effectGrowth, '');
        }
        foreach ((array) ($def['effects'] ?? []) as $key => $value) {
            $benefits[] = self::benefit(self::effectLabel((string) $key), (float) $value, $level, $effectGrowth, '');
        }

        return self::previewPayload($world, $base, $growth, $level, $benefits, $effectGrowth);
    }

    private static function previewBridge(array $world, string $id): ?array
    {
        $bridge = $world['bridges'][$id] ?? null;
        if ($bridge === null) {
            return null;
        }

        $growth = (float) App::balance('bridges.capacity_growth', 1.018);
        $level  = (int) $bridge['level'];

        return self::previewPayload(
            $world,
            (array) App::balance('bridges.cost', []),
            (float) App::balance('bridges.cost_growth', 1.09),
            $level,
            [self::benefit('Brückenkapazität', (float) App::balance('bridges.capacity', 60), $level, $growth, '/Min.')],
            $growth
        );
    }

    private static function previewRoute(array $world, string $id): ?array
    {
        $route = $world['routes'][$id] ?? null;
        if ($route === null) {
            return null;
        }

        $level    = (int) ($route['level'] ?? 1);
        $modeDef  = (array) App::balance('transport_modes.' . (string) $route['mode'], []);
        $speedG   = (float) App::balance('transport.speed_growth', 1.015);
        $capG     = (float) App::balance('transport.capacity_growth', 1.012);

        return self::previewPayload(
            $world,
            ['wood' => 90, 'stone' => 60, 'tools' => 2],
            1.062,
            $level,
            [
                self::benefit('Tempo', (float) ($modeDef['speed'] ?? 1), $level, $speedG, ' Felder/Sek.'),
                self::benefit('Ladung je Fahrt', (float) ($modeDef['capacity'] ?? 10), $level, $capG, ''),
            ],
            $speedG
        );
    }

    private static function previewResearch(array $world, string $key): ?array
    {
        $def = App::balance('research.' . $key);
        if (!is_array($def)) {
            return null;
        }

        $level    = (int) ($world['research'][$key] ?? 0);
        $growth   = (float) ($def['growth'] ?? 1.18);
        $perLevel = (float) ($def['per_level'] ?? 1.0);
        $discount = min(0.6, World::effects($world)['research_discount'] / 100);

        $scaled = [];
        foreach ((array) ($def['cost'] ?? []) as $resource => $amount) {
            $scaled[$resource] = (float) $amount * (1 - $discount);
        }

        return self::previewPayload($world, $scaled, $growth, $level, [[
            'label'   => (string) $def['name'],
            'from'    => round(($perLevel ** $level) * 100, 1),
            'to'      => round(($perLevel ** ($level + 1)) * 100, 1),
            'unit'    => ' %',
            'percent' => $perLevel - 1.0,
        ]], $perLevel);
    }

    private static function previewUnit(array $world, string $unit): ?array
    {
        $def = App::balance('units.' . $unit);
        if (!is_array($def)) {
            return null;
        }

        $level = (int) ($world['unit_levels'][$unit] ?? 1);
        $hpG   = (float) App::balance('unit_training.hp_growth', 1.01);
        $dmgG  = (float) App::balance('unit_training.damage_growth', 1.008);

        return self::previewPayload(
            $world,
            (array) App::balance('unit_training.level_cost', []),
            (float) App::balance('unit_training.level_growth', 1.11),
            $level,
            [
                self::benefit('Lebenspunkte', (float) $def['hp'], $level, $hpG, ''),
                self::benefit('Schaden', (float) $def['damage'], $level, $dmgG, ''),
            ],
            $hpG
        );
    }

    /** Kosten für +1/+10/+100/MAX plus Nutzen je Stufe. */
    private static function previewPayload(array $world, array $base, float $growth, int $level, array $benefits, float $effectGrowth): array
    {
        $available = Economy::available($world);
        $steps     = [];

        foreach ([1, 10, 100] as $count) {
            $cost = Formulas::bulkCost($base, $level + 1, $count, $growth);
            $steps[(string) $count] = [
                'count'   => $count,
                'cost'    => $cost,
                'afford'  => Formulas::canAfford($cost, $available),
                'safe'    => Formulas::withinSafeRange($cost),
                'missing' => Formulas::missing($cost, $available),
            ];
        }

        $max = Formulas::maxAffordable($base, $level + 1, $available, $growth);
        $steps['max'] = [
            'count'   => $max,
            'cost'    => $max > 0 ? Formulas::bulkCost($base, $level + 1, $max, $growth) : [],
            'afford'  => $max > 0,
            'safe'    => true,
            'missing' => [],
        ];

        return [
            'level'    => $level,
            'steps'    => $steps,
            'benefits' => array_values(array_filter($benefits)),
            'gain'     => round(($effectGrowth - 1.0) * 100, 2),
            'tier'     => Formulas::tier($level),
            'next_tier'=> Formulas::nextMilestone($level),
        ];
    }

    private static function benefit(string $label, float $base, int $level, float $growth, string $unit): ?array
    {
        if ($base <= 0) {
            return null;
        }
        $preview = Formulas::preview($base, max(1, $level), max(1, $level) + 1, $growth);

        return [
            'label'   => $label,
            'from'    => round($preview['from'], 2),
            'to'      => round($preview['to'], 2),
            'unit'    => $unit,
            'percent' => round($preview['percent'], 4),
        ];
    }

    private static function effectLabel(string $key): string
    {
        return match ($key) {
            'population'        => 'Einwohner',
            'route_slots'       => 'Routenplätze',
            'carriers'          => 'Mögliche Träger',
            'defense_hp'        => 'Verteidigungsstärke',
            'defense_damage'    => 'Turmschaden',
            'defense_range'     => 'Reichweite',
            'army_capacity'     => 'Truppenplätze',
            'research_discount' => 'Forschungsrabatt',
            'trade_slots'       => 'Handelsplätze',
            'gold_rate'         => 'Goldeinnahmen',
            'load_speed'        => 'Umschlagtempo',
            'patrol_strength'   => 'Patrouillenstärke',
            'convoy_safety'     => 'Konvoischutz',
            'score'             => 'Punkte',
            default             => $key,
        };
    }

    // =================================================================
    // Hilfen
    // =================================================================

    public static function modeList(array $world): array
    {
        $out = [];
        foreach ((array) App::balance('transport_modes', []) as $key => $def) {
            $out[] = [
                'key'         => $key,
                'name'        => (string) $def['name'],
                'speed'       => (float) $def['speed'],
                'capacity'    => (float) $def['capacity'],
                'cost'        => (array) ($def['cost'] ?? []),
                'unlocked'    => Logistics::modeAvailable($world, (string) $key),
                'requirement' => Logistics::modeRequirement($world, (string) $key),
            ];
        }

        return $out;
    }

    private static function unreadCount(string $uid): int
    {
        $count = 0;
        foreach (Player::notifications($uid) as $item) {
            if (empty($item['read'])) {
                $count++;
            }
        }

        return $count;
    }

    private function cacheable(): void
    {
        if (!headers_sent()) {
            header('Cache-Control: private, max-age=600');
        }
    }

    public static function str(string $key, string $default = ''): string
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? $default;

        return Security::clean(is_scalar($value) ? (string) $value : $default, 64);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    /** +1/+10/+100 oder „max". */
    public static function steps(): int|string
    {
        $raw = (string) ($_POST['steps'] ?? $_GET['steps'] ?? '1');
        if ($raw === 'max') {
            return 'max';
        }

        return max(1, min((int) $raw, Formulas::MAX_STEPS_PER_ACTION));
    }

    /** Routenende aus der Anfrage lesen: 'store' oder 'b:<id>'. */
    private static function endpoint(string $key): array
    {
        $raw = self::str($key);
        if ($raw === '' || $raw === 'store') {
            return ['store'];
        }
        if (str_starts_with($raw, 'b:')) {
            return ['b', substr($raw, 2)];
        }

        return ['store'];
    }
}
