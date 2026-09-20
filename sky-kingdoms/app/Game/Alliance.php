<?php

declare(strict_types=1);

namespace SkyKingdoms\Game;

use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Audit;
use SkyKingdoms\Core\Ids;
use SkyKingdoms\Core\Security;

/**
 * Allianzen: gemeinsam spielen, Punkte bündeln, sich gegenseitig nicht angreifen.
 */
final class Alliance
{
    public const COST = ['gold' => 1500];
    public const MAX_MEMBERS = 30;

    public static function key(string $aid): string
    {
        return 'alliances/' . preg_replace('/[^a-f0-9]/', '', $aid) . '.json';
    }

    public static function get(string $aid): ?array
    {
        if (!Ids::isValid($aid)) {
            return null;
        }

        return App::store()->read(self::key($aid));
    }

    /** @return array<int,array<mixed>> */
    public static function all(int $limit = 200): array
    {
        $out = [];
        foreach (App::store()->listFiles('alliances') as $file) {
            $doc = App::store()->read('alliances/' . $file);
            if ($doc === null) {
                continue;
            }
            $out[] = self::summary($doc);
            if (count($out) >= $limit) {
                break;
            }
        }

        usort($out, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $out;
    }

    private static function summary(array $doc): array
    {
        $score = 0;
        foreach ((array) ($doc['members'] ?? []) as $uid => $member) {
            $card  = Player::publicCard((string) $uid);
            $score += (int) ($card['score'] ?? 0);
        }

        return [
            'id'      => (string) $doc['id'],
            'name'    => (string) $doc['name'],
            'tag'     => (string) $doc['tag'],
            'members' => count((array) ($doc['members'] ?? [])),
            'score'   => $score,
            'desc'    => (string) ($doc['description'] ?? ''),
            'open'    => (bool) ($doc['open'] ?? true),
        ];
    }

    /** Allianz gründen (kostet Gold aus dem Lager). */
    public static function create(string $uid, string $name, string $tag): array
    {
        $account = Player::account($uid);
        if ($account === null) {
            return ['ok' => false, 'error' => 'Konto nicht gefunden.'];
        }
        if (!empty($account['alliance'])) {
            return ['ok' => false, 'error' => 'Du bist bereits in einer Allianz.'];
        }

        $name = Security::clean($name, 30);
        $tag  = mb_strtoupper(Security::clean($tag, 5));

        if (mb_strlen($name) < 3) {
            return ['ok' => false, 'error' => 'Der Name muss mindestens 3 Zeichen lang sein.'];
        }
        if (!preg_match('/^[A-Z0-9]{2,5}$/u', $tag)) {
            return ['ok' => false, 'error' => 'Das Kürzel besteht aus 2 bis 5 Grossbuchstaben oder Ziffern.'];
        }
        foreach (self::all() as $existing) {
            if (mb_strtolower($existing['name']) === mb_strtolower($name) || $existing['tag'] === $tag) {
                return ['ok' => false, 'error' => 'Name oder Kürzel ist schon vergeben.'];
            }
        }

        $paid = Player::withWorld($uid, static function (array $world): array {
            if (!Economy::pay($world, self::COST)) {
                return ['ok' => false, 'error' => 'Für die Gründung fehlen 1.500 Goldmünzen.'];
            }

            return ['ok' => true, 'world' => $world];
        });
        if (!($paid['ok'] ?? false)) {
            return $paid;
        }

        $aid = Ids::generate(5);
        App::store()->write(self::key($aid), [
            'id'          => $aid,
            'name'        => $name,
            'tag'         => $tag,
            'description' => '',
            'open'        => true,
            'founder'     => $uid,
            'created_at'  => time(),
            'members'     => [$uid => ['role' => 'leader', 'joined' => time()]],
        ]);

        Player::updateAccount($uid, static function (array $account) use ($aid): array {
            $account['alliance'] = $aid;

            return $account;
        });
        Player::refreshPublic($uid);
        Audit::log('alliance.create', 'Allianz gegründet: ' . $name, ['aid' => $aid], $uid);

        return ['ok' => true, 'alliance' => $aid];
    }

    public static function join(string $uid, string $aid): array
    {
        $account = Player::account($uid);
        if ($account === null) {
            return ['ok' => false, 'error' => 'Konto nicht gefunden.'];
        }
        if (!empty($account['alliance'])) {
            return ['ok' => false, 'error' => 'Du bist bereits in einer Allianz.'];
        }

        $result = App::store()->update(self::key($aid), static function (array $doc) use ($uid): ?array {
            if ($doc === [] || empty($doc['id'])) {
                return null;
            }
            if (count((array) ($doc['members'] ?? [])) >= self::MAX_MEMBERS) {
                return null;
            }
            if (empty($doc['open'])) {
                return null;
            }
            $doc['members'][$uid] = ['role' => 'member', 'joined' => time()];

            return $doc;
        });

        if ($result === null) {
            return ['ok' => false, 'error' => 'Der Beitritt war nicht möglich (voll, geschlossen oder nicht vorhanden).'];
        }

        Player::updateAccount($uid, static function (array $account) use ($aid): array {
            $account['alliance'] = $aid;

            return $account;
        });
        Player::refreshPublic($uid);

        return ['ok' => true, 'alliance' => $aid];
    }

    public static function leave(string $uid): array
    {
        $account = Player::account($uid);
        $aid     = (string) ($account['alliance'] ?? '');
        if ($aid === '') {
            return ['ok' => false, 'error' => 'Du bist in keiner Allianz.'];
        }

        App::store()->update(self::key($aid), static function (array $doc) use ($uid): array {
            unset($doc['members'][$uid]);

            // Führung weitergeben, wenn nötig
            $members = (array) ($doc['members'] ?? []);
            if ($members !== [] && !self::hasLeader($members)) {
                $first = array_key_first($members);
                $doc['members'][$first]['role'] = 'leader';
            }

            return $doc;
        });

        $doc = self::get($aid);
        if ($doc !== null && (array) ($doc['members'] ?? []) === []) {
            App::store()->delete(self::key($aid));
        }

        Player::updateAccount($uid, static function (array $account): array {
            $account['alliance'] = null;

            return $account;
        });
        Player::refreshPublic($uid);

        return ['ok' => true];
    }

    private static function hasLeader(array $members): bool
    {
        foreach ($members as $member) {
            if (($member['role'] ?? '') === 'leader') {
                return true;
            }
        }

        return false;
    }

    /** Sind zwei Spieler in derselben Allianz? (Kein Angriff untereinander.) */
    public static function sameAlliance(string $a, string $b): bool
    {
        $aAcc = Player::account($a);
        $bAcc = Player::account($b);
        $aid  = (string) ($aAcc['alliance'] ?? '');

        return $aid !== '' && $aid === (string) ($bAcc['alliance'] ?? '');
    }

    /** Ausführliche Ansicht inklusive Mitgliederliste. */
    public static function details(string $aid): ?array
    {
        $doc = self::get($aid);
        if ($doc === null) {
            return null;
        }

        $members = [];
        foreach ((array) ($doc['members'] ?? []) as $uid => $member) {
            $card = Player::publicCard((string) $uid);
            $members[] = [
                'id'     => (string) $uid,
                'name'   => (string) ($card['name'] ?? 'Unbekannt'),
                'score'  => (int) ($card['score'] ?? 0),
                'level'  => (int) ($card['level'] ?? 1),
                'role'   => (string) ($member['role'] ?? 'member'),
                'joined' => (int) ($member['joined'] ?? 0),
                'last_seen' => (int) ($card['last_seen'] ?? 0),
            ];
        }
        usort($members, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return self::summary($doc) + ['members_list' => $members, 'founder' => (string) ($doc['founder'] ?? '')];
    }
}
