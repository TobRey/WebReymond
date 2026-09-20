<?php

declare(strict_types=1);

namespace SkyKingdoms\Http\Controllers;

use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Audit;
use SkyKingdoms\Core\Auth;
use SkyKingdoms\Core\Csrf;
use SkyKingdoms\Core\Logger;
use SkyKingdoms\Core\Response;
use SkyKingdoms\Core\Security;
use SkyKingdoms\Core\Session;
use SkyKingdoms\Core\Url;
use SkyKingdoms\Core\Validator;
use SkyKingdoms\Core\View;
use SkyKingdoms\Game\Player;
use SkyKingdoms\Game\Quests;
use SkyKingdoms\Store\Store;

/**
 * Der Installationsassistent.
 *
 * Schritte: Prüfung → Einstellungen → Adminkonto → Übersicht → Einrichten → Fertig
 * Nach erfolgreicher Installation sperrt sich der Assistent selbst.
 */
final class InstallController
{
    public function handle(): void
    {
        Session::start();
        Security::headers();

        if (App::isInstalled() && !$this->unlockRequested()) {
            View::page('install/locked', ['title' => 'Bereits installiert'], 'partials/plain');
        }

        $step = max(1, min(5, (int) ($_GET['step'] ?? 1)));

        if (Security::isPost()) {
            Csrf::verifyOrFail();
            $step = $this->post($step);
        }

        match ($step) {
            1 => $this->stepCheck(),
            2 => $this->stepSettings(),
            3 => $this->stepAdmin(),
            4 => $this->stepReview(),
            default => $this->stepDone(),
        };
    }

    private function unlockRequested(): bool
    {
        return false;
    }

    // =================================================================
    // Schritte
    // =================================================================

    private function stepCheck(): void
    {
        $report = self::diagnose();
        View::page('install/check', [
            'title'  => 'Systemprüfung',
            'report' => $report,
            'ready'  => $report['ready'],
        ], 'partials/plain');
    }

    private function stepSettings(): void
    {
        View::page('install/settings', [
            'title'  => 'Grundeinstellungen',
            'data'   => Session::get('install.settings', [
                'name'     => 'Sky Kingdoms',
                'timezone' => 'Europe/Zurich',
                'offline'  => 24,
                'open'     => '1',
            ]),
            'errors' => Session::get('install.errors', []),
            'zones'  => self::timezones(),
        ], 'partials/plain');
        Session::forget('install.errors');
    }

    private function stepAdmin(): void
    {
        View::page('install/admin', [
            'title'  => 'Administratorkonto',
            'data'   => Session::get('install.admin', ['username' => '', 'email' => '']),
            'errors' => Session::get('install.errors', []),
        ], 'partials/plain');
        Session::forget('install.errors');
    }

    private function stepReview(): void
    {
        $settings = Session::get('install.settings');
        $admin    = Session::get('install.admin');

        if (!is_array($settings) || !is_array($admin)) {
            Response::redirect('install/?step=2');
        }

        View::page('install/review', [
            'title'    => 'Alles bereit?',
            'settings' => $settings,
            'admin'    => $admin,
        ], 'partials/plain');
    }

    private function stepDone(): void
    {
        $result = Session::get('install.result', []);
        Session::forget('install.result');
        Session::forget('install.settings');
        Session::forget('install.admin');

        View::page('install/done', [
            'title'  => 'Fertig',
            'result' => is_array($result) ? $result : [],
        ], 'partials/plain');
    }

    // =================================================================
    // Formulare
    // =================================================================

    private function post(int $step): int
    {
        return match ($step) {
            2 => $this->saveSettings(),
            3 => $this->saveAdmin(),
            4 => $this->install(),
            default => $step,
        };
    }

    private function saveSettings(): int
    {
        $v = Validator::make($_POST)
            ->text('name', 'Der Spielname', 2, 40)
            ->int('offline', 'Der Offline-Zeitraum', 1, 168)
            ->in('timezone', 'Die Zeitzone', self::timezones())
            ->in('open', 'Die Registrierung', ['0', '1']);

        $data = [
            'name'     => (string) $v->value('name', 'Sky Kingdoms'),
            'timezone' => (string) $v->value('timezone', 'Europe/Zurich'),
            'offline'  => (int) $v->value('offline', 24),
            'open'     => (string) $v->value('open', '1'),
        ];
        Session::set('install.settings', $data);

        if ($v->fails()) {
            Session::set('install.errors', $v->errors());

            return 2;
        }

        Response::redirect('install/?step=3');
    }

    private function saveAdmin(): int
    {
        $v = Validator::make($_POST)
            ->username('username')
            ->email('email')
            ->password('password')
            ->matches('password_confirm', 'password', 'Die beiden Passwörter stimmen nicht überein.');

        Session::set('install.admin', [
            'username' => (string) $v->value('username', ''),
            'email'    => (string) $v->value('email', ''),
            'password' => (string) ($_POST['password'] ?? ''),
        ]);

        if ($v->fails()) {
            Session::set('install.errors', $v->errors());

            return 3;
        }

        Response::redirect('install/?step=4');
    }

    /** Die eigentliche Installation. */
    private function install(): int
    {
        $settings = Session::get('install.settings');
        $admin    = Session::get('install.admin');

        if (!is_array($settings) || !is_array($admin)) {
            Response::redirect('install/?step=2');
        }

        $report = self::diagnose();
        if (!$report['ready']) {
            Session::set('install.errors', ['system' => 'Die Systemprüfung ist noch nicht bestanden.']);

            return 1;
        }

        try {
            // 1. Datenordner anlegen und absichern
            $dataDir = SK_ROOT . '/storage/data';
            self::ensureProtectedDir($dataDir);
            foreach (['users', 'index', 'index/username', 'index/email', 'index/remember', 'meta', 'logs', 'limits', 'resets', 'alliances', 'trades', 'attacks'] as $sub) {
                self::ensureProtectedDir($dataDir . '/' . $sub);
            }

            // 2. Konfiguration schreiben
            $appKey = bin2hex(random_bytes(32));
            $config = [
                'installed'    => true,
                'installed_at' => time(),
                'app_key'      => $appKey,
                'app_url'      => Url::detectAppUrl(),
                'data_dir'     => 'storage/data',
                'settings'     => [
                    'name'                => (string) $settings['name'],
                    'timezone'            => (string) $settings['timezone'],
                    'registration_open'   => $settings['open'] === '1',
                    'max_offline_seconds' => max(3600, (int) $settings['offline'] * 3600),
                ],
            ];

            $written = self::writeConfig($config);
            if (!$written) {
                throw new \RuntimeException('Die Datei config/config.php konnte nicht geschrieben werden.');
            }

            // 3. Konfiguration sofort aktiv machen
            App::setConfig('app_key', $appKey);
            App::setConfig('name', $config['settings']['name']);
            App::setConfig('timezone', $config['settings']['timezone']);
            App::setConfig('registration_open', true);
            App::setConfig('max_offline_seconds', $config['settings']['max_offline_seconds']);
            App::applyInstallation($config);
            App::setStore(new Store($dataDir));

            // 4. Grundeinstellungen ablegen
            App::store()->write('meta/settings.json', $config['settings']);
            App::store()->write('meta/install.json', [
                'version'      => SK_VERSION,
                'installed_at' => time(),
                'php'          => PHP_VERSION,
                'base'         => Url::base(),
            ]);

            // 5. Administratorkonto samt Startkönigreich
            $created = Auth::register(
                (string) $admin['username'],
                (string) $admin['email'],
                (string) $admin['password'],
                [Player::ROLE_PLAYER, Player::ROLE_ADMIN],
                (string) $admin['username'] . 's Reich'
            );

            if (!($created['ok'] ?? false)) {
                throw new \RuntimeException((string) ($created['error'] ?? 'Das Administratorkonto konnte nicht angelegt werden.'));
            }

            // 6. Aufgaben und Beispieldaten
            Quests::install();

            App::setConfig('registration_open', $settings['open'] === '1');
            App::store()->write('meta/settings.json', $config['settings']);

            Audit::log('install.completed', 'Installation abgeschlossen', ['php' => PHP_VERSION], (string) $created['uid']);

            Session::set('install.result', [
                'admin'     => (string) $admin['username'],
                'name'      => (string) $settings['name'],
                'base'      => Url::base(),
                'selfcheck' => self::selfCheckDataProtection(),
            ]);

            return 5;
        } catch (\Throwable $e) {
            Logger::error('Installation fehlgeschlagen', ['fehler' => $e->getMessage()]);
            Session::set('install.errors', ['system' => $e->getMessage()]);

            return 4;
        }
    }

    // =================================================================
    // Systemprüfung
    // =================================================================

    /**
     * Vollständige Diagnose. Wird auch im Adminbereich verwendet.
     *
     * @return array{ready:bool,groups:array<int,array>,base:string,rewrite:bool}
     */
    public static function diagnose(): array
    {
        $checks = [];

        // --- PHP -------------------------------------------------------
        $checks['PHP'][] = self::check(
            'PHP-Version 8.2 oder neuer',
            PHP_VERSION_ID >= 80200,
            PHP_VERSION,
            'Stelle die PHP-Version im cPanel unter „MultiPHP Manager" auf 8.2 oder neuer.'
        );

        foreach (['json' => 'JSON-Verarbeitung', 'mbstring' => 'Umlaute und Unicode', 'session' => 'Sitzungen'] as $ext => $label) {
            $checks['PHP'][] = self::check(
                'Erweiterung ' . $ext . ' (' . $label . ')',
                extension_loaded($ext),
                extension_loaded($ext) ? 'vorhanden' : 'fehlt',
                'Aktiviere die Erweiterung im cPanel unter „Select PHP Version" → „Extensions".'
            );
        }

        foreach (['zip' => 'Sicherungen herunterladen', 'gd' => 'PNG-Symbole erzeugen', 'openssl' => 'verschlüsselter E-Mail-Versand'] as $ext => $label) {
            $checks['Optional'][] = self::check(
                'Erweiterung ' . $ext . ' (' . $label . ')',
                extension_loaded($ext),
                extension_loaded($ext) ? 'vorhanden' : 'fehlt',
                'Nicht zwingend nötig – ohne sie entfällt nur: ' . $label . '.',
                true
            );
        }

        $checks['PHP'][] = self::check(
            'Passwort-Hashing verfügbar',
            function_exists('password_hash'),
            defined('PASSWORD_ARGON2ID') ? 'Argon2id' : 'bcrypt',
            'Ohne password_hash() ist kein sicherer Betrieb möglich.'
        );

        // --- Schreibrechte ---------------------------------------------
        foreach ([
            'config'            => 'Konfiguration',
            'storage'           => 'Datenordner',
            'storage/data'      => 'Spielstände',
            'storage/logs'      => 'Protokolle',
            'storage/sessions'  => 'Sitzungen',
        ] as $path => $label) {
            $full = SK_ROOT . '/' . $path;
            if (!is_dir($full)) {
                @mkdir($full, 0770, true);
            }
            $writable = is_dir($full) && is_writable($full);
            $checks['Schreibrechte'][] = self::check(
                $label . ' (' . $path . ')',
                $writable,
                $writable ? 'beschreibbar' : 'nicht beschreibbar',
                'Setze im cPanel-Dateimanager die Rechte dieses Ordners auf 755 (oder 775).'
            );
        }

        // --- Dateisystem: Sperren und atomares Umbenennen ---------------
        $fsTest = self::testFilesystem();
        $checks['Dateisystem'][] = self::check(
            'Dateisperren (flock)',
            $fsTest['flock'],
            $fsTest['flock'] ? 'funktioniert' : 'nicht verfügbar',
            'Ohne Dateisperren können gleichzeitige Spielzüge Daten beschädigen. Bitte den Hoster fragen.'
        );
        $checks['Dateisystem'][] = self::check(
            'Atomares Ersetzen (rename)',
            $fsTest['rename'],
            $fsTest['rename'] ? 'funktioniert' : 'nicht verfügbar',
            'Ohne atomares Umbenennen sind Spielstände bei Abbrüchen gefährdet.'
        );

        // --- Pfad und Webserver -----------------------------------------
        $base    = Url::base();
        $rewrite = self::detectRewrite();

        $checks['Adresse'][] = self::check(
            'Erkannter Installationsordner',
            true,
            $base === '' ? 'Hauptverzeichnis der Domain' : $base,
            'Alle Links und Bilder richten sich automatisch danach.'
        );
        $checks['Adresse'][] = self::check(
            'Vollständige Adresse',
            true,
            Url::detectAppUrl(),
            'Diese Adresse wird für E-Mails verwendet und ist im Adminbereich änderbar.'
        );
        $checks['Adresse'][] = self::check(
            'Hübsche Adressen (mod_rewrite)',
            $rewrite,
            $rewrite ? 'aktiv' : 'nicht aktiv',
            'Nicht nötig: Ohne mod_rewrite läuft alles über index.php, api/ und admin/.',
            true
        );

        $ready = true;
        foreach ($checks as $group) {
            foreach ($group as $check) {
                if (!$check['ok'] && !$check['optional']) {
                    $ready = false;
                }
            }
        }

        return ['ready' => $ready, 'groups' => $checks, 'base' => $base, 'rewrite' => $rewrite];
    }

    private static function check(string $label, bool $ok, string $value, string $hint, bool $optional = false): array
    {
        return ['label' => $label, 'ok' => $ok, 'value' => $value, 'hint' => $hint, 'optional' => $optional];
    }

    /** @return array{flock:bool,rename:bool} */
    private static function testFilesystem(): array
    {
        $dir    = SK_ROOT . '/storage/cache';
        $result = ['flock' => false, 'rename' => false];

        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return $result;
        }

        $test = $dir . '/.systemtest-' . bin2hex(random_bytes(4));
        $handle = @fopen($test, 'c+b');
        if ($handle !== false) {
            $result['flock'] = @flock($handle, LOCK_EX | LOCK_NB);
            @flock($handle, LOCK_UN);
            fclose($handle);
        }

        if (is_file($test)) {
            $target = $test . '.moved';
            $result['rename'] = @rename($test, $target);
            @unlink($target);
            @unlink($test);
        }

        return $result;
    }

    private static function detectRewrite(): bool
    {
        if (function_exists('apache_get_modules')) {
            return in_array('mod_rewrite', (array) apache_get_modules(), true);
        }

        return isset($_SERVER['HTTP_X_SK_REWRITE']) || getenv('HTTP_MOD_REWRITE') === 'On';
    }

    /**
     * Prüft nach der Installation, ob die Spielstände von aussen abrufbar sind.
     *
     * @return array{tested:bool,protected:bool,url:string,note:string}
     */
    public static function selfCheckDataProtection(): array
    {
        $url = Url::absolute('storage/data/meta/install.json.php');

        $content = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 6,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($body !== false) {
                $content = $code === 200 ? (string) $body : '';
            }
        } elseif (ini_get('allow_url_fopen')) {
            $context = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
            $body    = @file_get_contents($url, false, $context);
            $content = $body === false ? null : (string) $body;
        }

        if ($content === null) {
            return [
                'tested'    => false,
                'protected' => true,
                'url'       => $url,
                'note'      => 'Die Erreichbarkeit konnte nicht automatisch geprüft werden. Rufe die Adresse einmal selbst auf – es darf KEIN Inhalt erscheinen.',
            ];
        }

        $leaked = str_contains($content, '"version"') || str_contains($content, 'installed_at');

        return [
            'tested'    => true,
            'protected' => !$leaked,
            'url'       => $url,
            'note'      => $leaked
                ? 'ACHTUNG: Die Spielstände sind über den Browser erreichbar. Prüfe, ob .htaccess-Dateien erlaubt sind (AllowOverride All), oder verschiebe storage/data ausserhalb des Web-Ordners.'
                : 'Die Spielstände sind von aussen nicht abrufbar (doppelt abgesichert: .htaccess und PHP-Wächter in jeder Datei).',
        ];
    }

    // =================================================================
    // Hilfen
    // =================================================================

    private static function ensureProtectedDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Ordner konnte nicht angelegt werden: ' . $dir);
        }

        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
        }

        $index = $dir . '/index.html';
        if (!is_file($index)) {
            @file_put_contents($index, "<!doctype html><title>Kein Zugriff</title>Kein Zugriff.");
        }
    }

    private static function writeConfig(array $config): bool
    {
        $file = SK_ROOT . '/config/config.php';
        $body = "<?php\n"
            . "/**\n"
            . " * Sky Kingdoms – Installationskonfiguration.\n"
            . " * Automatisch erzeugt am " . date('d.m.Y H:i') . ".\n"
            . " *\n"
            . " * Diese Datei enthält den geheimen Schlüssel der Installation.\n"
            . " * Sie darf NIEMALS öffentlich abrufbar oder weitergegeben werden.\n"
            . " */\n\n"
            . "declare(strict_types=1);\n\n"
            . "defined('SK_ROOT') || exit('Direkter Zugriff nicht erlaubt.');\n\n"
            . 'return ' . var_export($config, true) . ";\n";

        $ok = @file_put_contents($file, $body, LOCK_EX) !== false;
        if ($ok) {
            @chmod($file, 0640);
        }

        return $ok;
    }

    /** @return string[] */
    public static function timezones(): array
    {
        return [
            'Europe/Zurich', 'Europe/Berlin', 'Europe/Vienna', 'Europe/London',
            'Europe/Paris', 'Europe/Madrid', 'Europe/Rome', 'Europe/Warsaw',
            'Europe/Istanbul', 'America/New_York', 'America/Chicago', 'America/Los_Angeles',
            'Asia/Dubai', 'Asia/Singapore', 'Asia/Tokyo', 'Australia/Sydney', 'UTC',
        ];
    }
}
