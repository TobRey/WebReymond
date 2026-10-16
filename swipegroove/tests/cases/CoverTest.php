<?php
declare(strict_types=1);

namespace SwipeGroove\Tests\Cases;

use SwipeGroove\Tests\TestApp;
use SwipeGroove\Tests\TestCase;

final class CoverTest extends TestCase
{
    private TestApp $t;
    private array $tokens;

    protected function setUp(): void
    {
        $this->t = TestApp::create();
        $this->tokens = $this->t->seedUsers();
        $this->t->http->addPlaylist('pl000000000000000000001', 'spotify_user2', 'Mine', [1]);
    }

    private function uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    private function makePremium(string $spotifyUser): void
    {
        $this->t->db->run('UPDATE users SET premium_manual_until = ? WHERE spotify_user_id = ?', [gmdate('Y-m-d H:i:s', time() + 86400 * 30), $spotifyUser]);
    }

    public function testFreeUsersHaveNoQuotaAndOwnerIsUnlimited(): void
    {
        $r = $this->t->request('POST', '/api/covers', ['job_id' => $this->uuid(), 'prompt' => 'Sunset over the city'], $this->tokens['user2']);
        self::assertSame(403, $r->status);
        self::assertSame('covers.premium_required', TestApp::json($r)['error']);
        $q = TestApp::json($this->t->request('GET', '/api/covers/quota', [], $this->tokens['owner']));
        self::assertTrue($q['unlimited']);
        $r = TestApp::json($this->t->request('POST', '/api/covers', ['job_id' => $this->uuid(), 'prompt' => 'Sunset over the city', 'style' => 'neon'], $this->tokens['owner']));
        self::assertSame('done', $r['cover']['status']);
        self::assertStringContains('/groove/media/cover/', $r['cover']['image_url']);
        // Nur die Beschreibung geht an den Anbieter, keine Spotify-Daten
        $call = array_values(array_filter($this->t->http->log, fn($l) => str_contains($l['url'], 'openai')))[0];
        self::assertStringContains('Sunset over the city', (string) $call['body']);
        self::assertFalse(str_contains((string) $call['body'], 'Mine'));
        self::assertFalse(str_contains((string) $call['body'], 'scdn'));
    }

    public function testQuotaCountsOnlySuccessfulGenerations(): void
    {
        $this->makePremium('spotify_user2');
        $this->t->app->settings()->set('premium_cover_quota', 2);
        $this->t->http->openaiResponses[] = [500, ['error' => ['message' => 'server error']]];
        $r = $this->t->request('POST', '/api/covers', ['job_id' => $this->uuid(), 'prompt' => 'A dog'], $this->tokens['user2']);
        self::assertSame(502, $r->status);
        $q = TestApp::json($this->t->request('GET', '/api/covers/quota', [], $this->tokens['user2']));
        self::assertSame(0, $q['used']);
        self::assertSame(2, $q['remaining']);
        // Inhaltsrichtlinie → 422, zählt nicht
        $this->t->http->openaiResponses[] = [400, ['error' => ['message' => 'Your request was rejected by the safety system', 'code' => 'moderation_blocked']]];
        $r = $this->t->request('POST', '/api/covers', ['job_id' => $this->uuid(), 'prompt' => 'Bad'], $this->tokens['user2']);
        self::assertSame(422, $r->status);
        self::assertSame('covers.provider_content_policy', TestApp::json($r)['error']);
        $ok1 = TestApp::json($this->t->request('POST', '/api/covers', ['job_id' => $this->uuid(), 'prompt' => 'A cat'], $this->tokens['user2']));
        $ok2 = TestApp::json($this->t->request('POST', '/api/covers', ['job_id' => $this->uuid(), 'prompt' => 'A bird'], $this->tokens['user2']));
        self::assertSame(0, $ok2['quota']['remaining']);
        $r = $this->t->request('POST', '/api/covers', ['job_id' => $this->uuid(), 'prompt' => 'A fish'], $this->tokens['user2']);
        self::assertSame(403, $r->status);
        self::assertSame('covers.quota_exhausted', TestApp::json($r)['error']);
        self::assertNotNull(TestApp::json($r)['resets_at']);
        // Wiederholung derselben Job-ID liefert das vorhandene Ergebnis ohne neuen Aufruf
        $before = count($this->t->http->log);
        $again = TestApp::json($this->t->request('POST', '/api/covers', ['job_id' => $ok1['cover']['id'], 'prompt' => 'A cat'], $this->tokens['user2']));
        self::assertSame($ok1['cover']['id'], $again['cover']['id']);
        self::assertSame($before, count($this->t->http->log));
        // Kostenübersicht im Admin
        $admin = TestApp::json($this->t->request('GET', '/api/admin/covers', [], $this->tokens['owner']));
        self::assertTrue(count($admin['jobs']) >= 4);
        self::assertTrue((float) $admin['by_month'][0]['cost_usd'] > 0);
    }

    public function testParallelRequestsCannotExceedQuota(): void
    {
        if (!function_exists('pcntl_fork')) {
            return;
        }
        $this->makePremium('spotify_user2');
        $this->t->app->settings()->set('premium_cover_quota', 3);
        $this->t->app->settings()->set('max_parallel_cover_jobs', 10);
        $uid = $this->t->userId('spotify_user2');
        $token = $this->tokens['user2'];
        $this->t->db->pdo()->exec('DO 1');
        $children = [];
        for ($i = 0; $i < 8; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                // Kind: eigene DB-Verbindung und App-Instanz
                $child = TestApp::createForChild();
                $child->http->openaiResponses = [];
                $r = $child->request('POST', '/api/covers', ['job_id' => $this->uuid(), 'prompt' => 'Parallel ' . $i], $token);
                exit($r->status === 200 ? 0 : 1);
            }
            $children[] = $pid;
        }
        $ok = 0;
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            if (pcntl_wexitstatus($status) === 0) {
                $ok++;
            }
        }
        $this->t = TestApp::createForChild();
        $done = (int) $this->t->db->value('SELECT COUNT(*) FROM cover_jobs WHERE user_id = ? AND status IN (\'done\',\'applied\')', [$uid]);
        self::assertSame(3, $done, 'Genau Kontingent erfolgreich');
        self::assertSame(3, $ok);
    }

    public function testApplyCoverToOwnPlaylistOnlyAfterConfirmation(): void
    {
        $r = TestApp::json($this->t->request('POST', '/api/covers', ['job_id' => $this->uuid(), 'prompt' => 'Mountains'], $this->tokens['owner']));
        $id = $r['cover']['id'];
        self::assertFalse((bool) ($this->t->http->playlists['pl000000000000000000001']['cover_uploaded'] ?? false));
        // Owner besitzt diese Playlist nicht → nicht bearbeitbar / nicht gefunden
        $this->t->http->playlists['pl000000000000000000001']['followers'] = ['spotify_owner'];
        $bad = $this->t->request('POST', '/api/covers/' . $id . '/apply', ['playlist_id' => 'pl000000000000000000001'], $this->tokens['owner']);
        self::assertSame(403, $bad->status);
        $this->t->http->addPlaylist('plowner0000000000000001', 'spotify_owner', 'Owner List', [2]);
        $ok = TestApp::json($this->t->request('POST', '/api/covers/' . $id . '/apply', ['playlist_id' => 'plowner0000000000000001'], $this->tokens['owner']));
        self::assertSame('applied', $ok['status']);
        self::assertTrue($this->t->http->playlists['plowner0000000000000001']['cover_uploaded']);
        // Bild abrufbar, fremder Nutzer nicht
        self::assertSame(200, $this->t->request('GET', '/media/cover/' . $id, [], $this->tokens['owner'])->status);
        self::assertSame(404, $this->t->request('GET', '/media/cover/' . $id, [], $this->tokens['user2'])->status);
    }
}
