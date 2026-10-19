<?php
declare(strict_types=1);

namespace SwipeGroove\Spotify;

use SwipeGroove\Core\App;
use SwipeGroove\Core\HttpException;

/**
 * Playlist-Manager: Cache, Synchronisation und Schreibzugriffe mit Snapshot-Konflikterkennung.
 */
final class PlaylistService
{
    public function __construct(private App $app)
    {
    }

    private function client(): SpotifyClient
    {
        return $this->app->service(SpotifyClient::class);
    }

    private function ttlSeconds(string $key, int $default): int
    {
        return max(60, $this->app->settings()->int($key, $default) * 60);
    }

    // ------------------------------------------------------------------ Liste & Sync

    /**
     * Synchronisiert die Playlist-Liste (nicht die Inhalte). Gibt die Cache-Zeilen zurück.
     * @return list<array<string,mixed>>
     */
    public function sync(int $userId, bool $force = false): array
    {
        $db = $this->app->db();
        if (!$force) {
            $latest = $db->value('SELECT MAX(synced_at) FROM playlist_cache WHERE user_id = ?', [$userId]);
            if ($latest && strtotime((string) $latest) > time() - $this->ttlSeconds('spotify_cache_ttl_minutes', 30)) {
                return $this->cachedList($userId);
            }
        }
        $account = $this->app->service(TokenStore::class)->account($userId);
        $spotifyUserId = (string) ($account['spotify_user_id'] ?? '');
        $playlists = $this->client()->allPlaylists($userId);
        $now = $db->now();
        $seen = [];
        $db->transaction(function () use ($db, $userId, $playlists, $spotifyUserId, $now, &$seen): void {
            foreach ($playlists as $pl) {
                $id = (string) $pl['id'];
                $seen[] = $id;
                $ownerId = (string) ($pl['owner']['id'] ?? '');
                $isOwner = $ownerId !== '' && $ownerId === $spotifyUserId;
                $collab = (bool) ($pl['collaborative'] ?? false);
                $total = (int) ($pl['items']['total'] ?? $pl['tracks']['total'] ?? 0);
                $existing = $db->one('SELECT id, snapshot_id, track_count FROM playlist_cache WHERE user_id = ? AND spotify_playlist_id = ?', [$userId, $id]);
                $data = [
                    'name' => mb_substr((string) ($pl['name'] ?? ''), 0, 255),
                    'description' => isset($pl['description']) ? html_entity_decode((string) $pl['description'], ENT_QUOTES | ENT_HTML5) : null,
                    'image_url' => $pl['images'][0]['url'] ?? null,
                    'owner_spotify_id' => $ownerId ?: null,
                    'owner_name' => isset($pl['owner']['display_name']) ? mb_substr((string) $pl['owner']['display_name'], 0, 191) : null,
                    'is_owner' => $isOwner ? 1 : 0,
                    'is_collaborative' => $collab ? 1 : 0,
                    'spotify_public' => isset($pl['public']) ? ((bool) $pl['public'] ? 1 : 0) : null,
                    'track_count' => $total,
                    'snapshot_id' => isset($pl['snapshot_id']) ? (string) $pl['snapshot_id'] : null,
                    'can_edit' => ($isOwner || $collab) ? 1 : 0,
                    'synced_at' => $now,
                ];
                if ($existing) {
                    if (($existing['snapshot_id'] ?? null) !== $data['snapshot_id'] || (int) $existing['track_count'] !== $total) {
                        $data['items_complete'] = 0; // Inhalte neu laden
                    }
                    $db->update('playlist_cache', $data, 'id = ?', [$existing['id']]);
                } else {
                    $data['user_id'] = $userId;
                    $data['spotify_playlist_id'] = $id;
                    $db->insert('playlist_cache', $data);
                }
            }
            if ($seen === []) {
                $db->run('DELETE FROM playlist_cache WHERE user_id = ?', [$userId]);
            } else {
                $placeholders = implode(',', array_fill(0, count($seen), '?'));
                $db->run('DELETE FROM playlist_cache WHERE user_id = ? AND spotify_playlist_id NOT IN (' . $placeholders . ')', array_merge([$userId], $seen));
            }
        });
        $this->refreshPublishedMeta($userId);
        return $this->cachedList($userId);
    }

    /** @return list<array<string,mixed>> */
    public function cachedList(int $userId): array
    {
        $rows = $this->app->db()->all(
            'SELECT c.*, p.is_published, p.id AS published_id, p.like_count, p.comment_count
             FROM playlist_cache c
             LEFT JOIN published_playlists p ON p.user_id = c.user_id AND p.spotify_playlist_id = c.spotify_playlist_id
             WHERE c.user_id = ? ORDER BY c.name ASC',
            [$userId]
        );
        return array_map([$this, 'presentRow'], $rows);
    }

    /** @return array<string,mixed> */
    public function presentRow(array $c): array
    {
        return [
            'id' => $c['spotify_playlist_id'],
            'name' => $c['name'],
            'description' => $c['description'],
            'image_url' => $c['image_url'],
            'owner_name' => $c['owner_name'],
            'is_owner' => (bool) $c['is_owner'],
            'is_collaborative' => (bool) $c['is_collaborative'],
            'can_edit' => (bool) $c['can_edit'],
            'spotify_public' => $c['spotify_public'] === null ? null : (bool) $c['spotify_public'],
            'track_count' => (int) $c['track_count'],
            'snapshot_id' => $c['snapshot_id'],
            'is_published' => (bool) ($c['is_published'] ?? 0),
            'published_id' => isset($c['published_id']) ? (int) $c['published_id'] : null,
            'like_count' => (int) ($c['like_count'] ?? 0),
            'comment_count' => (int) ($c['comment_count'] ?? 0),
            'synced_at' => $c['synced_at'],
            'items_loaded' => (bool) ($c['items_complete'] ?? 0),
            'spotify_url' => 'https://open.spotify.com/playlist/' . $c['spotify_playlist_id'],
        ];
    }

    /** @return array<string,mixed> Cache-Zeile */
    public function cacheRow(int $userId, string $playlistId): array
    {
        self::assertId($playlistId);
        $sql = 'SELECT c.*, p.is_published, p.id AS published_id, p.like_count, p.comment_count FROM playlist_cache c
                LEFT JOIN published_playlists p ON p.user_id = c.user_id AND p.spotify_playlist_id = c.spotify_playlist_id
                WHERE c.user_id = ? AND c.spotify_playlist_id = ?';
        $row = $this->app->db()->one($sql, [$userId, $playlistId]);
        if (!$row) {
            // Evtl. neu – Liste synchronisieren
            $this->sync($userId, true);
            $row = $this->app->db()->one($sql, [$userId, $playlistId]);
        }
        if (!$row) {
            throw HttpException::notFound('playlist.not_found');
        }
        return $row;
    }

    public function assertEditable(array $row): void
    {
        if (!(int) $row['can_edit']) {
            throw HttpException::forbidden('playlist.not_editable');
        }
    }

    public static function assertId(string $id): void
    {
        if (!preg_match('/^[A-Za-z0-9]{10,40}$/', $id)) {
            throw HttpException::badRequest('playlist.invalid_id');
        }
    }

    // ------------------------------------------------------------------ Inhalte

    /**
     * Lädt alle Items (Cache oder Spotify). Nur ein vollständig geladener Satz gilt als vollständig.
     * @return array{playlist:array<string,mixed>, items:list<array<string,mixed>>, complete:bool}
     */
    public function items(int $userId, string $playlistId, bool $force = false): array
    {
        $db = $this->app->db();
        $row = $this->cacheRow($userId, $playlistId);
        $fresh = $row['items_synced_at'] && strtotime((string) $row['items_synced_at']) > time() - $this->ttlSeconds('spotify_items_cache_ttl_minutes', 15);
        if (!$force && (int) $row['items_complete'] === 1 && $fresh) {
            $items = $this->cachedItems((int) $row['id']);
            $this->healPublishedTracks($userId, $playlistId, $items, (string) $row['snapshot_id']);
            return ['playlist' => $this->presentRow($row), 'items' => $items, 'complete' => true];
        }
        $meta = $this->client()->playlist($userId, $playlistId);
        $result = $this->client()->allPlaylistItems($userId, $playlistId);
        $now = $db->now();
        $db->transaction(function () use ($db, $row, $result, $meta, $now): void {
            $db->run('DELETE FROM playlist_items_cache WHERE playlist_cache_id = ?', [$row['id']]);
            $stmt = $db->pdo()->prepare('INSERT INTO playlist_items_cache (playlist_cache_id, position, track_id, uri, name, artists, artist_ids, album_name, album_id, image_url, duration_ms, is_local, added_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($result['items'] as $pos => $it) {
                $stmt->execute([
                    $row['id'], $pos, $it['id'], $it['uri'] !== null ? mb_substr($it['uri'], 0, 255) : null, $it['name'] !== null ? mb_substr($it['name'], 0, 255) : null,
                    json_encode($it['artists'], JSON_UNESCAPED_UNICODE), json_encode($it['artist_ids']),
                    $it['album_name'] !== null ? mb_substr($it['album_name'], 0, 255) : null, $it['album_id'], $it['image_url'], $it['duration_ms'], (int) $it['is_local'],
                    $it['added_at'] ? gmdate('Y-m-d H:i:s', strtotime($it['added_at']) ?: time()) : null,
                ]);
            }
            $db->update('playlist_cache', [
                'items_synced_at' => $now,
                'items_complete' => 1,
                'track_count' => count($result['items']),
                'snapshot_id' => isset($meta['snapshot_id']) ? (string) $meta['snapshot_id'] : $row['snapshot_id'],
                'name' => mb_substr((string) ($meta['name'] ?? $row['name']), 0, 255),
                'description' => isset($meta['description']) ? html_entity_decode((string) $meta['description'], ENT_QUOTES | ENT_HTML5) : $row['description'],
                'image_url' => $meta['images'][0]['url'] ?? $row['image_url'],
                'spotify_public' => isset($meta['public']) ? ((bool) $meta['public'] ? 1 : 0) : $row['spotify_public'],
            ], 'id = ?', [$row['id']]);
        });
        $row = $this->cacheRow($userId, $playlistId);
        $items = $this->cachedItems((int) $row['id']);
        $this->healPublishedTracks($userId, $playlistId, $items, (string) $row['snapshot_id']);
        return ['playlist' => $this->presentRow($row), 'items' => $items, 'complete' => true];
    }

    /** Veröffentlichte Songliste nachziehen, wenn sie leer oder veraltet ist (nach frischem Laden der Inhalte). */
    private function healPublishedTracks(int $userId, string $playlistId, array $items, string $snapshot): void
    {
        $db = $this->app->db();
        $pub = $db->one('SELECT id, snapshot_id FROM published_playlists WHERE user_id = ? AND spotify_playlist_id = ? AND is_published = 1', [$userId, $playlistId]);
        if (!$pub || $items === []) {
            return;
        }
        $stored = (int) $db->value('SELECT COUNT(*) FROM published_playlist_tracks WHERE published_playlist_id = ?', [$pub['id']]);
        if ($stored === 0 || $pub['snapshot_id'] !== $snapshot) {
            $this->storePublishedTracks((int) $pub['id'], $items);
            $db->update('published_playlists', ['snapshot_id' => $snapshot, 'track_count' => count($items), 'updated_at' => $db->now()], 'id = ?', [$pub['id']]);
        }
    }

    /** @return list<array<string,mixed>> */
    public function cachedItems(int $cacheId): array
    {
        $rows = $this->app->db()->all('SELECT * FROM playlist_items_cache WHERE playlist_cache_id = ? ORDER BY position ASC', [$cacheId]);
        return array_map(static fn(array $r): array => [
            'position' => (int) $r['position'],
            'id' => $r['track_id'],
            'uri' => $r['uri'],
            'name' => $r['name'],
            'artists' => json_decode((string) $r['artists'], true) ?: [],
            'artist_ids' => json_decode((string) $r['artist_ids'], true) ?: [],
            'album_name' => $r['album_name'],
            'album_id' => $r['album_id'],
            'image_url' => $r['image_url'],
            'duration_ms' => $r['duration_ms'] !== null ? (int) $r['duration_ms'] : null,
            'is_local' => (bool) $r['is_local'],
            'added_at' => $r['added_at'],
            'spotify_url' => $r['track_id'] ? 'https://open.spotify.com/track/' . $r['track_id'] : null,
        ], $rows);
    }

    /** Markiert die Inhalte als veraltet (nach Schreibzugriffen). */
    public function invalidateItems(int $userId, string $playlistId, ?int $trackCountDelta = null, ?string $snapshot = null): void
    {
        $db = $this->app->db();
        $sql = 'UPDATE playlist_cache SET items_complete = 0, items_synced_at = NULL';
        $params = [];
        if ($trackCountDelta !== null) {
            $sql .= ', track_count = GREATEST(0, CAST(track_count AS SIGNED) + ?)';
            $params[] = $trackCountDelta;
        }
        if ($snapshot !== null) {
            $sql .= ', snapshot_id = ?';
            $params[] = $snapshot;
        }
        $sql .= ' WHERE user_id = ? AND spotify_playlist_id = ?';
        $params[] = $userId;
        $params[] = $playlistId;
        $db->run($sql, $params);
    }

    /** Aktuelle Snapshot-ID bei Spotify abfragen und mit der erwarteten vergleichen. */
    public function assertSnapshot(int $userId, string $playlistId, ?string $expected): string
    {
        $meta = $this->client()->playlist($userId, $playlistId);
        $current = (string) ($meta['snapshot_id'] ?? '');
        if ($expected !== null && $expected !== '' && $current !== '' && $current !== $expected) {
            throw HttpException::conflict('playlist.snapshot_conflict', ['snapshot_id' => $current]);
        }
        return $current;
    }

    // ------------------------------------------------------------------ Schreibzugriffe

    /** @return array<string,mixed> */
    public function create(int $userId, string $name, string $description, bool $public): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw HttpException::validation(['name' => 'validation.playlist_name']);
        }
        $pl = $this->client()->createPlaylist($userId, $name, mb_substr(trim($description), 0, 300), $public);
        $this->sync($userId, true);
        return $this->presentRow($this->cacheRow($userId, (string) $pl['id']));
    }

    public function updateDetails(int $userId, string $playlistId, array $changes): array
    {
        $row = $this->cacheRow($userId, $playlistId);
        $this->assertEditable($row);
        $payload = [];
        if (array_key_exists('name', $changes)) {
            $n = trim((string) $changes['name']);
            if ($n === '' || mb_strlen($n) > 100) {
                throw HttpException::validation(['name' => 'validation.playlist_name']);
            }
            $payload['name'] = $n;
        }
        if (array_key_exists('description', $changes)) {
            $payload['description'] = mb_substr(trim((string) $changes['description']), 0, 300);
        }
        if (array_key_exists('public', $changes) && (int) $row['is_owner'] === 1) {
            $payload['public'] = (bool) $changes['public'];
        }
        if ($payload === []) {
            return $this->presentRow($row);
        }
        $this->client()->changeDetails($userId, $playlistId, $payload);
        $update = [];
        if (isset($payload['name'])) {
            $update['name'] = mb_substr($payload['name'], 0, 255);
        }
        if (isset($payload['description'])) {
            $update['description'] = $payload['description'];
        }
        if (array_key_exists('public', $payload)) {
            $update['spotify_public'] = $payload['public'] ? 1 : 0;
        }
        $this->app->db()->update('playlist_cache', $update, 'id = ?', [$row['id']]);
        $this->refreshPublishedMeta($userId, $playlistId);
        return $this->presentRow($this->cacheRow($userId, $playlistId));
    }

    public function uploadCover(int $userId, string $playlistId, string $jpeg): void
    {
        $row = $this->cacheRow($userId, $playlistId);
        $this->assertEditable($row);
        $this->client()->uploadCover($userId, $playlistId, $jpeg);
        // Spotify liefert die neue Bild-URL verzögert; Liste beim nächsten Sync erneuern
        $this->app->db()->update('playlist_cache', ['synced_at' => '2000-01-01 00:00:00'], 'id = ?', [$row['id']]);
    }

    /** @param list<string> $uris */
    public function addItems(int $userId, string $playlistId, array $uris, ?int $position = null): array
    {
        $row = $this->cacheRow($userId, $playlistId);
        $this->assertEditable($row);
        $uris = self::cleanUris($uris);
        if ($uris === []) {
            throw HttpException::badRequest('playlist.no_items');
        }
        $snapshot = $this->client()->addItems($userId, $playlistId, $uris, $position);
        $this->invalidateItems($userId, $playlistId, count($uris), $snapshot);
        return ['snapshot_id' => $snapshot, 'added' => count($uris)];
    }

    /**
     * Entfernt bestimmte Vorkommen (uri + positions). Prüft vorher den Snapshot.
     * @param list<array{uri:string, positions:list<int>}> $items
     */
    public function removeItems(int $userId, string $playlistId, array $items, ?string $expectedSnapshot): array
    {
        $row = $this->cacheRow($userId, $playlistId);
        $this->assertEditable($row);
        $clean = [];
        $count = 0;
        foreach ($items as $it) {
            if (!is_array($it) || empty($it['uri']) || !self::isTrackUri((string) $it['uri'])) {
                continue;
            }
            $positions = array_values(array_unique(array_map('intval', array_filter((array) ($it['positions'] ?? []), 'is_numeric'))));
            $entry = ['uri' => (string) $it['uri']];
            if ($positions !== []) {
                $entry['positions'] = $positions;
                $count += count($positions);
            } else {
                $count++;
            }
            $clean[] = $entry;
        }
        if ($clean === []) {
            throw HttpException::badRequest('playlist.no_items');
        }
        $current = $this->assertSnapshot($userId, $playlistId, $expectedSnapshot);
        $snapshot = $this->client()->removeItems($userId, $playlistId, $clean, $current ?: null);
        $this->invalidateItems($userId, $playlistId, -$count, $snapshot);
        return ['snapshot_id' => $snapshot, 'removed' => $count];
    }

    public function reorder(int $userId, string $playlistId, int $rangeStart, int $insertBefore, int $rangeLength, ?string $expectedSnapshot): array
    {
        $row = $this->cacheRow($userId, $playlistId);
        $this->assertEditable($row);
        if ($rangeStart < 0 || $insertBefore < 0 || $rangeLength < 1 || $rangeLength > 100) {
            throw HttpException::badRequest();
        }
        $current = $this->assertSnapshot($userId, $playlistId, $expectedSnapshot);
        $snapshot = $this->client()->reorderItems($userId, $playlistId, $rangeStart, $insertBefore, $rangeLength, $current ?: null);
        // Lokalen Cache passend umsortieren statt komplett neu zu laden
        $db = $this->app->db();
        $items = $this->cachedItems((int) $row['id']);
        if ((int) $row['items_complete'] === 1 && $items !== []) {
            $moved = array_splice($items, $rangeStart, $rangeLength);
            $target = $insertBefore > $rangeStart ? $insertBefore - $rangeLength : $insertBefore;
            array_splice($items, $target, 0, $moved);
            $db->transaction(function () use ($db, $row, $items): void {
                $db->run('DELETE FROM playlist_items_cache WHERE playlist_cache_id = ?', [$row['id']]);
                $stmt = $db->pdo()->prepare('INSERT INTO playlist_items_cache (playlist_cache_id, position, track_id, uri, name, artists, artist_ids, album_name, album_id, image_url, duration_ms, is_local, added_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
                foreach ($items as $pos => $it) {
                    $stmt->execute([$row['id'], $pos, $it['id'], $it['uri'], $it['name'], json_encode($it['artists'], JSON_UNESCAPED_UNICODE), json_encode($it['artist_ids']), $it['album_name'], $it['album_id'], $it['image_url'], $it['duration_ms'], (int) $it['is_local'], $it['added_at']]);
                }
            });
            $db->update('playlist_cache', ['snapshot_id' => $snapshot], 'id = ?', [$row['id']]);
        } else {
            $this->invalidateItems($userId, $playlistId, null, $snapshot);
        }
        return ['snapshot_id' => $snapshot];
    }

    /** Entfernt die Playlist aus der eigenen Bibliothek (Entfolgen – kein endgültiges Löschen). */
    public function unfollow(int $userId, string $playlistId): void
    {
        $row = $this->cacheRow($userId, $playlistId);
        $this->client()->unfollowPlaylist($userId, $playlistId);
        $db = $this->app->db();
        $db->run('DELETE FROM playlist_cache WHERE id = ?', [$row['id']]);
        $db->run('UPDATE published_playlists SET is_published = 0, updated_at = ? WHERE user_id = ? AND spotify_playlist_id = ?', [$db->now(), $userId, $playlistId]);
    }

    // ------------------------------------------------------------------ Duplikate

    /**
     * Doppelte Einträge: dieselbe Spotify-Track-ID innerhalb der Playlist.
     * Remixe/Live-Versionen (andere IDs) werden nicht gleichgesetzt.
     * @return array{playlist:array<string,mixed>, groups:list<array<string,mixed>>, snapshot_id:?string}
     */
    public function duplicates(int $userId, string $playlistId): array
    {
        $data = $this->items($userId, $playlistId);
        $byId = [];
        foreach ($data['items'] as $it) {
            if (!$it['id']) {
                continue;
            }
            $byId[$it['id']][] = $it;
        }
        $groups = [];
        foreach ($byId as $id => $list) {
            if (count($list) < 2) {
                continue;
            }
            $groups[] = [
                'track_id' => $id,
                'uri' => $list[0]['uri'],
                'name' => $list[0]['name'],
                'artists' => $list[0]['artists'],
                'album_name' => $list[0]['album_name'],
                'image_url' => $list[0]['image_url'],
                'occurrences' => array_map(static fn(array $i): array => ['position' => $i['position'], 'added_at' => $i['added_at']], $list),
                'keep_position' => $list[0]['position'],
            ];
        }
        return ['playlist' => $data['playlist'], 'groups' => $groups, 'snapshot_id' => $data['playlist']['snapshot_id']];
    }

    // ------------------------------------------------------------------ Leere Playlists

    /**
     * Leere Playlists – nur solche, deren Inhalt erfolgreich geprüft wurde.
     * @return array{candidates:list<array<string,mixed>>, unverified:list<string>}
     */
    public function emptyCandidates(int $userId): array
    {
        $list = $this->sync($userId);
        $candidates = [];
        $unverified = [];
        $checked = 0;
        foreach ($list as $pl) {
            if ($pl['track_count'] !== 0) {
                continue;
            }
            if ($checked >= 60) {
                $unverified[] = $pl['id'];
                continue;
            }
            $checked++;
            try {
                $meta = $this->client()->playlist($userId, $pl['id']);
                $total = (int) ($meta['items']['total'] ?? $meta['tracks']['total'] ?? -1);
            } catch (SpotifyException $e) {
                if ($e->reason === SpotifyException::RATE_LIMITED) {
                    $unverified[] = $pl['id'];
                    break;
                }
                $unverified[] = $pl['id'];
                continue;
            }
            if ($total === 0) {
                $pl['verified_empty'] = true;
                $candidates[] = $pl;
            } elseif ($total > 0) {
                $this->app->db()->run('UPDATE playlist_cache SET track_count = ? WHERE user_id = ? AND spotify_playlist_id = ?', [$total, $userId, $pl['id']]);
            } else {
                $unverified[] = $pl['id'];
            }
        }
        return ['candidates' => $candidates, 'unverified' => $unverified];
    }

    /**
     * Entfernt ausgewählte leere Playlists aus der Bibliothek, nach erneuter Prüfung.
     * @param list<string> $ids
     * @return list<array{id:string, status:string, reason?:string}>
     */
    public function removeEmpty(int $userId, array $ids): array
    {
        $results = [];
        foreach (array_slice(array_values(array_unique($ids)), 0, 100) as $id) {
            $id = (string) $id;
            try {
                self::assertId($id);
                $meta = $this->client()->playlist($userId, $id);
                $total = (int) ($meta['items']['total'] ?? $meta['tracks']['total'] ?? -1);
                if ($total !== 0) {
                    $results[] = ['id' => $id, 'status' => 'skipped', 'reason' => 'not_empty'];
                    continue;
                }
                $this->client()->unfollowPlaylist($userId, $id);
                $this->app->db()->run('DELETE FROM playlist_cache WHERE user_id = ? AND spotify_playlist_id = ?', [$userId, $id]);
                $results[] = ['id' => $id, 'status' => 'removed'];
            } catch (SpotifyException $e) {
                $results[] = ['id' => $id, 'status' => 'failed', 'reason' => $e->reason];
                if ($e->reason === SpotifyException::RATE_LIMITED) {
                    break;
                }
            } catch (HttpException $e) {
                $results[] = ['id' => $id, 'status' => 'failed', 'reason' => $e->errorCode];
            }
        }
        return $results;
    }

    // ------------------------------------------------------------------ Veröffentlichung auf SwipeGroove

    public function setPublished(int $userId, string $playlistId, bool $published): array
    {
        $db = $this->app->db();
        $row = $this->cacheRow($userId, $playlistId);
        if ($published && !(int) $row['is_owner']) {
            throw HttpException::forbidden('playlist.publish_own_only');
        }
        $now = $db->now();
        $existing = $db->one('SELECT * FROM published_playlists WHERE user_id = ? AND spotify_playlist_id = ?', [$userId, $playlistId]);
        if ($published) {
            // Inhalte für die Community-Ansicht zwischenspeichern (ohne weitere Spotify-Aufrufe durch Betrachter)
            $data = $this->items($userId, $playlistId);
            $values = [
                'name' => $row['name'], 'description' => $row['description'], 'image_url' => $row['image_url'],
                'track_count' => count($data['items']), 'snapshot_id' => $row['snapshot_id'],
                'is_published' => 1, 'updated_at' => $now,
            ];
            if ($existing) {
                if (!(int) $existing['is_published']) {
                    $values['published_at'] = $now;
                }
                $db->update('published_playlists', $values, 'id = ?', [$existing['id']]);
                $pubId = (int) $existing['id'];
            } else {
                $values += ['user_id' => $userId, 'spotify_playlist_id' => $playlistId, 'published_at' => $now, 'created_at' => $now];
                $pubId = $db->insert('published_playlists', $values);
            }
            $this->storePublishedTracks($pubId, $data['items']);
            if (!$existing || !(int) $existing['is_published']) {
                $db->insert('activities', ['user_id' => $userId, 'type' => 'published', 'published_playlist_id' => $pubId, 'created_at' => $now]);
            }
        } elseif ($existing) {
            $db->update('published_playlists', ['is_published' => 0, 'updated_at' => $now], 'id = ?', [$existing['id']]);
        }
        return $this->presentRow($this->cacheRow($userId, $playlistId));
    }

    private function storePublishedTracks(int $pubId, array $items): void
    {
        $db = $this->app->db();
        $db->transaction(function () use ($db, $pubId, $items): void {
            $db->run('DELETE FROM published_playlist_tracks WHERE published_playlist_id = ?', [$pubId]);
            $stmt = $db->pdo()->prepare('INSERT INTO published_playlist_tracks (published_playlist_id, position, track_id, name, artists, album_name, image_url, duration_ms) VALUES (?,?,?,?,?,?,?,?)');
            foreach (array_slice($items, 0, 500) as $pos => $it) {
                $stmt->execute([$pubId, $pos, $it['id'], $it['name'], mb_substr(implode(', ', $it['artists']), 0, 500), $it['album_name'], $it['image_url'], $it['duration_ms']]);
            }
        });
    }

    /** Aktualisiert Name/Cover veröffentlichter Playlists nach einem Sync. */
    public function refreshPublishedMeta(int $userId, ?string $playlistId = null): void
    {
        $db = $this->app->db();
        $sql = 'SELECT p.id AS pub_id, p.snapshot_id AS pub_snapshot, c.* FROM published_playlists p JOIN playlist_cache c ON c.user_id = p.user_id AND c.spotify_playlist_id = p.spotify_playlist_id WHERE p.user_id = ? AND p.is_published = 1';
        $params = [$userId];
        if ($playlistId) {
            $sql .= ' AND p.spotify_playlist_id = ?';
            $params[] = $playlistId;
        }
        foreach ($db->all($sql, $params) as $r) {
            $db->update('published_playlists', [
                'name' => $r['name'], 'description' => $r['description'], 'image_url' => $r['image_url'],
                'track_count' => (int) $r['track_count'], 'updated_at' => $db->now(),
            ], 'id = ?', [$r['pub_id']]);
            $cached = (int) $r['items_complete'] === 1 ? $this->cachedItems((int) $r['id']) : [];
            // Nie mit einer leeren Liste überschreiben, wenn die Playlist Songs hat
            if ($r['pub_snapshot'] !== $r['snapshot_id'] && $cached !== []) {
                $this->storePublishedTracks((int) $r['pub_id'], $cached);
                $db->update('published_playlists', ['snapshot_id' => $r['snapshot_id']], 'id = ?', [$r['pub_id']]);
            }
        }
    }

    // ------------------------------------------------------------------ Hilfen

    /** @param list<mixed> $uris @return list<string> */
    public static function cleanUris(array $uris): array
    {
        $out = [];
        foreach ($uris as $u) {
            if (is_string($u) && self::isTrackUri($u)) {
                $out[] = $u;
            } elseif (is_string($u) && preg_match('/^[A-Za-z0-9]{22}$/', $u)) {
                $out[] = 'spotify:track:' . $u;
            }
        }
        return array_values(array_unique($out));
    }

    public static function isTrackUri(string $uri): bool
    {
        return (bool) preg_match('/^spotify:(track|episode):[A-Za-z0-9]{22}$/', $uri);
    }
}
