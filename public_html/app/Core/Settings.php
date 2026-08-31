<?php

declare(strict_types=1);

namespace WebAtze\Core;

/**
 * Einstellungen, die im Adminbereich geändert werden können –
 * Kontaktangaben, Impressumstext, Social-Media-Links.
 *
 * Sie liegen in der Datenbank, nicht in config.php, weil sie im laufenden
 * Betrieb geändert werden und kein Geheimnis sind.
 */
final class Settings
{
    private static ?array $cache = null;

    public const DEFAULTS = [
        'company_name' => 'WebAtze',
        'owner_name' => '',
        'street' => '',
        'zip_city' => '',
        'country' => 'Schweiz',
        'email' => '',
        'phone' => '',
        'whatsapp' => '',
        'instagram' => '',
        'linkedin' => '',
        'uid' => '',
        // Für Rechnungen. Ohne IBAN steht auf der Rechnung keine
        // Zahlungsangabe – das fällt beim ersten Mal auf.
        'iban' => '',
        'imprint_extra' => '',
        'hero_available' => '1',
    ];

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $values = self::DEFAULTS;

        try {
            foreach (Db::all('SELECT key_name, value FROM settings') as $row) {
                $values[(string) $row['key_name']] = (string) ($row['value'] ?? '');
            }
        } catch (\Throwable) {
            // Vor der ersten Migration gibt es die Tabelle noch nicht.
        }

        return self::$cache = $values;
    }

    public static function get(string $key, string $default = ''): string
    {
        $all = self::all();
        return $all[$key] ?? $default;
    }

    public static function put(string $key, string $value): void
    {
        $exists = Db::value('SELECT 1 FROM settings WHERE key_name = :k', ['k' => $key]);

        if ($exists) {
            Db::update('settings', ['value' => $value, 'updated_at' => Db::now()], 'key_name = :k', ['k' => $key]);
        } else {
            Db::insert('settings', ['key_name' => $key, 'value' => $value, 'updated_at' => Db::now()]);
        }

        self::$cache = null;
    }

    public static function putMany(array $values): void
    {
        Db::transaction(static function () use ($values): void {
            foreach ($values as $key => $value) {
                self::put((string) $key, (string) $value);
            }
        });
    }

    /** Ist genug ausgefüllt, damit das Impressum rechtlich brauchbar ist? */
    public static function imprintComplete(): bool
    {
        $all = self::all();
        foreach (['owner_name', 'street', 'zip_city', 'email'] as $key) {
            if (trim($all[$key] ?? '') === '') {
                return false;
            }
        }
        return true;
    }
}
