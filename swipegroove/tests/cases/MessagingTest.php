<?php
declare(strict_types=1);

namespace SwipeGroove\Tests\Cases;

use SwipeGroove\Tests\TestApp;
use SwipeGroove\Tests\TestCase;

final class MessagingTest extends TestCase
{
    private TestApp $t;
    private array $tokens;

    protected function setUp(): void
    {
        $this->t = TestApp::create();
        $this->tokens = $this->t->seedUsers();
        foreach (['spotify_owner' => 'owner', 'spotify_user2' => 'alice', 'spotify_user3' => 'bob'] as $sp => $name) {
            $this->t->db->run('UPDATE users SET username = ?, display_name = ?, onboarding_done = 1 WHERE spotify_user_id = ?', [$name, ucfirst($name), $sp]);
        }
    }

    public function testMessageRequestFlowAndPrivacy(): void
    {
        // Nicht befreundet → Anfrage
        $conv = TestApp::json($this->t->request('POST', '/api/conversations', ['username' => 'bob'], $this->tokens['user2']));
        self::assertSame('pending', $conv['status']);
        $cid = $conv['id'];
        $m = TestApp::json($this->t->request('POST', '/api/conversations/' . $cid . '/messages', ['body' => 'Hi Bob'], $this->tokens['user2']));
        self::assertSame('Hi Bob', $m['body']);
        // Zweite Nachricht vor Annahme nicht erlaubt
        self::assertSame(403, $this->t->request('POST', '/api/conversations/' . $cid . '/messages', ['body' => 'again'], $this->tokens['user2'])->status);
        // Dritter hat keinen Zugriff
        self::assertSame(404, $this->t->request('GET', '/api/conversations/' . $cid . '/messages', [], $this->tokens['owner'])->status);
        self::assertSame(404, $this->t->request('POST', '/api/conversations/' . $cid . '/messages', ['body' => 'x'], $this->tokens['owner'])->status);
        // Bob sieht die Anfrage, ungelesen 1
        $list = TestApp::json($this->t->request('GET', '/api/conversations', [], $this->tokens['user3']));
        self::assertCount(1, $list['conversations']);
        self::assertSame(1, $list['conversations'][0]['unread_count']);
        self::assertSame(1, TestApp::json($this->t->request('GET', '/api/me', [], $this->tokens['user3']))['unread_messages']);
        $r = TestApp::json($this->t->request('POST', '/api/conversations/' . $cid . '/accept', [], $this->tokens['user3']));
        self::assertSame('accepted', $r['status']);
        // Inkrementelles Polling
        $this->t->request('POST', '/api/conversations/' . $cid . '/messages', ['body' => 'Hello Alice'], $this->tokens['user3']);
        $u = TestApp::json($this->t->request('GET', '/api/updates', [], $this->tokens['user2'], ['conversation' => $cid, 'after' => $m['id']]));
        self::assertCount(1, $u['messages']);
        self::assertSame('Hello Alice', $u['messages'][0]['body']);
        self::assertSame(1, $u['unread_messages']);
        $this->t->request('POST', '/api/conversations/' . $cid . '/read', [], $this->tokens['user2']);
        self::assertSame(0, TestApp::json($this->t->request('GET', '/api/updates', [], $this->tokens['user2']))['unread_messages']);
        // Verlauf
        $msgs = TestApp::json($this->t->request('GET', '/api/conversations/' . $cid . '/messages', [], $this->tokens['user2']));
        self::assertCount(2, $msgs['messages']);
        self::assertTrue($msgs['messages'][0]['mine']);
        // Geteilter Link
        $l = TestApp::json($this->t->request('POST', '/api/conversations/' . $cid . '/messages', ['body' => '', 'link' => ['type' => 'profile', 'ref' => 'owner']], $this->tokens['user2']));
        self::assertSame('profile', $l['link']['type']);
        self::assertSame(400, $this->t->request('POST', '/api/conversations/' . $cid . '/messages', ['body' => '', 'link' => ['type' => 'evil', 'ref' => '<x>']], $this->tokens['user2'])->status);
    }

    public function testDeclineAndSettings(): void
    {
        $conv = TestApp::json($this->t->request('POST', '/api/conversations', ['username' => 'bob'], $this->tokens['user2']));
        $this->t->request('POST', '/api/conversations/' . $conv['id'] . '/messages', ['body' => 'Hi'], $this->tokens['user2']);
        $this->t->request('POST', '/api/conversations/' . $conv['id'] . '/decline', [], $this->tokens['user3']);
        self::assertSame(403, $this->t->request('POST', '/api/conversations/' . $conv['id'] . '/messages', ['body' => 'again'], $this->tokens['user2'])->status);
        // Bob deaktiviert Anfragen → Owner kann nicht schreiben
        $this->t->request('PATCH', '/api/me', ['msg_requests_allowed' => false], $this->tokens['user3']);
        self::assertSame(403, $this->t->request('POST', '/api/conversations', ['username' => 'bob'], $this->tokens['owner'])->status);
        // Freunde dürfen direkt schreiben
        $this->t->request('POST', '/api/users/bob/friend-request', [], $this->tokens['owner']);
        $this->t->request('POST', '/api/users/owner/friend-accept', [], $this->tokens['user3']);
        $c = TestApp::json($this->t->request('POST', '/api/conversations', ['username' => 'bob'], $this->tokens['owner']));
        self::assertSame('accepted', $c['status']);
        // Nachrichten von Freunden deaktiviert
        $this->t->request('PATCH', '/api/me', ['msg_from_friends' => false], $this->tokens['user3']);
        self::assertSame(403, $this->t->request('POST', '/api/conversations/' . $c['id'] . '/messages', ['body' => 'x'], $this->tokens['owner'])->status);
    }

    public function testAdminCannotReadChatsWithoutReport(): void
    {
        $conv = TestApp::json($this->t->request('POST', '/api/conversations', ['username' => 'bob'], $this->tokens['user2']));
        $m = TestApp::json($this->t->request('POST', '/api/conversations/' . $conv['id'] . '/messages', ['body' => 'secret'], $this->tokens['user2']));
        self::assertSame(404, $this->t->request('GET', '/api/conversations/' . $conv['id'] . '/messages', [], $this->tokens['owner'])->status);
        // Meldung durch Bob → Admin sieht begrenzten Kontext, Zugriff protokolliert
        $rep = TestApp::json($this->t->request('POST', '/api/reports', ['target_type' => 'message', 'target_id' => $m['id'], 'reason' => 'harassment'], $this->tokens['user3']));
        $d = TestApp::json($this->t->request('GET', '/api/admin/reports/' . $rep['id'], [], $this->tokens['owner']));
        self::assertSame('secret', $d['content']['messages'][0]['body']);
        self::assertSame(1, (int) $this->t->db->value('SELECT COUNT(*) FROM audit_log WHERE action = ?', ['moderation.view_message']));
        // Fremde Nachricht kann nicht gemeldet werden
        self::assertSame(404, $this->t->request('POST', '/api/reports', ['target_type' => 'message', 'target_id' => $m['id'], 'reason' => 'spam'], $this->tokens['owner'])->status);
    }
}
