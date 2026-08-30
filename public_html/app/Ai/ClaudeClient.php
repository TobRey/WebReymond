<?php

declare(strict_types=1);

namespace WebAtze\Ai;

use RuntimeException;
use WebAtze\Core\{Config, Db, Logger};

/**
 * Anbindung an die Claude-API von Anthropic.
 *
 * Bewusst mit cURL statt mit einer fertigen Bibliothek: auf gemietetem
 * Hosting lässt sich Composer oft nicht ausführen. cURL ist überall da.
 *
 * Drei Dinge, die hier wichtig sind:
 *
 *   1. Strukturierte Antworten. Die API bekommt ein JSON-Schema mit und
 *      liefert garantiert passende Daten – kein Herausfischen aus Fliesstext.
 *   2. Zwischenspeicherung. Der grosse, immer gleiche Teil der Anweisung
 *      wird von Anthropic zwischengespeichert. Ein zweiter Aufruf mit
 *      demselben Anfang kostet dafür nur einen Bruchteil.
 *   3. Kostenprotokoll. Jeder Aufruf wird mit Tokenzahlen und errechneten
 *      Kosten festgehalten und ist im Adminbereich einsehbar.
 */
final class ClaudeClient
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const VERSION = '2023-06-01';
    private const MAX_RETRIES = 3;

    /**
     * Preise in US-Dollar je einer Million Token (Stand Juni 2026).
     *
     * Zwischengespeicherte Eingaben kosten weniger: das Anlegen etwa das
     * 1.25-fache, das Wiederverwenden etwa ein Zehntel des Eingabepreises.
     */
    private const PRICING = [
        'claude-opus-5'    => ['in' => 5.00, 'out' => 25.00],
        'claude-fable-5'   => ['in' => 10.00, 'out' => 50.00],
        'claude-sonnet-5'  => ['in' => 2.00, 'out' => 10.00],
        'claude-haiku-4-5' => ['in' => 1.00, 'out' => 5.00],
    ];

    private const CACHE_WRITE_FACTOR = 1.25;
    private const CACHE_READ_FACTOR = 0.10;

    private ?int $projectId = null;
    private ?int $jobId = null;

    public function forProject(?int $projectId, ?int $jobId = null): self
    {
        $this->projectId = $projectId;
        $this->jobId = $jobId;
        return $this;
    }

    public static function isConfigured(): bool
    {
        return trim((string) Config::get('anthropic.api_key', '')) !== '';
    }

    /**
     * Eine Frage mit fest vorgegebener Antwortform stellen.
     *
     * @param string $purpose     wofür – erscheint im Kostenprotokoll
     * @param string $system      die dauerhafte Anweisung (wird zwischengespeichert)
     * @param string $userMessage die eigentliche Aufgabe
     * @param array  $schema      JSON-Schema der erwarteten Antwort
     * @param array  $options     model, effort, max_tokens
     *
     * @return array die Antwort, bereits geprüft und als Array
     */
    public function structured(
        string $purpose,
        string $system,
        string $userMessage,
        array $schema,
        array $options = []
    ): array {
        $model = $options['model'] ?? (string) Config::get('anthropic.model_content', 'claude-sonnet-5');

        $payload = [
            'model' => $model,
            'max_tokens' => (int) ($options['max_tokens'] ?? 16000),

            // Der Systemteil ist bei jedem Aufruf derselbe. Die Markierung
            // sagt Anthropic: das darfst du zwischenspeichern.
            'system' => [[
                'type' => 'text',
                'text' => $system,
                'cache_control' => ['type' => 'ephemeral'],
            ]],

            'messages' => [[
                'role' => 'user',
                'content' => $userMessage,
            ]],

            'output_config' => [
                'format' => [
                    'type' => 'json_schema',
                    'schema' => self::sealSchema($schema),
                ],
                'effort' => $options['effort'] ?? 'high',
            ],
        ];

        $response = $this->send($payload, $purpose);

        $text = self::firstText($response);
        if ($text === '') {
            throw new RuntimeException('Die KI hat keine verwertbare Antwort geliefert.');
        }

        $data = json_decode($text, true);
        if (!is_array($data)) {
            Logger::warning('Antwort war kein gültiges JSON', ['purpose' => $purpose]);
            throw new RuntimeException('Die Antwort der KI war unvollständig. Bitte noch einmal versuchen.');
        }

        return $data;
    }

    /** Freie Textantwort, z.B. für einen Referenztext. */
    public function text(
        string $purpose,
        string $system,
        string $userMessage,
        array $options = []
    ): string {
        $payload = [
            'model' => $options['model'] ?? (string) Config::get('anthropic.model_content', 'claude-sonnet-5'),
            'max_tokens' => (int) ($options['max_tokens'] ?? 4000),
            'system' => [[
                'type' => 'text',
                'text' => $system,
                'cache_control' => ['type' => 'ephemeral'],
            ]],
            'messages' => [['role' => 'user', 'content' => $userMessage]],
            'output_config' => ['effort' => $options['effort'] ?? 'medium'],
        ];

        return self::firstText($this->send($payload, $purpose));
    }

    /**
     * Anfrage abschicken, mit Wiederholung bei vorübergehenden Störungen.
     */
    private function send(array $payload, string $purpose): array
    {
        $apiKey = trim((string) Config::get('anthropic.api_key', ''));
        if ($apiKey === '') {
            throw new RuntimeException(
                'Es ist kein Anthropic-Schlüssel hinterlegt. '
                . 'Er gehört in app/config.php unter anthropic.api_key.'
            );
        }

        $timeout = (int) Config::get('anthropic.timeout', 120);
        $lastError = '';

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $started = microtime(true);

            $result = $this->request($payload, $apiKey, $timeout);
            $duration = (int) round((microtime(true) - $started) * 1000);

            // Erfolg
            if ($result['status'] >= 200 && $result['status'] < 300) {
                $data = $result['data'];

                $this->record($purpose, $payload['model'], $data['usage'] ?? [], $duration, true);

                // Die Sicherheitsprüfung kann eine Anfrage ablehnen. Das ist
                // kein Fehler im technischen Sinn – die Antwort kommt mit
                // Status 200, enthält aber keinen Inhalt.
                if (($data['stop_reason'] ?? '') === 'refusal') {
                    $category = $data['stop_details']['category'] ?? 'unbekannt';
                    Logger::warning('Anfrage wurde abgelehnt', ['grund' => $category, 'zweck' => $purpose]);
                    throw new RuntimeException(
                        'Die KI hat diese Anfrage abgelehnt. Bitte die Beschreibung im Formular anpassen.'
                    );
                }

                if (($data['stop_reason'] ?? '') === 'max_tokens') {
                    Logger::warning('Antwort war zu lang und wurde abgeschnitten', ['zweck' => $purpose]);
                }

                return $data;
            }

            // Fehler
            $status = $result['status'];
            $apiMessage = $result['data']['error']['message'] ?? $result['error'];
            $lastError = sprintf('HTTP %d: %s', $status, $apiMessage);

            $this->record($purpose, $payload['model'], [], $duration, false);

            // 4xx ausser 408 und 429 sind unsere Schuld – ein zweiter
            // Versuch mit denselben Daten scheitert genauso.
            $retryable = $status === 0 || $status === 408 || $status === 429 || $status >= 500;

            if (!$retryable || $attempt === self::MAX_RETRIES) {
                Logger::error('Claude-Anfrage endgültig fehlgeschlagen', [
                    'status' => $status,
                    'zweck' => $purpose,
                ]);
                throw new RuntimeException(self::friendlyError($status, $apiMessage));
            }

            // Wartezeit verdoppelt sich, plus ein wenig Zufall, damit nicht
            // alle gleichzeitig wieder anklopfen.
            $wait = min(30, (2 ** $attempt)) + (random_int(0, 400) / 1000);
            Logger::warning('Claude-Anfrage wird wiederholt', [
                'status' => $status, 'versuch' => $attempt, 'wartet' => $wait,
            ]);
            usleep((int) ($wait * 1_000_000));
        }

        throw new RuntimeException($lastError !== '' ? $lastError : 'Die KI war nicht erreichbar.');
    }

    /** @return array{status:int, data:array, error:string} */
    private function request(array $payload, string $apiKey, int $timeout): array
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => self::ENDPOINT,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: ' . self::VERSION,
            ],
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['status' => 0, 'data' => [], 'error' => $error ?: 'Verbindung fehlgeschlagen.'];
        }

        $decoded = json_decode((string) $raw, true);

        return [
            'status' => $status,
            'data' => is_array($decoded) ? $decoded : [],
            'error' => is_array($decoded) ? '' : 'Antwort war kein JSON.',
        ];
    }

    /** Ersten Textblock aus der Antwort holen. */
    private static function firstText(array $response): string
    {
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text' && ($block['text'] ?? '') !== '') {
                return (string) $block['text'];
            }
        }
        return '';
    }

    /**
     * Sorgt dafür, dass jedes Objekt im Schema geschlossen ist.
     *
     * Ohne additionalProperties:false dürfte die Antwort zusätzliche Felder
     * enthalten, mit denen der Aufbau später nichts anfangen kann.
     */
    private static function sealSchema(array $schema): array
    {
        if (($schema['type'] ?? '') === 'object') {
            $schema['additionalProperties'] = false;

            if (!isset($schema['required']) && isset($schema['properties'])) {
                $schema['required'] = array_keys($schema['properties']);
            }

            foreach ($schema['properties'] ?? [] as $key => $property) {
                if (is_array($property)) {
                    $schema['properties'][$key] = self::sealSchema($property);
                }
            }
        }

        if (($schema['type'] ?? '') === 'array' && isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = self::sealSchema($schema['items']);
        }

        return $schema;
    }

    /**
     * Aufruf im Protokoll festhalten.
     *
     * Die Kosten werden in Millionstel-Dollar gespeichert, damit keine
     * Rundungsfehler entstehen. Ein Fehlschlag wird ebenfalls vermerkt –
     * sonst fehlt später die Erklärung für eine Lücke.
     */
    private function record(string $purpose, string $model, array $usage, int $durationMs, bool $ok): void
    {
        $input = (int) ($usage['input_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? 0);
        $cacheWrite = (int) ($usage['cache_creation_input_tokens'] ?? 0);
        $cacheRead = (int) ($usage['cache_read_input_tokens'] ?? 0);

        try {
            Db::insert('ai_calls', [
                'project_id' => $this->projectId,
                'job_id' => $this->jobId,
                'purpose' => mb_substr($purpose, 0, 40),
                'model' => mb_substr($model, 0, 60),
                'input_tokens' => $input,
                'cache_write_tokens' => $cacheWrite,
                'cache_read_tokens' => $cacheRead,
                'output_tokens' => $output,
                'cost_micro' => self::costMicroDollars($model, $input, $cacheWrite, $cacheRead, $output),
                'duration_ms' => $durationMs,
                'ok' => $ok ? 1 : 0,
                'created_at' => Db::now(),
            ]);
        } catch (\Throwable $e) {
            Logger::warning('Kostenprotokoll konnte nicht geschrieben werden: ' . $e->getMessage());
        }
    }

    /** Kosten eines Aufrufs in Millionstel-Dollar. */
    public static function costMicroDollars(
        string $model,
        int $input,
        int $cacheWrite,
        int $cacheRead,
        int $output
    ): int {
        $price = self::PRICING[$model] ?? self::PRICING['claude-sonnet-5'];

        $dollars =
            ($input / 1_000_000) * $price['in']
            + ($cacheWrite / 1_000_000) * $price['in'] * self::CACHE_WRITE_FACTOR
            + ($cacheRead / 1_000_000) * $price['in'] * self::CACHE_READ_FACTOR
            + ($output / 1_000_000) * $price['out'];

        return (int) round($dollars * 1_000_000);
    }

    /** Aus einer technischen Meldung eine verständliche machen. */
    private static function friendlyError(int $status, string $message): string
    {
        return match (true) {
            $status === 401 => 'Der Anthropic-Schlüssel wird nicht akzeptiert. Bitte in app/config.php prüfen.',
            $status === 400 => 'Die Anfrage wurde abgelehnt: ' . $message,
            $status === 429 => 'Das Anfragelimit ist erreicht. In einigen Minuten geht es weiter.',
            $status === 402 || str_contains(mb_strtolower($message), 'credit')
                => 'Das Anthropic-Guthaben ist aufgebraucht. Bitte im Anthropic-Konto aufladen.',
            $status >= 500 => 'Die KI ist gerade nicht erreichbar. Der Auftrag wird automatisch wiederholt.',
            $status === 0 => 'Keine Verbindung zur KI. Läuft auf diesem Server ausgehendes HTTPS?',
            default => 'Unerwartete Antwort der KI (' . $status . ').',
        };
    }
}
