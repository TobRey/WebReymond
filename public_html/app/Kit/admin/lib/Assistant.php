<?php

declare(strict_types=1);

namespace WebAtzeKit;

/**
 * Der WebAtze-Assistent.
 *
 * Der Kunde beschreibt in einem Satz, was anders werden soll. Diese
 * Anfrage geht an WebAtze – dort läuft die Änderung, dort bleibt der
 * Schlüssel zum Sprachmodell. Auf dem Server des Kunden liegt nur ein
 * Kennwort, das ausschliesslich für diese eine Website gilt und sich
 * jederzeit zurückziehen lässt.
 *
 * Zurück kommt der geänderte Abschnitt. Alles andere an der Website
 * bleibt unberührt – nicht aus gutem Willen, sondern weil die Antwort
 * gar nichts anderes enthalten kann.
 */
final class Assistant
{
    private const TIMEOUT = 90;

    private string $endpoint;
    private string $token;

    public function __construct(array $config)
    {
        $this->endpoint = rtrim((string) ($config['assistant_url'] ?? ''), '/');
        $this->token = (string) ($config['assistant_token'] ?? '');
    }

    public function isConfigured(): bool
    {
        return $this->endpoint !== '' && $this->token !== '';
    }

    /**
     * Einen Abschnitt ändern lassen.
     *
     * @return array{ok:bool, error:string, summary:string, content:array, overrides:array, template:string}
     */
    public function edit(int $sectionId, string $instruction): array
    {
        if (!$this->isConfigured()) {
            return self::fail('Der Assistent ist für diese Website nicht eingerichtet.');
        }

        $instruction = trim($instruction);
        if ($instruction === '') {
            return self::fail('Bitte beschreiben, was geändert werden soll.');
        }

        $response = $this->post('/assistant/v1/edit', [
            'section_id' => $sectionId,
            'instruction' => mb_substr($instruction, 0, 600),
        ]);

        if (!$response['ok']) {
            return self::fail($response['error']);
        }

        $data = $response['data'];

        if (empty($data['ok'])) {
            return self::fail((string) ($data['error'] ?? 'Die Änderung hat nicht geklappt.'));
        }

        return [
            'ok' => true,
            'error' => '',
            'summary' => (string) ($data['summary'] ?? 'Abschnitt angepasst.'),
            'content' => (array) ($data['content'] ?? []),
            'overrides' => (array) ($data['overrides'] ?? []),
            'template' => (string) ($data['template'] ?? ''),
        ];
    }

    /** Kurz nachfragen, ob der Assistent gerade erreichbar ist. */
    public function ping(): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $response = $this->post('/assistant/v1/ping', []);

        return $response['ok'] && !empty($response['data']['available']);
    }

    // ------------------------------------------------------------------

    /** @return array{ok:bool, error:string, data:array} */
    private function post(string $path, array $payload): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'Auf diesem Server fehlt cURL.', 'data' => []];
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $ch = curl_init($this->endpoint . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-WebAtze-Token: ' . $this->token,
                'Content-Length: ' . strlen((string) $body),
            ],
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return [
                'ok' => false,
                'error' => 'Der Assistent ist gerade nicht erreichbar (' . $error . ').',
                'data' => [],
            ];
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'Unerwartete Antwort vom Assistenten.', 'data' => []];
        }

        if ($status === 403) {
            return [
                'ok' => false,
                'error' => 'Der Zugang des Assistenten gilt nicht mehr. Bitte WebAtze melden.',
                'data' => [],
            ];
        }

        if ($status === 429) {
            return [
                'ok' => false,
                'error' => (string) ($data['error'] ?? 'Zu viele Änderungen in kurzer Zeit.'),
                'data' => [],
            ];
        }

        return ['ok' => true, 'error' => '', 'data' => $data];
    }

    private static function fail(string $message): array
    {
        return [
            'ok' => false,
            'error' => $message,
            'summary' => '',
            'content' => [],
            'overrides' => [],
            'template' => '',
        ];
    }
}
