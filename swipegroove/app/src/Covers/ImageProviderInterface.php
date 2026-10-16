<?php
declare(strict_types=1);

namespace SwipeGroove\Covers;

interface ImageProviderInterface
{
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * Erzeugt ein quadratisches Bild und liefert die Bytes (PNG/JPEG/WebP).
     * @return array{bytes:string, mime:string, model:string}
     * @throws ImageProviderException
     */
    public function generate(string $prompt, string $model, string $quality): array;
}
