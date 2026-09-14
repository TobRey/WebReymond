<?php
declare(strict_types=1);

namespace App\Service\Ai;

use App\Core\HttpClient;
use App\Core\Json;
use App\Core\Logger;

/**
 * Adapter fuer die Google-Gemini-kompatible generateContent-API.
 * Funktioniert mit dem kostenlosen Kontingent von Google AI Studio und
 * mit jeder API, die dasselbe Format spricht (frei konfigurierbare Basis-URL).
 */
final class GeminiProvider implements ProviderInterface
{
    public function __construct(
        private string $apiKey,
        private string $model,
        private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta',
        private HttpClient $http = new HttpClient()
    ) {
        $this->baseUrl = rtrim($this->baseUrl !== '' ? $this->baseUrl : 'https://generativelanguage.googleapis.com/v1beta', '/');
        $this->model = $this->model !== '' ? $this->model : 'gemini-2.0-flash';
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function complete(string $systemPrompt, array $messages, array $options = []): AiResult
    {
        if ($this->apiKey === '') {
            return AiResult::failure('Kein API-Schluessel hinterlegt.', $this->name());
        }

        $contents = [];
        foreach ($messages as $message) {
            $role = ($message['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
            $text = (string)($message['content'] ?? '');
            if ($text === '') {
                continue;
            }
            $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
        }
        if ($contents === []) {
            return AiResult::failure('Keine Nachricht uebergeben.', $this->name());
        }

        $payload = [
            'contents' => $contents,
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'generationConfig' => [
                'temperature'     => (float)($options['temperature'] ?? 0.85),
                'maxOutputTokens' => (int)($options['max_tokens'] ?? 420),
                'topP'            => 0.95,
            ],
            'safetySettings' => [
                ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
            ],
        ];

        $url = $this->baseUrl . '/models/' . rawurlencode($this->model) . ':generateContent';
        $response = $this->http->postJson($url, $payload, ['x-goog-api-key' => $this->apiKey]);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $detail = $this->errorMessage($response['body']);
            Logger::warning('Gemini-Anfrage fehlgeschlagen', ['status' => $response['status'], 'detail' => $detail]);
            return AiResult::failure($detail !== '' ? $detail : ($response['error'] ?: 'HTTP ' . $response['status']), $this->name(), $response['status']);
        }

        $data = Json::decode($response['body']) ?? [];
        $text = '';
        foreach ((array)Json::get($data, 'candidates.0.content.parts', []) as $part) {
            $text .= (string)($part['text'] ?? '');
        }
        if (trim($text) === '') {
            $blockReason = (string)Json::get($data, 'promptFeedback.blockReason', '');
            return AiResult::failure($blockReason !== '' ? 'Antwort blockiert (' . $blockReason . ').' : 'Leere Antwort erhalten.', $this->name(), $response['status']);
        }
        return AiResult::success(trim($text), $this->name(), $this->model, $response['duration']);
    }

    public function test(): AiResult
    {
        return $this->complete(
            'Du bist ein Testdienst. Antworte exakt mit: VERBINDUNG OK',
            [['role' => 'user', 'content' => 'Test']],
            ['max_tokens' => 20, 'temperature' => 0.0]
        );
    }

    private function errorMessage(string $body): string
    {
        $data = Json::decode($body);
        $message = (string)(Json::get($data ?? [], 'error.message', ''));
        return mb_substr($message, 0, 300);
    }
}
