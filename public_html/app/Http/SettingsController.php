<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Ai\ClaudeClient;
use WebAtze\Core\{Audit, Config, Crypto, Db, Request, Response, Session, Settings, Validator, View};

/**
 * Einstellungen der eigenen Website: Kontaktangaben, Impressum, Passwort.
 */
final class SettingsController
{
    public function index(Request $request): Response
    {
        return Response::html(View::partial('layouts/admin', [
            'title' => 'Einstellungen',
            'content' => View::partial('admin/settings', [
                'settings' => Settings::all(),
                'user' => Session::user(),
                'aiConfigured' => ClaudeClient::isConfigured(),
                'imprintComplete' => Settings::imprintComplete(),
                'cronHint' => self::cronLine(),
                'diagnostics' => self::diagnostics([
                    'actual' => ($request->isSecure() ? 'https://' : 'http://') . $request->host(),
                ]),
            ]),
        ]))->noCache()->noIndex();
    }

    public function save(Request $request): Response
    {
        $action = $request->input('action', 'settings');

        if ($action === 'password') {
            return $this->changePassword($request);
        }

        $values = [];
        foreach (array_keys(Settings::DEFAULTS) as $key) {
            if (!$request->has($key)) {
                continue;
            }
            $values[$key] = $key === 'imprint_extra'
                ? $request->text($key, 4000)
                : mb_substr($request->input($key), 0, 300);
        }

        if (isset($values['email']) && $values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            Session::flash('error', 'Die E-Mail-Adresse ist nicht gültig.');
            return $this->back();
        }

        Settings::putMany($values);
        Audit::log('settings.saved', '', ['felder' => array_keys($values)], $request);
        Session::flash('success', 'Einstellungen gespeichert.');

        return $this->back();
    }

    private function changePassword(Request $request): Response
    {
        $user = Session::user();
        if ($user === null) {
            return Response::notFound();
        }

        $current = $request->input('current_password');
        $new = $request->input('new_password');
        $repeat = $request->input('repeat_password');

        $row = Db::first('SELECT * FROM users WHERE id = :id', ['id' => (int) $user['id']]);
        if ($row === null || !Crypto::checkPassword($current, (string) $row['password_hash'])) {
            Session::flash('error', 'Das bisherige Passwort stimmt nicht.');
            return $this->back();
        }

        $check = Validator::make(['new_password' => $new, 'repeat_password' => $repeat])
            ->password('new_password', 'Neues Passwort', 10)
            ->matches('repeat_password', 'new_password', 'Wiederholung');

        if ($check->fails()) {
            Session::flash('error', $check->firstError());
            return $this->back();
        }

        Db::update('users', [
            'password_hash' => Crypto::hashPassword($new),
        ], 'id = :id', ['id' => (int) $user['id']]);

        // Alle anderen Sitzungen beenden – wer das alte Passwort hatte,
        // ist damit draussen.
        Db::delete('auth_sessions', 'user_id = :u', ['u' => (int) $user['id']]);

        Audit::log('auth.password_changed', (string) $user['username'], [], $request);
        Session::logout();

        Session::flash('success', 'Passwort geändert. Bitte neu anmelden.');

        return Response::redirect('/' . trim((string) Config::get('create_path', 'create'), '/'))
            ->noCache()->noIndex();
    }

    /** Die Cron-Zeile zum Kopieren. */
    private static function cronLine(): string
    {
        $php = PHP_BINARY !== '' ? PHP_BINARY : '/usr/local/bin/php';
        return sprintf('* * * * * %s %s/worker.php >/dev/null 2>&1', $php, BASE_DIR);
    }

    /** Läuft alles, was laufen muss? */
    private static function diagnostics(array $request = []): array
    {
        $checks = [];

        // Stimmt "app_url" nicht mit der Adresse überein, unter der die
        // Seite wirklich läuft, scheitert jede Herkunftsprüfung – und
        // damit jedes Formular, ohne dass man den Grund sähe. Genau
        // deshalb steht dieser Punkt hier ganz oben.
        $configured = rtrim((string) Config::get('app_url', ''), '/');
        $actual = rtrim((string) ($request['actual'] ?? ''), '/');
        $matches = $configured !== '' && $actual !== '' && $configured === $actual;

        $checks[] = [
            'label' => 'Adresse in der Konfiguration',
            'ok' => $matches,
            'value' => $configured === '' ? 'nicht gesetzt' : $configured,
            'hint' => $matches
                ? 'Stimmt mit der aufgerufenen Adresse überein.'
                : 'Die Seite läuft gerade unter ' . ($actual !== '' ? $actual : 'einer anderen Adresse')
                  . '. Solange beides nicht übereinstimmt, wird jedes Formular abgewiesen. '
                  . 'In app/config.php unter "app_url" berichtigen – mit oder ohne "www", '
                  . 'genau so, wie die Seite aufgerufen wird.',
        ];

        $checks[] = [
            'label' => 'PHP-Version',
            'ok' => PHP_VERSION_ID >= 80100,
            'value' => PHP_VERSION,
            'hint' => 'Mindestens PHP 8.1. In cPanel unter "MultiPHP Manager" umstellbar.',
        ];

        foreach (['zip' => 'Pakete schnüren', 'gd' => 'Bilder verarbeiten',
                  'curl' => 'KI und Netzabfragen', 'sodium' => 'Zugangsdaten verschlüsseln',
                  'ftp' => 'Websites hochladen', 'mbstring' => 'Umlaute und Sonderzeichen'] as $ext => $purpose) {
            $checks[] = [
                'label' => 'Erweiterung ' . $ext,
                'ok' => extension_loaded($ext),
                'value' => extension_loaded($ext) ? 'vorhanden' : 'fehlt',
                'hint' => $purpose,
            ];
        }

        $storageWritable = is_writable(STORAGE_DIR);
        $checks[] = [
            'label' => 'Schreibrechte storage/',
            'ok' => $storageWritable,
            'value' => $storageWritable ? 'in Ordnung' : 'fehlen',
            'hint' => 'Ohne Schreibrechte lassen sich weder Websites bauen noch Pakete ablegen.',
        ];

        $lastJob = Db::first("SELECT finished_at FROM jobs WHERE status = 'done' ORDER BY id DESC LIMIT 1");
        $housekeeping = (int) Settings::get('housekeeping_at', '0');
        $cronRan = $housekeeping > 0 && time() - $housekeeping < 7200;

        $checks[] = [
            'label' => 'Cronjob',
            'ok' => $cronRan,
            'value' => $housekeeping > 0
                ? 'zuletzt ' . date('d.m.Y H:i', $housekeeping)
                : 'noch nie gelaufen',
            'hint' => 'Ohne Cronjob werden Aufträge nur beim Anlegen bearbeitet und Vorschauen '
                . 'nie automatisch aufgeräumt.',
        ];

        $checks[] = [
            'label' => 'Anthropic-Schlüssel',
            'ok' => ClaudeClient::isConfigured(),
            'value' => ClaudeClient::isConfigured() ? 'hinterlegt' : 'fehlt',
            'hint' => 'Ohne Schlüssel läuft der Generator im Übungsmodus mit Platzhaltertexten.',
        ];

        $checks[] = [
            'label' => 'Verbindung verschlüsselt',
            'ok' => str_starts_with((string) Config::get('app_url', ''), 'https://'),
            'value' => (string) Config::get('app_url', ''),
            'hint' => 'Ohne https wandern Passwörter im Klartext durchs Netz.',
        ];

        $checks[] = [
            'label' => 'install.php entfernt',
            'ok' => !is_file(BASE_DIR . '/install.php'),
            'value' => is_file(BASE_DIR . '/install.php') ? 'liegt noch da' : 'entfernt',
            'hint' => 'Die Einrichtungsdatei sollte nach der Installation verschwunden sein.',
        ];

        return $checks;
    }

    private function back(): Response
    {
        return Response::redirect(
            '/' . trim((string) Config::get('create_path', 'create'), '/') . '/einstellungen'
        )->noCache()->noIndex();
    }
}
