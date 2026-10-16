<?php
declare(strict_types=1);

namespace SwipeGroove\Http\Controllers;

use SwipeGroove\Core\App;
use SwipeGroove\Core\HttpException;
use SwipeGroove\Core\Request;
use SwipeGroove\Core\Response;
use SwipeGroove\Covers\CoverService;
use SwipeGroove\Spotify\PlaylistService;

final class CoverController
{
    public function __construct(private App $app)
    {
    }

    private function svc(): CoverService
    {
        return $this->app->service(CoverService::class);
    }

    public function index(Request $r): array
    {
        return ['covers' => $this->svc()->list($r->userId()), 'quota' => $this->quotaPayload($r), 'styles' => CoverService::STYLES];
    }

    private function quotaPayload(Request $r): array
    {
        $q = $this->svc()->quota($r->user);
        if ($q['unlimited']) {
            $q['remaining'] = null;
        }
        $q['available'] = $this->svc()->provider()->isConfigured();
        return $q;
    }

    public function quota(Request $r): array
    {
        return $this->quotaPayload($r);
    }

    public function generate(Request $r): array
    {
        $job = $this->svc()->generate($r->user, $r->str('job_id'), $r->str('prompt'), $r->str('style', 'none'));
        return ['cover' => $job, 'quota' => $this->quotaPayload($r)];
    }

    public function show(Request $r): array
    {
        return $this->svc()->present($this->svc()->find($r->userId(), (string) $r->attributes['id']));
    }

    public function image(Request $r): Response
    {
        $job = $this->svc()->find($r->userId(), (string) $r->attributes['id']);
        $path = $this->svc()->filePath($job);
        if (!$path) {
            throw HttpException::notFound('covers.not_found');
        }
        $download = $r->queryStr('download') === '1';
        return Response::file($path, 'image/jpeg', $download ? 'swipegroove-cover-' . substr((string) $job['job_uuid'], 0, 8) . '.jpg' : null, 3600);
    }

    public function apply(Request $r): array
    {
        $playlistId = $r->str('playlist_id');
        PlaylistService::assertId($playlistId);
        return $this->app->service(PlaylistController::class)->guardPublic(fn() => $this->svc()->apply($r->userId(), (string) $r->attributes['id'], $playlistId));
    }

    public function delete(Request $r): array
    {
        $this->svc()->delete($r->userId(), (string) $r->attributes['id']);
        return ['ok' => true];
    }
}
