<?php
declare(strict_types=1);

namespace App\Repository;

use App\Core\Crypto;
use App\Core\Json;

/**
 * Systemeinstellungen (Seite, KI, Spielregeln, Sicherheit).
 */
final class SettingsRepository
{
    private const FILE = 'settings/settings.json';

    public function __construct(private JsonStore $store)
    {
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            '_schema'  => WIT_SCHEMA_VERSION,
            'site' => [
                'name'            => 'WHERE IS TOBY?',
                'tagline'         => 'FBI Field Investigation Terminal',
                'agency'          => 'FEDERAL BUREAU OF INVESTIGATION',
                'language'        => 'de',
                'allow_guests'    => true,
                'allow_register'  => true,
                'imprint'         => "Betreiber: Bitte im Adminbereich eintragen.\nAnschrift: ---\nKontakt: ---",
                'privacy'         => "Diese Anwendung speichert nur Daten, die fuer den Spielfortschritt noetig sind: Benutzername, optionale E-Mail, Passwort-Hash, Spielstaende und Einstellungen. Es werden keine Tracker und keine Werbe-Cookies eingesetzt. Konten koennen jederzeit selbst geloescht werden.",
            ],
            'ai' => [
                'provider'        => 'offline',   // offline | gemini | openai_compatible
                'base_url'        => '',
                'model'           => '',
                'api_key_enc'     => '',
                'timeout'         => 30,
                'retries'         => 2,
                'max_tokens'      => 420,
                'temperature'     => 0.85,
                'rate_per_minute' => 12,
                'rate_per_hour'   => 180,
                'fallback_offline'=> true,
                'last_test'       => null,
            ],
            'gameplay' => [
                'hints_per_case'    => 4,
                'guest_hints'       => 2,
                'horror_intensity'  => 'normal',  // mild | normal | intense
                'jumpscares'        => true,
                'autosave_seconds'  => 20,
                'default_case'      => 'toby',
                'show_timer'        => true,
            ],
            'security' => [
                'max_login_attempts' => 6,
                'lockout_minutes'    => 15,
                'chat_per_minute'    => 15,
                'api_per_minute'     => 120,
                'registration_per_hour' => 8,
            ],
            'admin' => [
                'must_change_password' => true,
            ],
            'updated_at' => null,
        ];
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        $data = $this->store->read(self::FILE, []);
        return $this->mergeDefaults(self::defaults(), $data);
    }

    public function get(string $path, mixed $default = null): mixed
    {
        return Json::get($this->all(), $path, $default);
    }

    public function save(array $settings): void
    {
        $this->store->update(self::FILE, function (array $current) use ($settings): array {
            $merged = $this->mergeDefaults($this->mergeDefaults(self::defaults(), $current), $settings);
            $merged['updated_at'] = gmdate('c');
            $merged['_schema'] = WIT_SCHEMA_VERSION;
            return $merged;
        }, self::defaults());
    }

    public function set(string $path, mixed $value): void
    {
        $all = $this->all();
        $all = Json::set($all, $path, $value);
        $this->save($all);
    }

    /** Legt den API-Schluessel verschluesselt ab. */
    public function setApiKey(string $plainKey): void
    {
        $this->set('ai.api_key_enc', $plainKey === '' ? '' : Crypto::encrypt($plainKey));
    }

    public function apiKey(): string
    {
        $enc = (string)$this->get('ai.api_key_enc', '');
        if ($enc === '') {
            return '';
        }
        try {
            return Crypto::decrypt($enc);
        } catch (\Throwable) {
            return '';
        }
    }

    public function hasApiKey(): bool
    {
        return (string)$this->get('ai.api_key_enc', '') !== '';
    }

    /** Einstellungen ohne Geheimnisse (fuer Frontend/Export). */
    public function publicSettings(): array
    {
        $all = $this->all();
        unset($all['ai']['api_key_enc']);
        $all['ai']['api_key_set'] = $this->hasApiKey();
        return $all;
    }

    private function mergeDefaults(array $defaults, array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key]) && !array_is_list($value)) {
                $defaults[$key] = $this->mergeDefaults($defaults[$key], $value);
            } else {
                $defaults[$key] = $value;
            }
        }
        return $defaults;
    }
}
