<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Container;
use App\Core\Environment;
use App\Core\Json;

/**
 * Automatischer Diagnosebereich fuer den Adminbereich.
 * Prueft Umgebung, Schreibrechte, Schutz der Datenverzeichnisse,
 * Datenintegritaet, KI-Konfiguration und Fallqualitaet.
 */
final class DiagnosticsService
{
    public function __construct(private Container $container)
    {
    }

    /** @return array{summary:array<string,int>,groups:array<int,array<string,mixed>>} */
    public function run(bool $deep = false): array
    {
        $groups = [
            $this->environmentChecks(),
            $this->filesystemChecks(),
            $this->securityChecks(),
            $this->dataChecks(),
            $this->aiChecks(),
            $this->caseChecks(),
        ];
        if ($deep) {
            $groups[] = $this->concurrencyCheck();
        }

        $summary = ['ok' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($groups as $group) {
            foreach ($group['checks'] as $check) {
                $summary[$check['status']] = ($summary[$check['status']] ?? 0) + 1;
            }
        }
        return ['summary' => $summary, 'groups' => $groups];
    }

    private function check(string $name, string $status, string $message, string $fix = ''): array
    {
        return ['name' => $name, 'status' => $status, 'message' => $message, 'fix' => $fix];
    }

    private function environmentChecks(): array
    {
        $checks = [];
        $checks[] = $this->check(
            'PHP-Version',
            PHP_VERSION_ID >= 80200 ? 'ok' : (PHP_VERSION_ID >= 80100 ? 'warn' : 'fail'),
            'Laeuft mit PHP ' . PHP_VERSION . '.',
            PHP_VERSION_ID >= 80200 ? '' : 'In cPanel unter "MultiPHP Manager" auf PHP 8.2 oder neuer stellen.'
        );

        $required = ['json', 'mbstring', 'session'];
        $recommended = ['curl', 'gd', 'openssl', 'fileinfo', 'zip', 'sodium', 'exif'];
        foreach (Environment::extensions() as $name => $loaded) {
            if (in_array($name, $required, true)) {
                $checks[] = $this->check('Erweiterung ' . $name, $loaded ? 'ok' : 'fail', $loaded ? 'geladen' : 'FEHLT - zwingend erforderlich', 'In cPanel unter "Select PHP Version" aktivieren.');
            } elseif (in_array($name, $recommended, true)) {
                $message = match ($name) {
                    'curl'     => $loaded ? 'geladen' : 'fehlt - KI-Anbieter werden ueber Streams angesprochen (langsamer)',
                    'gd'       => $loaded ? 'geladen' : 'fehlt - automatische Bildgenerierung deaktiviert',
                    'zip'      => $loaded ? 'geladen' : 'fehlt - Sicherungen werden als JSON erstellt',
                    'sodium'   => $loaded ? 'geladen' : 'fehlt - API-Schluessel wird mit OpenSSL verschluesselt',
                    'exif'     => $loaded ? 'geladen' : 'fehlt - nur fuer Bildmetadaten relevant',
                    default    => $loaded ? 'geladen' : 'fehlt',
                };
                $checks[] = $this->check('Erweiterung ' . $name, $loaded ? 'ok' : 'warn', $message);
            }
        }

        $memory = (string)ini_get('memory_limit');
        $checks[] = $this->check('Speicherlimit', $this->bytes($memory) >= 67108864 || $memory === '-1' ? 'ok' : 'warn', 'memory_limit = ' . $memory, 'Mindestens 64M empfohlen.');
        $checks[] = $this->check('Maximale Laufzeit', (int)ini_get('max_execution_time') >= 30 || (int)ini_get('max_execution_time') === 0 ? 'ok' : 'warn', 'max_execution_time = ' . ini_get('max_execution_time') . 's', 'Fuer KI-Anfragen sind mindestens 30 Sekunden sinnvoll.');
        $checks[] = $this->check('Upload-Groesse', $this->bytes((string)ini_get('upload_max_filesize')) >= 8388608 ? 'ok' : 'warn', 'upload_max_filesize = ' . ini_get('upload_max_filesize'));

        return ['title' => 'Umgebung', 'checks' => $checks];
    }

    private function filesystemChecks(): array
    {
        $checks = [];
        $paths = [
            'Datenverzeichnis'   => WIT_STORAGE,
            'Faelle'             => WIT_STORAGE . '/cases',
            'Spielstaende'       => WIT_STORAGE . '/progress',
            'Konten'             => WIT_STORAGE . '/users',
            'Sitzungen'          => WIT_STORAGE . '/sessions',
            'Protokolle'         => WIT_STORAGE . '/logs',
            'Sicherungen'        => WIT_STORAGE . '/backups',
            'Zwischenspeicher'   => WIT_STORAGE . '/cache',
            'Uploads'            => WIT_UPLOADS,
        ];
        foreach ($paths as $label => $path) {
            if (!is_dir($path)) {
                $checks[] = $this->check($label, 'fail', 'Verzeichnis fehlt: ' . $this->shorten($path), 'Verzeichnis anlegen und Schreibrechte (755) setzen.');
                continue;
            }
            $writable = is_writable($path);
            $checks[] = $this->check($label, $writable ? 'ok' : 'fail', $writable ? 'beschreibbar' : 'NICHT beschreibbar: ' . $this->shorten($path), 'Im cPanel-Dateimanager Rechte auf 755 (Dateien 644) setzen.');
        }

        $free = @disk_free_space(WIT_STORAGE);
        if (is_float($free)) {
            $checks[] = $this->check('Freier Speicher', $free > 52428800 ? 'ok' : 'warn', round($free / 1048576) . ' MB frei');
        }
        return ['title' => 'Dateisystem', 'checks' => $checks];
    }

    private function securityChecks(): array
    {
        $checks = [];
        $checks[] = $this->check(
            'Installer gesperrt',
            is_file(WIT_ROOT . '/install.php') ? (is_file(WIT_STORAGE . '/settings/install.lock') ? 'ok' : 'fail') : 'ok',
            is_file(WIT_ROOT . '/install.php')
                ? (is_file(WIT_STORAGE . '/settings/install.lock') ? 'install.php ist gesperrt (Sperrdatei vorhanden).' : 'install.php ist NICHT gesperrt!')
                : 'install.php wurde entfernt.',
            'Installation abschliessen oder install.php loeschen.'
        );

        foreach ([['storage', WIT_STORAGE], ['uploads', WIT_UPLOADS], ['app', WIT_APP]] as [$label, $path]) {
            $htaccess = $path . '/.htaccess';
            $inside = str_starts_with($path, WIT_ROOT);
            if (!$inside) {
                $checks[] = $this->check('Schutz ' . $label, 'ok', 'Liegt ausserhalb des Webverzeichnisses - bestmoeglicher Schutz.');
                continue;
            }
            $ok = is_file($htaccess) && str_contains((string)@file_get_contents($htaccess), 'denied');
            $checks[] = $this->check('Schutz ' . $label, $ok ? 'ok' : 'fail', $ok ? '.htaccess-Schutz vorhanden' : '.htaccess-Schutz fehlt!', 'Datei .htaccess mit "Require all denied" anlegen.');
        }

        $settings = $this->container->settings();
        $adminMustChange = false;
        foreach ($this->container->users()->all() as $user) {
            if (($user['role'] ?? '') === 'admin' && !empty($user['must_change_password'])) {
                $adminMustChange = true;
            }
        }
        $checks[] = $this->check('Admin-Passwort', $adminMustChange ? 'warn' : 'ok', $adminMustChange ? 'Ein Administrator nutzt noch das Startpasswort.' : 'Startpasswort wurde geaendert.', 'Unter Adminbereich > Konto das Passwort aendern.');
        $checks[] = $this->check('HTTPS', Environment::isHttps() ? 'ok' : 'warn', Environment::isHttps() ? 'Seite laeuft ueber HTTPS.' : 'Kein HTTPS erkannt.', 'In cPanel ein kostenloses AutoSSL-Zertifikat aktivieren.');
        $checks[] = $this->check('Verschluesselung', extension_loaded('sodium') || extension_loaded('openssl') ? 'ok' : 'fail', extension_loaded('sodium') ? 'libsodium aktiv' : (extension_loaded('openssl') ? 'OpenSSL aktiv' : 'Keine Verschluesselung verfuegbar'), 'Erweiterung sodium oder openssl aktivieren.');
        $checks[] = $this->check('App-Schluessel', WIT_APP_KEY !== '' ? 'ok' : 'fail', WIT_APP_KEY !== '' ? 'gesetzt' : 'fehlt', 'Installation erneut ausfuehren.');

        return ['title' => 'Sicherheit', 'checks' => $checks];
    }

    private function dataChecks(): array
    {
        $checks = [];
        $store = $this->container->store();
        $corrupt = glob(WIT_STORAGE . '/backups/corrupt/*.json') ?: [];
        $checks[] = $this->check('JSON-Integritaet', $corrupt === [] ? 'ok' : 'warn', $corrupt === [] ? 'Keine beschaedigten Dateien gefunden.' : count($corrupt) . ' beschaedigte Datei(en) wurden gesichert.', 'Dateien unter storage/backups/corrupt pruefen.');

        $users = $this->container->users();
        $checks[] = $this->check('Konten', $users->adminExists() ? 'ok' : 'fail', $users->count() . ' Konto/Konten, Administrator ' . ($users->adminExists() ? 'vorhanden' : 'FEHLT'));

        $cases = $this->container->cases()->listSummaries(true);
        $published = array_filter($cases, static fn(array $c): bool => $c['status'] === 'published');
        $checks[] = $this->check('Faelle', $published !== [] ? 'ok' : 'warn', count($cases) . ' Fall/Faelle, davon ' . count($published) . ' veroeffentlicht.');

        $sessions = glob(WIT_STORAGE . '/sessions/sess_*') ?: [];
        $checks[] = $this->check('Sitzungen', 'ok', count($sessions) . ' aktive Sitzungsdatei(en).');

        $logSize = 0;
        foreach (glob(WIT_STORAGE . '/logs/*.log') ?: [] as $log) {
            $logSize += (int)filesize($log);
        }
        $checks[] = $this->check('Protokolle', $logSize < 10485760 ? 'ok' : 'warn', round($logSize / 1024) . ' KB Protokolldaten.', 'Alte Protokolle im Adminbereich loeschen.');

        return ['title' => 'Daten', 'checks' => $checks];
    }

    private function aiChecks(): array
    {
        $settings = $this->container->settings();
        $ai = $this->container->ai();
        $checks = [];
        $provider = $ai->providerName();
        $checks[] = $this->check('KI-Anbieter', 'ok', $provider === 'offline' ? 'Offline-Modus: regelbasierte Dialoge (immer spielbar).' : 'Anbieter: ' . $provider . ', Modell: ' . ($ai->model() ?: 'nicht gesetzt'));
        if ($provider !== 'offline') {
            if (!$settings->hasApiKey()) {
                $checks[] = $this->check('API-Schluessel', 'warn', 'kein Schluessel hinterlegt - es wird offline gespielt');
            } elseif ($settings->apiKey() === '') {
                $checks[] = $this->check(
                    'API-Schluessel',
                    'fail',
                    'hinterlegt, aber nicht entschluesselbar',
                    'Der App-Schluessel in "config.local.php" passt nicht mehr zum gespeicherten API-Schluessel. '
                    . 'Das passiert, wenn die Konfigurationsdatei geloescht oder ersetzt wurde. '
                    . 'Den API-Schluessel unter "KI" neu eintragen.'
                );
            } else {
                $checks[] = $this->check('API-Schluessel', 'ok', 'hinterlegt und verschluesselt gespeichert');
            }
            $checks[] = $this->check('Modellname', $ai->model() !== '' ? 'ok' : 'warn', $ai->model() !== '' ? $ai->model() : 'kein Modell eingetragen');
            $lastTest = $settings->get('ai.last_test');
            if (is_array($lastTest)) {
                $checks[] = $this->check('Letzter Verbindungstest', ($lastTest['ok'] ?? false) ? 'ok' : 'warn', (string)($lastTest['message'] ?? '') . ' (' . (string)($lastTest['at'] ?? '') . ')');
            }
        }
        $checks[] = $this->check('Offline-Rueckfall', (bool)$settings->get('ai.fallback_offline', true) ? 'ok' : 'warn', (bool)$settings->get('ai.fallback_offline', true) ? 'Bei KI-Ausfall uebernimmt das regelbasierte System.' : 'Deaktiviert - bei Ausfall bleiben Chats stumm.');
        return ['title' => 'KI-Konfiguration', 'checks' => $checks];
    }

    private function caseChecks(): array
    {
        $checks = [];
        $validator = $this->container->caseValidator();
        foreach ($this->container->cases()->listSummaries(true) as $summary) {
            $case = $this->container->cases()->find($summary['id']);
            if ($case === null) {
                continue;
            }
            $result = $validator->validate($case);
            $errors = $result['stats']['errors'];
            $warnings = $result['stats']['warnings'];
            $checks[] = $this->check(
                'Fall "' . $summary['title'] . '"',
                $errors > 0 ? 'fail' : ($warnings > 0 ? 'warn' : 'ok'),
                $errors . ' Fehler, ' . $warnings . ' Hinweise. ' . $result['stats']['puzzles'] . ' Raetsel, ' . $result['stats']['evidence'] . ' Beweise, ' . $result['stats']['npcs'] . ' NPCs.',
                $errors > 0 ? 'Im Fall-Editor unter "Pruefung" die Meldungen abarbeiten.' : ''
            );
        }
        if ($checks === []) {
            $checks[] = $this->check('Faelle', 'warn', 'Es ist kein Fall vorhanden.');
        }
        return ['title' => 'Faelle', 'checks' => $checks];
    }

    /** Schreibt parallel in dieselbe Datei und prueft die Konsistenz. */
    private function concurrencyCheck(): array
    {
        $store = $this->container->store();
        $file = 'cache/diag_concurrency.json';
        $store->write($file, ['counter' => 0, 'entries' => []]);
        $rounds = 25;
        for ($i = 0; $i < $rounds; $i++) {
            $store->update($file, static function (array $data) use ($i): array {
                $data['counter'] = (int)($data['counter'] ?? 0) + 1;
                $data['entries'][] = $i;
                return $data;
            });
        }
        $result = $store->read($file, [], false);
        $ok = (int)($result['counter'] ?? 0) === $rounds && count((array)($result['entries'] ?? [])) === $rounds;
        $store->delete($file);

        $checks = [$this->check('Paralleles Schreiben', $ok ? 'ok' : 'fail', $ok ? $rounds . ' aufeinanderfolgende Schreibvorgaenge ohne Datenverlust.' : 'Datenverlust beim Schreiben erkannt.', 'Dateisystem und Sperren (flock) pruefen.')];

        $start = microtime(true);
        for ($i = 0; $i < 20; $i++) {
            $store->write('cache/diag_speed.json', ['i' => $i, 'ts' => microtime(true)]);
        }
        $duration = (microtime(true) - $start) * 1000;
        $store->delete('cache/diag_speed.json');
        $checks[] = $this->check('Schreibgeschwindigkeit', $duration < 900 ? 'ok' : 'warn', round($duration) . ' ms fuer 20 Schreibvorgaenge.');

        return ['title' => 'Belastungstest', 'checks' => $checks];
    }

    private function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }
        $unit = strtolower(substr($value, -1));
        $number = (int)$value;
        return match ($unit) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function shorten(string $path): string
    {
        return str_replace(dirname(WIT_ROOT), '...', $path);
    }
}
