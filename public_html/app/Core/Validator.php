<?php

declare(strict_types=1);

namespace WebAtze\Core;

/**
 * Prüft Formulareingaben und sammelt verständliche Fehlermeldungen.
 *
 * Nur was hier durchkommt, wird gespeichert. Fehlermeldungen sind bewusst
 * in ganzen Sätzen formuliert und sagen, was zu tun ist.
 */
final class Validator
{
    private array $data;
    private array $errors = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function make(array $data): self
    {
        return new self($data);
    }

    public function required(string $field, string $label): self
    {
        $value = trim((string) ($this->data[$field] ?? ''));
        if ($value === '') {
            $this->addError($field, __t('validation.required', ['field' => $label]));
        }
        return $this;
    }

    public function maxLength(string $field, int $max, string $label): self
    {
        $value = (string) ($this->data[$field] ?? '');
        if (mb_strlen($value) > $max) {
            $this->addError($field, __t('validation.max', ['field' => $label, 'max' => $max]));
        }
        return $this;
    }

    public function minLength(string $field, int $min, string $label): self
    {
        $value = trim((string) ($this->data[$field] ?? ''));
        if ($value !== '' && mb_strlen($value) < $min) {
            $this->addError($field, __t('validation.min', ['field' => $label, 'min' => $min]));
        }
        return $this;
    }

    public function email(string $field, string $label, bool $required = true): self
    {
        $value = trim((string) ($this->data[$field] ?? ''));
        if ($value === '') {
            if ($required) {
                $this->addError($field, __t('validation.required', ['field' => $label]));
            }
            return $this;
        }
        if (!filter_var($value, FILTER_VALIDATE_EMAIL) || mb_strlen($value) > 190) {
            $this->addError($field, __t('validation.email', ['field' => $label]));
        }
        return $this;
    }

    public function hexColor(string $field, string $label, bool $required = false): self
    {
        $value = trim((string) ($this->data[$field] ?? ''));
        if ($value === '') {
            if ($required) {
                $this->addError($field, __t('validation.required', ['field' => $label]));
            }
            return $this;
        }
        if (!self::isHexColor($value)) {
            $this->addError($field, __t('validation.color', ['field' => $label]));
        }
        return $this;
    }

    public function url(string $field, string $label, bool $required = false): self
    {
        $value = trim((string) ($this->data[$field] ?? ''));
        if ($value === '') {
            if ($required) {
                $this->addError($field, __t('validation.required', ['field' => $label]));
            }
            return $this;
        }
        if (!self::isHttpUrl($value)) {
            $this->addError($field, __t('validation.url', ['field' => $label]));
        }
        return $this;
    }

    public function domain(string $field, string $label, bool $required = false): self
    {
        $value = strtolower(trim((string) ($this->data[$field] ?? '')));
        if ($value === '') {
            if ($required) {
                $this->addError($field, __t('validation.required', ['field' => $label]));
            }
            return $this;
        }
        if (!self::isDomain($value)) {
            $this->addError($field, __t('validation.domain', ['field' => $label]));
        }
        return $this;
    }

    public function in(string $field, array $allowed, string $label): self
    {
        $value = (string) ($this->data[$field] ?? '');
        if ($value !== '' && !in_array($value, $allowed, true)) {
            $this->addError($field, __t('validation.choice', ['field' => $label]));
        }
        return $this;
    }

    /** Passwortstärke – bewusst pragmatisch, nicht schikanös. */
    public function password(string $field, string $label, int $min = 10): self
    {
        $value = (string) ($this->data[$field] ?? '');
        if ($value === '') {
            $this->addError($field, __t('validation.required', ['field' => $label]));
            return $this;
        }
        if (mb_strlen($value) < $min) {
            $this->addError($field, __t('validation.password_short', ['field' => $label, 'min' => $min]));
            return $this;
        }
        $classes = 0;
        foreach (['/[a-z]/u', '/[A-Z]/u', '/\d/', '/[^\w\s]/u'] as $pattern) {
            if (preg_match($pattern, $value)) {
                $classes++;
            }
        }
        if ($classes < 3) {
            $this->addError($field, __t('validation.password_weak', ['field' => $label]));
        }
        return $this;
    }

    public function matches(string $field, string $otherField, string $label): self
    {
        if ((string) ($this->data[$field] ?? '') !== (string) ($this->data[$otherField] ?? '')) {
            $this->addError($field, __t('validation.matches', ['field' => $label]));
        }
        return $this;
    }

    public function custom(string $field, bool $condition, string $message): self
    {
        if (!$condition) {
            $this->addError($field, $message);
        }
        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): string
    {
        return (string) (reset($this->errors) ?: '');
    }

    private function addError(string $field, string $message): void
    {
        // Nur der erste Fehler pro Feld – mehr überfordert nur.
        $this->errors[$field] ??= $message;
    }

    // ------------------------------------------------------------------
    // Wiederverwendbare Prüfungen
    // ------------------------------------------------------------------

    public static function isHexColor(string $value): bool
    {
        return (bool) preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value);
    }

    public static function isHttpUrl(string $value): bool
    {
        if (mb_strlen($value) > 500 || !filter_var($value, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }

    public static function isDomain(string $value): bool
    {
        $value = strtolower(trim($value));
        if ($value === '' || mb_strlen($value) > 253 || str_contains($value, ' ')) {
            return false;
        }
        // Ein versehentlich mitkopiertes https:// verzeihen wir beim Prüfen nicht,
        // sondern melden es – der Aufrufer normalisiert vorher.
        return (bool) preg_match(
            '/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/',
            $value
        );
    }

    /** 'https://www.beispiel.ch/pfad' -> 'beispiel.ch' */
    public static function normaliseDomain(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('#^https?://#', '', $value) ?? $value;
        $value = explode('/', $value)[0];
        $value = explode('?', $value)[0];
        $value = preg_replace('/^www\./', '', $value) ?? $value;
        return trim($value, '.');
    }
}
