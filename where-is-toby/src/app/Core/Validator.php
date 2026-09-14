<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Serverseitige Eingabevalidierung und Bereinigung.
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];

    public function __construct(private array $data)
    {
    }

    public static function make(array $data): self
    {
        return new self($data);
    }

    public function required(string $field, string $label): self
    {
        $value = $this->data[$field] ?? '';
        if (!is_scalar($value) || trim((string)$value) === '') {
            $this->errors[$field] ??= $label . ' ist erforderlich.';
        }
        return $this;
    }

    public function length(string $field, string $label, int $min, int $max): self
    {
        $value = (string)($this->data[$field] ?? '');
        $len = mb_strlen($value);
        if ($len < $min || $len > $max) {
            $this->errors[$field] ??= sprintf('%s muss zwischen %d und %d Zeichen lang sein.', $label, $min, $max);
        }
        return $this;
    }

    public function username(string $field = 'username'): self
    {
        $value = (string)($this->data[$field] ?? '');
        if (!preg_match('~^[a-zA-Z0-9_.\-]{3,32}$~', $value)) {
            $this->errors[$field] ??= 'Benutzername: 3-32 Zeichen, nur Buchstaben, Zahlen, Punkt, Bindestrich, Unterstrich.';
        }
        return $this;
    }

    public function email(string $field = 'email', bool $optional = true): self
    {
        $value = trim((string)($this->data[$field] ?? ''));
        if ($value === '') {
            if (!$optional) {
                $this->errors[$field] ??= 'E-Mail-Adresse ist erforderlich.';
            }
            return $this;
        }
        if (!filter_var($value, FILTER_VALIDATE_EMAIL) || mb_strlen($value) > 190) {
            $this->errors[$field] ??= 'Bitte eine gueltige E-Mail-Adresse angeben.';
        }
        return $this;
    }

    public function password(string $field = 'password'): self
    {
        $value = (string)($this->data[$field] ?? '');
        if (mb_strlen($value) < 10) {
            $this->errors[$field] ??= 'Das Passwort muss mindestens 10 Zeichen lang sein.';
            return $this;
        }
        if (mb_strlen($value) > 200) {
            $this->errors[$field] ??= 'Das Passwort darf hoechstens 200 Zeichen lang sein.';
            return $this;
        }
        $classes = 0;
        $classes += preg_match('~[a-z]~u', $value);
        $classes += preg_match('~[A-Z]~u', $value);
        $classes += preg_match('~[0-9]~', $value);
        $classes += preg_match('~[^a-zA-Z0-9]~u', $value);
        if ($classes < 3) {
            $this->errors[$field] ??= 'Das Passwort braucht mindestens drei von vier Zeichenarten: Klein-, Grossbuchstaben, Zahlen, Sonderzeichen.';
        }
        return $this;
    }

    public function matches(string $field, string $otherField, string $label): self
    {
        if ((string)($this->data[$field] ?? '') !== (string)($this->data[$otherField] ?? '')) {
            $this->errors[$field] ??= $label . ' stimmt nicht ueberein.';
        }
        return $this;
    }

    public function in(string $field, array $allowed, string $label): self
    {
        if (!in_array((string)($this->data[$field] ?? ''), $allowed, true)) {
            $this->errors[$field] ??= $label . ' hat einen unzulaessigen Wert.';
        }
        return $this;
    }

    public function accepted(string $field, string $label): self
    {
        $value = $this->data[$field] ?? false;
        $ok = $value === true || in_array((string)$value, ['1', 'on', 'true', 'ja', 'yes'], true);
        if (!$ok) {
            $this->errors[$field] ??= $label . ' muss bestaetigt werden.';
        }
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
        return (string)(reset($this->errors) ?: '');
    }

    /* ----------------------------------------------------------
     |  Statische Helfer
     ---------------------------------------------------------- */

    /** Gibt eine sichere ID (Slug) zurueck oder wirft eine Ausnahme. */
    public static function id(string $value, string $label = 'ID'): string
    {
        $value = trim($value);
        if (!preg_match('~^[a-zA-Z0-9_\-]{1,64}$~', $value)) {
            throw HttpException::badRequest($label . ' ist ungueltig.');
        }
        return $value;
    }

    /** Verhindert Path-Traversal: erlaubt nur einfache Dateinamen. */
    public static function filename(string $value): string
    {
        $value = basename(str_replace(['\\', "\0"], '/', $value));
        if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9._\-]{0,120}$~', $value) || str_contains($value, '..')) {
            throw HttpException::badRequest('Ungueltiger Dateiname.');
        }
        return $value;
    }

    /** Prueft, dass ein Pfad innerhalb eines erlaubten Verzeichnisses liegt. */
    public static function withinDirectory(string $path, string $directory): string
    {
        $realDir = realpath($directory);
        $realPath = realpath($path);
        if ($realDir === false) {
            throw HttpException::notFound('Verzeichnis nicht gefunden.');
        }
        if ($realPath === false || !str_starts_with($realPath, $realDir . DIRECTORY_SEPARATOR)) {
            Logger::security('Path-Traversal-Versuch blockiert', ['path' => $path]);
            throw HttpException::forbidden('Ungueltiger Pfad.');
        }
        return $realPath;
    }

    /** Entfernt Steuerzeichen und begrenzt die Laenge von Freitext. */
    public static function text(string $value, int $max = 2000): string
    {
        $value = preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~u', '', $value) ?? '';
        return mb_substr(trim($value), 0, $max);
    }
}
