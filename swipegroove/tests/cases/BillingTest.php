<?php
declare(strict_types=1);

namespace SwipeGroove\Tests\Cases;

use SwipeGroove\Billing\StripeClient;
use SwipeGroove\Tests\TestApp;
use SwipeGroove\Tests\TestCase;

final class BillingTest extends TestCase
{
    private TestApp $t;
    private array $tokens;

    protected function setUp(): void
    {
        $this->t = TestApp::create();
        $this->tokens = $this->t->seedUsers();
    }

    private function webhook(array $event, ?string $secret = 'whsec_test', ?int $ts = null): \SwipeGroove\Core\Response
    {
        $payload = json_encode($event);
        $ts ??= time();
        $sig = 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $payload, (string) $secret);
        $r = new \SwipeGroove\Core\Request();
        $r->method = 'POST';
        $r->path = '/webhooks/stripe';
        $r->fullPath = '/groove/webhooks/stripe';
        $r->query = [];
        $r->rawBody = $payload;
        $r->headers = ['content-type' => 'application/json', 'stripe-signature' => $sig];
        $r->ip = '1.2.3.4';
        return $this->t->app->handle($r);
    }

    public function testSignatureVerification(): void
    {
        $payload = '{"id":"evt_1"}';
        $ts = time();
        $good = 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $payload, 'sec');
        self::assertTrue(StripeClient::verifySignature($payload, $good, 'sec'));
        self::assertFalse(StripeClient::verifySignature($payload, $good, 'other'));
        self::assertFalse(StripeClient::verifySignature($payload . ' ', $good, 'sec'));
        $old = 't=' . ($ts - 1000) . ',v1=' . hash_hmac('sha256', ($ts - 1000) . '.' . $payload, 'sec');
        self::assertFalse(StripeClient::verifySignature($payload, $old, 'sec'));
        self::assertFalse(StripeClient::verifySignature($payload, 't=' . $ts . ',v0=abc', 'sec'));
    }

    public function testCheckoutAndWebhookActivatesPremiumIdempotently(): void
    {
        $uid = $this->t->userId('spotify_user2');
        $r = TestApp::json($this->t->request('POST', '/api/billing/checkout', [], $this->tokens['user2']));
        self::assertStringContains('checkout.stripe.com', $r['url']);
        $customer = $this->t->db->value('SELECT stripe_customer_id FROM subscriptions WHERE user_id = ?', [$uid]);
        self::assertNotNull($customer);
        // Besitzer braucht kein Abo
        self::assertSame(409, $this->t->request('POST', '/api/billing/checkout', [], $this->tokens['owner'])->status);
        $sub = $this->t->http->stripeSubscription('sub_1', 'active', ['customer' => $customer, 'user_id' => $uid]);
        $event = ['id' => 'evt_100', 'type' => 'customer.subscription.created', 'livemode' => false, 'data' => ['object' => $sub]];
        self::assertSame(200, $this->webhook($event)->status);
        $me = TestApp::json($this->t->request('GET', '/api/me', [], $this->tokens['user2']));
        self::assertTrue($me['premium']['active']);
        self::assertSame('stripe', $me['premium']['source']);
        // Doppelte Zustellung
        $r = $this->webhook($event);
        self::assertSame('duplicate', TestApp::json($r)['result']);
        // Ungültige Signatur
        self::assertSame(400, $this->webhook($event + ['id' => 'evt_101'], 'wrong')->status);
        self::assertSame(400, $this->webhook(['id' => 'evt_102', 'type' => 'x', 'data' => ['object' => []]], 'whsec_test', time() - 5000)->status);
        // Live-Ereignis im Testmodus wird ignoriert
        $live = ['id' => 'evt_103', 'type' => 'customer.subscription.deleted', 'livemode' => true, 'data' => ['object' => $sub]];
        self::assertSame('ignored', TestApp::json($this->webhook($live))['result']);
        self::assertTrue(TestApp::json($this->t->request('GET', '/api/me', [], $this->tokens['user2']))['premium']['active']);
        // Kündigung zum Periodenende: bleibt bis dahin aktiv
        $sub['cancel_at_period_end'] = true;
        self::assertSame(200, $this->webhook(['id' => 'evt_104', 'type' => 'customer.subscription.updated', 'livemode' => false, 'data' => ['object' => $sub]])->status);
        $me = TestApp::json($this->t->request('GET', '/api/me', [], $this->tokens['user2']));
        self::assertTrue($me['premium']['active']);
        self::assertTrue($me['premium']['cancel_at_period_end']);
        // Zahlung fehlgeschlagen → past_due mit Fehler, abgelaufene Periode → inaktiv
        $this->webhook(['id' => 'evt_105', 'type' => 'invoice.payment_failed', 'livemode' => false, 'data' => ['object' => ['customer' => $customer, 'subscription' => 'sub_1', 'last_finalization_error' => ['message' => 'card declined']]]]);
        self::assertSame('card declined', $this->t->db->value('SELECT last_payment_error FROM subscriptions WHERE user_id = ?', [$uid]));
        $ended = $this->t->http->stripeSubscription('sub_1', 'canceled', ['customer' => $customer, 'user_id' => $uid, 'items' => ['data' => [['id' => 'si_1', 'price' => ['id' => 'price_test'], 'current_period_start' => time() - 40 * 86400, 'current_period_end' => time() - 10 * 86400]]]]);
        $this->webhook(['id' => 'evt_106', 'type' => 'customer.subscription.deleted', 'livemode' => false, 'data' => ['object' => $ended]]);
        $me = TestApp::json($this->t->request('GET', '/api/me', [], $this->tokens['user2']));
        self::assertFalse($me['premium']['active']);
        self::assertSame(403, $this->t->request('POST', '/api/covers', ['job_id' => '11111111-1111-4111-8111-111111111111', 'prompt' => 'A long enough prompt'], $this->tokens['user2'])->status);
        // Portal
        $p = TestApp::json($this->t->request('POST', '/api/billing/portal', [], $this->tokens['user2']));
        self::assertStringContains('billing.stripe.com', $p['url']);
    }

    public function testCheckoutCompletedEventFetchesSubscription(): void
    {
        $uid = $this->t->userId('spotify_user3');
        $event = ['id' => 'evt_200', 'type' => 'checkout.session.completed', 'livemode' => false, 'data' => ['object' => ['mode' => 'subscription', 'subscription' => 'sub_abc', 'client_reference_id' => (string) $uid, 'customer' => 'cus_testabc']]];
        self::assertSame(200, $this->webhook($event)->status);
        $me = TestApp::json($this->t->request('GET', '/api/me', [], $this->tokens['user3']));
        self::assertTrue($me['premium']['active']);
        // Admin-Übersicht maskiert Kundennummer
        $adm = TestApp::json($this->t->request('GET', '/api/admin/subscriptions', [], $this->tokens['owner']));
        self::assertFalse(str_contains(json_encode($adm), 'cus_testabc'));
    }

    public function testWebhookFailureIsRetryable(): void
    {
        $this->t->http->stripeResponses[] = [500, ['error' => ['message' => 'boom']]];
        $event = ['id' => 'evt_300', 'type' => 'checkout.session.completed', 'livemode' => false, 'data' => ['object' => ['mode' => 'subscription', 'subscription' => 'sub_abc', 'client_reference_id' => '2']]];
        self::assertSame(500, $this->webhook($event)->status);
        self::assertSame(0, (int) $this->t->db->value('SELECT COUNT(*) FROM stripe_events WHERE event_id = ?', ['evt_300']));
        self::assertSame(200, $this->webhook($event)->status);
    }
}
