<?php
declare(strict_types=1);

namespace SwipeGroove\Http\Controllers;

use SwipeGroove\Billing\StripeClient;
use SwipeGroove\Billing\SubscriptionService;
use SwipeGroove\Core\App;
use SwipeGroove\Core\HttpException;
use SwipeGroove\Core\Request;
use SwipeGroove\Core\Response;

final class BillingController
{
    public function __construct(private App $app)
    {
    }

    private function svc(): SubscriptionService
    {
        return $this->app->service(SubscriptionService::class);
    }

    public function status(Request $r): array
    {
        $cfg = $this->app->config;
        return [
            'premium' => $this->svc()->summary($r->user),
            'available' => $this->svc()->stripe()->isConfigured(),
            'mode' => $this->svc()->stripe()->mode(),
            'price_chf' => $this->app->settings()->str('premium_price_chf', '2.00'),
            'cover_quota' => $this->app->settings()->int('premium_cover_quota', 50),
            'publishable_key' => $cfg?->string('stripe.publishable_key') ?: null,
        ];
    }

    public function checkout(Request $r): array
    {
        $this->app->limiter()->enforce('checkout:' . $r->userId(), 10, 3600);
        try {
            return ['url' => $this->svc()->checkoutUrl($r->user)];
        } catch (\RuntimeException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->app->logger->error('Stripe Checkout fehlgeschlagen', ['message' => $e->getMessage()]);
            throw new HttpException(502, 'billing.stripe_error');
        }
    }

    public function portal(Request $r): array
    {
        try {
            return ['url' => $this->svc()->portalUrl($r->user)];
        } catch (\RuntimeException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            throw new HttpException(502, 'billing.stripe_error');
        }
    }

    public function refresh(Request $r): array
    {
        $this->app->limiter()->enforce('billing-refresh:' . $r->userId(), 10, 600);
        try {
            return ['premium' => $this->svc()->refreshFromStripe($r->user)];
        } catch (\RuntimeException $e) {
            throw new HttpException(502, 'billing.stripe_error');
        }
    }

    /** POST /webhooks/stripe – signaturgeprüft, idempotent */
    public function webhook(Request $r): Response
    {
        $secret = $this->app->config?->string('stripe.webhook_secret') ?? '';
        $sig = $r->header('stripe-signature') ?? '';
        if (!StripeClient::verifySignature($r->rawBody, $sig, $secret)) {
            $this->app->logger->warning('Stripe-Webhook mit ungültiger Signatur');
            return Response::json(['error' => 'invalid_signature'], 400);
        }
        $event = json_decode($r->rawBody, true);
        if (!is_array($event)) {
            return Response::json(['error' => 'invalid_payload'], 400);
        }
        try {
            $result = $this->svc()->handleEvent($event);
        } catch (\Throwable $e) {
            $this->app->logger->error('Stripe-Webhook-Verarbeitung fehlgeschlagen', ['type' => $event['type'] ?? null, 'message' => $e->getMessage()]);
            return Response::json(['error' => 'processing_failed'], 500);
        }
        return Response::json(['received' => true, 'result' => $result]);
    }
}
