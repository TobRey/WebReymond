<?php
declare(strict_types=1);

namespace SwipeGroove\Spotify;

use SwipeGroove\Core\App;
use SwipeGroove\Core\HttpException;

/**
 * Swipe-Feed: Karten ausliefern, Aktionen idempotent ausführen, Rückgängig machen.
 */
final class SwipeService
{
    public function __construct(private App $app)
    {
    }

    private function playlists(): PlaylistService
    {
        return $this->app->service(PlaylistService::class);
    }

    /** @return list<array<string,mixed>> bearbeitbare Zielplaylists */
    public function targets(int $userId): array
    {
        return array_values(array_filter($this->playlists()->sync($userId), static fn(array $p) => $p['can_edit']));
    }

    /** @return array<string,true> */
    private function excludedTrackIds(int $userId, string $playlistId): array
    {
        $db = $this->app->db();
        $ex = [];
        $cache = $db->one('SELECT id FROM playlist_cache WHERE user_id = ? AND spotify_playlist_id = ?', [$userId, $playlistId]);
        if ($cache) {
            foreach ($db->column('SELECT track_id FROM playlist_items_cache WHERE playlist_cache_id = ? AND track_id IS NOT NULL', [$cache['id']]) as $id) {
                $ex[(string) $id] = true;
            }
        }
        foreach ($db->column('SELECT track_id FROM swipe_hidden WHERE user_id = ? AND spotify_playlist_id = ?', [$userId, $playlistId]) as $id) {
            $ex[(string) $id] = true;
        }
        foreach ($db->column('SELECT track_id FROM swipe_actions WHERE user_id = ? AND spotify_playlist_id = ? AND action = \'add\' AND status IN (\'done\',\'pending\')', [$userId, $playlistId]) as $id) {
            $ex[(string) $id] = true;
        }
        // Bereits ausgelieferte Karten (24 h) nicht erneut zeigen
        foreach ($db->column('SELECT track_id FROM feed_candidates WHERE user_id = ? AND spotify_playlist_id = ? AND served = 1', [$userId, $playlistId]) as $id) {
            $ex[(string) $id] = true;
        }
        return $ex;
    }

    /**
     * Liefert die nächsten Karten.
     * @return array{cards:list<array<string,mixed>>, target:array<string,mixed>, exhausted:bool, reason:?string, method:string}
     */
    public function feed(int $userId, string $playlistId, string $mode, array $filters, int $limit = 8): array
    {
        if (!in_array($mode, FeedService::MODES, true)) {
            throw HttpException::badRequest('swipe.invalid_mode');
        }
        $row = $this->playlists()->cacheRow($userId, $playlistId);
        $this->playlists()->assertEditable($row);
        $db = $this->app->db();
        $filterHash = md5(json_encode($filters) ?: '');
        // Inhalte der Zielplaylist müssen vollständig bekannt sein, bevor ausgeschlossen wird
        $this->playlists()->items($userId, $playlistId);
        $exclude = $this->excludedTrackIds($userId, $playlistId);

        $fetch = fn(): array => $db->all(
            'SELECT id, track_id, payload FROM feed_candidates WHERE user_id = ? AND spotify_playlist_id = ? AND mode = ? AND filter_hash = ? AND served = 0 ORDER BY id ASC LIMIT ?',
            [$userId, $playlistId, $mode, $filterHash, $limit * 2]
        );
        $rows = $fetch();
        $exhausted = false;
        $reason = null;
        if (count($rows) < $limit) {
            $gen = $this->app->service(FeedService::class)->generate($userId, $playlistId, $mode, $filters, $exclude, $filterHash);
            $exhausted = $gen['exhausted'];
            $reason = $gen['reason'];
            $rows = $fetch();
        }
        $cards = [];
        foreach ($rows as $r) {
            if (isset($exclude[$r['track_id']])) {
                $db->run('UPDATE feed_candidates SET served = 1 WHERE id = ?', [$r['id']]);
                continue;
            }
            $t = json_decode((string) $r['payload'], true) ?: [];
            $cards[] = $this->card($t);
            $exclude[$r['track_id']] = true;
            if (count($cards) >= $limit) {
                break;
            }
        }
        if ($cards !== []) {
            $ids = array_map(static fn($c) => $c['id'], $cards);
            $db->run('UPDATE feed_candidates SET served = 1 WHERE user_id = ? AND spotify_playlist_id = ? AND track_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_merge([$userId, $playlistId], $ids));
        }
        return [
            'cards' => $cards,
            'target' => $this->playlists()->presentRow($row),
            'exhausted' => $cards === [] && $exhausted,
            'reason' => $cards === [] ? $reason : null,
            'method' => 'search_catalog',
        ];
    }

    private function card(array $t): array
    {
        return [
            'id' => (string) ($t['id'] ?? ''),
            'uri' => (string) ($t['uri'] ?? ''),
            'name' => $t['name'] ?? '',
            'artists' => $t['artists'] ?? [],
            'album_name' => $t['album_name'] ?? null,
            'image_url' => $t['image_url'] ?? null,
            'duration_ms' => $t['duration_ms'] ?? null,
            'source' => $t['source'] ?? null,
            'spotify_url' => !empty($t['id']) ? 'https://open.spotify.com/track/' . $t['id'] : null,
        ];
    }

    /**
     * Führt eine Swipe-Aktion idempotent aus (action_uuid).
     * @return array<string,mixed>
     */
    public function action(int $userId, string $uuid, string $playlistId, string $trackId, string $action): array
    {
        if (!PlaylistOperations::validUuid($uuid)) {
            throw HttpException::badRequest('operation.invalid_id');
        }
        if (!preg_match('/^[A-Za-z0-9]{22}$/', $trackId)) {
            throw HttpException::badRequest('swipe.invalid_track');
        }
        if (!in_array($action, ['add', 'hide', 'skip'], true)) {
            throw HttpException::badRequest('swipe.invalid_action');
        }
        $db = $this->app->db();
        $row = $this->playlists()->cacheRow($userId, $playlistId);
        $this->playlists()->assertEditable($row);
        $uri = 'spotify:track:' . $trackId;
        $now = $db->now();

        $existing = $db->one('SELECT * FROM swipe_actions WHERE action_uuid = ?', [$uuid]);
        if ($existing) {
            if ((int) $existing['user_id'] !== $userId) {
                throw HttpException::forbidden();
            }
            if ($existing['status'] === 'pending' && strtotime((string) $existing['updated_at']) > time() - 60) {
                return $this->presentAction($existing, 'in_progress');
            }
            if ($existing['status'] !== 'pending') {
                return $this->presentAction($existing, 'duplicate');
            }
            $actionId = (int) $existing['id'];
        } else {
            // Gleicher Song für dieselbe Zielplaylist bereits hinzugefügt? (schnelle Doppel-Swipes)
            if ($action === 'add') {
                $dup = $db->one('SELECT id FROM swipe_actions WHERE user_id = ? AND spotify_playlist_id = ? AND track_id = ? AND action = \'add\' AND status IN (\'done\',\'pending\') AND created_at > ?',
                    [$userId, $playlistId, $trackId, gmdate('Y-m-d H:i:s', time() - 86400)]);
                if ($dup) {
                    throw HttpException::conflict('swipe.already_added');
                }
            }
            try {
                $actionId = $db->insert('swipe_actions', [
                    'action_uuid' => $uuid, 'user_id' => $userId, 'spotify_playlist_id' => $playlistId,
                    'track_id' => $trackId, 'track_uri' => $uri, 'action' => $action, 'status' => 'pending',
                    'snapshot_before' => $row['snapshot_id'], 'created_at' => $now, 'updated_at' => $now,
                ]);
            } catch (\PDOException $e) {
                $again = $db->one('SELECT * FROM swipe_actions WHERE action_uuid = ?', [$uuid]);
                if ($again) {
                    return $this->presentAction($again, 'duplicate');
                }
                throw $e;
            }
        }

        try {
            if ($action === 'add') {
                $contains = $this->targetContains($userId, $playlistId, $trackId);
                $db->update('swipe_actions', ['occurrences_before' => $contains ? 1 : 0], 'id = ?', [$actionId]);
                if ($contains) {
                    $db->update('swipe_actions', ['status' => 'failed', 'error' => 'already_in_playlist', 'updated_at' => $db->now()], 'id = ?', [$actionId]);
                    throw HttpException::conflict('swipe.already_in_playlist');
                }
                $snapshot = $this->app->service(SpotifyClient::class)->addItems($userId, $playlistId, [$uri]);
                $db->update('swipe_actions', ['status' => 'done', 'snapshot_after' => $snapshot, 'position_added' => (int) $row['track_count'], 'updated_at' => $db->now()], 'id = ?', [$actionId]);
                $this->playlists()->invalidateItems($userId, $playlistId, 1, $snapshot);
            } elseif ($action === 'hide') {
                $db->run('INSERT IGNORE INTO swipe_hidden (user_id, spotify_playlist_id, track_id, created_at) VALUES (?,?,?,?)', [$userId, $playlistId, $trackId, $now]);
                $db->update('swipe_actions', ['status' => 'done', 'updated_at' => $db->now()], 'id = ?', [$actionId]);
            } else {
                $db->update('swipe_actions', ['status' => 'done', 'updated_at' => $db->now()], 'id = ?', [$actionId]);
            }
        } catch (SpotifyException $e) {
            $db->update('swipe_actions', ['status' => 'failed', 'error' => mb_substr($e->reason, 0, 255), 'updated_at' => $db->now()], 'id = ?', [$actionId]);
            throw $e;
        }
        $final = $db->one('SELECT * FROM swipe_actions WHERE id = ?', [$actionId]);
        return $this->presentAction($final ?? [], 'ok');
    }

    /** Prüft gegen den Cache und – falls dieser veraltet ist – gegen die aktuellen Spotify-Daten. */
    private function targetContains(int $userId, string $playlistId, string $trackId): bool
    {
        $db = $this->app->db();
        $cache = $db->one('SELECT id, items_complete, items_synced_at FROM playlist_cache WHERE user_id = ? AND spotify_playlist_id = ?', [$userId, $playlistId]);
        if (!$cache) {
            return false;
        }
        if ((int) $cache['items_complete'] === 1) {
            return (bool) $db->value('SELECT 1 FROM playlist_items_cache WHERE playlist_cache_id = ? AND track_id = ? LIMIT 1', [$cache['id'], $trackId]);
        }
        // Cache veraltet (z. B. nach eigenem Hinzufügen): auf frühere erfolgreiche Aktionen prüfen
        return (bool) $db->value('SELECT 1 FROM swipe_actions WHERE user_id = ? AND spotify_playlist_id = ? AND track_id = ? AND action = \'add\' AND status = \'done\' LIMIT 1', [$userId, $playlistId, $trackId]);
    }

    private function presentAction(array $a, string $result): array
    {
        return [
            'result' => $result,
            'id' => $a['action_uuid'] ?? null,
            'action' => $a['action'] ?? null,
            'status' => $a['status'] ?? null,
            'track_id' => $a['track_id'] ?? null,
            'error' => $a['error'] ?? null,
        ];
    }

    /**
     * Macht die letzte Aktion (oder eine bestimmte) rückgängig.
     * Bei "add" wird nur das durch diese Aktion hinzugefügte Vorkommen entfernt.
     */
    public function undo(int $userId, string $playlistId, ?string $uuid): array
    {
        $db = $this->app->db();
        if ($uuid !== null) {
            $a = $db->one('SELECT * FROM swipe_actions WHERE action_uuid = ? AND user_id = ? AND spotify_playlist_id = ?', [$uuid, $userId, $playlistId]);
        } else {
            $a = $db->one('SELECT * FROM swipe_actions WHERE user_id = ? AND spotify_playlist_id = ? AND status = \'done\' ORDER BY id DESC LIMIT 1', [$userId, $playlistId]);
        }
        if (!$a) {
            throw HttpException::notFound('swipe.nothing_to_undo');
        }
        if ($a['status'] !== 'done') {
            throw HttpException::conflict('swipe.cannot_undo', ['status' => $a['status']]);
        }
        $trackId = (string) $a['track_id'];
        $removed = 0;
        if ($a['action'] === 'hide') {
            $db->run('DELETE FROM swipe_hidden WHERE user_id = ? AND spotify_playlist_id = ? AND track_id = ?', [$userId, $playlistId, $trackId]);
        } elseif ($a['action'] === 'add') {
            $client = $this->app->service(SpotifyClient::class);
            $meta = $client->playlist($userId, $playlistId);
            $current = $client->allPlaylistItems($userId, $playlistId);
            $positions = [];
            foreach ($current['items'] as $pos => $it) {
                if ($it['id'] === $trackId) {
                    $positions[$pos] = $it['added_at'] ? strtotime($it['added_at']) : null;
                }
            }
            if ($positions !== []) {
                $actionTime = strtotime((string) $a['created_at']) ?: time();
                // Kandidaten: Vorkommen, die nicht vor der Aktion hinzugefügt wurden (10 min Toleranz für Uhrenabweichung); davon das letzte
                $candidates = array_filter($positions, static fn($t) => $t === null || $t >= $actionTime - 600);
                $pick = $candidates !== [] ? max(array_keys($candidates)) : null;
                // Ohne verlässliche Zeitstempel: nur entfernen, wenn mehr Vorkommen existieren als vor der Aktion
                if ($pick === null && count($positions) > (int) ($a['occurrences_before'] ?? 0)) {
                    $pick = max(array_keys($positions));
                }
                if ($pick !== null) {
                    $snapshot = $client->removeItems($userId, $playlistId, [['uri' => (string) $a['track_uri'], 'positions' => [$pick]]], (string) ($meta['snapshot_id'] ?? ''));
                    $removed = 1;
                    $this->playlists()->invalidateItems($userId, $playlistId, -1, $snapshot);
                }
            }
        }
        $db->update('swipe_actions', ['status' => 'undone', 'updated_at' => $db->now()], 'id = ?', [$a['id']]);
        // Karte wieder verfügbar machen
        $db->run('UPDATE feed_candidates SET served = 0 WHERE user_id = ? AND spotify_playlist_id = ? AND track_id = ?', [$userId, $playlistId, $trackId]);
        $cand = $db->one('SELECT payload FROM feed_candidates WHERE user_id = ? AND spotify_playlist_id = ? AND track_id = ? ORDER BY id DESC LIMIT 1', [$userId, $playlistId, $trackId]);
        $card = $cand ? $this->card(json_decode((string) $cand['payload'], true) ?: []) : null;
        if ($card) {
            $db->run('UPDATE feed_candidates SET served = 1 WHERE user_id = ? AND spotify_playlist_id = ? AND track_id = ?', [$userId, $playlistId, $trackId]);
        }
        return ['undone' => $a['action'], 'removed_occurrences' => $removed, 'card' => $card, 'id' => $a['action_uuid']];
    }

    public function resetHidden(int $userId, ?string $playlistId): int
    {
        if ($playlistId) {
            PlaylistService::assertId($playlistId);
            return $this->app->db()->exec('DELETE FROM swipe_hidden WHERE user_id = ? AND spotify_playlist_id = ?', [$userId, $playlistId]);
        }
        return $this->app->db()->exec('DELETE FROM swipe_hidden WHERE user_id = ?', [$userId]);
    }
}
