<?php

declare(strict_types=1);

namespace SkyKingdoms\Game;

use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Audit;
use SkyKingdoms\Core\Ids;

/**
 * Handel zwischen Spielern.
 *
 * Ablauf: Wer ein Angebot einstellt, gibt die Ware SOFORT ab (sie wird aus dem
 * Lager genommen und im Angebot hinterlegt). Damit kann niemand dieselbe Ware
 * mehrfach anbieten. Beim Annehmen wechselt alles in einem Zug den Besitzer;
 * schlägt ein Schritt fehl, wird das Angebot wieder freigegeben.
 */
final class Trade
{
    public const MAX_OPEN   = 8;
    public const LIFETIME   = 604800; // 7 Tage
    public const FEE        = 0.03;   // 3 % Marktgebühr auf Gold

    private static function key(string $id): string
    {
        return 'trades/' . preg_replace('/[^a-f0-9]/', '', $id) . '.json';
    }

    /** @return array<int,array<mixed>> Offene Angebote (neueste zuerst). */
    public static function open(int $limit = 60): array
    {
        $out = [];
        foreach (App::store()->listFiles('trades') as $file) {
            $doc = App::store()->read('trades/' . $file);
            if ($doc === null || ($doc['status'] ?? '') !== 'open') {
                continue;
            }
            if ((int) ($doc['expires'] ?? 0) < time()) {
                continue;
            }
            $card = Player::publicCard((string) $doc['seller']);
            $doc['seller_name'] = (string) ($card['name'] ?? 'Unbekannt');
            $out[] = $doc;
        }

        usort($out, static fn (array $a, array $b): int => (int) $b['created'] <=> (int) $a['created']);

        return array_slice($out, 0, $limit);
    }

    /** Angebot einstellen. */
    public static function create(string $uid, string $giveResource, int $giveAmount, string $wantResource, int $wantAmount): array
    {
        if ($giveResource === $wantResource) {
            return ['ok' => false, 'error' => 'Gib und Nimm müssen unterschiedliche Waren sein.'];
        }
        if (!is_array(App::balance('resources.' . $giveResource)) || !is_array(App::balance('resources.' . $wantResource))) {
            return ['ok' => false, 'error' => 'Diese Ware kennen wir nicht.'];
        }
        $giveAmount = max(1, min($giveAmount, 100000000));
        $wantAmount = max(1, min($wantAmount, 100000000));

        $mine = 0;
        foreach (self::open(200) as $offer) {
            if (($offer['seller'] ?? '') === $uid) {
                $mine++;
            }
        }
        if ($mine >= self::MAX_OPEN) {
            return ['ok' => false, 'error' => 'Du hast bereits ' . self::MAX_OPEN . ' offene Angebote.'];
        }

        $paid = Player::withWorld($uid, static function (array $world) use ($giveResource, $giveAmount): array {
            $effects = World::effects($world);
            if ($effects['trade_slots'] < 1) {
                return ['ok' => false, 'error' => 'Du brauchst einen Markt, um handeln zu können.'];
            }
            if (!Economy::pay($world, [$giveResource => $giveAmount])) {
                return ['ok' => false, 'error' => 'So viel hast du nicht im Lager.'];
            }

            return ['ok' => true, 'world' => $world];
        });

        if (!($paid['ok'] ?? false)) {
            return $paid;
        }

        $id = Ids::generate(5);
        App::store()->write(self::key($id), [
            'id'      => $id,
            'seller'  => $uid,
            'give'    => [$giveResource => $giveAmount],
            'want'    => [$wantResource => $wantAmount],
            'status'  => 'open',
            'created' => time(),
            'expires' => time() + self::LIFETIME,
        ]);

        return ['ok' => true, 'trade' => $id];
    }

    /** Angebot annehmen. */
    public static function accept(string $uid, string $id): array
    {
        $doc = App::store()->read(self::key($id));
        if ($doc === null || ($doc['status'] ?? '') !== 'open') {
            return ['ok' => false, 'error' => 'Dieses Angebot gibt es nicht mehr.'];
        }
        if ((string) $doc['seller'] === $uid) {
            return ['ok' => false, 'error' => 'Du kannst dein eigenes Angebot nicht annehmen.'];
        }

        // Angebot für uns reservieren (verhindert doppeltes Annehmen).
        $claimed = App::store()->update(self::key($id), static function (array $current) use ($uid): ?array {
            if (($current['status'] ?? '') !== 'open') {
                return null;
            }
            $current['status'] = 'pending';
            $current['buyer']  = $uid;

            return $current;
        });

        if ($claimed === null) {
            return ['ok' => false, 'error' => 'Jemand war schneller.'];
        }

        $want = (array) $doc['want'];
        $give = (array) $doc['give'];

        // Käufer bezahlt und erhält die Ware.
        $buyerResult = Player::withWorld($uid, static function (array $world) use ($want, $give): array {
            if (!Economy::pay($world, $want)) {
                return ['ok' => false, 'error' => 'Dafür fehlen dir die Waren.'];
            }
            $credited = Economy::credit($world, $give);

            return ['ok' => true, 'world' => $world, 'received' => $credited['stored'], 'lost' => $credited['lost']];
        });

        if (!($buyerResult['ok'] ?? false)) {
            App::store()->update(self::key($id), static function (array $current): array {
                $current['status'] = 'open';
                unset($current['buyer']);

                return $current;
            });

            return $buyerResult;
        }

        // Verkäufer erhält die Gegenleistung (abzüglich Marktgebühr auf Gold).
        $payout = $want;
        if (isset($payout['gold'])) {
            $payout['gold'] = (int) floor($payout['gold'] * (1 - self::FEE));
        }

        $seller = (string) $doc['seller'];
        Player::withWorld($seller, static function (array $world) use ($payout): array {
            Economy::credit($world, $payout);

            return ['ok' => true, 'world' => $world];
        });

        App::store()->update(self::key($id), static function (array $current) use ($uid): array {
            $current['status'] = 'done';
            $current['buyer']  = $uid;
            $current['closed'] = time();

            return $current;
        });

        Player::notify($seller, 'trade', 'Handel abgeschlossen', 'Dein Angebot wurde angenommen.', ['trade' => $id]);
        Audit::log('trade.accept', 'Handel angenommen', ['trade' => $id, 'seller' => $seller], $uid);

        return ['ok' => true, 'received' => $buyerResult['received'] ?? [], 'lost' => $buyerResult['lost'] ?? []];
    }

    /** Eigenes Angebot zurückziehen – die Ware kommt zurück ins Lager. */
    public static function cancel(string $uid, string $id): array
    {
        $doc = App::store()->read(self::key($id));
        if ($doc === null || (string) $doc['seller'] !== $uid) {
            return ['ok' => false, 'error' => 'Dieses Angebot gehört dir nicht.'];
        }
        if (($doc['status'] ?? '') !== 'open') {
            return ['ok' => false, 'error' => 'Dieses Angebot lässt sich nicht mehr zurückziehen.'];
        }

        $closed = App::store()->update(self::key($id), static function (array $current): ?array {
            if (($current['status'] ?? '') !== 'open') {
                return null;
            }
            $current['status'] = 'cancelled';
            $current['closed'] = time();

            return $current;
        });

        if ($closed === null) {
            return ['ok' => false, 'error' => 'Dieses Angebot lässt sich nicht mehr zurückziehen.'];
        }

        $result = Player::withWorld($uid, static function (array $world) use ($doc): array {
            $credited = Economy::credit($world, (array) $doc['give']);

            return ['ok' => true, 'world' => $world, 'received' => $credited['stored'], 'lost' => $credited['lost']];
        });

        return ['ok' => true, 'received' => $result['received'] ?? []];
    }

    /** Abgelaufene Angebote gelegentlich zurückgeben. */
    public static function sweep(): void
    {
        foreach (App::store()->listFiles('trades') as $file) {
            $doc = App::store()->read('trades/' . $file);
            if ($doc === null) {
                continue;
            }
            if (($doc['status'] ?? '') === 'open' && (int) ($doc['expires'] ?? 0) < time()) {
                self::cancel((string) $doc['seller'], (string) $doc['id']);
            }
            // Erledigte Angebote nach 30 Tagen entfernen
            if (in_array($doc['status'] ?? '', ['done', 'cancelled'], true)
                && (int) ($doc['closed'] ?? 0) < time() - 2592000) {
                App::store()->delete('trades/' . $file);
            }
        }
    }
}
