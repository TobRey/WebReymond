<?php
declare(strict_types=1);

namespace SwipeGroove\Tests\Cases;

use SwipeGroove\Tests\TestApp;
use SwipeGroove\Tests\TestCase;

final class CommunityTest extends TestCase
{
    private TestApp $t;
    private array $tokens;

    protected function setUp(): void
    {
        $this->t = TestApp::create();
        $this->tokens = $this->t->seedUsers();
        foreach (['spotify_owner' => 'owner', 'spotify_user2' => 'alice', 'spotify_user3' => 'bob'] as $sp => $name) {
            $this->t->db->run('UPDATE users SET username = ?, display_name = ?, onboarding_done = 1, bio = ? WHERE spotify_user_id = ?', [$name, ucfirst($name), 'Bio of ' . $name, $sp]);
        }
        $this->t->http->addPlaylist('plalice0000000000000001', 'spotify_user2', 'Alice Mix', [1, 2, 3]);
        $this->t->request('POST', '/api/playlists/plalice0000000000000001/publish', ['published' => true], $this->tokens['user2']);
    }

    private function publishedId(): int
    {
        return (int) $this->t->db->value('SELECT id FROM published_playlists WHERE spotify_playlist_id = ?', ['plalice0000000000000001']);
    }

    public function testPrivateProfileHidesContentEverywhere(): void
    {
        $pid = $this->publishedId();
        // Öffentlich: Bob sieht Bio, Playlist, Detailseite, Feed
        $p = TestApp::json($this->t->request('GET', '/api/users/alice', [], $this->tokens['user3']));
        self::assertSame('Bio of alice', $p['bio']);
        self::assertTrue($p['content_visible']);
        self::assertCount(1, TestApp::json($this->t->request('GET', '/api/users/alice/playlists', [], $this->tokens['user3']))['playlists']);
        self::assertSame(200, $this->t->request('GET', '/api/community/playlists/' . $pid, [], $this->tokens['user3'])->status);
        self::assertCount(1, TestApp::json($this->t->request('GET', '/api/community/feed', [], $this->tokens['user3']))['items']);
        $this->t->request('POST', '/api/community/playlists/' . $pid . '/like', [], $this->tokens['user3']);
        self::assertCount(1, TestApp::json($this->t->request('GET', '/api/community/playlists/' . $pid . '/likes', [], $this->tokens['owner']))['users']);
        // Alice wird privat
        $this->t->request('PATCH', '/api/me', ['is_private' => true], $this->tokens['user2']);
        $p = TestApp::json($this->t->request('GET', '/api/users/alice', [], $this->tokens['user3']));
        self::assertFalse(isset($p['bio']));
        self::assertFalse(isset($p['banner_url']));
        self::assertFalse($p['content_visible']);
        self::assertSame('Alice', $p['display_name']);
        self::assertTrue(isset($p['follower_count']));
        self::assertNull($p['published_count']);
        self::assertSame([], TestApp::json($this->t->request('GET', '/api/users/alice/playlists', [], $this->tokens['user3']))['playlists']);
        self::assertSame(404, $this->t->request('GET', '/api/community/playlists/' . $pid, [], $this->tokens['user3'])->status, 'Direktlink auf private Playlist');
        self::assertSame(404, $this->t->request('GET', '/api/community/playlists/' . $pid . '/comments', [], $this->tokens['user3'])->status);
        self::assertSame(0, count(TestApp::json($this->t->request('GET', '/api/community/feed', [], $this->tokens['user3']))['items']));
        self::assertSame(403, $this->t->request('GET', '/api/users/alice/following', [], $this->tokens['user3'])->status);
        self::assertSame(200, $this->t->request('GET', '/api/users/alice/followers', [], $this->tokens['user3'])->status);
        // Freunde haben keinen Sonderzugriff
        $this->t->request('POST', '/api/users/bob/friend-request', [], $this->tokens['user2']);
        $this->t->request('POST', '/api/users/alice/friend-accept', [], $this->tokens['user3']);
        self::assertSame(404, $this->t->request('GET', '/api/community/playlists/' . $pid, [], $this->tokens['user3'])->status);
        // Eigentümerin sieht ihre Inhalte weiterhin
        self::assertSame(200, $this->t->request('GET', '/api/community/playlists/' . $pid, [], $this->tokens['user2'])->status);
        // Private Likerin taucht nicht in Like-Listen auf
        $this->t->request('POST', '/api/users/bob/follow', [], $this->tokens['user2']);
        $this->t->http->addPlaylist('plbob000000000000000001', 'spotify_user3', 'Bob Mix', [4]);
        $this->t->request('POST', '/api/playlists/plbob000000000000000001/publish', ['published' => true], $this->tokens['user3']);
        $bobPid = (int) $this->t->db->value('SELECT id FROM published_playlists WHERE spotify_playlist_id = ?', ['plbob000000000000000001']);
        $this->t->request('POST', '/api/community/playlists/' . $bobPid . '/like', [], $this->tokens['user2']);
        $likes = TestApp::json($this->t->request('GET', '/api/community/playlists/' . $bobPid . '/likes', [], $this->tokens['owner']));
        self::assertSame(1, $likes['total']);
        self::assertCount(0, $likes['users']);
        // Private Aktivität nicht im Feed
        $feed = TestApp::json($this->t->request('GET', '/api/community/feed', [], $this->tokens['owner']));
        foreach ($feed['items'] as $it) {
            self::assertFalse($it['actor']['username'] === 'alice' && $it['type'] === 'liked');
        }
    }

    public function testPlaylistPrivacyToggleAndAccountPrivacyPrecedence(): void
    {
        $pid = $this->publishedId();
        $this->t->request('POST', '/api/playlists/plalice0000000000000001/publish', ['published' => false], $this->tokens['user2']);
        self::assertSame(404, $this->t->request('GET', '/api/community/playlists/' . $pid, [], $this->tokens['user3'])->status);
        $this->t->request('POST', '/api/playlists/plalice0000000000000001/publish', ['published' => true], $this->tokens['user2']);
        self::assertSame(200, $this->t->request('GET', '/api/community/playlists/' . $pid, [], $this->tokens['user3'])->status);
        $r = TestApp::json($this->t->request('GET', '/api/community/playlists/' . $pid, [], $this->tokens['user3']));
        self::assertCount(3, $r['tracks']);
        self::assertSame('https://open.spotify.com/playlist/plalice0000000000000001', $r['spotify_url']);
    }

    public function testFollowFriendsAndRequests(): void
    {
        $r = TestApp::json($this->t->request('POST', '/api/users/bob/follow', [], $this->tokens['user2']));
        self::assertTrue($r['following']);
        self::assertSame('none', $r['friend_status']);
        $r = TestApp::json($this->t->request('POST', '/api/users/bob/friend-request', [], $this->tokens['user2']));
        self::assertSame('requested', $r['friend_status']);
        $reqs = TestApp::json($this->t->request('GET', '/api/friends/requests', [], $this->tokens['user3']));
        self::assertCount(1, $reqs['incoming']);
        self::assertSame('alice', $reqs['incoming'][0]['username']);
        // Zurückziehen und erneut
        $this->t->request('POST', '/api/users/bob/friend-decline', [], $this->tokens['user2']);
        self::assertSame('none', TestApp::json($this->t->request('GET', '/api/users/bob', [], $this->tokens['user2']))['relationship']['friend_status']);
        $this->t->request('POST', '/api/users/bob/friend-request', [], $this->tokens['user2']);
        $r = TestApp::json($this->t->request('POST', '/api/users/alice/friend-accept', [], $this->tokens['user3']));
        self::assertSame('friends', $r['friend_status']);
        self::assertFalse($r['following'], 'Freundschaft ist kein Following');
        $friends = TestApp::json($this->t->request('GET', '/api/users/alice/friends', [], $this->tokens['owner']))['users'];
        self::assertSame('bob', $friends[0]['username']);
        $this->t->request('DELETE', '/api/users/bob/friend', [], $this->tokens['user2']);
        self::assertSame('none', TestApp::json($this->t->request('GET', '/api/users/bob', [], $this->tokens['user2']))['relationship']['friend_status']);
        // Benachrichtigungen bei Bob: follow + friend_request
        $n = TestApp::json($this->t->request('GET', '/api/notifications', [], $this->tokens['user3']));
        $types = array_column($n['notifications'], 'type');
        self::assertContains('follow', $types);
        self::assertContains('friend_request', $types);
        $self = $this->t->request('POST', '/api/users/alice/follow', [], $this->tokens['user2']);
        self::assertSame(400, $self->status);
    }

    public function testBlockingHidesAndPreventsInteraction(): void
    {
        $pid = $this->publishedId();
        $this->t->request('POST', '/api/users/bob/follow', [], $this->tokens['user2']);
        $this->t->request('POST', '/api/users/bob/block', [], $this->tokens['user2']);
        self::assertSame(404, $this->t->request('GET', '/api/users/alice', [], $this->tokens['user3'])->status);
        self::assertSame(404, $this->t->request('GET', '/api/community/playlists/' . $pid, [], $this->tokens['user3'])->status);
        self::assertSame(404, $this->t->request('POST', '/api/users/alice/friend-request', [], $this->tokens['user3'])->status);
        self::assertSame(404, $this->t->request('POST', '/api/conversations', ['username' => 'alice'], $this->tokens['user3'])->status);
        self::assertSame(0, (int) $this->t->db->value('SELECT COUNT(*) FROM follows'));
        $search = TestApp::json($this->t->request('GET', '/api/users/search', [], $this->tokens['user3'], ['q' => 'ali']));
        self::assertCount(0, $search['users']);
        $blocks = TestApp::json($this->t->request('GET', '/api/blocks', [], $this->tokens['user2']));
        self::assertSame('bob', $blocks['users'][0]['username']);
        $this->t->request('DELETE', '/api/users/bob/block', [], $this->tokens['user2']);
        self::assertSame(200, $this->t->request('GET', '/api/users/alice', [], $this->tokens['user3'])->status);
    }

    public function testCommentsEditDeleteAndOwnerModeration(): void
    {
        $pid = $this->publishedId();
        $c = TestApp::json($this->t->request('POST', '/api/community/playlists/' . $pid . '/comments', ['body' => 'Nice!'], $this->tokens['user3']));
        self::assertSame('Nice!', $c['body']);
        self::assertTrue($c['can_edit']);
        $cid = $c['id'];
        // Fremder darf nicht bearbeiten
        self::assertSame(404, $this->t->request('PATCH', '/api/community/comments/' . $cid, ['body' => 'x'], $this->tokens['owner'])->status);
        $e = TestApp::json($this->t->request('PATCH', '/api/community/comments/' . $cid, ['body' => 'Nice edit'], $this->tokens['user3']));
        self::assertSame('Nice edit', $e['body']);
        self::assertNotNull($e['updated_at']);
        // Besitzerin der Playlist darf löschen
        $list = TestApp::json($this->t->request('GET', '/api/community/playlists/' . $pid . '/comments', [], $this->tokens['user2']));
        self::assertTrue($list['comments'][0]['can_delete']);
        self::assertSame(200, $this->t->request('DELETE', '/api/community/comments/' . $cid, [], $this->tokens['user2'])->status);
        $list = TestApp::json($this->t->request('GET', '/api/community/playlists/' . $pid . '/comments', [], $this->tokens['user2']));
        self::assertCount(0, $list['comments']);
        self::assertSame(0, (int) $this->t->db->value('SELECT comment_count FROM published_playlists WHERE id = ?', [$pid]));
        // Benachrichtigung für Alice
        $n = TestApp::json($this->t->request('GET', '/api/notifications', [], $this->tokens['user2']));
        self::assertSame('comment', $n['notifications'][0]['type']);
        $r = TestApp::json($this->t->request('POST', '/api/notifications/read', [], $this->tokens['user2']));
        self::assertTrue($r['marked'] >= 1);
        self::assertSame(0, TestApp::json($this->t->request('GET', '/api/me', [], $this->tokens['user2']))['unread_notifications']);
    }

    public function testReportsAndAdminModeration(): void
    {
        $pid = $this->publishedId();
        $c = TestApp::json($this->t->request('POST', '/api/community/playlists/' . $pid . '/comments', ['body' => 'spam spam'], $this->tokens['user3']));
        $rep = TestApp::json($this->t->request('POST', '/api/reports', ['target_type' => 'comment', 'target_id' => $c['id'], 'reason' => 'spam', 'details' => 'Werbung'], $this->tokens['user2']));
        self::assertTrue($rep['id'] > 0);
        // Nicht-Admin hat keinen Zugriff
        self::assertSame(403, $this->t->request('GET', '/api/admin/reports', [], $this->tokens['user2'])->status);
        $reports = TestApp::json($this->t->request('GET', '/api/admin/reports', [], $this->tokens['owner']));
        self::assertCount(1, $reports['reports']);
        $detail = TestApp::json($this->t->request('GET', '/api/admin/reports/' . $rep['id'], [], $this->tokens['owner']));
        self::assertSame('spam spam', $detail['content']['body']);
        self::assertSame(200, $this->t->request('POST', '/api/admin/moderate/comment/' . $c['id'], [], $this->tokens['owner'])->status);
        self::assertSame(200, $this->t->request('POST', '/api/admin/reports/' . $rep['id'] . '/resolve', ['status' => 'resolved', 'note' => 'entfernt'], $this->tokens['owner'])->status);
        self::assertSame('resolved', $this->t->db->value('SELECT status FROM reports WHERE id = ?', [$rep['id']]));
        $audit = TestApp::json($this->t->request('GET', '/api/admin/audit', [], $this->tokens['owner']));
        self::assertContains('moderation.remove_comment', array_column($audit['entries'], 'action'));
        // Sperren
        $bobId = $this->t->userId('spotify_user3');
        self::assertSame(200, $this->t->request('POST', '/api/admin/users/' . $bobId . '/suspend', ['reason' => 'Spam'], $this->tokens['owner'])->status);
        self::assertSame(401, $this->t->request('GET', '/api/me', [], $this->tokens['user3'])->status, 'Sessions beendet');
        $again = $this->t->loginAs('spotify_user3');
        self::assertSame(403, $this->t->request('GET', '/api/playlists', [], $again['token'])->status);
        self::assertSame(404, $this->t->request('GET', '/api/users/bob', [], $this->tokens['user2'])->status);
        // Besitzer kann nicht gesperrt werden
        self::assertSame(403, $this->t->request('POST', '/api/admin/users/' . $this->t->userId('spotify_owner') . '/suspend', [], $this->tokens['owner'])->status);
        $this->t->request('POST', '/api/admin/users/' . $bobId . '/unsuspend', [], $this->tokens['owner']);
        self::assertSame(200, $this->t->request('GET', '/api/playlists', [], $again['token'])->status);
    }

    public function testExportAndDeleteAccount(): void
    {
        $r = $this->t->request('GET', '/api/me/export', [], $this->tokens['user2']);
        self::assertSame(200, $r->status);
        $data = json_decode($r->body, true);
        self::assertSame('alice', $data['profile']['username']);
        self::assertFalse(str_contains($r->body, 'access-'), 'Keine Tokens im Export');
        self::assertSame(400, $this->t->request('DELETE', '/api/me', ['confirm' => 'wrong'], $this->tokens['user2'])->status);
        self::assertSame(200, $this->t->request('DELETE', '/api/me', ['confirm' => 'alice'], $this->tokens['user2'])->status);
        self::assertNull($this->t->db->value('SELECT id FROM users WHERE username = ?', ['alice']));
        self::assertSame(0, (int) $this->t->db->value('SELECT COUNT(*) FROM published_playlists'));
        self::assertSame(403, $this->t->request('DELETE', '/api/me', ['confirm' => 'owner'], $this->tokens['owner'])->status);
    }
}
