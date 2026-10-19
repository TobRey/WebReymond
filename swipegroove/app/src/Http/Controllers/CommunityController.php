<?php
declare(strict_types=1);

namespace SwipeGroove\Http\Controllers;

use SwipeGroove\Community\CommunityService;
use SwipeGroove\Core\App;
use SwipeGroove\Core\Request;

final class CommunityController
{
    public function __construct(private App $app)
    {
    }

    private function svc(): CommunityService
    {
        return $this->app->service(CommunityService::class);
    }

    private function username(Request $r): string
    {
        return strtolower((string) ($r->attributes['username'] ?? ''));
    }

    private function id(Request $r): int
    {
        return (int) ($r->attributes['id'] ?? 0);
    }

    public function feed(Request $r): array
    {
        $scope = $r->user && $r->queryStr('scope') === 'following' ? 'following' : 'all';
        return $this->svc()->feed($r->user, $r->queryInt('before'), 20, $scope);
    }

    public function playlist(Request $r): array
    {
        return $this->svc()->playlist($this->id($r), $r->user);
    }

    public function like(Request $r): array
    {
        return $this->svc()->like($r->userId(), $this->id($r), true);
    }

    public function unlike(Request $r): array
    {
        return $this->svc()->like($r->userId(), $this->id($r), false);
    }

    public function likes(Request $r): array
    {
        return $this->svc()->likes($this->id($r), $r->user);
    }

    public function comments(Request $r): array
    {
        return $this->svc()->comments($this->id($r), $r->user, $r->queryInt('before'));
    }

    public function addComment(Request $r): array
    {
        return $this->svc()->addComment($r->userId(), $this->id($r), $r->str('body'));
    }

    public function editComment(Request $r): array
    {
        return $this->svc()->editComment($r->userId(), $this->id($r), $r->str('body'));
    }

    public function deleteComment(Request $r): array
    {
        $this->svc()->deleteComment($r->user, $this->id($r));
        return ['ok' => true];
    }

    public function searchUsers(Request $r): array
    {
        $this->app->limiter()->enforce('usersearch:' . $this->limitKey($r), 120, 600);
        return ['users' => $this->svc()->searchUsers($r->queryStr('q'), $r->user)];
    }

    /** Kombinierte Suche (Personen + veröffentlichte Playlists), auch für Gäste. */
    public function search(Request $r): array
    {
        $this->app->limiter()->enforce('usersearch:' . $this->limitKey($r), 120, 600);
        $q = $r->queryStr('q');
        return ['users' => $this->svc()->searchUsers($q, $r->user, 12), 'playlists' => $this->svc()->searchPlaylists($q, $r->user)];
    }

    private function limitKey(Request $r): string
    {
        return $r->user ? 'u' . $r->userId() : 'ip' . $r->ip;
    }

    public function profile(Request $r): array
    {
        return $this->svc()->profile($this->username($r), $r->user);
    }

    public function userPlaylists(Request $r): array
    {
        return ['playlists' => $this->svc()->userPlaylists($this->username($r), $r->user)];
    }

    public function followers(Request $r): array
    {
        return ['users' => $this->svc()->userList($this->username($r), 'followers', $r->user, $r->queryInt('offset'), 50)];
    }

    public function following(Request $r): array
    {
        return ['users' => $this->svc()->userList($this->username($r), 'following', $r->user, $r->queryInt('offset'), 50)];
    }

    public function friends(Request $r): array
    {
        return ['users' => $this->svc()->userList($this->username($r), 'friends', $r->user, $r->queryInt('offset'), 50)];
    }

    public function follow(Request $r): array
    {
        $this->app->limiter()->enforce('follow:' . $r->userId(), 100, 3600);
        return $this->svc()->follow($r->userId(), $this->username($r));
    }

    public function unfollow(Request $r): array
    {
        return $this->svc()->unfollow($r->userId(), $this->username($r));
    }

    public function friendRequest(Request $r): array
    {
        return $this->svc()->friendRequest($r->userId(), $this->username($r));
    }

    public function friendAccept(Request $r): array
    {
        return $this->svc()->friendAccept($r->userId(), $this->username($r));
    }

    public function friendDecline(Request $r): array
    {
        return $this->svc()->friendDecline($r->userId(), $this->username($r));
    }

    public function friendRemove(Request $r): array
    {
        return $this->svc()->friendRemove($r->userId(), $this->username($r));
    }

    public function block(Request $r): array
    {
        return $this->svc()->block($r->userId(), $this->username($r));
    }

    public function unblock(Request $r): array
    {
        return $this->svc()->unblock($r->userId(), $this->username($r));
    }

    public function friendRequests(Request $r): array
    {
        return $this->svc()->friendRequests($r->userId());
    }

    public function blocks(Request $r): array
    {
        return ['users' => $this->svc()->blockedUsers($r->userId())];
    }

    public function report(Request $r): array
    {
        $id = $this->svc()->report($r->userId(), $r->str('target_type'), $r->int('target_id'), $r->str('reason'), $r->str('details'));
        return ['id' => $id];
    }
}
