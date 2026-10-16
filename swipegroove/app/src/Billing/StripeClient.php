<?php
declare(strict_types=1);

namespace SwipeGroove\Billing;

use SwipeGroove\Core\App;

/**
 * Minimaler Stripe-Client (Checkout, Customer Portal, Subscriptions) ohne externe Abhängigkeit.
 * Webhook-Signaturen werden nach Stripe-Schema v1 (HMAC-SHA256 über "t.payload") geprüft.
 */
final class StripeClient
{
    private const API = 'https://api.stripe.com/v1';

    public function __construct(private App $app)
    {
    }

    public function secretKey(): string
    {
        return $this->app->config?->string('stripe.secret_key') ?? '';
    }

    public function isConfigured(): bool
    {
        return $this->secretKey() !== '' && ($this->app->config?->string('stripe.price_id') ?? '') !== '';
    }

    public function mode(): string
    {
        return $this->app->config?->string('stripe.mode', 'test') ?? 'test';
    }

    /** @return array<string,mixed> */
    public function request(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->secretKey(),
            'Stripe-Version' => '2025-08-27.basil',
        ];
        $body = null;
        $url = self::API . $path;
        if ($method === 'GET') {
            if ($params) {
                $url .= '?' . http_build_query($params);
            }
        } else {
            $body = http_build_query($params);
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            if ($idempotencyKey) {
                $headers['Idempotency-Key'] = $idempotencyKey;
            }
        }
        $start = microtime(true);
        try {
            $resp = $this->app->http->request($method, $url, $headers, $body, ['timeout' => 30]);
        } catch (\Throwable $e) {
            $this->log($method, $path, 0, $start, $e->getMessage());
            throw new \RuntimeException('Stripe nicht erreichbar');
        }
        $data = $resp->json();
        $err = $resp->ok() ? null : (string) ($data['error']['message'] ?? ('HTTP ' . $resp->status));
        $this->log($method, $path, $resp->status, $start, $err);
        if (!$resp->ok()) {
            throw new \RuntimeException('Stripe: ' . $err, $resp->status);
        }
        return $data;
    }

    private function log(string $method, string $path, int $status, float $start, ?string $error): void
    {
        try {
            $this->app->db()->insert('integration_log', [
                'service' => 'stripe', 'method' => $method, 'endpoint' => mb_substr(preg_replace('#/(cs|sub|cus|bps|in)_[A-Za-z0-9]+#', '/{id}', $path) ?? $path, 0, 191), 'status' => $status,
                'duration_ms' => (int) ((microtime(true) - $start) * 1000), 'user_id' => null,
                'error' => $error ? mb_substr($error, 0, 255) : null, 'created_at' => $this->app->db()->now(),
            ]);
        } catch (\Throwable) {
        }
    }

    /**
     * Prüft die Stripe-Signatur. Gibt true zurück, wenn gültig.
     */
    public static function verifySignature(string $payload, string $header, string $secret, int $tolerance = 300, ?int $now = null): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't') {
                $timestamp = (int) $kv[1];
            } elseif ($kv[0] === 'v1') {
                $signatures[] = $kv[1];
            }
        }
        if ($timestamp === null || $signatures === []) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $valid = false;
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            return false;
        }
        $now ??= time();
        return abs($now - $timestamp) <= $tolerance;
    }
}
