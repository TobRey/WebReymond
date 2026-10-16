<?php
declare(strict_types=1);

namespace SwipeGroove\Covers;

use SwipeGroove\Core\App;

/**
 * OpenAI Images API (POST /v1/images/generations). GPT-Image-Modelle liefern immer Base64.
 * Der API-Schlüssel bleibt serverseitig; an den Anbieter geht ausschliesslich die vom Nutzer
 * eingegebene Bildbeschreibung (keine Spotify-Cover oder -Metadaten).
 */
final class OpenAiImageProvider implements ImageProviderInterface
{
    private const URL = 'https://api.openai.com/v1/images/generations';
    public const MODELS = ['gpt-image-1-mini', 'gpt-image-1.5', 'gpt-image-1', 'gpt-image-2'];
    public const QUALITIES = ['low', 'medium', 'high'];

    public function __construct(private App $app)
    {
    }

    public function name(): string
    {
        return 'openai';
    }

    private function key(): string
    {
        return $this->app->config?->string('images.openai_api_key') ?? '';
    }

    public function isConfigured(): bool
    {
        return $this->key() !== '';
    }

    public function generate(string $prompt, string $model, string $quality): array
    {
        if (!$this->isConfigured()) {
            throw new ImageProviderException('not_configured', 'OpenAI API-Schlüssel fehlt');
        }
        $body = json_encode([
            'model' => $model,
            'prompt' => $prompt,
            'n' => 1,
            'size' => '1024x1024',
            'quality' => in_array($quality, self::QUALITIES, true) ? $quality : 'medium',
            'output_format' => 'jpeg',
            'output_compression' => 92,
            'moderation' => 'auto',
        ], JSON_UNESCAPED_UNICODE) ?: '{}';
        $start = microtime(true);
        try {
            $resp = $this->app->http->request('POST', self::URL, [
                'Authorization' => 'Bearer ' . $this->key(),
                'Content-Type' => 'application/json',
            ], $body, ['timeout' => 120]);
        } catch (\Throwable $e) {
            $this->log(0, $start, $e->getMessage());
            throw new ImageProviderException('unavailable', 'Bildanbieter nicht erreichbar');
        }
        $data = $resp->json();
        $err = $resp->ok() ? null : (string) ($data['error']['message'] ?? ('HTTP ' . $resp->status));
        $this->log($resp->status, $start, $err);
        if (!$resp->ok()) {
            $code = (string) ($data['error']['code'] ?? '');
            $type = (string) ($data['error']['type'] ?? '');
            $reason = match (true) {
                $resp->status === 401 || $resp->status === 403 => 'auth',
                $resp->status === 429 => 'rate_limited',
                $code === 'moderation_blocked' || $code === 'content_policy_violation' || str_contains($type, 'policy') || str_contains(strtolower($err ?? ''), 'safety') => 'content_policy',
                $resp->status === 400 => 'invalid',
                $resp->status >= 500 => 'unavailable',
                default => 'unknown',
            };
            throw new ImageProviderException($reason, $err ?? '', $resp->status);
        }
        $b64 = $data['data'][0]['b64_json'] ?? null;
        if (!is_string($b64)) {
            throw new ImageProviderException('invalid', 'Antwort ohne Bilddaten', $resp->status, true);
        }
        $bytes = base64_decode($b64, true);
        if ($bytes === false || $bytes === '') {
            throw new ImageProviderException('invalid', 'Bilddaten nicht dekodierbar', $resp->status, true);
        }
        $format = (string) ($data['output_format'] ?? 'jpeg');
        $mime = match ($format) { 'png' => 'image/png', 'webp' => 'image/webp', default => 'image/jpeg' };
        return ['bytes' => $bytes, 'mime' => $mime, 'model' => $model];
    }

    private function log(int $status, float $start, ?string $error): void
    {
        try {
            $this->app->db()->insert('integration_log', [
                'service' => 'openai', 'method' => 'POST', 'endpoint' => '/v1/images/generations', 'status' => $status,
                'duration_ms' => (int) ((microtime(true) - $start) * 1000), 'user_id' => null,
                'error' => $error ? mb_substr($error, 0, 255) : null, 'created_at' => $this->app->db()->now(),
            ]);
        } catch (\Throwable) {
        }
    }
}
