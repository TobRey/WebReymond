<?php
declare(strict_types=1);

namespace App\Core;

final class Json
{
    public static function encode(mixed $value, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $json = json_encode($value, $flags);
        if ($json === false) {
            throw new \RuntimeException('JSON-Kodierung fehlgeschlagen: ' . json_last_error_msg());
        }
        return $json;
    }

    /** @return array<mixed>|null */
    public static function decode(string $json): ?array
    {
        if (trim($json) === '') {
            return null;
        }
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($data) ? $data : null;
    }

    /** Liest verschachtelte Werte per Punktnotation: get($a, 'ai.provider', 'offline') */
    public static function get(array $data, string $path, mixed $default = null): mixed
    {
        $cursor = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }
        return $cursor;
    }

    public static function set(array $data, string $path, mixed $value): array
    {
        $segments = explode('.', $path);
        $ref = &$data;
        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
        return $data;
    }
}
