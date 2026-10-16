<?php
declare(strict_types=1);

namespace SwipeGroove\Billing;

use SwipeGroove\Core\App;
use SwipeGroove\Core\HttpException;
use SwipeGroove\Users\UserRepository;

/**
 * Premium-Status: Besitzer (immer, ohne Zahlung), manuelle Freischaltung durch Admin,
 * oder verifiziertes Stripe-Abo. Aktivierung ausschliesslich über Webhook-Ereignisse
 * oder serverseitige Abfrage bei Stripe – nie über Client-Angaben.
 */
final class SubscriptionService
{
    private const ACTIVE_STATUSES = ['active', 'trialing', 'past_due'];

    public function __construct(private App $app)
    {
    }

    public function stripe(): StripeClient
    {
        return $this->app->service(StripeClient::class);
    }

    public function row(int $userId): ?array
    {
        return $this->app->db()->one('SELECT * FROM subscriptions WHERE user_id = ?', [$userId]);
    }

    public function isOwner(object $user): bool
    {
        return $this->app->service(UserRepository::class)->isOwner((int) $user->id);
    }

    public function isPremium(object $user): bool
    {
        return (bool) $this->summary($user)['active'];
    }

    /** @return array<string,mixed> */
    public function summary(object $user): array
    {
        $owner = $this->isOwner($user);
        $manualUntil = $user->premium_manual_until ?? null;
        $manual = $manualUntil && strtotime((string) $manualUntil) > time();
        $sub = $this->row((int) $user->id);
        $stripeActive = false;
        if ($sub && in_array($sub['status'], self::ACTIVE_STATUSES, true)) {
            $end = $sub['current_period_end'] ? strtotime((string) $sub['current_period_end']) : null;
            // past_due: Kulanz bis Periodenende + 3 Tage
            $stripeActive = $end === null || $end + ($sub['status'] === 'past_due' ? 3 * 86400 : 86400) > time();
        }
        $source = $owner ? 'owner' : ($stripeActive ? 'stripe' : ($manual ? 'manual' : 'none'));
        return [
            'active' => $owner || $stripeActive || $manual,
            'source' => $source,
            'unlimited_covers' => $owner,
            'status' => $sub['status'] ?? 'none',
            'cancel_at_period_end' => (bool) ($sub['cancel_at_period_end'] ?? false),
            'current_period_end' => $sub['current_period_end'] ?? null,
            'manual_until' => $manual ? $manualUntil : null,
            'livemode' => (bool) ($sub['livemode'] ?? false),
            'payment_error' => $sub['last_payment_error'] ?? null,
        ];
    }

    /**
     * Abrechnungszeitraum für das Cover-Kontingent: Stripe-Periode, sonst Kalendermonat.
     * @return array{key:string, start:string, end:string}
     */
    public function currentPeriod(object $user): array
    {
        $sub = $this->row((int) $user->id);
        if ($sub && $sub['current_period_start'] && $sub['current_period_end'] && strtotime((string) $sub['current_period_end']) > time()) {
            return [
                'key' => 'sub:' . substr(md5((string) $sub['stripe_subscription_id'] . $sub['current_period_start']), 0, 16),
                'start' => $sub['current_period_start'],
                'end' => $sub['current_period_end'],
            ];
        }
        $start = gmdate('Y-m-01 00:00:00');
        $end = gmdate('Y-m-01 00:00:00', strtotime('first day of next month'));
        return ['key' => 'month:' . gmdate('Y-m'), 'start' => $start, 'end' => $end];
    }

    private function ensureCustomer(object $user): string
    {
        $sub = $this->row((int) $user->id);
        if ($sub && $sub['stripe_customer_id'] && (bool) $sub['livemode'] === ($this->stripe()->mode() === 'live')) {
            return (string) $sub['stripe_customer_id'];
        }
        $customer = $this->stripe()->request('POST', '/customers', [
            'metadata[sg_user_id]' => (string) $user->id,
            'metadata[sg_username]' => (string) $user->username,
            'description' => 'SwipeGroove ' . $user->username,
        ], 'cust-' . $user->id . '-' . $this->stripe()->mode());
        $db = $this->app->db();
        $data = ['stripe_customer_id' => $customer['id'], 'livemode' => $this->stripe()->mode() === 'live' ? 1 : 0, 'updated_at' => $db->now()];
        if ($sub) {
            $db->update('subscriptions', $data, 'user_id = ?', [$user->id]);
        } else {
            $db->insert('subscriptions', $data + ['user_id' => $user->id, 'status' => 'none', 'created_at' => $db->now()]);
        }
        return (string) $customer['id'];
    }

    public function checkoutUrl(object $user): string
    {
        if (!$this->stripe()->isConfigured()) {
            throw HttpException::conflict('billing.not_configured');
        }
        if ($this->isOwner($user)) {
            throw HttpException::conflict('billing.owner');
        }
        $sub = $this->row((int) $user->id);
        if ($sub && in_array($sub['status'], ['active', 'trialing', 'past_due'], true) && !(bool) $sub['cancel_at_period_end']) {
            throw HttpException::conflict('billing.already_subscribed');
        }
        $customer = $this->ensureCustomer($user);
        $session = $this->stripe()->request('POST', '/checkout/sessions', [
            'mode' => 'subscription',
            'customer' => $customer,
            'line_items[0][price]' => $this->app->config?->string('stripe.price_id'),
            'line_items[0][quantity]' => '1',
            'success_url' => $this->app->url->to('/premium', ['checkout' => 'success']),
            'cancel_url' => $this->app->url->to('/premium', ['checkout' => 'cancel']),
            'client_reference_id' => (string) $user->id,
            'metadata[sg_user_id]' => (string) $user->id,
            'subscription_data[metadata][sg_user_id]' => (string) $user->id,
            'allow_promotion_codes' => 'true',
            'locale' => $user->lang === 'de' ? 'de' : 'en',
        ]);
        return (string) ($session['url'] ?? '');
    }

    public function portalUrl(object $user): string
    {
        if (!$this->stripe()->isConfigured()) {
            throw HttpException::conflict('billing.not_configured');
        }
        $sub = $this->row((int) $user->id);
        if (!$sub || !$sub['stripe_customer_id']) {
            throw HttpException::conflict('billing.no_customer');
        }
        $session = $this->stripe()->request('POST', '/billing_portal/sessions', [
            'customer' => $sub['stripe_customer_id'],
            'return_url' => $this->app->url->to('/premium'),
            'locale' => $user->lang === 'de' ? 'de' : 'en',
        ]);
        return (string) ($session['url'] ?? '');
    }

    /** Status direkt bei Stripe nachladen (z. B. nach Rückkehr vom Checkout, falls Webhook verzögert). */
    public function refreshFromStripe(object $user): array
    {
        $sub = $this->row((int) $user->id);
        if (!$this->stripe()->isConfigured() || !$sub || !$sub['stripe_customer_id']) {
            return $this->summary($user);
        }
        $list = $this->stripe()->request('GET', '/subscriptions', ['customer' => $sub['stripe_customer_id'], 'status' => 'all', 'limit' => 5]);
        $best = null;
        foreach ($list['data'] ?? [] as $s) {
            if (!$best || in_array($s['status'], self::ACTIVE_STATUSES, true)) {
                $best = $s;
                if (in_array($s['status'], self::ACTIVE_STATUSES, true)) {
                    break;
                }
            }
        }
        if ($best) {
            $this->applySubscriptionObject($best, (int) $user->id);
        }
        $fresh = $this->app->service(UserRepository::class)->find((int) $user->id) ?? $user;
        return $this->summary($fresh);
    }

    /** Übernimmt ein Stripe-Subscription-Objekt in die Datenbank. */
    public function applySubscriptionObject(array $s, ?int $userIdHint = null): void
    {
        $db = $this->app->db();
        $customerId = is_array($s['customer'] ?? null) ? ($s['customer']['id'] ?? null) : ($s['customer'] ?? null);
        $userId = $userIdHint;
        if (!$userId && isset($s['metadata']['sg_user_id'])) {
            $userId = (int) $s['metadata']['sg_user_id'];
        }
        if (!$userId && $customerId) {
            $userId = (int) ($db->value('SELECT user_id FROM subscriptions WHERE stripe_customer_id = ?', [$customerId]) ?? 0);
        }
        if (!$userId) {
            $this->app->logger->warning('Stripe-Abo ohne zuordenbaren Nutzer', ['subscription' => $s['id'] ?? null]);
            return;
        }
        $item = $s['items']['data'][0] ?? null;
        $periodStart = $item['current_period_start'] ?? $s['current_period_start'] ?? null;
        $periodEnd = $item['current_period_end'] ?? $s['current_period_end'] ?? null;
        $data = [
            'stripe_customer_id' => $customerId,
            'stripe_subscription_id' => $s['id'] ?? null,
            'price_id' => $item['price']['id'] ?? null,
            'status' => (string) ($s['status'] ?? 'none'),
            'livemode' => !empty($s['livemode']) ? 1 : 0,
            'current_period_start' => $periodStart ? gmdate('Y-m-d H:i:s', (int) $periodStart) : null,
            'current_period_end' => $periodEnd ? gmdate('Y-m-d H:i:s', (int) $periodEnd) : null,
            'cancel_at_period_end' => !empty($s['cancel_at_period_end']) ? 1 : 0,
            'updated_at' => $db->now(),
        ];
        if (in_array($data['status'], ['active', 'trialing'], true)) {
            $data['last_payment_error'] = null;
        }
        $existing = $this->row($userId);
        if ($existing) {
            // Ein neueres aktives Abo nicht durch ein altes gekündigtes überschreiben
            if ($existing['stripe_subscription_id'] && $existing['stripe_subscription_id'] !== ($s['id'] ?? null)
                && in_array($existing['status'], ['active', 'trialing'], true) && !in_array($data['status'], ['active', 'trialing'], true)) {
                return;
            }
            $db->update('subscriptions', $data, 'user_id = ?', [$userId]);
        } else {
            $db->insert('subscriptions', $data + ['user_id' => $userId, 'created_at' => $db->now()]);
        }
    }

    /**
     * Verarbeitet ein Webhook-Ereignis idempotent (stripe_events).
     * @return string processed|ignored|duplicate
     */
    public function handleEvent(array $event): string
    {
        $db = $this->app->db();
        $id = (string) ($event['id'] ?? '');
        $type = (string) ($event['type'] ?? '');
        if ($id === '' || $type === '') {
            throw HttpException::badRequest('billing.invalid_event');
        }
        // Live/Test-Trennung: Ereignisse des falschen Modus ignorieren
        $live = !empty($event['livemode']);
        if ($live !== ($this->stripe()->mode() === 'live')) {
            $db->run('INSERT IGNORE INTO stripe_events (event_id, type, livemode, status, received_at, processed_at) VALUES (?,?,?,?,?,?)', [$id, $type, $live ? 1 : 0, 'ignored', $db->now(), $db->now()]);
            return 'ignored';
        }
        $claimed = $db->exec('INSERT IGNORE INTO stripe_events (event_id, type, livemode, status, received_at) VALUES (?,?,?,?,?)', [$id, $type, $live ? 1 : 0, 'processing', $db->now()]);
        if ($claimed === 0) {
            return 'duplicate';
        }
        $obj = $event['data']['object'] ?? [];
        try {
            switch ($type) {
                case 'checkout.session.completed':
                    if (($obj['mode'] ?? '') === 'subscription' && !empty($obj['subscription'])) {
                        $userId = (int) ($obj['client_reference_id'] ?? $obj['metadata']['sg_user_id'] ?? 0);
                        $sub = $this->stripe()->request('GET', '/subscriptions/' . $obj['subscription']);
                        $this->applySubscriptionObject($sub, $userId ?: null);
                    }
                    break;
                case 'customer.subscription.created':
                case 'customer.subscription.updated':
                case 'customer.subscription.deleted':
                case 'customer.subscription.paused':
                case 'customer.subscription.resumed':
                    $this->applySubscriptionObject($obj);
                    break;
                case 'invoice.paid':
                case 'invoice.payment_succeeded':
                    $subId = $obj['subscription'] ?? ($obj['parent']['subscription_details']['subscription'] ?? null);
                    if (is_string($subId)) {
                        $sub = $this->stripe()->request('GET', '/subscriptions/' . $subId);
                        $this->applySubscriptionObject($sub);
                    }
                    break;
                case 'invoice.payment_failed':
                    $customer = $obj['customer'] ?? null;
                    if (is_string($customer)) {
                        $db->run('UPDATE subscriptions SET last_payment_error = ?, updated_at = ? WHERE stripe_customer_id = ?',
                            [mb_substr((string) ($obj['last_finalization_error']['message'] ?? 'payment_failed'), 0, 255), $db->now(), $customer]);
                    }
                    $subId = $obj['subscription'] ?? ($obj['parent']['subscription_details']['subscription'] ?? null);
                    if (is_string($subId)) {
                        $sub = $this->stripe()->request('GET', '/subscriptions/' . $subId);
                        $this->applySubscriptionObject($sub);
                    }
                    break;
                default:
                    $db->update('stripe_events', ['status' => 'ignored', 'processed_at' => $db->now()], 'event_id = ?', [$id]);
                    return 'ignored';
            }
            $db->update('stripe_events', ['status' => 'processed', 'processed_at' => $db->now()], 'event_id = ?', [$id]);
            return 'processed';
        } catch (\Throwable $e) {
            // Freigeben, damit Stripe den Webhook erneut zustellen kann
            $db->run('DELETE FROM stripe_events WHERE event_id = ?', [$id]);
            throw $e;
        }
    }
}
