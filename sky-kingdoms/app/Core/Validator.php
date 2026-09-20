<?php

declare(strict_types=1);

namespace SkyKingdoms\Core;

/** Eingabeprüfung mit deutschen Fehlermeldungen. */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];

    /** @var array<string,mixed> */
    private array $values = [];

    public function __construct(private array $input)
    {
    }

    public static function make(array $input): self
    {
        return new self($input);
    }

    public function text(string $field, string $label, int $min = 0, int $max = 255, bool $required = true): self
    {
        $value = Security::clean($this->input[$field] ?? '', $max + 1);

        if ($value === '' && $required) {
            $this->errors[$field] = $label . ' darf nicht leer sein.';
        } elseif ($value !== '' && mb_strlen($value) < $min) {
            $this->errors[$field] = $label . ' muss mindestens ' . $min . ' Zeichen lang sein.';
        } elseif (mb_strlen($value) > $max) {
            $this->errors[$field] = $label . ' darf höchstens ' . $max . ' Zeichen lang sein.';
        }

        $this->values[$field] = $value;

        return $this;
    }

    public function username(string $field = 'username'): self
    {
        $min   = (int) App::config('username_min_length', 3);
        $max   = (int) App::config('username_max_length', 20);
        $value = Security::clean($this->input[$field] ?? '', $max + 1);

        if ($value === '') {
            $this->errors[$field] = 'Bitte wähle einen Spielernamen.';
        } elseif (mb_strlen($value) < $min || mb_strlen($value) > $max) {
            $this->errors[$field] = 'Der Spielername muss zwischen ' . $min . ' und ' . $max . ' Zeichen lang sein.';
        } elseif (!preg_match('/^[\p{L}\p{N}](?:[\p{L}\p{N} _.\-]*[\p{L}\p{N}])?$/u', $value)) {
            $this->errors[$field] = 'Erlaubt sind Buchstaben, Ziffern, Leerzeichen, Punkt, Bindestrich und Unterstrich.';
        } elseif (preg_match('/\s{2,}/u', $value)) {
            $this->errors[$field] = 'Bitte keine mehrfachen Leerzeichen im Namen.';
        }

        $this->values[$field] = $value;

        return $this;
    }

    public function email(string $field = 'email'): self
    {
        $value = mb_strtolower(Security::clean($this->input[$field] ?? '', 191));

        if ($value === '') {
            $this->errors[$field] = 'Bitte gib eine E-Mail-Adresse an.';
        } elseif (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = 'Diese E-Mail-Adresse sieht nicht gültig aus.';
        }

        $this->values[$field] = $value;

        return $this;
    }

    public function password(string $field = 'password', string $usernameField = 'username', string $emailField = 'email'): self
    {
        $value = (string) ($this->input[$field] ?? '');
        $problems = Password::problems(
            $value,
            (string) ($this->values[$usernameField] ?? $this->input[$usernameField] ?? ''),
            (string) ($this->values[$emailField] ?? $this->input[$emailField] ?? '')
        );

        if ($problems !== []) {
            $this->errors[$field] = $problems[0];
        }

        $this->values[$field] = $value;

        return $this;
    }

    public function matches(string $field, string $otherField, string $message): self
    {
        $a = (string) ($this->input[$field] ?? '');
        $b = (string) ($this->input[$otherField] ?? '');

        if ($a !== $b) {
            $this->errors[$field] = $message;
        }
        $this->values[$field] = $a;

        return $this;
    }

    public function accepted(string $field, string $message): self
    {
        $value = $this->input[$field] ?? null;
        if (!in_array($value, ['1', 1, true, 'on', 'ja'], true)) {
            $this->errors[$field] = $message;
        }

        return $this;
    }

    public function int(string $field, string $label, int $min, int $max, bool $required = true): self
    {
        $raw = $this->input[$field] ?? null;
        if ($raw === null || $raw === '') {
            if ($required) {
                $this->errors[$field] = $label . ' fehlt.';
            }
            $this->values[$field] = $min;

            return $this;
        }

        if (!is_numeric($raw)) {
            $this->errors[$field] = $label . ' muss eine Zahl sein.';
            $this->values[$field] = $min;

            return $this;
        }

        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            $this->errors[$field] = $label . ' muss zwischen ' . $min . ' und ' . $max . ' liegen.';
        }
        $this->values[$field] = max($min, min($max, $value));

        return $this;
    }

    public function in(string $field, string $label, array $allowed): self
    {
        $value = Security::clean($this->input[$field] ?? '', 64);
        if (!in_array($value, $allowed, true)) {
            $this->errors[$field] = $label . ' ist ungültig.';
        }
        $this->values[$field] = $value;

        return $this;
    }

    public function addError(string $field, string $message): self
    {
        $this->errors[$field] = $message;

        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): string
    {
        return $this->errors === [] ? '' : (string) reset($this->errors);
    }

    public function value(string $field, mixed $default = null): mixed
    {
        return $this->values[$field] ?? $default;
    }

    /** @return array<string,mixed> */
    public function values(): array
    {
        return $this->values;
    }
}
