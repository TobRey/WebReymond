<?php
declare(strict_types=1);

namespace SwipeGroove\Tests;

use SwipeGroove\Core\HttpClientInterface;
use SwipeGroove\Core\HttpResponse;

/**
 * Simulierter HTTP-Transport: Spotify (Accounts + Web API, Februar-2026-Endpunkte),
 * OpenAI Images und Stripe. Hält Playlist-Zustand inkl. Snapshot-IDs.
 * Ausschliesslich für Tests – diese Daten erscheinen nie in der echten Anwendung.
 */
final class FakeHttp implements HttpClientInterface
{
    /** @var array<string,array{id:string,name:string,description:string,owner:string,collaborative:bool,public:bool,items:list<string>,snapshot:int}> */
    public array $playlists = [];
    /** @var array<string,array{id:string,name:string,artists:list<array{id:string,name:string}>,album:array{id:string,name:string}}> */
    public array $tracks = [];
    /** @var array<string,string> token => spotify user id */
    public array $tokens = [];
    /** @var array<string,array{id:string,display_name:string}> */
    public array $users = [];
    /** @var list<array{method:string,url:string,body:?string}> */
    public array $log = [];
    /** @var array<string,string> refresh token => user */
    public array $refreshTokens = [];
    public bool $rotateRefresh = true;
    public int $tokenCounter = 0;
    /** Fehlerinjektion: Schlüssel "METHOD path-substring" => [status, body, remaining count] */
    public array $failures = [];
    public ?int $rateLimitNext = null;
    public bool $premiumUsers = true;
    public array $openaiResponses = [];
    public array $stripeResponses = [];
    public string $openaiImageBytes = '';
    public array $libraryUnfollowed = [];
    /** Verzögerung (Sekunden) simulierter Antworten – 0 in Tests */
    public int $searchLimitCap = 10;
    /** Vom Nutzer zuletzt erteilte Scopes (Fake-Autorisierung). */
    public string $grantScope = 'playlist-read-private playlist-modify-public playlist-modify-private ugc-image-upload';
    /** @var list<array<string,mixed>> Wiedergabe-Aufrufe */
    public array $plays = [];

    public function __construct()
    {
        $this->users['spotify_owner'] = ['id' => 'spotify_owner', 'display_name' => 'Owner Person'];
        $this->users['spotify_user2'] = ['id' => 'spotify_user2', 'display_name' => 'Second User'];
        $this->users['spotify_user3'] = ['id' => 'spotify_user3', 'display_name' => 'Third User'];
        for ($i = 1; $i <= 60; $i++) {
            $id = str_pad('track' . $i, 22, 'x');
            $this->tracks[$id] = [
                'id' => $id, 'name' => 'Song ' . $i, 'duration_ms' => 180000 + $i * 1000,
                'artists' => [['id' => str_pad('artist' . (($i % 6) + 1), 22, 'a'), 'name' => 'Artist ' . (($i % 6) + 1)]],
                'album' => ['id' => str_pad('album' . (($i % 10) + 1), 22, 'b'), 'name' => 'Album ' . (($i % 10) + 1), 'images' => [['url' => 'https://i.scdn.co/image/' . $i, 'width' => 300, 'height' => 300]]],
            ];
        }
        // Bild für OpenAI-Fake
        $img = imagecreatetruecolor(64, 64);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 50, 120));
        ob_start();
        imagepng($img);
        $this->openaiImageBytes = (string) ob_get_clean();
    }

    public function trackId(int $n): string
    {
        return str_pad('track' . $n, 22, 'x');
    }

    public function addPlaylist(string $id, string $owner, string $name, array $trackNums = [], bool $collaborative = false, bool $public = true): void
    {
        $this->playlists[$id] = [
            'id' => $id, 'name' => $name, 'description' => '', 'owner' => $owner, 'collaborative' => $collaborative, 'public' => $public,
            'items' => array_map(fn($n) => $this->trackId($n), $trackNums), 'snapshot' => 1,
        ];
    }

    public function snapshot(string $playlistId): string
    {
        return 'snap-' . $playlistId . '-' . $this->playlists[$playlistId]['snapshot'];
    }

    public function issueCode(string $spotifyUser): string
    {
        return 'code-' . $spotifyUser . '-' . bin2hex(random_bytes(4));
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse
    {
        $this->log[] = ['method' => $method, 'url' => $url, 'body' => $body];
        foreach ($this->failures as $key => &$f) {
            [$m, $sub] = explode(' ', $key, 2);
            if ($m === $method && str_contains($url, $sub) && $f[2] > 0) {
                $f[2]--;
                return new HttpResponse($f[0], $f[3] ?? [], is_string($f[1]) ? $f[1] : json_encode($f[1]));
            }
        }
        unset($f);
        if (str_starts_with($url, 'https://accounts.spotify.com/api/token')) {
            return $this->token($body ?? '');
        }
        if (str_starts_with($url, 'https://api.spotify.com/v1/')) {
            return $this->spotify($method, substr($url, strlen('https://api.spotify.com/v1/')), $headers, $body);
        }
        if (str_starts_with($url, 'https://api.openai.com/')) {
            return $this->openai($body ?? '');
        }
        if (str_starts_with($url, 'https://api.stripe.com/')) {
            return $this->stripe($method, $url, $body);
        }
        return new HttpResponse(404, [], '{"error":"unknown host"}');
    }

    private function json(int $status, array $data, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, $headers, json_encode($data, JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    private function token(string $body): HttpResponse
    {
        parse_str($body, $p);
        if (($p['grant_type'] ?? '') === 'authorization_code') {
            $code = (string) ($p['code'] ?? '');
            if (!preg_match('/^code-(.+)-[0-9a-f]{8}$/', $code, $m) || empty($p['code_verifier'])) {
                return $this->json(400, ['error' => 'invalid_grant', 'error_description' => 'Invalid authorization code']);
            }
            return $this->issueTokens($m[1]);
        }
        if (($p['grant_type'] ?? '') === 'refresh_token') {
            $rt = (string) ($p['refresh_token'] ?? '');
            if (!isset($this->refreshTokens[$rt])) {
                return $this->json(400, ['error' => 'invalid_grant', 'error_description' => 'Refresh token revoked']);
            }
            $user = $this->refreshTokens[$rt];
            if ($this->rotateRefresh) {
                unset($this->refreshTokens[$rt]);
            }
            return $this->issueTokens($user, $this->rotateRefresh ? null : $rt);
        }
        return $this->json(400, ['error' => 'unsupported_grant_type']);
    }

    private function issueTokens(string $user, ?string $keepRefresh = null): HttpResponse
    {
        $this->tokenCounter++;
        $access = 'access-' . $user . '-' . $this->tokenCounter;
        $this->tokens[$access] = $user;
        $refresh = $keepRefresh ?? ('refresh-' . $user . '-' . $this->tokenCounter);
        $this->refreshTokens[$refresh] = $user;
        return $this->json(200, ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => 3600, 'refresh_token' => $refresh, 'scope' => $this->grantScope]);
    }

    public function expireAccessTokens(): void
    {
        $this->tokens = [];
    }

    public function revokeAll(): void
    {
        $this->tokens = [];
        $this->refreshTokens = [];
    }

    private function spotify(string $method, string $path, array $headers, ?string $body): HttpResponse
    {
        $auth = $headers['Authorization'] ?? '';
        $token = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
        if (!isset($this->tokens[$token])) {
            return $this->json(401, ['error' => ['status' => 401, 'message' => 'The access token expired']]);
        }
        if ($this->rateLimitNext !== null) {
            $ra = $this->rateLimitNext;
            $this->rateLimitNext = null;
            return $this->json(429, ['error' => ['status' => 429, 'message' => 'API rate limit exceeded']], ['retry-after' => (string) $ra]);
        }
        $user = $this->tokens[$token];
        $parts = parse_url($path);
        $p = trim((string) ($parts['path'] ?? ''), '/');
        parse_str((string) ($parts['query'] ?? ''), $q);
        $data = $body ? (json_decode($body, true) ?? []) : [];
        $seg = explode('/', $p);

        if ($p === 'me/player/play' && $method === 'PUT') {
            if (!$this->premiumUsers) {
                return $this->json(403, ['error' => ['status' => 403, 'message' => 'Player command failed: Premium required', 'reason' => 'PREMIUM_REQUIRED']]);
            }
            $this->plays[] = ['user' => $user, 'device' => $q['device_id'] ?? null] + $data;
            return new HttpResponse(204, [], '');
        }
        if ($p === 'me/player/seek' && $method === 'PUT') {
            $this->plays[] = ['user' => $user, 'seek' => (int) ($q['position_ms'] ?? -1)];
            return new HttpResponse(204, [], '');
        }
        if ($p === 'me/player' && $method === 'GET') {
            $last = null;
            foreach (array_reverse($this->plays) as $pl) {
                if (($pl['user'] ?? '') === $user && (isset($pl['uris']) || isset($pl['context_uri']))) {
                    $last = $pl;
                    break;
                }
            }
            if (!$last) {
                return new HttpResponse(204, [], '');
            }
            $tid = isset($last['uris']) ? substr((string) $last['uris'][0], 14) : 'ctxtrack000000000000001';
            return $this->json(200, ['is_playing' => true, 'progress_ms' => 42000, 'item' => ['id' => $tid, 'name' => 'Playing ' . $tid, 'duration_ms' => 200000, 'artists' => [['name' => 'Fake Artist']], 'album' => ['images' => [['url' => 'https://i.scdn.co/image/x']]]]]);
        }
        if ($p === 'me/player/pause' && $method === 'PUT') {
            return new HttpResponse(204, [], '');
        }
        if ($p === 'me' || $p === 'me/') {
            return $this->json(200, ['id' => $user, 'display_name' => $this->users[$user]['display_name'] ?? $user, 'images' => [['url' => 'https://i.scdn.co/image/' . $user, 'width' => 300]], 'uri' => 'spotify:user:' . $user]);
        }
        if ($p === 'me/playlists' && $method === 'GET') {
            $mine = array_values(array_filter($this->playlists, fn($pl) => $pl['owner'] === $user || in_array($user, $pl['followers'] ?? [], true)));
            $limit = (int) ($q['limit'] ?? 20);
            $offset = (int) ($q['offset'] ?? 0);
            $page = array_slice($mine, $offset, $limit);
            return $this->json(200, [
                'items' => array_map(fn($pl) => $this->playlistObject($pl, false), $page),
                'total' => count($mine), 'limit' => $limit, 'offset' => $offset,
                'next' => $offset + $limit < count($mine) ? 'https://api.spotify.com/v1/me/playlists?offset=' . ($offset + $limit) : null,
            ]);
        }
        if ($p === 'me/playlists' && $method === 'POST') {
            $id = 'pl' . str_pad((string) (count($this->playlists) + 1), 20, '0', STR_PAD_LEFT);
            $this->playlists[$id] = ['id' => $id, 'name' => (string) ($data['name'] ?? ''), 'description' => (string) ($data['description'] ?? ''), 'owner' => $user, 'collaborative' => false, 'public' => (bool) ($data['public'] ?? false), 'items' => [], 'snapshot' => 1];
            return $this->json(201, $this->playlistObject($this->playlists[$id], true));
        }
        if ($p === 'me/library' && $method === 'DELETE') {
            foreach (explode(',', (string) ($q['uris'] ?? '')) as $uri) {
                if (preg_match('/^spotify:playlist:(.+)$/', $uri, $m) && isset($this->playlists[$m[1]])) {
                    $this->libraryUnfollowed[] = $m[1];
                    if ($this->playlists[$m[1]]['owner'] === $user) {
                        unset($this->playlists[$m[1]]);
                    } else {
                        $this->playlists[$m[1]]['followers'] = array_values(array_diff($this->playlists[$m[1]]['followers'] ?? [], [$user]));
                    }
                }
            }
            return new HttpResponse(200, [], '');
        }
        if ($p === 'search') {
            $query = (string) ($q['q'] ?? '');
            $type = (string) ($q['type'] ?? 'track');
            $limit = min($this->searchLimitCap, (int) ($q['limit'] ?? 10));
            $offset = (int) ($q['offset'] ?? 0);
            if ($type === 'artist') {
                $artists = [];
                foreach ($this->tracks as $t) {
                    foreach ($t['artists'] as $a) {
                        if (stripos($a['name'], str_replace('"', '', $query)) !== false) {
                            $artists[$a['id']] = ['id' => $a['id'], 'name' => $a['name'], 'images' => []];
                        }
                    }
                }
                return $this->json(200, ['artists' => ['items' => array_slice(array_values($artists), $offset, $limit)]]);
            }
            $matches = [];
            $artistFilter = preg_match('/artist:"([^"]+)"/', $query, $m) ? $m[1] : null;
            $free = trim(preg_replace('/(artist|genre|year):("[^"]*"|\S+)/', '', $query) ?? '');
            foreach ($this->tracks as $t) {
                $ok = true;
                if ($artistFilter !== null) {
                    $ok = in_array($artistFilter, array_column($t['artists'], 'name'), true);
                }
                if ($ok && $free !== '') {
                    $ok = stripos($t['name'], $free) !== false || stripos($t['artists'][0]['name'], $free) !== false;
                }
                if ($ok) {
                    $matches[] = $t;
                }
            }
            return $this->json(200, ['tracks' => ['items' => array_slice($matches, $offset, $limit), 'total' => count($matches)]]);
        }
        if ($seg[0] === 'tracks' && isset($seg[1]) && $method === 'GET') {
            return isset($this->tracks[$seg[1]]) ? $this->json(200, $this->tracks[$seg[1]]) : $this->json(404, ['error' => ['status' => 404, 'message' => 'not found']]);
        }
        if ($seg[0] === 'artists' && isset($seg[2]) && $seg[2] === 'albums') {
            $albums = [];
            foreach ($this->tracks as $t) {
                if (in_array($seg[1], array_column($t['artists'], 'id'), true)) {
                    $albums[$t['album']['id']] = ['id' => $t['album']['id'], 'name' => $t['album']['name'], 'images' => $t['album']['images']];
                }
            }
            return $this->json(200, ['items' => array_values($albums), 'total' => count($albums)]);
        }
        if ($seg[0] === 'albums' && isset($seg[2]) && $seg[2] === 'tracks') {
            $items = array_values(array_filter($this->tracks, fn($t) => $t['album']['id'] === $seg[1]));
            return $this->json(200, ['items' => array_map(fn($t) => array_diff_key($t, ['album' => 1]), $items), 'total' => count($items)]);
        }
        if ($seg[0] === 'me' && ($seg[1] ?? '') === 'player') {
            if (!$this->premiumUsers) {
                return $this->json(403, ['error' => ['status' => 403, 'message' => 'Player command failed: Premium required', 'reason' => 'PREMIUM_REQUIRED']]);
            }
            if (($seg[2] ?? '') === 'devices') {
                return $this->json(200, ['devices' => [['id' => 'dev123456789abc', 'name' => 'Phone', 'type' => 'Smartphone', 'is_active' => true, 'is_restricted' => false]]]);
            }
            return new HttpResponse(204, [], '');
        }
        if ($seg[0] === 'playlists' && isset($seg[1])) {
            $id = $seg[1];
            if (!isset($this->playlists[$id])) {
                return $this->json(404, ['error' => ['status' => 404, 'message' => 'Not found']]);
            }
            $pl = &$this->playlists[$id];
            $canEdit = $pl['owner'] === $user || $pl['collaborative'];
            $sub = $seg[2] ?? '';
            if ($sub === '' && $method === 'GET') {
                return $this->json(200, $this->playlistObject($pl, true));
            }
            if ($sub === '' && $method === 'PUT') {
                if (!$canEdit) {
                    return $this->json(403, ['error' => ['status' => 403, 'message' => 'Forbidden']]);
                }
                foreach (['name', 'description', 'public', 'collaborative'] as $k) {
                    if (array_key_exists($k, $data)) {
                        $pl[$k] = $data[$k];
                    }
                }
                return new HttpResponse(200, [], '');
            }
            if ($sub === 'images' && $method === 'PUT') {
                if (!$canEdit) {
                    return $this->json(403, ['error' => ['status' => 403, 'message' => 'Forbidden']]);
                }
                if (strlen((string) $body) > 256 * 1024 || base64_decode((string) $body, true) === false) {
                    return $this->json(400, ['error' => ['status' => 400, 'message' => 'Bad image']]);
                }
                $pl['cover_uploaded'] = true;
                return new HttpResponse(202, [], '');
            }
            if ($sub === 'followers' && $method === 'DELETE') {
                $this->libraryUnfollowed[] = $id;
                unset($this->playlists[$id]);
                return new HttpResponse(200, [], '');
            }
            if ($sub === 'tracks') {
                return $this->json(403, ['error' => ['status' => 403, 'message' => 'This endpoint was removed in the February 2026 migration. Use /items.']]);
            }
            if ($sub === 'items') {
                if ($method === 'GET') {
                    if (!$canEdit) {
                        return $this->json(403, ['error' => ['status' => 403, 'message' => 'Only available for playlists the user owns or collaborates on']]);
                    }
                    $limit = min(50, (int) ($q['limit'] ?? 20));
                    $offset = (int) ($q['offset'] ?? 0);
                    $slice = array_slice($pl['items'], $offset, $limit);
                    $items = [];
                    foreach ($slice as $i => $tid) {
                        $items[] = ['added_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600 + $offset + $i), 'is_local' => false, 'item' => $this->tracks[$tid] + ['uri' => 'spotify:track:' . $tid, 'type' => 'track']];
                    }
                    return $this->json(200, ['items' => $items, 'total' => count($pl['items']), 'limit' => $limit, 'offset' => $offset, 'next' => $offset + $limit < count($pl['items']) ? 'next' : null]);
                }
                if (!$canEdit) {
                    return $this->json(403, ['error' => ['status' => 403, 'message' => 'Forbidden']]);
                }
                if ($method === 'POST') {
                    $uris = $data['uris'] ?? [];
                    $ids = [];
                    foreach ($uris as $u) {
                        if (preg_match('/^spotify:track:(.+)$/', (string) $u, $m) && isset($this->tracks[$m[1]])) {
                            $ids[] = $m[1];
                        } else {
                            return $this->json(400, ['error' => ['status' => 400, 'message' => 'Invalid track uri: ' . $u]]);
                        }
                    }
                    if (isset($data['position'])) {
                        array_splice($pl['items'], (int) $data['position'], 0, $ids);
                    } else {
                        $pl['items'] = array_merge($pl['items'], $ids);
                    }
                    $pl['snapshot']++;
                    return $this->json(201, ['snapshot_id' => $this->snapshot($id)]);
                }
                if ($method === 'DELETE') {
                    if (isset($data['snapshot_id']) && $data['snapshot_id'] !== $this->snapshot($id)) {
                        // Spotify wendet Entfernen relativ zum Snapshot an; hier konservativ ablehnen
                        return $this->json(400, ['error' => ['status' => 400, 'message' => 'Snapshot mismatch']]);
                    }
                    $removePositions = [];
                    foreach ($data['items'] ?? $data['tracks'] ?? [] as $it) {
                        $tid = preg_replace('/^spotify:track:/', '', (string) $it['uri']);
                        if (!empty($it['positions'])) {
                            foreach ($it['positions'] as $pos) {
                                if (($pl['items'][$pos] ?? null) !== $tid) {
                                    return $this->json(400, ['error' => ['status' => 400, 'message' => 'Position does not match uri']]);
                                }
                                $removePositions[] = (int) $pos;
                            }
                        } else {
                            foreach ($pl['items'] as $pos => $x) {
                                if ($x === $tid) {
                                    $removePositions[] = $pos;
                                }
                            }
                        }
                    }
                    $removePositions = array_unique($removePositions);
                    $pl['items'] = array_values(array_filter($pl['items'], fn($x, $pos) => !in_array($pos, $removePositions, true), ARRAY_FILTER_USE_BOTH));
                    $pl['snapshot']++;
                    return $this->json(200, ['snapshot_id' => $this->snapshot($id)]);
                }
                if ($method === 'PUT') {
                    if (isset($data['uris'])) {
                        $pl['items'] = array_map(fn($u) => preg_replace('/^spotify:track:/', '', (string) $u), $data['uris']);
                    } else {
                        $start = (int) $data['range_start'];
                        $len = (int) ($data['range_length'] ?? 1);
                        $before = (int) $data['insert_before'];
                        $moved = array_splice($pl['items'], $start, $len);
                        $target = $before > $start ? $before - $len : $before;
                        array_splice($pl['items'], $target, 0, $moved);
                    }
                    $pl['snapshot']++;
                    return $this->json(200, ['snapshot_id' => $this->snapshot($id)]);
                }
            }
        }
        return $this->json(404, ['error' => ['status' => 404, 'message' => 'Unknown endpoint ' . $method . ' ' . $p]]);
    }

    private function playlistObject(array $pl, bool $full): array
    {
        return [
            'id' => $pl['id'], 'name' => $pl['name'], 'description' => $pl['description'],
            'images' => [['url' => 'https://mosaic.scdn.co/' . $pl['id'], 'width' => 300]],
            'owner' => ['id' => $pl['owner'], 'display_name' => $this->users[$pl['owner']]['display_name'] ?? $pl['owner']],
            'collaborative' => $pl['collaborative'], 'public' => $pl['public'],
            'snapshot_id' => $this->snapshot($pl['id']),
            'items' => ['total' => count($pl['items'])],
            'external_urls' => ['spotify' => 'https://open.spotify.com/playlist/' . $pl['id']],
        ];
    }

    private function openai(string $body): HttpResponse
    {
        if ($this->openaiResponses !== []) {
            $r = array_shift($this->openaiResponses);
            return new HttpResponse($r[0], [], json_encode($r[1]));
        }
        $data = json_decode($body, true) ?? [];
        return $this->json(200, ['created' => time(), 'output_format' => 'png', 'data' => [['b64_json' => base64_encode($this->openaiImageBytes)]], 'usage' => ['output_tokens' => 1000]]);
    }

    private function stripe(string $method, string $url, ?string $body): HttpResponse
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) $body, $p);
        if ($this->stripeResponses !== []) {
            $r = array_shift($this->stripeResponses);
            return new HttpResponse($r[0], [], json_encode($r[1]));
        }
        if (str_ends_with($path, '/customers')) {
            return $this->json(200, ['id' => 'cus_test' . substr(md5($body ?? ''), 0, 8), 'object' => 'customer']);
        }
        if (str_ends_with($path, '/checkout/sessions')) {
            return $this->json(200, ['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']);
        }
        if (str_ends_with($path, '/billing_portal/sessions')) {
            return $this->json(200, ['id' => 'bps_1', 'url' => 'https://billing.stripe.com/p/session/test_1']);
        }
        if (preg_match('#/subscriptions/(sub_[A-Za-z0-9]+)$#', $path, $m)) {
            return $this->json(200, $this->stripeSubscription($m[1], 'active'));
        }
        if (str_ends_with($path, '/subscriptions')) {
            return $this->json(200, ['data' => []]);
        }
        return $this->json(404, ['error' => ['message' => 'unknown']]);
    }

    public function stripeSubscription(string $id, string $status, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => $id, 'object' => 'subscription', 'status' => $status, 'livemode' => false,
            'customer' => $overrides['customer'] ?? 'cus_testabc',
            'cancel_at_period_end' => false,
            'metadata' => ['sg_user_id' => (string) ($overrides['user_id'] ?? '')],
            'items' => ['data' => [['id' => 'si_1', 'price' => ['id' => 'price_test'], 'current_period_start' => time() - 86400, 'current_period_end' => time() + 29 * 86400]]],
        ], $overrides);
    }
}
