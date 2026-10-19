<?php
declare(strict_types=1);

namespace SwipeGroove\Http\Controllers;

use SwipeGroove\Core\App;
use SwipeGroove\Core\HttpException;
use SwipeGroove\Core\Request;
use SwipeGroove\Core\Response;
use SwipeGroove\Spotify\PlaylistService;
use SwipeGroove\Spotify\SpotifyClient;
use SwipeGroove\Spotify\SpotifyException;
use SwipeGroove\Spotify\SwipeService;
use SwipeGroove\Spotify\TokenStore;

final class SwipeController
{
    public function __construct(private App $app)
    {
    }

    private function guard(callable $fn): mixed
    {
        return $this->app->service(PlaylistController::class)->guardPublic($fn);
    }

    public function targets(Request $r): array
    {
        return ['targets' => $this->guard(fn() => $this->app->service(SwipeService::class)->targets($r->userId()))];
    }

    public function feed(Request $r): array
    {
        $playlistId = $r->queryStr('playlist_id');
        PlaylistService::assertId($playlistId);
        $mode = $r->queryStr('mode', 'match');
        $filters = [];
        if ($mode === 'custom') {
            $raw = json_decode($r->queryStr('filters', '{}'), true);
            $filters = is_array($raw) ? [
                'q' => mb_substr((string) ($raw['q'] ?? ''), 0, 100),
                'artists' => array_slice((array) ($raw['artists'] ?? []), 0, 5),
                'genres' => array_slice((array) ($raw['genres'] ?? []), 0, 5),
                'years' => (string) ($raw['years'] ?? ''),
            ] : [];
        }
        $this->app->limiter()->enforce('feed:' . $r->userId(), 60, 600);
        $limit = max(1, min(12, $r->queryInt('limit', 8)));
        return $this->guard(fn() => $this->app->service(SwipeService::class)->feed($r->userId(), $playlistId, $mode, $filters, $limit));
    }

    public function action(Request $r): array
    {
        $this->app->limiter()->enforce('swipe:' . $r->userId(), 300, 600);
        $playlistId = $r->str('playlist_id');
        PlaylistService::assertId($playlistId);
        return $this->guard(fn() => $this->app->service(SwipeService::class)->action(
            $r->userId(), $r->str('action_id'), $playlistId, $r->str('track_id'), $r->str('action')
        ));
    }

    public function undo(Request $r): array
    {
        $playlistId = $r->str('playlist_id');
        PlaylistService::assertId($playlistId);
        return $this->guard(fn() => $this->app->service(SwipeService::class)->undo($r->userId(), $playlistId, $r->str('action_id') ?: null));
    }

    public function resetHidden(Request $r): array
    {
        $n = $this->app->service(SwipeService::class)->resetHidden($r->userId(), $r->str('playlist_id') ?: null);
        return ['reset' => $n];
    }

    public function searchArtists(Request $r): array
    {
        $q = trim($r->queryStr('q'));
        if (mb_strlen($q) < 2) {
            return ['artists' => []];
        }
        $this->app->limiter()->enforce('search:' . $r->userId(), 120, 600);
        return ['artists' => $this->guard(fn() => $this->app->service(SpotifyClient::class)->searchArtists($r->userId(), mb_substr($q, 0, 80)))];
    }

    // ---------------------------------------------------------------- Wiedergabe über Spotify Connect

    private function assertPlayback(Request $r): void
    {
        if ($this->app->settings()->str('playback_mode') !== 'connect') {
            throw HttpException::forbidden('playback.disabled');
        }
        if (!(int) $r->user->playback_enabled) {
            throw HttpException::forbidden('playback.user_disabled');
        }
        $store = $this->app->service(TokenStore::class);
        if (!$store->hasScope($r->userId(), 'user-modify-playback-state')) {
            throw HttpException::forbidden('playback.scope_missing');
        }
    }

    public function devices(Request $r): array
    {
        $this->assertPlayback($r);
        $devices = $this->guard(fn() => $this->app->service(SpotifyClient::class)->devices($r->userId()));
        return ['devices' => array_map(static fn(array $d) => [
            'id' => $d['id'] ?? null, 'name' => $d['name'] ?? '', 'type' => $d['type'] ?? '', 'is_active' => (bool) ($d['is_active'] ?? false), 'is_restricted' => (bool) ($d['is_restricted'] ?? false),
        ], $devices)];
    }

    public function play(Request $r): array
    {
        $this->assertPlayback($r);
        $this->app->limiter()->enforce('play:' . $r->userId(), 120, 600);
        if ($r->bool('resume')) {
            try {
                $this->app->service(SpotifyClient::class)->resume($r->userId());
            } catch (SpotifyException $e) {
                throw new HttpException($e->reason === SpotifyException::NOT_FOUND ? 404 : 502, $e->reason === SpotifyException::NOT_FOUND ? 'playback.no_device' : $e->errorCode());
            }
            return ['ok' => true];
        }
        // Einzelner Song oder Warteschlange (max. 50, z. B. die Songs einer Playlist)
        $ids = array_values(array_filter(array_map('strval', $r->arr('track_ids'))));
        if ($ids === []) {
            $ids = [$r->str('track_id')];
        }
        $ids = array_slice($ids, 0, 50);
        foreach ($ids as $trackId) {
            if (!preg_match('/^[A-Za-z0-9]{22}$/', $trackId)) {
                throw HttpException::badRequest('swipe.invalid_track');
            }
        }
        $offset = max(0, min(count($ids) - 1, $r->int('offset')));
        $device = $r->str('device_id') ?: null;
        if ($device !== null && !preg_match('/^[A-Za-z0-9]{10,80}$/', $device)) {
            $device = null;
        }
        try {
            $this->app->service(SpotifyClient::class)->play($r->userId(), array_map(static fn(string $id) => 'spotify:track:' . $id, $ids), $device, $offset);
        } catch (SpotifyException $e) {
            if ($e->reason === SpotifyException::NOT_FOUND) {
                throw new HttpException(404, 'playback.no_device');
            }
            if ($e->reason === SpotifyException::PREMIUM_REQUIRED) {
                throw new HttpException(403, 'playback.premium_required');
            }
            throw new HttpException(502, $e->errorCode());
        }
        return ['ok' => true];
    }

    /**
     * Kurzlebiges Zugriffstoken für den Web Playback SDK-Player im Browser (nur Wiedergabe auf dieser Seite).
     * Das Refresh-Token verlässt den Server nie; das Token wird nicht gespeichert oder geloggt.
     */
    public function playerToken(Request $r): Response
    {
        $this->assertPlayback($r);
        $store = $this->app->service(TokenStore::class);
        if (!$store->hasScope($r->userId(), 'streaming')) {
            throw HttpException::forbidden('playback.scope_missing');
        }
        $this->app->limiter()->enforce('ptoken:' . $r->userId(), 60, 600);
        $acc = $store->account($r->userId());
        $soon = !$acc || strtotime((string) ($acc['token_expires_at'] ?? '')) < time() + 300;
        try {
            $token = $store->accessToken($r->userId(), $soon);
        } catch (SpotifyException $e) {
            throw new HttpException($e->reason === SpotifyException::REVOKED ? 401 : 502, $e->errorCode());
        }
        $acc = $store->account($r->userId());
        $expiresIn = max(60, (int) (strtotime((string) ($acc['token_expires_at'] ?? '')) - time()));
        $resp = Response::json(['access_token' => $token, 'expires_in' => $expiresIn]);
        $resp->headers['Cache-Control'] = 'no-store, private';
        return $resp;
    }

    public function pause(Request $r): array
    {
        $this->assertPlayback($r);
        try {
            $this->app->service(SpotifyClient::class)->pause($r->userId());
        } catch (SpotifyException $e) {
            throw new HttpException(502, $e->errorCode());
        }
        return ['ok' => true];
    }
}
