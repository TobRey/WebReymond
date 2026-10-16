<?php
declare(strict_types=1);

namespace SwipeGroove\Http\Controllers;

use SwipeGroove\Core\App;
use SwipeGroove\Core\HttpException;
use SwipeGroove\Core\Request;
use SwipeGroove\Spotify\PlaylistOperations;
use SwipeGroove\Spotify\PlaylistService;
use SwipeGroove\Spotify\SpotifyClient;
use SwipeGroove\Spotify\SpotifyException;
use SwipeGroove\Spotify\TokenStore;
use SwipeGroove\Users\ImageService;

final class PlaylistController
{
    public function __construct(private App $app)
    {
    }

    private function pls(): PlaylistService
    {
        return $this->app->service(PlaylistService::class);
    }

    /** Übersetzt Spotify-Fehler in HTTP-Antworten mit ehrlichem Status. */
    public function guardPublic(callable $fn): mixed
    {
        return $this->guard($fn);
    }

    private function guard(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (SpotifyException $e) {
            $status = match ($e->reason) {
                SpotifyException::NOT_CONNECTED, SpotifyException::REVOKED => 409,
                SpotifyException::RATE_LIMITED => 429,
                SpotifyException::PREMIUM_REQUIRED, SpotifyException::FORBIDDEN => 403,
                SpotifyException::NOT_FOUND => 404,
                SpotifyException::BAD_REQUEST => 400,
                default => 502,
            };
            throw new HttpException($status, $e->errorCode(), null, array_filter([
                'retry_after' => $e->retryAfter ?: null,
                'spotify_message' => $e->spotifyMessage,
            ]));
        }
    }

    private function id(Request $r): string
    {
        $id = (string) ($r->attributes['id'] ?? '');
        PlaylistService::assertId($id);
        return $id;
    }

    public function spotifyStatus(Request $r): array
    {
        return $this->app->service(TokenStore::class)->status($r->userId());
    }

    public function index(Request $r): array
    {
        $force = $r->queryStr('refresh') === '1';
        $list = $this->guard(fn() => $this->pls()->sync($r->userId(), $force));
        return ['playlists' => $list, 'count' => count($list)];
    }

    public function sync(Request $r): array
    {
        $list = $this->guard(fn() => $this->pls()->sync($r->userId(), true));
        return ['playlists' => $list, 'count' => count($list)];
    }

    public function create(Request $r): array
    {
        $this->app->limiter()->enforce('pl-create:' . $r->userId(), 30, 3600);
        return $this->guard(fn() => $this->pls()->create($r->userId(), $r->str('name'), $r->str('description'), $r->bool('public')));
    }

    public function show(Request $r): array
    {
        $force = $r->queryStr('refresh') === '1';
        return $this->guard(fn() => $this->pls()->items($r->userId(), $this->id($r), $force));
    }

    public function update(Request $r): array
    {
        $changes = [];
        foreach (['name', 'description', 'public'] as $k) {
            if (array_key_exists($k, $r->json())) {
                $changes[$k] = $r->json()[$k];
            }
        }
        return $this->guard(fn() => $this->pls()->updateDetails($r->userId(), $this->id($r), $changes));
    }

    public function uploadCover(Request $r): array
    {
        $this->app->limiter()->enforce('cover-upload:' . $r->userId(), 20, 3600);
        $bytes = ImageService::uploadedBytes('image');
        $crop = ImageService::cropFromInput($r->input('crop'));
        $jpeg = $this->app->service(ImageService::class)->toSpotifyCoverJpeg($bytes, $crop);
        $this->guard(fn() => $this->pls()->uploadCover($r->userId(), $this->id($r), $jpeg));
        return ['ok' => true];
    }

    public function addItems(Request $r): array
    {
        $uris = $r->arr('uris');
        $position = $r->input('position');
        return $this->guard(fn() => $this->pls()->addItems($r->userId(), $this->id($r), $uris, is_numeric($position) ? (int) $position : null));
    }

    public function removeItems(Request $r): array
    {
        return $this->guard(fn() => $this->pls()->removeItems($r->userId(), $this->id($r), $r->arr('items'), $r->str('snapshot_id') ?: null));
    }

    public function reorder(Request $r): array
    {
        return $this->guard(fn() => $this->pls()->reorder(
            $r->userId(), $this->id($r), $r->int('range_start'), $r->int('insert_before'), max(1, $r->int('range_length', 1)), $r->str('snapshot_id') ?: null
        ));
    }

    public function duplicates(Request $r): array
    {
        return $this->guard(fn() => $this->pls()->duplicates($r->userId(), $this->id($r)));
    }

    public function removeDuplicates(Request $r): array
    {
        $uuid = $r->str('op_id');
        return $this->guard(fn() => $this->app->service(PlaylistOperations::class)->start(
            $r->userId(), $uuid, 'remove_duplicates', $this->id($r), null,
            ['removals' => $r->arr('removals'), 'snapshot_id' => $r->str('snapshot_id') ?: null]
        ));
    }

    public function duplicatePlaylist(Request $r): array
    {
        $this->app->limiter()->enforce('pl-create:' . $r->userId(), 30, 3600);
        return $this->guard(fn() => $this->app->service(PlaylistOperations::class)->start(
            $r->userId(), $r->str('op_id'), 'duplicate', $this->id($r), null, ['name' => $r->str('name')]
        ));
    }

    public function move(Request $r): array
    {
        $source = $r->str('source_id');
        $target = $r->str('target_id');
        PlaylistService::assertId($source);
        PlaylistService::assertId($target);
        if ($source === $target) {
            throw HttpException::badRequest('playlist.same_playlist');
        }
        return $this->guard(fn() => $this->app->service(PlaylistOperations::class)->start(
            $r->userId(), $r->str('op_id'), 'move', $source, $target,
            ['items' => $r->arr('items'), 'snapshot_id' => $r->str('snapshot_id') ?: null, 'skip_existing' => $r->bool('skip_existing', true)]
        ));
    }

    public function copy(Request $r): array
    {
        $target = $r->str('target_id');
        PlaylistService::assertId($target);
        return $this->guard(fn() => $this->app->service(PlaylistOperations::class)->start(
            $r->userId(), $r->str('op_id'), 'copy', $r->str('source_id') ?: null, $target,
            ['uris' => $r->arr('uris'), 'skip_existing' => $r->bool('skip_existing', true)]
        ));
    }

    public function merge(Request $r): array
    {
        $target = $r->str('target_id') ?: null;
        if ($target) {
            PlaylistService::assertId($target);
        }
        return $this->guard(fn() => $this->app->service(PlaylistOperations::class)->start(
            $r->userId(), $r->str('op_id'), 'merge', null, $target,
            [
                'sources' => $r->arr('sources'), 'new_name' => $r->str('new_name'), 'new_description' => $r->str('new_description'),
                'new_public' => $r->bool('new_public'), 'skip_existing' => $r->bool('skip_existing', true),
            ]
        ));
    }

    public function operation(Request $r): array
    {
        $op = $this->app->service(PlaylistOperations::class)->find($r->userId(), (string) $r->attributes['id']);
        if (!$op) {
            throw HttpException::notFound('operation.not_found');
        }
        return $this->app->service(PlaylistOperations::class)->present($op);
    }

    public function resumeOperation(Request $r): array
    {
        return $this->guard(fn() => $this->app->service(PlaylistOperations::class)->resume($r->userId(), (string) $r->attributes['id']));
    }

    public function emptyCandidates(Request $r): array
    {
        return $this->guard(fn() => $this->pls()->emptyCandidates($r->userId()));
    }

    public function removeEmpty(Request $r): array
    {
        $ids = array_values(array_filter($r->arr('ids'), 'is_string'));
        return ['results' => $this->guard(fn() => $this->pls()->removeEmpty($r->userId(), $ids))];
    }

    public function publish(Request $r): array
    {
        return $this->guard(fn() => $this->pls()->setPublished($r->userId(), $this->id($r), $r->bool('published')));
    }

    public function unfollow(Request $r): array
    {
        $this->guard(fn() => $this->pls()->unfollow($r->userId(), $this->id($r)));
        return ['ok' => true];
    }

    public function search(Request $r): array
    {
        $q = trim($r->queryStr('q'));
        if (mb_strlen($q) < 2) {
            return ['tracks' => []];
        }
        $this->app->limiter()->enforce('search:' . $r->userId(), 120, 600);
        $offset = max(0, min(90, $r->queryInt('offset', 0)));
        $tracks = $this->guard(fn() => $this->app->service(SpotifyClient::class)->searchTracks($r->userId(), mb_substr($q, 0, 120), $offset));
        return ['tracks' => array_map(static fn(array $t) => $t + ['spotify_url' => 'https://open.spotify.com/track/' . $t['id']], $tracks), 'limit' => SpotifyClient::SEARCH_LIMIT];
    }
}
