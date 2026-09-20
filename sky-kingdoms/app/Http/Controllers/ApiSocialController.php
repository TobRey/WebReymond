<?php

declare(strict_types=1);

namespace SkyKingdoms\Http\Controllers;

use SkyKingdoms\Core\Csrf;
use SkyKingdoms\Core\Response;
use SkyKingdoms\Core\Security;
use SkyKingdoms\Game\Alliance;
use SkyKingdoms\Game\Combat\Battle;
use SkyKingdoms\Game\Player;
use SkyKingdoms\Game\Ranking;
use SkyKingdoms\Game\Trade;

/**
 * API-Teil rund um andere Spieler: Rangliste, Profile, Allianzen, Handel,
 * Angriffe und Kampfberichte.
 */
final class ApiSocialController
{
    private const MUTATIONS = [
        'alliance_create', 'alliance_join', 'alliance_leave',
        'trade_create', 'trade_accept', 'trade_cancel',
        'attack_start', 'attack_finish',
    ];

    public function handle(string $action, string $uid, array $user): never
    {
        if (in_array($action, self::MUTATIONS, true)) {
            Security::requirePost();
            Csrf::verifyOrFail();
        }

        match ($action) {
            'ranking'        => $this->ranking($uid),
            'player'         => $this->player(),
            'alliances'      => $this->alliances($user),
            'alliance_create'=> $this->allianceCreate($uid),
            'alliance_join'  => $this->respond(Alliance::join($uid, ApiController::str('id'))),
            'alliance_leave' => $this->respond(Alliance::leave($uid)),
            'trades'         => $this->trades($uid),
            'trade_create'   => $this->tradeCreate($uid),
            'trade_accept'   => $this->respond(Trade::accept($uid, ApiController::str('id'))),
            'trade_cancel'   => $this->respond(Trade::cancel($uid, ApiController::str('id'))),
            'attack_targets' => Response::ok(['targets' => Battle::targets($uid)]),
            'attack_start'   => $this->attackStart($uid),
            'attack_finish'  => $this->attackFinish($uid),
            'reports'        => Response::ok(['reports' => Battle::reports($uid, 20, max(0, ApiController::int('offset')))]),
            'report'         => $this->report($uid),
            default          => Response::fail('Unbekannte Aktion.', 404),
        };
    }

    private function ranking(string $uid): never
    {
        $page = max(1, ApiController::int('page', 1));

        Response::ok([
            'ranking' => Ranking::page($page),
            'me'      => ['id' => $uid, 'rank' => Ranking::rankOf($uid)],
        ]);
    }

    private function player(): never
    {
        $id   = ApiController::str('id');
        $card = Player::publicCard($id);
        if ($card === null) {
            Response::fail('Diesen Spieler gibt es nicht.', 404);
        }

        unset($card['banned']);
        $allianceId = (string) ($card['alliance'] ?? '');
        if ($allianceId !== '') {
            $alliance = Alliance::get($allianceId);
            $card['alliance_name'] = (string) ($alliance['name'] ?? '');
            $card['alliance_tag']  = (string) ($alliance['tag'] ?? '');
        }

        Response::ok(['player' => $card, 'rank' => Ranking::rankOf($id)]);
    }

    private function alliances(array $user): never
    {
        $mine = (string) ($user['alliance'] ?? '');

        Response::ok([
            'alliances' => Alliance::all(50),
            'mine'      => $mine !== '' ? Alliance::details($mine) : null,
            'cost'      => Alliance::COST,
        ]);
    }

    private function allianceCreate(string $uid): never
    {
        $this->respond(Alliance::create($uid, (string) ($_POST['name'] ?? ''), (string) ($_POST['tag'] ?? '')));
    }

    private function trades(string $uid): never
    {
        Trade::sweep();

        Response::ok([
            'offers' => Trade::open(),
            'me'     => $uid,
            'fee'    => Trade::FEE,
        ]);
    }

    private function tradeCreate(string $uid): never
    {
        $this->respond(Trade::create(
            $uid,
            ApiController::str('give'),
            ApiController::int('give_amount', 1),
            ApiController::str('want'),
            ApiController::int('want_amount', 1)
        ));
    }

    private function attackStart(string $uid): never
    {
        $squadRaw = $_POST['squad'] ?? '';
        $squad    = [];

        if (is_string($squadRaw) && $squadRaw !== '') {
            $decoded = json_decode($squadRaw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $type => $count) {
                    $type = preg_replace('/[^a-z_]/', '', (string) $type);
                    if ($type !== '' && is_numeric($count)) {
                        $squad[$type] = max(0, min(200, (int) $count));
                    }
                }
            }
        }

        $this->respond(Battle::start($uid, ApiController::str('target'), ApiController::str('mission'), $squad));
    }

    private function attackFinish(string $uid): never
    {
        $actionsRaw = $_POST['actions'] ?? '[]';
        $actions    = [];

        if (is_string($actionsRaw)) {
            $decoded = json_decode($actionsRaw, true);
            if (is_array($decoded)) {
                foreach (array_slice($decoded, 0, 200) as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $actions[] = [
                        't' => max(0, (int) ($entry['t'] ?? 0)),
                        'a' => preg_replace('/[^a-z]/', '', (string) ($entry['a'] ?? '')),
                        'v' => is_scalar($entry['v'] ?? '') ? (string) $entry['v'] : '',
                    ];
                }
            }
        }

        $claimed = null;
        if (isset($_POST['result']) && is_string($_POST['result'])) {
            $decoded = json_decode($_POST['result'], true);
            if (is_array($decoded)) {
                $claimed = $decoded;
            }
        }

        $this->respond(Battle::finish($uid, ApiController::str('attack'), $actions, $claimed));
    }

    private function report(string $uid): never
    {
        $report = Battle::report($uid, ApiController::str('id'));
        if ($report === null) {
            Response::fail('Diesen Bericht gibt es nicht.', 404);
        }

        Response::ok(['report' => $report]);
    }

    private function respond(array $result): never
    {
        if (!($result['ok'] ?? false)) {
            Response::fail((string) ($result['error'] ?? 'Das hat nicht geklappt.'), 422);
        }

        unset($result['ok'], $result['world'], $result['before'], $result['summary']);
        Response::ok($result);
    }
}
