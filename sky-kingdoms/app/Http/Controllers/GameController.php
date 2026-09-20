<?php

declare(strict_types=1);

namespace SkyKingdoms\Http\Controllers;

use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Auth;
use SkyKingdoms\Core\Csrf;
use SkyKingdoms\Core\Num;
use SkyKingdoms\Core\Response;
use SkyKingdoms\Core\Security;
use SkyKingdoms\Core\Session;
use SkyKingdoms\Core\Validator;
use SkyKingdoms\Core\View;
use SkyKingdoms\Game\Economy;
use SkyKingdoms\Game\Player;
use SkyKingdoms\Game\Quests;
use SkyKingdoms\Game\Ranking;
use SkyKingdoms\Game\World;

/** Die Seiten rund um das Spiel selbst. */
final class GameController
{
    /** Die Spielfläche. Alle weiteren Daten holt der Browser über die API. */
    public function play(): void
    {
        $user = Auth::requireLogin();

        View::page('game/play', [
            'title'   => 'Königreich',
            'user'    => $user,
            'csrf'    => Csrf::token(),
        ], 'partials/game');
    }

    public function profile(): void
    {
        $user  = Auth::requireLogin();
        $uid   = (string) $user['id'];
        $world = Player::world($uid) ?? [];
        $card  = Player::refreshPublic($uid, $world);

        View::page('game/profile', [
            'title'        => 'Profil',
            'user'         => $user,
            'card'         => $card,
            'world'        => $world,
            'rank'         => Ranking::rankOf($uid),
            'storage'      => Economy::storageOverview($world),
            'achievements' => Quests::achievementsForPlayer($world),
        ], 'partials/plain');
    }

    public function ranking(): void
    {
        Auth::requireLogin();
        $page = max(1, (int) ($_GET['seite'] ?? 1));

        View::page('game/ranking', [
            'title' => 'Rangliste',
            'data'  => Ranking::page($page),
            'me'    => Auth::id(),
            'wide'  => true,
        ], 'partials/plain');
    }

    /** Kontoeinstellungen: Passwort ändern, Konto löschen. */
    public function account(): void
    {
        $user   = Auth::requireLogin();
        $uid    = (string) $user['id'];
        $errors = [];
        $done   = '';

        if (Security::isPost()) {
            Csrf::verifyOrFail();
            $action = (string) ($_POST['action'] ?? '');

            if ($action === 'password') {
                $v = Validator::make($_POST)
                    ->password('new_password', 'nichts', 'nichts')
                    ->matches('new_password_confirm', 'new_password', 'Die beiden Passwörter stimmen nicht überein.');

                if ($v->fails()) {
                    $errors = $v->errors();
                } else {
                    $result = Auth::changePassword($uid, (string) ($_POST['old_password'] ?? ''), (string) $_POST['new_password']);
                    if ($result['ok']) {
                        $done = 'Dein Passwort wurde geändert. Alle anderen Geräte wurden abgemeldet.';
                    } else {
                        $errors['old_password'] = (string) $result['error'];
                    }
                }
            }

            if ($action === 'settings') {
                $settings = [
                    'sound'      => isset($_POST['sound']),
                    'music'      => isset($_POST['music']),
                    'animations' => isset($_POST['animations']),
                    'haptics'    => isset($_POST['haptics']),
                    'quality'    => in_array($_POST['quality'] ?? 'auto', ['auto', 'high', 'medium', 'low'], true)
                        ? (string) $_POST['quality'] : 'auto',
                ];
                Player::updateAccount($uid, static function (array $account) use ($settings): array {
                    $account['settings'] = array_merge((array) ($account['settings'] ?? []), $settings);

                    return $account;
                });
                $done = 'Einstellungen gespeichert.';
                $user = Player::account($uid) ?? $user;
            }

            if ($action === 'delete') {
                $password = (string) ($_POST['delete_password'] ?? '');
                if (!\SkyKingdoms\Core\Password::verify($password, (string) $user['password'])) {
                    $errors['delete_password'] = 'Das Passwort stimmt nicht.';
                } elseif (($_POST['delete_confirm'] ?? '') !== 'LÖSCHEN') {
                    $errors['delete_confirm'] = 'Bitte tippe LÖSCHEN, um das Konto endgültig zu entfernen.';
                } else {
                    \SkyKingdoms\Core\Audit::log('account.deleted', 'Konto gelöscht', ['name' => $user['username']], $uid);
                    Auth::logout();
                    Player::delete($uid);
                    Session::start();
                    Session::flash('info', 'Dein Konto wurde vollständig gelöscht.');
                    Response::redirect('?p=login');
                }
            }
        }

        View::page('game/account', [
            'title'  => 'Konto',
            'user'   => $user,
            'errors' => $errors,
            'done'   => $done,
        ], 'partials/plain');
    }

    /** Daten, die im HTML-Grundgerüst mitgeliefert werden (spart eine Anfrage). */
    public static function bootData(array $user): array
    {
        $uid = (string) $user['id'];

        return [
            'user' => [
                'id'       => $uid,
                'name'     => (string) $user['username'],
                'roles'    => (array) ($user['roles'] ?? []),
                'settings' => (array) ($user['settings'] ?? []),
            ],
            'game' => [
                'name'      => (string) App::config('name', 'Sky Kingdoms'),
                'version'   => SK_VERSION,
                'maxOffline'=> (int) App::config('max_offline_seconds', 86400),
            ],
        ];
    }

    /** Kompakte Beschreibung aller Balancewerte für den Browser. */
    public static function staticData(): array
    {
        $resources = [];
        foreach ((array) App::balance('resources', []) as $key => $def) {
            $resources[$key] = [
                'name'  => (string) $def['name'],
                'class' => (string) $def['class'],
                'hud'   => (bool) ($def['hud'] ?? false),
                'order' => (int) ($def['order'] ?? 999),
                'color' => (string) ($def['color'] ?? '#888'),
            ];
        }

        $buildings = [];
        foreach ((array) App::balance('buildings', []) as $key => $def) {
            $buildings[$key] = [
                'name'     => (string) $def['name'],
                'desc'     => (string) ($def['desc'] ?? ''),
                'role'     => (string) ($def['role'] ?? ''),
                'islands'  => (array) ($def['islands'] ?? []),
                'size'     => (array) ($def['size'] ?? [1, 1]),
                'cost'     => (array) ($def['cost'] ?? []),
                'produces' => (array) ($def['produces'] ?? []),
                'consumes' => (array) ($def['consumes'] ?? []),
                'storage'  => (array) ($def['storage'] ?? []),
                'effects'  => (array) ($def['effects'] ?? []),
                'workers'  => (int) ($def['workers'] ?? 0),
                'sprite'   => (string) ($def['sprite'] ?? $key),
                'limit'    => (int) ($def['limit'] ?? 0),
            ];
        }

        $islands = [];
        foreach ((array) App::balance('island_types', []) as $key => $def) {
            $islands[$key] = [
                'name' => (string) $def['name'],
                'desc' => (string) ($def['desc'] ?? ''),
                'grid' => (array) ($def['grid'] ?? [10, 8]),
                'mask' => (array) ($def['mask'] ?? []),
                'tint' => (string) ($def['tint'] ?? '#7ec97a'),
                'unlock_cost' => (array) ($def['unlock_cost'] ?? []),
            ];
        }

        $modes = [];
        foreach ((array) App::balance('transport_modes', []) as $key => $def) {
            $modes[$key] = [
                'name'     => (string) $def['name'],
                'speed'    => (float) $def['speed'],
                'capacity' => (float) $def['capacity'],
                'cost'     => (array) ($def['cost'] ?? []),
                'sprite'   => (string) ($def['sprite'] ?? 'porter'),
            ];
        }

        $units = [];
        foreach ((array) App::balance('units', []) as $key => $def) {
            $units[$key] = [
                'name'   => (string) $def['name'],
                'hp'     => (float) $def['hp'],
                'damage' => (float) $def['damage'],
                'speed'  => (float) $def['speed'],
                'carry'  => (float) $def['carry'],
                'cost'   => (array) ($def['cost'] ?? []),
                'pop'    => (int) ($def['pop'] ?? 1),
                'sprite' => (string) ($def['sprite'] ?? 'unit_spearman'),
            ];
        }

        $missions = [];
        foreach ((array) App::balance('missions', []) as $key => $def) {
            $missions[$key] = [
                'name' => (string) $def['name'],
                'desc' => (string) ($def['desc'] ?? ''),
                'loot' => (float) ($def['loot'] ?? 0),
                'target' => (string) ($def['target'] ?? ''),
            ];
        }

        return [
            'resources'   => $resources,
            'buildings'   => $buildings,
            'islandTypes' => $islands,
            'modes'       => $modes,
            'units'       => $units,
            'missions'    => $missions,
            'classes'     => (array) App::balance('storage_classes', []),
            'abilities'   => (array) App::balance('combat.abilities', []),
            'slots'       => World::SLOTS,
            'milestones'  => (array) App::balance('formulas.milestones', []),
        ];
    }
}
