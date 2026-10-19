<?php
declare(strict_types=1);

namespace SwipeGroove\Spotify;

use SwipeGroove\Core\App;
use SwipeGroove\Core\HttpResponse;

/**
 * Spotify Web API Client (Stand der Endpunkte: Web-API-Änderungen Februar 2026).
 *
 * - Playlist-Inhalte laufen über /playlists/{id}/items (nicht mehr /tracks)
 * - Playlist-Erstellung über POST /me/playlists
 * - Bibliothek/Folgen über /me/library mit URIs
 * - Suche liefert im Development Mode maximal 10 Treffer pro Anfrage
 *
 * Alle Aufrufe werden (ohne Tokens) im integration_log protokolliert.
 */
final class SpotifyClient
{
    public const API = 'https://api.spotify.com/v1';
    public const SEARCH_LIMIT = 10;
    public const PAGE_LIMIT = 50;
    public const MAX_ITEMS_PER_WRITE = 100;

    /** Felder für Playlist-Items; `item` ist der neue Schlüssel, `track` wird zur Sicherheit toleriert. */
    private const ITEM_FIELDS = 'total,next,limit,offset,items(added_at,is_local,item(id,uri,name,duration_ms,type,artists(id,name),album(id,name,images)),track(id,uri,name,duration_ms,type,artists(id,name),album(id,name,images)))';

    public function __construct(private App $app)
    {
    }

    /** Basis der Web-API (nur für lokale Tests überschreibbar; Standard: api.spotify.com/v1). */
    private function apiBase(): string
    {
        $v = rtrim($this->app->config?->string('spotify.api_base_url') ?? '', '/');
        return $v !== '' ? $v : self::API;
    }

    // ------------------------------------------------------------------ Low-level

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed>|string|null $body
     */
    public function request(int $userId, string $method, string $path, array $query = [], array|string|null $body = null, ?string $contentType = null): HttpResponse
    {
        $store = $this->app->service(TokenStore::class);
        $token = $store->accessToken($userId);
        $attempt = 0;
        $refreshed = false;
        while (true) {
            $attempt++;
            $resp = $this->raw($method, $path, $query, $body, $token, $contentType, $userId);
            if ($resp->status === 401 && !$refreshed) {
                $refreshed = true;
                $token = $store->accessToken($userId, true, $token);
                continue;
            }
            if ($resp->status === 429) {
                $retryAfter = (int) ($resp->header('retry-after') ?? 2);
                if ($attempt <= 2 && $retryAfter <= 3) {
                    sleep(max(1, $retryAfter));
                    continue;
                }
                throw new SpotifyException(SpotifyException::RATE_LIMITED, 429, 'Spotify-Rate-Limit erreicht', max(1, $retryAfter));
            }
            if ($resp->status >= 500 && $attempt < 3) {
                usleep(400000 * $attempt);
                continue;
            }
            return $resp;
        }
    }

    private function raw(string $method, string $path, array $query, array|string|null $body, string $token, ?string $contentType, int $userId): HttpResponse
    {
        $url = str_starts_with($path, 'http') ? $path : $this->apiBase() . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
        $payload = null;
        if ($body !== null) {
            if (is_array($body)) {
                $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
                $headers['Content-Type'] = 'application/json';
            } else {
                $payload = $body;
                $headers['Content-Type'] = $contentType ?? 'application/json';
            }
        }
        $start = microtime(true);
        try {
            $resp = $this->app->http->request($method, $url, $headers, $payload, ['timeout' => 30]);
        } catch (\Throwable $e) {
            $this->log($method, $path, 0, $start, $userId, $e->getMessage());
            throw new SpotifyException(SpotifyException::UNAVAILABLE, 0, 'Spotify nicht erreichbar');
        }
        $err = null;
        if (!$resp->ok()) {
            $j = $resp->json();
            $err = (string) ($j['error']['message'] ?? $j['error']['reason'] ?? $j['error'] ?? ('HTTP ' . $resp->status));
            if (is_array($j['error'] ?? null) && isset($j['error']['reason'])) {
                $err .= ' [' . $j['error']['reason'] . ']';
            }
        }
        $this->log($method, $path, $resp->status, $start, $userId, $err);
        return $resp;
    }

    private function log(string $method, string $path, int $status, float $start, int $userId, ?string $error): void
    {
        try {
            $endpoint = preg_replace('#/[A-Za-z0-9]{22}(?=/|$)#', '/{id}', (string) parse_url(str_starts_with($path, 'http') ? $path : '/' . $path, PHP_URL_PATH)) ?? $path;
            $this->app->db()->insert('integration_log', [
                'service' => 'spotify', 'method' => $method, 'endpoint' => mb_substr($endpoint, 0, 191), 'status' => $status,
                'duration_ms' => (int) ((microtime(true) - $start) * 1000), 'user_id' => $userId,
                'error' => $error ? mb_substr($error, 0, 255) : null, 'created_at' => $this->app->db()->now(),
            ]);
        } catch (\Throwable) {
        }
    }

    /**
     * Wirft bei Fehlerstatus eine SpotifyException mit passendem Grund.
     * @return array<string,mixed>
     */
    public function expect(HttpResponse $resp, array $okStatuses = [200, 201, 202, 204]): array
    {
        if (in_array($resp->status, $okStatuses, true)) {
            return $resp->json();
        }
        $j = $resp->json();
        $msg = (string) ($j['error']['message'] ?? '');
        $reasonField = (string) ($j['error']['reason'] ?? '');
        $reason = match (true) {
            $resp->status === 403 && (str_contains(strtoupper($reasonField), 'PREMIUM') || str_contains(strtolower($msg), 'premium')) => SpotifyException::PREMIUM_REQUIRED,
            $resp->status === 403 => SpotifyException::FORBIDDEN,
            $resp->status === 404 => SpotifyException::NOT_FOUND,
            $resp->status === 400 => SpotifyException::BAD_REQUEST,
            $resp->status === 401 => SpotifyException::REVOKED,
            $resp->status === 429 => SpotifyException::RATE_LIMITED,
            $resp->status >= 500 || $resp->status === 0 => SpotifyException::UNAVAILABLE,
            default => SpotifyException::UNKNOWN,
        };
        throw new SpotifyException($reason, $resp->status, 'Spotify-Fehler ' . $resp->status . ($msg ? ': ' . $msg : ''), 0, $msg);
    }

    // ------------------------------------------------------------------ Profil

    /** @return array<string,mixed> */
    public function me(string $accessToken): array
    {
        // Direkt mit Token (während des Logins existiert noch kein Nutzer)
        $start = microtime(true);
        try {
            $resp = $this->app->http->request('GET', $this->apiBase() . '/me', ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json']);
        } catch (\Throwable $e) {
            $this->log('GET', 'me', 0, $start, 0, $e->getMessage());
            throw new SpotifyException(SpotifyException::UNAVAILABLE, 0, 'Spotify nicht erreichbar');
        }
        $this->log('GET', 'me', $resp->status, $start, 0, $resp->ok() ? null : 'profile error');
        return $this->expect($resp);
    }

    // ------------------------------------------------------------------ Playlists

    /**
     * Alle Playlists des Nutzers (eigene und gefolgte), vollständig paginiert.
     * @return list<array<string,mixed>>
     */
    public function allPlaylists(int $userId): array
    {
        $out = [];
        $offset = 0;
        $guard = 0;
        do {
            $data = $this->expect($this->request($userId, 'GET', 'me/playlists', ['limit' => self::PAGE_LIMIT, 'offset' => $offset]));
            $items = $data['items'] ?? [];
            foreach ($items as $pl) {
                if (is_array($pl) && !empty($pl['id'])) {
                    $out[] = $pl;
                }
            }
            $offset += self::PAGE_LIMIT;
            $total = (int) ($data['total'] ?? 0);
            $guard++;
        } while (!empty($data['next']) && $offset < $total && $guard < 100);
        return $out;
    }

    /** @return array<string,mixed> */
    public function playlist(int $userId, string $playlistId): array
    {
        return $this->expect($this->request($userId, 'GET', 'playlists/' . $playlistId, [
            'fields' => 'id,name,description,images,owner(id,display_name),collaborative,public,snapshot_id,items(total),tracks(total),external_urls',
        ]));
    }

    /**
     * Alle Items einer Playlist, vollständig paginiert. Bricht bei Fehlern mit Exception ab
     * (unvollständige Listen werden nie als vollständig behandelt).
     * @return array{items:list<array<string,mixed>>, total:int}
     */
    public function allPlaylistItems(int $userId, string $playlistId, int $maxItems = 10000): array
    {
        $items = [];
        $offset = 0;
        $total = 0;
        $guard = 0;
        do {
            $data = $this->expect($this->request($userId, 'GET', 'playlists/' . $playlistId . '/items', [
                'limit' => self::PAGE_LIMIT, 'offset' => $offset, 'fields' => self::ITEM_FIELDS, 'additional_types' => 'track',
            ]));
            $total = (int) ($data['total'] ?? 0);
            foreach ($data['items'] ?? [] as $entry) {
                $items[] = self::normalizeItem(is_array($entry) ? $entry : []);
            }
            $offset += self::PAGE_LIMIT;
            $guard++;
        } while (!empty($data['next']) && $offset < $total && $offset < $maxItems && $guard < 400);
        return ['items' => $items, 'total' => $total];
    }

    /**
     * Normalisiert ein Playlist-Item (neues `item`-Feld, altes `track`-Feld).
     * @return array<string,mixed>
     */
    public static function normalizeItem(array $entry): array
    {
        $t = $entry['item'] ?? $entry['track'] ?? null;
        $t = is_array($t) ? $t : [];
        $artists = [];
        $artistIds = [];
        foreach ($t['artists'] ?? [] as $a) {
            if (!empty($a['name'])) {
                $artists[] = (string) $a['name'];
            }
            if (!empty($a['id'])) {
                $artistIds[] = (string) $a['id'];
            }
        }
        $images = $t['album']['images'] ?? [];
        $image = null;
        if (is_array($images) && $images !== []) {
            usort($images, static fn($a, $b) => ((int) ($b['width'] ?? 0)) <=> ((int) ($a['width'] ?? 0)));
            // mittlere Grösse bevorzugen (300px), sonst grösste
            foreach ($images as $img) {
                if ((int) ($img['width'] ?? 0) <= 320) {
                    $image = $img['url'] ?? null;
                    break;
                }
            }
            $image ??= $images[0]['url'] ?? null;
        }
        return [
            'id' => isset($t['id']) ? (string) $t['id'] : null,
            'uri' => isset($t['uri']) ? (string) $t['uri'] : null,
            'name' => isset($t['name']) ? (string) $t['name'] : null,
            'artists' => $artists,
            'artist_ids' => $artistIds,
            'album_name' => isset($t['album']['name']) ? (string) $t['album']['name'] : null,
            'album_id' => isset($t['album']['id']) ? (string) $t['album']['id'] : null,
            'image_url' => $image,
            'duration_ms' => isset($t['duration_ms']) ? (int) $t['duration_ms'] : null,
            'is_local' => (bool) ($entry['is_local'] ?? false),
            'added_at' => isset($entry['added_at']) ? (string) $entry['added_at'] : null,
            'type' => (string) ($t['type'] ?? 'track'),
        ];
    }

    /** @return array<string,mixed> */
    public function createPlaylist(int $userId, string $name, string $description, bool $public): array
    {
        return $this->expect($this->request($userId, 'POST', 'me/playlists', [], [
            'name' => $name, 'description' => $description, 'public' => $public,
        ]));
    }

    public function changeDetails(int $userId, string $playlistId, array $changes): void
    {
        $this->expect($this->request($userId, 'PUT', 'playlists/' . $playlistId, [], $changes));
    }

    /** @param list<string> $uris @return string|null snapshot_id */
    public function addItems(int $userId, string $playlistId, array $uris, ?int $position = null): ?string
    {
        $snapshot = null;
        foreach (array_chunk(array_values($uris), self::MAX_ITEMS_PER_WRITE) as $i => $chunk) {
            $body = ['uris' => $chunk];
            if ($position !== null) {
                $body['position'] = $position + $i * self::MAX_ITEMS_PER_WRITE;
            }
            $data = $this->expect($this->request($userId, 'POST', 'playlists/' . $playlistId . '/items', [], $body));
            $snapshot = $data['snapshot_id'] ?? $snapshot;
        }
        return $snapshot;
    }

    /**
     * Entfernt Vorkommen. $items: [['uri'=>..., 'positions'=>[..]]] (positions optional = alle Vorkommen).
     * Mit snapshot_id wird Konflikterkennung durch Spotify aktiviert.
     * @return string|null neue snapshot_id
     */
    public function removeItems(int $userId, string $playlistId, array $items, ?string $snapshotId = null): ?string
    {
        $snapshot = null;
        foreach (array_chunk(array_values($items), self::MAX_ITEMS_PER_WRITE) as $chunk) {
            $body = ['items' => $chunk];
            if ($snapshotId !== null) {
                $body['snapshot_id'] = $snapshotId;
            }
            $data = $this->expect($this->request($userId, 'DELETE', 'playlists/' . $playlistId . '/items', [], $body));
            $snapshot = $data['snapshot_id'] ?? $snapshot;
            $snapshotId = $snapshot ?? $snapshotId;
        }
        return $snapshot;
    }

    public function reorderItems(int $userId, string $playlistId, int $rangeStart, int $insertBefore, int $rangeLength = 1, ?string $snapshotId = null): ?string
    {
        $body = ['range_start' => $rangeStart, 'insert_before' => $insertBefore, 'range_length' => $rangeLength];
        if ($snapshotId !== null) {
            $body['snapshot_id'] = $snapshotId;
        }
        $data = $this->expect($this->request($userId, 'PUT', 'playlists/' . $playlistId . '/items', [], $body));
        return $data['snapshot_id'] ?? null;
    }

    /** @param list<string> $uris */
    public function replaceItems(int $userId, string $playlistId, array $uris): ?string
    {
        $data = $this->expect($this->request($userId, 'PUT', 'playlists/' . $playlistId . '/items', [], ['uris' => array_slice($uris, 0, self::MAX_ITEMS_PER_WRITE)]));
        return $data['snapshot_id'] ?? null;
    }

    public function uploadCover(int $userId, string $playlistId, string $jpegBinary): void
    {
        $b64 = base64_encode($jpegBinary);
        if (strlen($b64) > 256 * 1024) {
            throw new SpotifyException(SpotifyException::BAD_REQUEST, 400, 'Cover zu gross (max. 256 KB Base64)');
        }
        $this->expect($this->request($userId, 'PUT', 'playlists/' . $playlistId . '/images', [], $b64, 'image/jpeg'));
    }

    /**
     * Entfernt eine Playlist aus der eigenen Bibliothek (Spotify kennt kein endgültiges Löschen;
     * "Entfolgen" entspricht dem Löschen in der Spotify-App).
     */
    public function unfollowPlaylist(int $userId, string $playlistId): void
    {
        $resp = $this->request($userId, 'DELETE', 'me/library', ['uris' => 'spotify:playlist:' . $playlistId]);
        if ($resp->status === 404 || $resp->status === 405 || $resp->status === 500) {
            // Fallback auf den früheren Endpunkt, falls der generische noch nicht für Playlists greift
            $resp = $this->request($userId, 'DELETE', 'playlists/' . $playlistId . '/followers');
        }
        $this->expect($resp);
    }

    // ------------------------------------------------------------------ Katalog

    /**
     * Suche (max. 10 Treffer pro Aufruf im Development Mode).
     * @return list<array<string,mixed>> normalisierte Tracks
     */
    public function searchTracks(int $userId, string $query, int $offset = 0, int $limit = self::SEARCH_LIMIT): array
    {
        $data = $this->expect($this->request($userId, 'GET', 'search', [
            'q' => $query, 'type' => 'track', 'limit' => min($limit, self::SEARCH_LIMIT), 'offset' => $offset,
        ]));
        $out = [];
        foreach ($data['tracks']['items'] ?? [] as $t) {
            if (is_array($t) && !empty($t['id'])) {
                $out[] = self::normalizeItem(['item' => $t]);
            }
        }
        return $out;
    }

    /** @return list<array{id:string,name:string,image_url:?string}> */
    public function searchArtists(int $userId, string $query): array
    {
        $data = $this->expect($this->request($userId, 'GET', 'search', ['q' => $query, 'type' => 'artist', 'limit' => self::SEARCH_LIMIT]));
        $out = [];
        foreach ($data['artists']['items'] ?? [] as $a) {
            if (is_array($a) && !empty($a['id'])) {
                $out[] = ['id' => (string) $a['id'], 'name' => (string) ($a['name'] ?? ''), 'image_url' => $a['images'][0]['url'] ?? null];
            }
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public function track(int $userId, string $trackId): ?array
    {
        $resp = $this->request($userId, 'GET', 'tracks/' . $trackId);
        if ($resp->status === 404) {
            return null;
        }
        $t = $this->expect($resp);
        return self::normalizeItem(['item' => $t]);
    }

    /**
     * Alben eines Künstlers (für Entdeckungs-Kandidaten).
     * @return list<array{id:string,name:string,image_url:?string}>
     */
    public function artistAlbums(int $userId, string $artistId, int $limit = 10): array
    {
        $data = $this->expect($this->request($userId, 'GET', 'artists/' . $artistId . '/albums', [
            'include_groups' => 'album,single', 'limit' => min(50, $limit),
        ]));
        $out = [];
        foreach ($data['items'] ?? [] as $a) {
            if (is_array($a) && !empty($a['id'])) {
                $out[] = ['id' => (string) $a['id'], 'name' => (string) ($a['name'] ?? ''), 'image_url' => $a['images'][0]['url'] ?? null];
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function albumTracks(int $userId, string $albumId, ?array $albumMeta = null): array
    {
        $data = $this->expect($this->request($userId, 'GET', 'albums/' . $albumId . '/tracks', ['limit' => 50]));
        $out = [];
        foreach ($data['items'] ?? [] as $t) {
            if (is_array($t) && !empty($t['id'])) {
                if ($albumMeta) {
                    $t['album'] = ['id' => $albumId, 'name' => $albumMeta['name'] ?? null, 'images' => $albumMeta['image_url'] ? [['url' => $albumMeta['image_url'], 'width' => 300]] : []];
                }
                $out[] = self::normalizeItem(['item' => $t]);
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ Wiedergabe (Spotify Connect)

    /** @return list<array<string,mixed>> */
    public function devices(int $userId): array
    {
        $data = $this->expect($this->request($userId, 'GET', 'me/player/devices'));
        return array_values(array_filter($data['devices'] ?? [], 'is_array'));
    }

    /** @param string|list<string> $trackUris */
    public function play(int $userId, string|array $trackUris, ?string $deviceId = null, int $offset = 0, int $positionMs = 0): void
    {
        $uris = is_array($trackUris) ? array_values($trackUris) : [$trackUris];
        $query = $deviceId ? ['device_id' => $deviceId] : [];
        $body = ['uris' => $uris];
        if ($offset > 0 && $offset < count($uris)) {
            $body['offset'] = ['position' => $offset];
        }
        if ($positionMs > 0) {
            $body['position_ms'] = $positionMs;
        }
        $this->expect($this->request($userId, 'PUT', 'me/player/play', $query, $body));
    }

    /** Spielt eine ganze Playlist (Kontext) ab Position $offset. */
    public function playContext(int $userId, string $contextUri, ?string $deviceId = null, int $offset = 0, int $positionMs = 0): void
    {
        $query = $deviceId ? ['device_id' => $deviceId] : [];
        $body = ['context_uri' => $contextUri, 'offset' => ['position' => $offset]];
        if ($positionMs > 0) {
            $body['position_ms'] = $positionMs;
        }
        $this->expect($this->request($userId, 'PUT', 'me/player/play', $query, $body));
    }

    public function seek(int $userId, int $positionMs): void
    {
        $this->expect($this->request($userId, 'PUT', 'me/player/seek', ['position_ms' => $positionMs]));
    }

    public function resume(int $userId): void
    {
        $this->expect($this->request($userId, 'PUT', 'me/player/play'));
    }

    public function pause(int $userId): void
    {
        $this->expect($this->request($userId, 'PUT', 'me/player/pause'));
    }

    /** @return array<string,mixed>|null */
    public function playbackState(int $userId): ?array
    {
        $resp = $this->request($userId, 'GET', 'me/player');
        if ($resp->status === 204) {
            return null;
        }
        return $this->expect($resp);
    }
}
