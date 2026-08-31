<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Ai\ClaudeClient;
use WebAtze\Core\{Audit, Config, Crypto, Db, Request, Response, SecondFactor,
    Session, Settings, Totp, Validator, View};

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
                'twoFactor' => self::twoFactorState(),
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

        if (in_array($action, ['2fa_start', '2fa_confirm', '2fa_disable', '2fa_codes', '2fa_forget'], true)) {
            return $this->secondFactor($request, $action);
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

    /** Zweiten Faktor einrichten, bestätigen, abschalten. */
    private function secondFactor(Request $request, string $action): Response
    {
        $user = Session::user();
        if ($user === null) {
            return Response::notFound();
        }

        $id = (int) $user['id'];

        switch ($action) {

            case '2fa_start':
                // Der Schlüssel wird nur vorbereitet. Scharf wird er erst,
                // wenn ein Code daraus stimmt – sonst könnte sich jemand
                // aussperren, dessen App ihn gar nicht übernommen hat.
                $secret = SecondFactor::prepare($id);
                Session::put('_wa_2fa_setup', $secret);
                Session::flash('success', 'Schlüssel erzeugt. Jetzt in der App eintragen und '
                    . 'mit dem ersten Code bestätigen.');
                break;

            case '2fa_confirm':
                $codes = SecondFactor::confirm($id, $request->input('code'));

                if ($codes === null) {
                    Session::flash('error', 'Der Code stimmt nicht. Stimmt die Uhrzeit auf dem Telefon?');
                    break;
                }

                Session::forget('_wa_2fa_setup');

                // Die Ersatzcodes gibt es genau einmal zu sehen.
                Session::put('_wa_2fa_recovery', $codes);
                Audit::log('auth.2fa_enabled', (string) $user['username'], [], $request);
                Session::flash('success', 'Der zweite Faktor ist aktiv.');
                break;

            case '2fa_codes':
                $row = Db::first('SELECT * FROM users WHERE id = :id', ['id' => $id]);

                if ($row === null || !SecondFactor::isActive($row)) {
                    Session::flash('error', 'Der zweite Faktor ist nicht aktiv.');
                    break;
                }

                if (!SecondFactor::check($row, $request->input('code'))['ok']) {
                    Session::flash('error', 'Ohne gültigen Code gibt es keine neuen Ersatzcodes.');
                    break;
                }

                $neu = Totp::recoveryCodes();
                Db::update('users', ['recovery_codes' => $neu['hashed']], 'id = :id', ['id' => $id]);

                Session::put('_wa_2fa_recovery', $neu['plain']);
                Audit::log('auth.2fa_codes', (string) $user['username'], [], $request);
                Session::flash('success', 'Neue Ersatzcodes erzeugt. Die alten gelten nicht mehr.');
                break;

            case '2fa_disable':
                if (!SecondFactor::disable($id, $request->input('code'))) {
                    Session::flash('error', 'Zum Abschalten wird ein gültiger Code gebraucht.');
                    break;
                }

                Audit::log('auth.2fa_disabled', (string) $user['username'], [], $request);
                Session::flash('warning', 'Der zweite Faktor ist abgeschaltet. '
                    . 'Ab jetzt genügt das Passwort allein.');
                break;

            case '2fa_forget':
                $n = SecondFactor::forgetDevices($id);
                Audit::log('auth.devices_forgotten', (string) $user['username'], ['anzahl' => $n], $request);
                Session::flash('success', $n === 1
                    ? 'Ein Gerät vergessen.'
                    : $n . ' Geräte vergessen. Sie fragen beim nächsten Mal wieder nach dem Code.');
                break;
        }

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
        // ist damit draussen. Bekannte Geräte gehen mit: Sonst käme
        // jemand mit dem alten Passwort auf seinem Gerät weiterhin
        // ohne Code hinein.
        Db::delete('auth_sessions', 'user_id = :u', ['u' => (int) $user['id']]);
        SecondFactor::forgetDevices((int) $user['id']);

        Audit::log('auth.password_changed', (string) $user['username'], [], $request);
        Session::logout();

        Session::flash('success', 'Passwort geändert. Bitte neu anmelden.');

        return Response::redirect('/' . trim((string) Config::get('create_path', 'create'), '/'))
            ->noCache()->noIndex();
    }

    /** Alles, was die Ansicht über den zweiten Faktor wissen muss. */
    private static function twoFactorState(): array
    {
        $user = Session::user();

        if ($user === null) {
            return ['active' => false];
        }

        $row = Db::first('SELECT * FROM users WHERE id = :id', ['id' => (int) $user['id']]) ?? [];

        // Die Ersatzcodes werden genau einmal gezeigt und dann vergessen.
        $recovery = Session::get('_wa_2fa_recovery', []);
        Session::forget('_wa_2fa_recovery');

        $setupSecret = (string) Session::get('_wa_2fa_setup', '');

        return [
            'active' => SecondFactor::isActive($row),
            'setupSecret' => $setupSecret,
            'setupUri' => $setupSecret === '' ? '' : Totp::uri(
                $setupSecret,
                (string) ($user['username'] ?? 'webatze'),
                (string) (Settings::get('company_name', 'WebAtze') ?: 'WebAtze')
            ),
            'recovery' => is_array($recovery) ? $recovery : [],
            'codesLeft' => SecondFactor::recoveryCodesLeft($row),
            'devices' => SecondFactor::devices((int) $user['id']),
        ];
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
