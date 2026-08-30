<?php

declare(strict_types=1);

namespace WebAtze\Build;

use phpseclib3\Net\SFTP;
use WebAtze\Core\{Crypto, Db, Http, Logger};

/**
 * Lädt eine fertige Website auf das Hosting des Kunden.
 *
 * Drei Wege, je nach Anbieter:
 *   sftp  verschlüsselt über SSH – die beste Wahl, wo verfügbar
 *   ftps  FTP mit Verschlüsselung
 *   ftp   unverschlüsselt; nur, wenn der Anbieter nichts anderes kann
 *
 * Zugangsdaten werden verschlüsselt gespeichert (libsodium) und tauchen
 * niemals in einem Protokoll auf.
 *
 * Nach dem Hochladen wird geprüft, ob die Startseite wirklich erreichbar
 * ist. Ohne diese Prüfung würde man erst beim Kunden merken, dass das
 * Verzeichnis falsch war.
 */
final class FtpDeployer
{
    /**
     * @param callable|null $onProgress fn(int $erledigt, int $gesamt, string $datei)
     * @return array{ok:bool, files:int, error:string, retryable:bool, verified:bool, url:string}
     */
    public static function deploy(array $project, ?callable $onProgress = null, float $budget = 120.0): array
    {
        $target = Db::first(
            'SELECT * FROM deploy_targets WHERE project_id = :p ORDER BY id DESC LIMIT 1',
            ['p' => (int) $project['id']]
        );

        if ($target === null) {
            return self::error('Für dieses Projekt sind keine Zugangsdaten hinterlegt.', false);
        }

        $password = Crypto::decrypt((string) ($target['secret'] ?? ''));
        if ($password === null) {
            return self::error(
                'Die gespeicherten Zugangsdaten lassen sich nicht entschlüsseln. '
                . 'Wurde der Schlüssel in app/config.php geändert? Bitte neu eingeben.',
                false
            );
        }

        $source = STORAGE_DIR . '/projects/' . (string) $project['slug'] . '/dist';
        if (!is_dir($source)) {
            return self::error('Es gibt noch keine gebaute Website zum Hochladen.', false);
        }

        $files = self::collect($source);
        if ($files === []) {
            return self::error('Der Ordner mit der Website ist leer.', false);
        }

        $protocol = (string) $target['protocol'];

        $result = match ($protocol) {
            'sftp' => self::viaSftp($target, $password, $source, $files, $onProgress, $budget),
            default => self::viaFtp($target, $password, $source, $files, $onProgress, $budget, $protocol === 'ftps'),
        };

        // Passwort so früh wie möglich aus dem Speicher nehmen
        sodium_memzero($password);

        Db::update('deploy_targets', [
            'last_result' => mb_substr($result['ok'] ? sprintf('%d Dateien hochgeladen.', $result['files']) : $result['error'], 0, 500),
            'last_deployed_at' => $result['ok'] ? Db::now() : null,
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => (int) $target['id']]);

        // Nachsehen, ob die Website wirklich da ist
        if ($result['ok']) {
            $check = self::verify($project);
            $result['verified'] = $check['ok'];
            $result['url'] = $check['url'];

            if (!$check['ok'] && $check['url'] !== '') {
                $result['error'] = 'Die Dateien sind oben, aber ' . $check['url']
                    . ' antwortet noch nicht. Das kann an der DNS-Umstellung liegen '
                    . 'oder daran, dass das Zielverzeichnis nicht stimmt.';
            }
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // SFTP
    // ------------------------------------------------------------------

    private static function viaSftp(
        array $target,
        string $password,
        string $source,
        array $files,
        ?callable $onProgress,
        float $budget
    ): array {
        if (!class_exists(SFTP::class)) {
            return self::error(
                'Für SFTP fehlt die mitgelieferte Bibliothek. '
                . 'Bitte FTP mit Verschlüsselung wählen oder das Paket vollständig hochladen.',
                false
            );
        }

        $host = (string) $target['host'];
        $port = (int) $target['port'] ?: 22;

        try {
            $sftp = new SFTP($host, $port, 20);

            if (!$sftp->login((string) $target['username'], $password)) {
                return self::error('Anmeldung abgelehnt. Bitte Benutzername und Passwort prüfen.', false);
            }
        } catch (\Throwable $e) {
            Logger::warning('SFTP-Verbindung fehlgeschlagen', ['host' => $host]);
            return self::error('Keine Verbindung zu ' . $host . ':' . $port . '.', true);
        }

        $remoteRoot = self::cleanPath((string) $target['remote_path']);
        $started = microtime(true);
        $done = 0;

        foreach ($files as $relative) {
            if (microtime(true) - $started > $budget) {
                return self::error(
                    sprintf('Zeit abgelaufen nach %d von %d Dateien. Der Auftrag wird fortgesetzt.', $done, count($files)),
                    true
                );
            }

            $remote = $remoteRoot . '/' . $relative;
            $directory = dirname($remote);

            if (!$sftp->is_dir($directory)) {
                $sftp->mkdir($directory, -1, true);
            }

            if (!$sftp->put($remote, $source . '/' . $relative, SFTP::SOURCE_LOCAL_FILE)) {
                return self::error('Die Datei ' . $relative . ' liess sich nicht schreiben.', true);
            }

            $done++;
            if ($onProgress !== null) {
                $onProgress($done, count($files), $relative);
            }
        }

        $sftp->disconnect();

        return ['ok' => true, 'files' => $done, 'error' => '', 'retryable' => false, 'verified' => false, 'url' => ''];
    }

    // ------------------------------------------------------------------
    // FTP und FTPS
    // ------------------------------------------------------------------

    private static function viaFtp(
        array $target,
        string $password,
        string $source,
        array $files,
        ?callable $onProgress,
        float $budget,
        bool $secure
    ): array {
        if (!function_exists('ftp_connect')) {
            return self::error('Die PHP-Erweiterung "ftp" fehlt auf diesem Server.', false);
        }

        $host = (string) $target['host'];
        $port = (int) $target['port'] ?: 21;

        $connection = $secure
            ? @ftp_ssl_connect($host, $port, 20)
            : @ftp_connect($host, $port, 20);

        if ($connection === false) {
            return self::error(
                'Keine Verbindung zu ' . $host . ':' . $port . '.'
                . ($secure ? ' Unterstützt der Anbieter FTP mit Verschlüsselung?' : ''),
                true
            );
        }

        if (!@ftp_login($connection, (string) $target['username'], $password)) {
            @ftp_close($connection);
            return self::error('Anmeldung abgelehnt. Bitte Benutzername und Passwort prüfen.', false);
        }

        // Fast alle Hosting-Anbieter brauchen den passiven Modus.
        @ftp_pasv($connection, true);

        $remoteRoot = self::cleanPath((string) $target['remote_path']);
        $created = [];
        $started = microtime(true);
        $done = 0;

        foreach ($files as $relative) {
            if (microtime(true) - $started > $budget) {
                @ftp_close($connection);
                return self::error(
                    sprintf('Zeit abgelaufen nach %d von %d Dateien. Der Auftrag wird fortgesetzt.', $done, count($files)),
                    true
                );
            }

            $remote = $remoteRoot . '/' . $relative;
            $directory = dirname($remote);

            if (!isset($created[$directory])) {
                self::ensureRemoteDir($connection, $directory);
                $created[$directory] = true;
            }

            if (!@ftp_put($connection, $remote, $source . '/' . $relative, FTP_BINARY)) {
                @ftp_close($connection);
                return self::error(
                    'Die Datei ' . $relative . ' liess sich nicht schreiben. '
                    . 'Stimmt das Zielverzeichnis, und ist genug Platz frei?',
                    true
                );
            }

            $done++;
            if ($onProgress !== null) {
                $onProgress($done, count($files), $relative);
            }
        }

        @ftp_close($connection);

        return ['ok' => true, 'files' => $done, 'error' => '', 'retryable' => false, 'verified' => false, 'url' => ''];
    }

    /** Verzeichnisse Stück für Stück anlegen. */
    private static function ensureRemoteDir($connection, string $path): void
    {
        $parts = array_filter(explode('/', trim($path, '/')));
        $current = '';

        foreach ($parts as $part) {
            $current .= '/' . $part;
            if (@ftp_chdir($connection, $current)) {
                continue;
            }
            @ftp_mkdir($connection, $current);
        }
        @ftp_chdir($connection, '/');
    }

    // ------------------------------------------------------------------

    /** Zugangsdaten speichern – das Passwort verschlüsselt. */
    public static function saveTarget(int $projectId, array $data): int
    {
        $existing = Db::first(
            'SELECT id, secret FROM deploy_targets WHERE project_id = :p ORDER BY id DESC LIMIT 1',
            ['p' => $projectId]
        );

        $password = (string) ($data['password'] ?? '');

        // Leeres Feld heisst "unverändert lassen", nicht "löschen".
        $secret = $password !== ''
            ? Crypto::encrypt($password)
            : (string) ($existing['secret'] ?? '');

        $values = [
            'protocol' => in_array($data['protocol'] ?? '', ['ftp', 'ftps', 'sftp'], true) ? $data['protocol'] : 'sftp',
            'host' => mb_substr(trim((string) ($data['host'] ?? '')), 0, 190),
            'port' => max(1, min(65535, (int) ($data['port'] ?? 22))),
            'username' => mb_substr(trim((string) ($data['username'] ?? '')), 0, 190),
            'secret' => $secret,
            'remote_path' => self::cleanPath((string) ($data['path'] ?? '/public_html')),
            'updated_at' => Db::now(),
        ];

        if ($existing !== null) {
            Db::update('deploy_targets', $values, 'id = :id', ['id' => (int) $existing['id']]);
            return (int) $existing['id'];
        }

        return Db::insert('deploy_targets', array_merge($values, [
            'project_id' => $projectId,
            'created_at' => Db::now(),
        ]));
    }

    /**
     * Nur die Verbindung prüfen, ohne etwas hochzuladen.
     * Damit lässt sich vor dem grossen Upload klären, ob die Angaben stimmen.
     */
    public static function test(int $projectId): array
    {
        $target = Db::first(
            'SELECT * FROM deploy_targets WHERE project_id = :p ORDER BY id DESC LIMIT 1',
            ['p' => $projectId]
        );

        if ($target === null) {
            return ['ok' => false, 'message' => 'Es sind keine Zugangsdaten hinterlegt.'];
        }

        $password = Crypto::decrypt((string) ($target['secret'] ?? ''));
        if ($password === null) {
            return ['ok' => false, 'message' => 'Die Zugangsdaten lassen sich nicht entschlüsseln.'];
        }

        $host = (string) $target['host'];
        $port = (int) $target['port'];
        $user = (string) $target['username'];
        $path = self::cleanPath((string) $target['remote_path']);

        try {
            if ((string) $target['protocol'] === 'sftp') {
                if (!class_exists(SFTP::class)) {
                    return ['ok' => false, 'message' => 'Die Bibliothek für SFTP fehlt.'];
                }

                $sftp = new SFTP($host, $port ?: 22, 15);
                if (!$sftp->login($user, $password)) {
                    return ['ok' => false, 'message' => 'Anmeldung abgelehnt. Benutzername oder Passwort stimmt nicht.'];
                }

                $exists = $sftp->is_dir($path);
                $sftp->disconnect();

                return $exists
                    ? ['ok' => true, 'message' => 'Verbindung steht, das Verzeichnis ' . $path . ' ist vorhanden.']
                    : ['ok' => false, 'message' => 'Anmeldung hat geklappt, aber ' . $path . ' gibt es dort nicht.'];
            }

            $connection = (string) $target['protocol'] === 'ftps'
                ? @ftp_ssl_connect($host, $port ?: 21, 15)
                : @ftp_connect($host, $port ?: 21, 15);

            if ($connection === false) {
                return ['ok' => false, 'message' => 'Keine Verbindung zu ' . $host . ':' . $port . '.'];
            }
            if (!@ftp_login($connection, $user, $password)) {
                @ftp_close($connection);
                return ['ok' => false, 'message' => 'Anmeldung abgelehnt. Benutzername oder Passwort stimmt nicht.'];
            }

            @ftp_pasv($connection, true);
            $exists = @ftp_chdir($connection, $path);
            @ftp_close($connection);

            return $exists
                ? ['ok' => true, 'message' => 'Verbindung steht, das Verzeichnis ' . $path . ' ist vorhanden.']
                : ['ok' => false, 'message' => 'Anmeldung hat geklappt, aber ' . $path . ' gibt es dort nicht.'];
        } catch (\Throwable $e) {
            Logger::warning('Verbindungstest fehlgeschlagen', ['host' => $host]);
            return ['ok' => false, 'message' => 'Die Verbindung ist fehlgeschlagen.'];
        } finally {
            sodium_memzero($password);
        }
    }

    /** Ist die Website nach dem Hochladen erreichbar? */
    private static function verify(array $project): array
    {
        $domain = trim((string) ($project['domain'] ?? ''));
        if ($domain === '') {
            return ['ok' => false, 'url' => ''];
        }

        $url = 'https://' . $domain . '/';
        $response = Http::get($url, 12);

        if (!$response['ok']) {
            $response = Http::get('http://' . $domain . '/', 12);
            $url = 'http://' . $domain . '/';
        }

        return [
            'ok' => $response['ok'] && str_contains($response['body'], '<html'),
            'url' => $url,
        ];
    }

    /** @return list<string> Pfade relativ zum Quellordner */
    private static function collect(string $directory): array
    {
        $files = [];

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if (!$item->isFile() || $item->isLink()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($directory) + 1));
            if (!str_contains($relative, '..')) {
                $files[] = $relative;
            }
        }

        sort($files);
        return $files;
    }

    private static function cleanPath(string $path): string
    {
        $path = '/' . trim(str_replace('\\', '/', $path), '/');
        $path = preg_replace('#/+#', '/', $path) ?? '/';

        // Kein Ausbrechen aus dem Zielverzeichnis
        $path = str_replace('..', '', $path);

        return rtrim($path, '/') ?: '/';
    }

    private static function error(string $message, bool $retryable): array
    {
        return [
            'ok' => false, 'files' => 0, 'error' => $message,
            'retryable' => $retryable, 'verified' => false, 'url' => '',
        ];
    }
}
