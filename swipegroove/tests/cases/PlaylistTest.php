<?php
declare(strict_types=1);

namespace SwipeGroove\Tests\Cases;

use SwipeGroove\Tests\TestApp;
use SwipeGroove\Tests\TestCase;

final class PlaylistTest extends TestCase
{
    private TestApp $t;
    private array $tokens;

    protected function setUp(): void
    {
        $this->t = TestApp::create();
        $this->tokens = $this->t->seedUsers();
    }

    private function uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    public function testListsManyPlaylistsWithPagination(): void
    {
        for ($i = 1; $i <= 120; $i++) {
            $this->t->http->addPlaylist('pl' . str_pad((string) $i, 20, '0', STR_PAD_LEFT), $i % 3 === 0 ? 'spotify_user3' : 'spotify_user2', 'List ' . $i, $i % 7 === 0 ? [] : [1, 2]);
        }
        // Fremde Playlists, denen der Nutzer folgt
        foreach ($this->t->http->playlists as $id => &$pl) {
            if ($pl['owner'] === 'spotify_user3') {
                $pl['followers'] = ['spotify_user2'];
            }
        }
        unset($pl);
        $r = TestApp::json($this->t->request('GET', '/api/playlists', [], $this->tokens['user2']));
        self::assertSame(120, $r['count']);
        $editable = array_filter($r['playlists'], fn($p) => $p['can_edit']);
        self::assertSame(80, count($editable));
        $empty = array_filter($r['playlists'], fn($p) => $p['track_count'] === 0);
        self::assertSame(17, count($empty));
        // Paginierung: 3 Seiten à 50
        $calls = array_filter($this->t->http->log, fn($l) => str_contains($l['url'], 'me/playlists?'));
        self::assertSame(3, count($calls));
        // Zweiter Aufruf nutzt den Cache
        $before = count($this->t->http->log);
        $this->t->request('GET', '/api/playlists', [], $this->tokens['user2']);
        self::assertSame($before, count($this->t->http->log));
    }

    public function testShowLoadsAllItemsAcrossPages(): void
    {
        $this->t->http->addPlaylist('pl000000000000000000001', 'spotify_user2', 'Big', range(1, 60));
        $this->t->http->playlists['pl000000000000000000001']['items'] = array_merge($this->t->http->playlists['pl000000000000000000001']['items'], array_map(fn($n) => $this->t->http->trackId($n), range(1, 60)));
        $r = TestApp::json($this->t->request('GET', '/api/playlists/pl000000000000000000001', [], $this->tokens['user2']));
        self::assertCount(120, $r['items']);
        self::assertTrue($r['complete']);
        self::assertSame('Song 1', $r['items'][0]['name']);
        self::assertSame(['Artist 2'], $r['items'][0]['artists']);
    }

    public function testCreateUpdateAndPublishSeparateFromSpotifyVisibility(): void
    {
        $r = TestApp::json($this->t->request('POST', '/api/playlists', ['name' => 'Neu', 'description' => 'Desc', 'public' => false], $this->tokens['user2']));
        self::assertSame('Neu', $r['name']);
        self::assertFalse($r['spotify_public']);
        self::assertFalse($r['is_published']);
        $id = $r['id'];
        $r = TestApp::json($this->t->request('PATCH', '/api/playlists/' . $id, ['name' => 'Neu2', 'public' => true], $this->tokens['user2']));
        self::assertSame('Neu2', $r['name']);
        self::assertTrue($r['spotify_public']);
        // Veröffentlichen auf SwipeGroove ändert Spotify-Sichtbarkeit nicht
        $this->t->http->playlists[$id]['public'] = false;
        $r = TestApp::json($this->t->request('POST', '/api/playlists/' . $id . '/publish', ['published' => true], $this->tokens['user2']));
        self::assertTrue($r['is_published']);
        self::assertFalse($this->t->http->playlists[$id]['public']);
        // Fremde Playlist nicht bearbeitbar
        $this->t->http->addPlaylist('plforeign00000000000001', 'spotify_user3', 'Fremd', [1]);
        $this->t->http->playlists['plforeign00000000000001']['followers'] = ['spotify_user2'];
        $r = $this->t->request('PATCH', '/api/playlists/plforeign00000000000001', ['name' => 'x'], $this->tokens['user2']);
        self::assertSame(403, $r->status);
        self::assertSame('playlist.not_editable', TestApp::json($r)['error']);
    }

    public function testFollowedForeignPlaylistShowsRestrictedViewInsteadOfError(): void
    {
        $this->t->http->addPlaylist('plforeign00000000000009', 'spotify_user3', 'Fremd', [1, 2, 3]);
        $this->t->http->playlists['plforeign00000000000009']['followers'] = ['spotify_user2'];
        $r = $this->t->request('GET', '/api/playlists/plforeign00000000000009', [], $this->tokens['user2']);
        self::assertSame(200, $r->status, $r->body);
        $j = TestApp::json($r);
        self::assertTrue($j['restricted']);
        self::assertSame('not_owner', $j['restricted_reason']);
        self::assertSame(3, $j['playlist']['track_count']);
        self::assertSame([], $j['items']);
        $calls = array_filter($this->t->http->log, fn($l) => str_contains($l['url'], 'plforeign00000000000009/items'));
        self::assertSame(0, count($calls), 'Kein verbotener Spotify-Aufruf');
        // Fremde Playlist kann nicht veröffentlicht werden
        $r = $this->t->request('POST', '/api/playlists/plforeign00000000000009/publish', ['published' => true], $this->tokens['user2']);
        self::assertSame(403, $r->status);
        self::assertSame('playlist.publish_own_only', TestApp::json($r)['error']);
    }

    public function testOwnPlaylistForbiddenBySpotifyShowsRestrictedView(): void
    {
        $this->t->http->addPlaylist('pl000000000000000000077', 'spotify_user2', 'Eigen', [1]);
        $this->t->http->failures['GET pl000000000000000000077/items'] = [403, ['error' => ['status' => 403, 'message' => 'Forbidden']], 5];
        $r = $this->t->request('GET', '/api/playlists/pl000000000000000000077', [], $this->tokens['user2']);
        self::assertSame(200, $r->status, $r->body);
        self::assertSame('spotify_forbidden', TestApp::json($r)['restricted_reason']);
    }

    public function testDuplicatesDetectedBySameTrackIdOnly(): void
    {
        $this->t->http->addPlaylist('pl000000000000000000001', 'spotify_user2', 'Dup', [1, 2, 1, 3, 2, 1]);
        $r = TestApp::json($this->t->request('GET', '/api/playlists/pl000000000000000000001/duplicates', [], $this->tokens['user2']));
        self::assertCount(2, $r['groups']);
        $g1 = $r['groups'][0];
        self::assertSame($this->t->http->trackId(1), $g1['track_id']);
        self::assertSame([0, 2, 5], array_column($g1['occurrences'], 'position'));
        self::assertSame(0, $g1['keep_position']);
        // Standard: ein Vorkommen behalten → Positionen 2,5 und 4 entfernen
        $r2 = TestApp::json($this->t->request('POST', '/api/playlists/pl000000000000000000001/duplicates/remove', [
            'op_id' => $this->uuid(), 'snapshot_id' => $r['snapshot_id'],
            'removals' => [['uri' => 'spotify:track:' . $this->t->http->trackId(1), 'positions' => [2, 5]], ['uri' => 'spotify:track:' . $this->t->http->trackId(2), 'positions' => [4]]],
        ], $this->tokens['user2']));
        self::assertSame('done', $r2['status']);
        self::assertSame([$this->t->http->trackId(1), $this->t->http->trackId(2), $this->t->http->trackId(3)], $this->t->http->playlists['pl000000000000000000001']['items']);
    }

    public function testSnapshotConflictIsDetectedBeforeRemoving(): void
    {
        $this->t->http->addPlaylist('pl000000000000000000001', 'spotify_user2', 'A', [1, 2, 3]);
        $r = TestApp::json($this->t->request('GET', '/api/playlists/pl000000000000000000001', [], $this->tokens['user2']));
        $snap = $r['playlist']['snapshot_id'];
        // Änderung in der Spotify-App
        $this->t->http->playlists['pl000000000000000000001']['items'] = [$this->t->http->trackId(9), $this->t->http->trackId(1), $this->t->http->trackId(2), $this->t->http->trackId(3)];
        $this->t->http->playlists['pl000000000000000000001']['snapshot']++;
        $r = $this->t->request('POST', '/api/playlists/pl000000000000000000001/items/remove', ['snapshot_id' => $snap, 'items' => [['uri' => 'spotify:track:' . $this->t->http->trackId(1), 'positions' => [0]]]], $this->tokens['user2']);
        self::assertSame(409, $r->status);
        self::assertSame('playlist.snapshot_conflict', TestApp::json($r)['error']);
        self::assertCount(4, $this->t->http->playlists['pl000000000000000000001']['items']);
    }

    public function testMoveAddsBeforeRemovingAndResumesAfterPartialFailure(): void
    {
        $http = $this->t->http;
        $http->addPlaylist('plsource000000000000001', 'spotify_user2', 'Quelle', [1, 2, 3, 4]);
        $http->addPlaylist('pltarget000000000000001', 'spotify_user2', 'Ziel', [10]);
        $snap = TestApp::json($this->t->request('GET', '/api/playlists/plsource000000000000001', [], $this->tokens['user2']))['playlist']['snapshot_id'];
        // Entfernen aus der Quelle schlägt einmal fehl (z. B. Netz)
        $http->failures['DELETE plsource000000000000001/items'] = [503, ['error' => ['status' => 503, 'message' => 'down']], 3];
        $op = $this->uuid();
        $r = TestApp::json($this->t->request('POST', '/api/playlists/move', [
            'op_id' => $op, 'source_id' => 'plsource000000000000001', 'target_id' => 'pltarget000000000000001', 'snapshot_id' => $snap,
            'items' => [['uri' => 'spotify:track:' . $http->trackId(2), 'positions' => [1]], ['uri' => 'spotify:track:' . $http->trackId(3), 'positions' => [2]]],
        ], $this->tokens['user2']));
        self::assertSame('partial', $r['status']);
        // Ziel hat die Songs, Quelle hat sie noch – nichts verloren
        self::assertSame([$http->trackId(10), $http->trackId(2), $http->trackId(3)], $http->playlists['pltarget000000000000001']['items']);
        self::assertCount(4, $http->playlists['plsource000000000000001']['items']);
        // Wiederaufnahme: fügt nicht erneut hinzu, entfernt jetzt aus der Quelle
        $r = TestApp::json($this->t->request('POST', '/api/playlists/operations/' . $op . '/resume', [], $this->tokens['user2']));
        self::assertSame('done', $r['status']);
        self::assertSame([$http->trackId(10), $http->trackId(2), $http->trackId(3)], $http->playlists['pltarget000000000000001']['items']);
        self::assertSame([$http->trackId(1), $http->trackId(4)], $http->playlists['plsource000000000000001']['items']);
        // Wiederholung derselben Operation ist idempotent
        $r = TestApp::json($this->t->request('POST', '/api/playlists/operations/' . $op . '/resume', [], $this->tokens['user2']));
        self::assertSame('done', $r['status']);
        self::assertCount(3, $http->playlists['pltarget000000000000001']['items']);
    }

    public function testConcurrentOperationStartIsRejectedWhileLocked(): void
    {
        $http = $this->t->http;
        $http->addPlaylist('pla00000000000000000001', 'spotify_user2', 'A', [1, 2]);
        $http->addPlaylist('plb00000000000000000001', 'spotify_user2', 'B', [3]);
        $op = $this->uuid();
        $now = $this->t->db->now();
        $this->t->db->insert('playlist_operations', [
            'op_uuid' => $op, 'user_id' => $this->t->userId('spotify_user2'), 'type' => 'copy', 'source_playlist_id' => 'pla00000000000000000001', 'target_playlist_id' => 'plb00000000000000000001',
            'payload' => json_encode(['uris' => ['spotify:track:' . $http->trackId(1)]]), 'status' => 'pending', 'progress' => '{}',
            'locked_until' => gmdate('Y-m-d H:i:s', time() + 120), 'created_at' => $now, 'updated_at' => $now,
        ]);
        $r = $this->t->request('POST', '/api/playlists/copy', ['op_id' => $op, 'source_id' => 'pla00000000000000000001', 'target_id' => 'plb00000000000000000001', 'uris' => ['spotify:track:' . $http->trackId(1)]], $this->tokens['user2']);
        self::assertSame(409, $r->status);
        self::assertSame('operation.in_progress', TestApp::json($r)['error']);
        self::assertCount(1, $http->playlists['plb00000000000000000001']['items'], 'Nichts doppelt hinzugefügt');
        // Abgelaufene Sperre → Ausführung
        $this->t->db->run('UPDATE playlist_operations SET locked_until = ? WHERE op_uuid = ?', [gmdate('Y-m-d H:i:s', time() - 5), $op]);
        $r = TestApp::json($this->t->request('POST', '/api/playlists/operations/' . $op . '/resume', [], $this->tokens['user2']));
        self::assertSame('done', $r['status']);
        self::assertCount(2, $http->playlists['plb00000000000000000001']['items']);
        self::assertNull($this->t->db->value('SELECT locked_until FROM playlist_operations WHERE op_uuid = ?', [$op]));
    }

    public function testLocalFilesWithLongUrisDoNotBreakLoading(): void
    {
        $http = $this->t->http;
        $http->addPlaylist('pl000000000000000000001', 'spotify_user2', 'Local', [1]);
        $longUri = 'spotify:local:' . str_repeat('Artist+Name+', 10) . ':' . str_repeat('Album+', 10) . ':' . str_repeat('Title+', 10) . ':240';
        $http->tracks['localtrack0000000000001'] = ['id' => null, 'uri' => $longUri, 'name' => 'Lokale Datei', 'duration_ms' => 240000, 'artists' => [['id' => null, 'name' => 'Lokal']], 'album' => ['id' => null, 'name' => 'Album', 'images' => []]];
        $http->playlists['pl000000000000000000001']['items'][] = 'localtrack0000000000001';
        $r = $this->t->request('GET', '/api/playlists/pl000000000000000000001', [], $this->tokens['user2']);
        self::assertSame(200, $r->status, $r->body);
        self::assertCount(2, TestApp::json($r)['items']);
    }

    public function testMergeAndDuplicateKeepOrderAndSkipExisting(): void
    {
        $http = $this->t->http;
        $http->addPlaylist('pla00000000000000000001', 'spotify_user2', 'A', [1, 2, 3]);
        $http->addPlaylist('plb00000000000000000001', 'spotify_user2', 'B', [3, 4, 5]);
        $r = TestApp::json($this->t->request('POST', '/api/playlists/merge', ['op_id' => $this->uuid(), 'sources' => ['pla00000000000000000001', 'plb00000000000000000001'], 'new_name' => 'Merged', 'skip_existing' => true], $this->tokens['user2']));
        self::assertSame('done', $r['status']);
        $target = $r['target_playlist_id'];
        self::assertSame(array_map(fn($n) => $http->trackId($n), [1, 2, 3, 4, 5]), $http->playlists[$target]['items']);
        $r = TestApp::json($this->t->request('POST', '/api/playlists/pla00000000000000000001/duplicate', ['op_id' => $this->uuid(), 'name' => 'A Kopie'], $this->tokens['user2']));
        self::assertSame('done', $r['status']);
        self::assertSame($http->playlists['pla00000000000000000001']['items'], $http->playlists[$r['target_playlist_id']]['items']);
        self::assertSame('A Kopie', $http->playlists[$r['target_playlist_id']]['name']);
    }

    public function testEmptyPlaylistsOnlyAfterSuccessfulVerification(): void
    {
        $http = $this->t->http;
        $http->addPlaylist('plempty0000000000000001', 'spotify_user2', 'Leer 1', []);
        $http->addPlaylist('plempty0000000000000002', 'spotify_user2', 'Leer 2', []);
        $http->addPlaylist('plfull00000000000000001', 'spotify_user2', 'Voll', [1]);
        $http->addPlaylist('plforeign00000000000002', 'spotify_user3', 'Fremd leer', []);
        $http->playlists['plforeign00000000000002']['followers'] = ['spotify_user2'];
        // Verifikation von Leer 2 scheitert
        $http->failures['GET playlists/plempty0000000000000002?'] = [500, ['error' => ['status' => 500, 'message' => 'oops']], 5];
        $r = TestApp::json($this->t->request('GET', '/api/playlists/empty', [], $this->tokens['user2']));
        $ids = array_column($r['candidates'], 'id');
        self::assertContains('plempty0000000000000001', $ids);
        self::assertContains('plforeign00000000000002', $ids);
        self::assertFalse(in_array('plempty0000000000000002', $ids, true));
        self::assertContains('plempty0000000000000002', $r['unverified']);
        $foreign = array_values(array_filter($r['candidates'], fn($c) => $c['id'] === 'plforeign00000000000002'))[0];
        self::assertFalse($foreign['is_owner']);
        // Leere Liste hat inzwischen Songs bekommen → wird übersprungen
        $http->playlists['plempty0000000000000001']['items'] = [$http->trackId(5)];
        $r = TestApp::json($this->t->request('POST', '/api/playlists/empty/remove', ['ids' => ['plempty0000000000000001', 'plforeign00000000000002']], $this->tokens['user2']));
        self::assertSame('skipped', $r['results'][0]['status']);
        self::assertSame('removed', $r['results'][1]['status']);
        self::assertContains('plforeign00000000000002', $http->libraryUnfollowed);
        self::assertTrue(isset($http->playlists['plempty0000000000000001']));
    }

    public function testCoverUploadIsReencodedBelowSpotifyLimit(): void
    {
        $http = $this->t->http;
        $http->addPlaylist('pl000000000000000000001', 'spotify_user2', 'Cover', [1]);
        $img = imagecreatetruecolor(2400, 1600);
        for ($i = 0; $i < 400; $i++) {
            imagefilledellipse($img, random_int(0, 2400), random_int(0, 1600), random_int(50, 400), random_int(50, 400), imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        }
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        $_POST['image_base64'] = base64_encode($png);
        $r = $this->t->request('POST', '/api/playlists/pl000000000000000000001/cover', [], $this->tokens['user2'], [], ['content-type' => 'multipart/form-data']);
        $_POST = [];
        self::assertSame(200, $r->status, $r->body);
        $upload = array_values(array_filter($http->log, fn($l) => str_contains($l['url'], '/images') && $l['method'] === 'PUT'))[0];
        self::assertTrue(strlen((string) $upload['body']) <= 256 * 1024);
        $jpeg = base64_decode((string) $upload['body'], true);
        $info = getimagesizefromstring($jpeg);
        self::assertSame('image/jpeg', $info['mime']);
        self::assertSame($info[0], $info[1]);
    }

    public function testReorderUpdatesLocalCache(): void
    {
        $http = $this->t->http;
        $http->addPlaylist('pl000000000000000000001', 'spotify_user2', 'Order', [1, 2, 3, 4, 5]);
        $snap = TestApp::json($this->t->request('GET', '/api/playlists/pl000000000000000000001', [], $this->tokens['user2']))['playlist']['snapshot_id'];
        $r = $this->t->request('POST', '/api/playlists/pl000000000000000000001/items/reorder', ['range_start' => 0, 'insert_before' => 3, 'range_length' => 1, 'snapshot_id' => $snap], $this->tokens['user2']);
        self::assertSame(200, $r->status);
        $expected = array_map(fn($n) => $http->trackId($n), [2, 3, 1, 4, 5]);
        self::assertSame($expected, $http->playlists['pl000000000000000000001']['items']);
        $items = TestApp::json($this->t->request('GET', '/api/playlists/pl000000000000000000001', [], $this->tokens['user2']))['items'];
        self::assertSame($expected, array_column($items, 'id'));
    }

    public function testSearchUsesSpotifyLimitOfTen(): void
    {
        $r = TestApp::json($this->t->request('GET', '/api/spotify/search', [], $this->tokens['user2'], ['q' => 'Song']));
        self::assertCount(10, $r['tracks']);
        self::assertSame(10, $r['limit']);
    }
}
