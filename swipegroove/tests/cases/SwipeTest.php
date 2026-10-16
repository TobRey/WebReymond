<?php
declare(strict_types=1);

namespace SwipeGroove\Tests\Cases;

use SwipeGroove\Tests\TestApp;
use SwipeGroove\Tests\TestCase;

final class SwipeTest extends TestCase
{
    private TestApp $t;
    private array $tokens;

    protected function setUp(): void
    {
        $this->t = TestApp::create();
        $this->tokens = $this->t->seedUsers();
        $this->t->http->addPlaylist('pltarget000000000000001', 'spotify_user2', 'Ziel', [1, 2, 3]);
        $this->t->http->addPlaylist('plother0000000000000001', 'spotify_user2', 'Andere', [20, 21, 22, 23]);
    }

    private function uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    private function feed(string $mode = 'match', array $filters = []): array
    {
        $q = ['playlist_id' => 'pltarget000000000000001', 'mode' => $mode];
        if ($filters) {
            $q['filters'] = json_encode($filters);
        }
        return TestApp::json($this->t->request('GET', '/api/swipe/feed', [], $this->tokens['user2'], $q));
    }

    public function testFeedExcludesContainedHiddenAndServedTracks(): void
    {
        $feed = $this->feed();
        self::assertTrue(count($feed['cards']) > 0);
        self::assertSame('search_catalog', $feed['method']);
        $ids = array_column($feed['cards'], 'id');
        foreach ([1, 2, 3] as $n) {
            self::assertFalse(in_array($this->t->http->trackId($n), $ids, true), 'Enthaltene Songs dürfen nicht vorgeschlagen werden');
        }
        // Zweite Seite liefert keine bereits gezeigten Karten
        $feed2 = $this->feed();
        $ids2 = array_column($feed2['cards'], 'id');
        self::assertSame([], array_intersect($ids, $ids2));
    }

    public function testEmptyTargetGivesHonestState(): void
    {
        $this->t->http->addPlaylist('plempty0000000000000001', 'spotify_user2', 'Leer', []);
        $r = TestApp::json($this->t->request('GET', '/api/swipe/feed', [], $this->tokens['user2'], ['playlist_id' => 'plempty0000000000000001', 'mode' => 'match']));
        self::assertSame([], $r['cards']);
        self::assertTrue($r['exhausted']);
        self::assertSame('empty_playlist', $r['reason']);
    }

    public function testAddIsIdempotentAndRejectsDoubleAdds(): void
    {
        $feed = $this->feed();
        $card = $feed['cards'][0];
        $uuid = $this->uuid();
        $body = ['action_id' => $uuid, 'playlist_id' => 'pltarget000000000000001', 'track_id' => $card['id'], 'action' => 'add'];
        $r1 = TestApp::json($this->t->request('POST', '/api/swipe/action', $body, $this->tokens['user2']));
        self::assertSame('ok', $r1['result']);
        self::assertSame('done', $r1['status']);
        // Wiederholung (Netzfehler-Retry) mit derselben ID
        $r2 = TestApp::json($this->t->request('POST', '/api/swipe/action', $body, $this->tokens['user2']));
        self::assertSame('duplicate', $r2['result']);
        // Schneller Doppel-Swipe mit neuer ID
        $r3 = $this->t->request('POST', '/api/swipe/action', ['action_id' => $this->uuid()] + $body, $this->tokens['user2']);
        self::assertSame(409, $r3->status);
        $items = $this->t->http->playlists['pltarget000000000000001']['items'];
        self::assertSame(1, count(array_keys($items, $card['id'], true)), 'Song genau einmal in der Playlist');
        self::assertCount(4, $items);
    }

    public function testHideIsScopedToTargetPlaylist(): void
    {
        $feed = $this->feed();
        $card = $feed['cards'][0];
        $r = TestApp::json($this->t->request('POST', '/api/swipe/action', ['action_id' => $this->uuid(), 'playlist_id' => 'pltarget000000000000001', 'track_id' => $card['id'], 'action' => 'hide'], $this->tokens['user2']));
        self::assertSame('done', $r['status']);
        self::assertSame(1, (int) $this->t->db->value('SELECT COUNT(*) FROM swipe_hidden WHERE spotify_playlist_id = ?', ['pltarget000000000000001']));
        // Für eine andere Zielplaylist darf der Song erscheinen
        $ids = [];
        for ($i = 0; $i < 6; $i++) {
            $f = TestApp::json($this->t->request('GET', '/api/swipe/feed', [], $this->tokens['user2'], ['playlist_id' => 'plother0000000000000001', 'mode' => 'discover']));
            $ids = array_merge($ids, array_column($f['cards'], 'id'));
            if (in_array($card['id'], $ids, true) || $f['cards'] === []) {
                break;
            }
        }
        self::assertContains($card['id'], $ids);
        // Zurücksetzen
        $r = TestApp::json($this->t->request('POST', '/api/me/swipes/reset', [], $this->tokens['user2']));
        self::assertSame(1, $r['reset']);
    }

    public function testUndoRemovesOnlyTheAddedOccurrence(): void
    {
        $http = $this->t->http;
        // Song 5 ist bereits einmal in der Playlist (bestehendes Vorkommen)
        $http->playlists['pltarget000000000000001']['items'][] = $http->trackId(5);
        $this->t->request('GET', '/api/playlists/pltarget000000000000001', [], $this->tokens['user2'], ['refresh' => '1']);
        $feed = $this->feed();
        $card = $feed['cards'][0];
        $add = TestApp::json($this->t->request('POST', '/api/swipe/action', ['action_id' => $this->uuid(), 'playlist_id' => 'pltarget000000000000001', 'track_id' => $card['id'], 'action' => 'add'], $this->tokens['user2']));
        self::assertSame('done', $add['status']);
        // Fremde Änderung nach dem Hinzufügen: ein weiterer Song kommt dazu
        $http->playlists['pltarget000000000000001']['items'][] = $http->trackId(30);
        $http->playlists['pltarget000000000000001']['snapshot']++;
        $undo = TestApp::json($this->t->request('POST', '/api/swipe/undo', ['playlist_id' => 'pltarget000000000000001'], $this->tokens['user2']));
        self::assertSame('add', $undo['undone']);
        self::assertSame(1, $undo['removed_occurrences']);
        self::assertSame($card['id'], $undo['card']['id']);
        $items = $http->playlists['pltarget000000000000001']['items'];
        self::assertFalse(in_array($card['id'], $items, true));
        self::assertContains($http->trackId(30), $items);
        self::assertContains($http->trackId(5), $items);
        // Undo eines Hide
        $hide = TestApp::json($this->t->request('POST', '/api/swipe/action', ['action_id' => $this->uuid(), 'playlist_id' => 'pltarget000000000000001', 'track_id' => $card['id'], 'action' => 'hide'], $this->tokens['user2']));
        $undo = TestApp::json($this->t->request('POST', '/api/swipe/undo', ['playlist_id' => 'pltarget000000000000001'], $this->tokens['user2']));
        self::assertSame('hide', $undo['undone']);
        self::assertSame(0, (int) $this->t->db->value('SELECT COUNT(*) FROM swipe_hidden'));
        // Nichts mehr rückgängig zu machen
        $r = $this->t->request('POST', '/api/swipe/undo', ['playlist_id' => 'pltarget000000000000001'], $this->tokens['user2']);
        self::assertSame(404, $r->status);
    }

    public function testUndoAfterAddThatWasAlreadyRemovedExternally(): void
    {
        $http = $this->t->http;
        $feed = $this->feed();
        $card = $feed['cards'][0];
        $this->t->request('POST', '/api/swipe/action', ['action_id' => $this->uuid(), 'playlist_id' => 'pltarget000000000000001', 'track_id' => $card['id'], 'action' => 'add'], $this->tokens['user2']);
        // Nutzer hat den Song in der Spotify-App schon entfernt
        $http->playlists['pltarget000000000000001']['items'] = array_values(array_diff($http->playlists['pltarget000000000000001']['items'], [$card['id']]));
        $undo = TestApp::json($this->t->request('POST', '/api/swipe/undo', ['playlist_id' => 'pltarget000000000000001'], $this->tokens['user2']));
        self::assertSame(0, $undo['removed_occurrences']);
        self::assertCount(3, $http->playlists['pltarget000000000000001']['items']);
    }

    public function testFailedSpotifyAddIsRecordedAndNotCountedAsAdded(): void
    {
        $feed = $this->feed();
        $card = $feed['cards'][0];
        $this->t->http->failures['POST pltarget000000000000001/items'] = [503, ['error' => ['status' => 503, 'message' => 'down']], 5];
        $uuid = $this->uuid();
        $r = $this->t->request('POST', '/api/swipe/action', ['action_id' => $uuid, 'playlist_id' => 'pltarget000000000000001', 'track_id' => $card['id'], 'action' => 'add'], $this->tokens['user2']);
        self::assertSame(502, $r->status);
        self::assertSame('failed', $this->t->db->value('SELECT status FROM swipe_actions WHERE action_uuid = ?', [$uuid]));
        self::assertCount(3, $this->t->http->playlists['pltarget000000000000001']['items']);
        // Nach Wiederherstellung ist ein erneuter Versuch mit neuer ID möglich
        $this->t->http->failures = [];
        $r = TestApp::json($this->t->request('POST', '/api/swipe/action', ['action_id' => $this->uuid(), 'playlist_id' => 'pltarget000000000000001', 'track_id' => $card['id'], 'action' => 'add'], $this->tokens['user2']));
        self::assertSame('done', $r['status']);
    }

    public function testCustomModeUsesFilters(): void
    {
        $feed = $this->feed('custom', ['artists' => [['name' => 'Artist 4']]]);
        self::assertTrue(count($feed['cards']) > 0);
        foreach ($feed['cards'] as $c) {
            self::assertSame(['Artist 4'], $c['artists']);
        }
        $none = $this->feed('custom', []);
        self::assertSame('no_filters', $none['reason']);
    }

    public function testPlaybackDisabledByDefaultAndPremiumErrorsHonest(): void
    {
        $r = $this->t->request('POST', '/api/playback/play', ['track_id' => $this->t->http->trackId(1)], $this->tokens['user2']);
        self::assertSame(403, $r->status);
        self::assertSame('playback.disabled', TestApp::json($r)['error']);
        $this->t->app->settings()->set('playback_mode', 'connect');
        $this->t->request('PATCH', '/api/me', ['playback_enabled' => true], $this->tokens['user2']);
        $r = $this->t->request('POST', '/api/playback/play', ['track_id' => $this->t->http->trackId(1)], $this->tokens['user2']);
        self::assertSame('playback.scope_missing', TestApp::json($r)['error']);
        $this->t->db->run('UPDATE spotify_accounts SET scopes = ?', ['user-modify-playback-state user-read-playback-state']);
        $this->t->http->premiumUsers = false;
        $r = $this->t->request('POST', '/api/playback/play', ['track_id' => $this->t->http->trackId(1)], $this->tokens['user2']);
        self::assertSame('playback.premium_required', TestApp::json($r)['error']);
        $this->t->http->premiumUsers = true;
        $r = $this->t->request('POST', '/api/playback/play', ['track_id' => $this->t->http->trackId(1)], $this->tokens['user2']);
        self::assertSame(200, $r->status);
    }
}
