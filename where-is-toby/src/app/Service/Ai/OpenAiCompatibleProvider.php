<?php
declare(strict_types=1);

namespace App\Service\Ai;

use App\Core\HttpClient;
use App\Core\Json;
use App\Core\Logger;

/**
 * Adapter fuer alle Anbieter mit OpenAI-kompatibler Chat-Completions-API.
 * Damit laufen unter anderem kostenlose Kontingente von OpenRouter, Groq,
 * Together, Mistral, DeepInfra sowie lokale Server (Ollama, LM Studio).
 * Basis-URL und Modellname sind im Adminbereich frei eintragbar.
 */
final class OpenAiCompatibleProvider implements ProviderInterface
{
    public function __construct(
        private string $apiKey,
        private string $model,
        private string $baseUrl = 'https://openrouter.ai/api/v1',
        private HttpClient $http = new HttpClient()
    ) {
        $this->baseUrl = rtrim($this->baseUrl !== '' ? $this->baseUrl : 'https://openrouter.ai/api/v1', '/');
    }

    public function name(): string
    {
        return 'openai_compatible';
    }

    public function complete(string $systemPrompt, array $messages, array $options = []): AiResult
    {
        if ($this->model === '') {
            return AiResult::failure('Kein Modellname konfiguriert.', $this->name());
        }

        $chat = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($messages as $message) {
            $role = in_array(($message['role'] ?? 'user'), ['user', 'assistant', 'system'], true) ? $message['role'] : 'user';
            $content = (string)($message['content'] ?? '');
            if ($content !== '') {
                $chat[] = ['role' => $role, 'content' => $content];
            }
        }

        $payload = [
            'model'       => $this->model,
            'messages'    => $chat,
            'temperature' => (float)($options['temperature'] ?? 0.85),
            'max_tokens'  => (int)($options['max_tokens'] ?? 420),
            'stream'      => false,
        ];

        $headers = ['Content-Type' => 'application/json'];
        if ($this->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        // Von OpenRouter empfohlene, unschaedliche Zusatzheader
        $headers['HTTP-Referer'] = 'https://localhost/where-is-toby';
        $headers['X-Title'] = 'Where Is Toby';

        $url = $this->baseUrl . '/chat/completions';
        $response = $this->http->postJson($url, $payload, $headers);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $detail = $this->errorMessage($response['body']);
            Logger::warning('KI-Anfrage fehlgeschlagen', ['status' => $response['status'], 'detail' => $detail]);
            return AiResult::failure($detail !== '' ? $detail : ($response['error'] ?: 'HTTP ' . $response['status']), $this->name(), $response['status']);
        }

        $data = Json::decode($response['body']) ?? [];
        $text = (string)Json::get($data, 'choices.0.message.content', '');
        if (trim($text) === '') {
            // Manche Anbieter nutzen "text" statt "message.content"
            $text = (string)Json::get($data, 'choices.0.text', '');
        }
        if (trim($text) === '') {
            return AiResult::failure('Leere Antwort erhalten.', $this->name(), $response['status']);
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
        if ($data === null) {
            return mb_substr(strip_tags($body), 0, 200);
        }
        $message = Json::get($data, 'error.message', '');
        if (!is_string($message) || $message === '') {
            $message = Json::get($data, 'message', '');
        }
        return mb_substr(is_string($message) ? $message : '', 0, 300);
    }
}
