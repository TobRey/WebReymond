<?php
declare(strict_types=1);

namespace App\Service\Ai;

use App\Core\HttpClient;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Repository\SettingsRepository;

/**
 * Faktory und Fassade fuer alle KI-Anbieter.
 *
 * - Anbieter frei waehlbar (offline, gemini, openai_compatible)
 * - API-Schluessel bleibt serverseitig und verschluesselt
 * - Ratenbegrenzung, Timeout, Wiederholungen und Fehlerbehandlung inklusive
 */
final class AiClient
{
    public function __construct(
        private SettingsRepository $settings,
        private RateLimiter $limiter
    ) {
    }

    public function providerName(): string
    {
        return (string)$this->settings->get('ai.provider', 'offline');
    }

    public function isOffline(): bool
    {
        $provider = $this->providerName();
        if ($provider === 'offline' || $provider === '') {
            return true;
        }
        if ($provider === 'openai_compatible') {
            // Lokale Server brauchen keinen Schluessel
            $base = (string)$this->settings->get('ai.base_url', '');
            $needsKey = !preg_match('~(localhost|127\.0\.0\.1|::1)~i', $base);
            return $needsKey && !$this->settings->hasApiKey();
        }
        return !$this->settings->hasApiKey();
    }

    public function model(): string
    {
        return (string)$this->settings->get('ai.model', '');
    }

    public function createProvider(?array $override = null): ?ProviderInterface
    {
        $config = $override ?? [
            'provider' => $this->providerName(),
            'base_url' => (string)$this->settings->get('ai.base_url', ''),
            'model'    => $this->model(),
            'api_key'  => $this->settings->apiKey(),
        ];

        $http = new HttpClient(
            (int)$this->settings->get('ai.timeout', 30),
            (int)$this->settings->get('ai.retries', 2)
        );

        return match ((string)$config['provider']) {
            'gemini' => new GeminiProvider(
                (string)($config['api_key'] ?? ''),
                (string)($config['model'] ?? ''),
                (string)($config['base_url'] ?? ''),
                $http
            ),
            'openai_compatible' => new OpenAiCompatibleProvider(
                (string)($config['api_key'] ?? ''),
                (string)($config['model'] ?? ''),
                (string)($config['base_url'] ?? ''),
                $http
            ),
            default => null,
        };
    }

    /**
     * @param array<int,array{role:string,content:string}> $messages
     */
    public function chat(string $systemPrompt, array $messages, array $options = [], string $rateKey = 'global'): AiResult
    {
        if ($this->isOffline()) {
            return AiResult::failure('offline', 'offline');
        }

        $perMinute = (int)$this->settings->get('ai.rate_per_minute', 12);
        $perHour = (int)$this->settings->get('ai.rate_per_hour', 180);
        $minute = $this->limiter->hit('ai:m:' . $rateKey, $perMinute, 60);
        if (!$minute['allowed']) {
            return AiResult::failure('rate_limit', $this->providerName(), 429);
        }
        $hour = $this->limiter->hit('ai:h:' . $rateKey, $perHour, 3600);
        if (!$hour['allowed']) {
            return AiResult::failure('rate_limit', $this->providerName(), 429);
        }

        $provider = $this->createProvider();
        if ($provider === null) {
            return AiResult::failure('offline', 'offline');
        }

        $options['temperature'] ??= (float)$this->settings->get('ai.temperature', 0.85);
        $options['max_tokens'] ??= (int)$this->settings->get('ai.max_tokens', 420);

        $result = $provider->complete($systemPrompt, $messages, $options);
        if (!$result->ok) {
            Logger::warning('KI-Antwort fehlgeschlagen', [
                'provider' => $provider->name(),
                'status'   => $result->status,
                'error'    => $result->error,
            ]);
        }
        return $result;
    }

    /** Einfache Textaufgabe (Adminbereich: Vorschlaege erzeugen). */
    public function suggest(string $systemPrompt, string $userPrompt, int $maxTokens = 700): AiResult
    {
        return $this->chat(
            $systemPrompt,
            [['role' => 'user', 'content' => $userPrompt]],
            ['max_tokens' => $maxTokens, 'temperature' => 0.9],
            'admin'
        );
    }

    /** Verbindungstest mit optionaler Ad-hoc-Konfiguration. */
    public function testConnection(?array $override = null): array
    {
        $provider = $this->createProvider($override);
        if ($provider === null) {
            return [
                'ok'      => true,
                'offline' => true,
                'message' => 'Offline-Modus aktiv: NPCs antworten ueber das regelbasierte Dialogsystem. Der Fall bleibt vollstaendig spielbar.',
            ];
        }
        $result = $provider->test();
        $payload = [
            'ok'       => $result->ok,
            'offline'  => false,
            'provider' => $provider->name(),
            'message'  => $result->ok
                ? 'Verbindung erfolgreich. Antwort: ' . mb_substr($result->text, 0, 120)
                : 'Verbindung fehlgeschlagen: ' . $this->humanError($result),
            'duration' => round($result->duration, 2),
        ];
        $this->settings->set('ai.last_test', [
            'at'      => gmdate('c'),
            'ok'      => $result->ok,
            'message' => mb_substr($payload['message'], 0, 300),
        ]);
        return $payload;
    }

    public function humanError(AiResult $result): string
    {
        return match (true) {
            $result->status === 401 || $result->status === 403 => 'Der API-Schluessel wurde abgelehnt (Status ' . $result->status . ').',
            $result->status === 404 => 'Modell oder Basis-URL nicht gefunden (Status 404). Modellnamen pruefen.',
            $result->status === 429 => 'Kontingent erschoepft oder zu viele Anfragen (Status 429).',
            $result->status >= 500  => 'Der KI-Anbieter meldet einen Serverfehler (Status ' . $result->status . ').',
            $result->error === 'rate_limit' => 'Eigenes Anfragelimit erreicht. Bitte kurz warten.',
            $result->error === 'offline' => 'Offline-Modus aktiv.',
            default => $result->error !== '' ? $result->error : 'Unbekannter Fehler.',
        };
    }
}
