<?php
declare(strict_types=1);

namespace App\Service\Ai;

interface ProviderInterface
{
    /**
     * @param array<int,array{role:string,content:string}> $messages
     * @param array<string,mixed> $options
     */
    public function complete(string $systemPrompt, array $messages, array $options = []): AiResult;

    public function name(): string;

    /** Kurzer Verbindungstest. */
    public function test(): AiResult;
}
