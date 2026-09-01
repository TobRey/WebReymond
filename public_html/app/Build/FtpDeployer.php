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
    /** Beim Herunterladen für die Sicherung: höchstens so viele Dateien. */
    private const MAX_FETCH_FILES = 500;

    /** Und keine einzelne grösser als das. */
    private const MAX_FETCH_BYTES = 8 * 1024 * 1024;

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
            return self::error(
                'Dieser Server kann kein FTP - die passende PHP-Erweiterung ist nicht '
                . 'eingebaut. Stelle unter Veroeffentlichen auf SFTP um (Port 22). '
                . 'Das bringt WebAtze selbst mit und funktioniert ueberall.',
                false
            );
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

    // ------------------------------------------------------------------
    // Herunterladen (für die Sicherung)
    // ------------------------------------------------------------------

    /**
     * Einen Ordner vom Server des Kunden holen.
     *
     * Gedacht für die tägliche Sicherung: Nur "data" interessiert, dort
     * liegen die Anfragen und die Änderungen des Kunden. Alles andere
     * lässt sich neu bauen.
     *
     * Bewusst eng gefasst: kein Ausstieg aus dem Ordner, eine Grenze für
     * Anzahl und Grösse, ein Zeitbudget. Ein Server, der zu viel liefert,
     * darf den Worker nicht ausbremsen.
     *
     * @return array<string, string> Pfad innerhalb des Ordners => Inhalt
     */
    public static function fetchDirectory(array $target, string $folder, float $budget = 25.0): array
    {
        $folder = trim($folder, '/');

        if ($folder === '' || str_contains($folder, '..')) {
            return [];
        }

        $password = Crypto::decrypt((string) ($target['secret'] ?? ''));
        if ($password === null) {
            return [];
        }

        $remote = self::cleanPath((string) $target['remote_path']) . '/' . $folder;

        try {
            $files = (string) $target['protocol'] === 'sftp'
                ? self::fetchViaSftp($target, $password, $remote, $budget)
                : self::fetchViaFtp($target, $password, $remote, $budget, (string) $target['protocol'] === 'ftps');
        } finally {
            sodium_memzero($password);
        }

        return $files;
    }

    /** @return array<string, string> */
    private static function fetchViaSftp(array $target, string $password, string $remote, float $budget): array
    {
        if (!class_exists(SFTP::class)) {
            return [];
        }

        $sftp = new SFTP((string) $target['host'], (int) $target['port'] ?: 22, 20);

        if (!$sftp->login((string) $target['username'], $password)) {
            return [];
        }

        $out = [];
        $started = microtime(true);

        $list = $sftp->nlist($remote, true);

        foreach (is_array($list) ? $list : [] as $entry) {
            if (microtime(true) - $started > $budget || count($out) >= self::MAX_FETCH_FILES) {
                break;
            }

            $name = ltrim((string) $entry, './');

            if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '..')) {
                continue;
            }

            if ($sftp->is_dir($remote . '/' . $name)) {
                continue;
            }

            $content = $sftp->get($remote . '/' . $name);

            if (is_string($content) && strlen($content) <= self::MAX_FETCH_BYTES) {
                $out[$name] = $content;
            }
        }

        $sftp->disconnect();

        return $out;
    }

    /** @return array<string, string> */
    private static function fetchViaFtp(
        array $target,
        string $password,
        string $remote,
        float $budget,
        bool $secure
    ): array {
        if (!function_exists('ftp_connect')) {
            return [];
        }

        $host = (string) $target['host'];
        $port = (int) $target['port'] ?: 21;

        $connection = $secure ? @ftp_ssl_connect($host, $port, 20) : @ftp_connect($host, $port, 20);

        if ($connection === false) {
            return [];
        }

        if (!@ftp_login($connection, (string) $target['username'], $password)) {
            @ftp_close($connection);
            return [];
        }

        @ftp_pasv($connection, true);

        $out = [];
        $started = microtime(true);
        $names = @ftp_nlist($connection, $remote);

        foreach (is_array($names) ? $names : [] as $entry) {
            if (microtime(true) - $started > $budget || count($out) >= self::MAX_FETCH_FILES) {
                break;
            }

            $name = basename((string) $entry);

            if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '..')) {
                continue;
            }

            $size = @ftp_size($connection, $remote . '/' . $name);

            // -1 heisst: kein Grössenwert – meistens ein Unterordner.
            if ($size < 0 || $size > self::MAX_FETCH_BYTES) {
                continue;
            }

            $stream = fopen('php://temp', 'r+');

            if ($stream === false) {
                continue;
            }

            if (@ftp_fget($connection, $stream, $remote . '/' . $name, FTP_BINARY)) {
                rewind($stream);
                $out[$name] = (string) stream_get_contents($stream);
            }

            fclose($stream);
        }

        @ftp_close($connection);

        return $out;
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
     * Die Verbindung pruefen - und beim Verzeichnis helfen.
     *
     * Hier stand vorher ein FTP-Aufruf ohne die Pruefung,
     * ob es die FTP-Erweiterung auf diesem Server ueberhaupt gibt. Fehlt
     * sie - auf geteiltem Hosting oft der Fall - ist das kein Fehler,
     * den man abfangen kann, sondern ein Absturz: Fehler 500, ohne einen
     * Hinweis, woran es lag.
     *
     * Ausserdem sagte die Antwort frueher nur, dass das Verzeichnis
     * nicht da ist - nicht, welche es gibt. Bei einer Subdomain, deren
     * Ordner irgendwo unter public_html liegt, ist das der Unterschied
     * zwischen Raten und Wissen. Deshalb kommt jetzt eine Liste mit.
     *
     * @return array{ok:bool, message:string, ordner:array<int,string>, vorschlag:string}
     */
    public static function test(int $projectId): array
    {
        $target = Db::first(
            'SELECT * FROM deploy_targets WHERE project_id = :p ORDER BY id DESC LIMIT 1',
            ['p' => $projectId]
        );

        if ($target === null) {
            return self::pruefErgebnis(false, 'Es sind keine Zugangsdaten hinterlegt.');
        }

        $password = Crypto::decrypt((string) ($target['secret'] ?? ''));

        if ($password === null) {
            return self::pruefErgebnis(false, 'Die Zugangsdaten lassen sich nicht entschlüsseln.');
        }

        $host = (string) $target['host'];
        $port = (int) $target['port'];
        $user = (string) $target['username'];
        $protokoll = (string) $target['protocol'];
        $pfad = self::cleanPath((string) $target['remote_path']);

        // Ein Handschlag ueber SFTP kann auf schwachen Servern dauern.
        @set_time_limit(90);

        try {
            return $protokoll === 'sftp'
                ? self::testSftp($host, $port, $user, $password, $pfad)
                : self::testFtp($host, $port, $user, $password, $pfad, $protokoll === 'ftps');
        } catch (\Throwable $e) {
            Logger::warning('Verbindungstest fehlgeschlagen', [
                'host' => $host,
                'protokoll' => $protokoll,
                'grund' => $e->getMessage(),
            ]);

            return self::pruefErgebnis(false,
                'Die Verbindung ist fehlgeschlagen: ' . self::kurz($e->getMessage()));
        } finally {
            sodium_memzero($password);
        }
    }

    /** @return array{ok:bool, message:string, ordner:array<int,string>, vorschlag:string} */
    private static function testSftp(
        string $host,
        int $port,
        string $user,
        string $password,
        string $pfad
    ): array {
        if (!class_exists(SFTP::class)) {
            return self::pruefErgebnis(false, 'Die Bibliothek für SFTP fehlt im Paket.');
        }

        $sftp = new SFTP($host, $port ?: 22, 20);

        if (!$sftp->login($user, $password)) {
            return self::pruefErgebnis(false,
                'Anmeldung abgelehnt. Benutzername oder Passwort stimmt nicht. '
                . 'Bei cPanel gehoert oft der volle Name dazu, also '
                . 'benutzer@deine-domain.ch statt nur benutzer.');
        }

        $daHeim = (string) ($sftp->pwd() ?: '/');
        $ordner = self::verzeichnisseSftp($sftp, $pfad, $daHeim);
        $vorhanden = $sftp->is_dir($pfad);

        $sftp->disconnect();

        return self::pruefErgebnis(
            $vorhanden,
            $vorhanden
                ? 'Verbindung steht, das Verzeichnis ' . $pfad . ' ist vorhanden.'
                : 'Anmeldung hat geklappt, aber ' . $pfad . ' gibt es dort nicht.',
            $ordner,
            self::vorschlagen($ordner, $pfad)
        );
    }

    /** @return array{ok:bool, message:string, ordner:array<int,string>, vorschlag:string} */
    private static function testFtp(
        string $host,
        int $port,
        string $user,
        string $password,
        string $pfad,
        bool $verschluesselt
    ): array {
        // Die Pruefung, die hier gefehlt hat. Ohne sie stuerzt der ganze
        // Aufruf ab, statt zu sagen, was los ist.
        if (!function_exists('ftp_connect')) {
            return self::pruefErgebnis(false,
                'Dieser Server kann kein FTP - die passende PHP-Erweiterung ist nicht '
                . 'eingebaut. Stelle im Formular auf SFTP um (Port 22). Das bringt '
                . 'WebAtze selbst mit, funktioniert ueberall und ist ausserdem '
                . 'verschluesselt.');
        }

        $verbindung = $verschluesselt
            ? @ftp_ssl_connect($host, $port ?: 21, 20)
            : @ftp_connect($host, $port ?: 21, 20);

        if ($verbindung === false) {
            return self::pruefErgebnis(false,
                'Keine Verbindung zu ' . $host . ':' . ($port ?: 21) . '. '
                . 'Stimmt die Adresse, und ist der Port richtig? FTP ist 21, SFTP ist 22.');
        }

        if (!@ftp_login($verbindung, $user, $password)) {
            @ftp_close($verbindung);

            return self::pruefErgebnis(false,
                'Anmeldung abgelehnt. Benutzername oder Passwort stimmt nicht. '
                . 'Bei cPanel gehoert oft der volle Name dazu, also '
                . 'benutzer@deine-domain.ch statt nur benutzer.');
        }

        @ftp_pasv($verbindung, true);

        $daHeim = (string) (@ftp_pwd($verbindung) ?: '/');
        $ordner = self::verzeichnisseFtp($verbindung, $pfad, $daHeim);
        $vorhanden = @ftp_chdir($verbindung, $pfad);

        @ftp_close($verbindung);

        return self::pruefErgebnis(
            $vorhanden,
            $vorhanden
                ? 'Verbindung steht, das Verzeichnis ' . $pfad . ' ist vorhanden.'
                : 'Anmeldung hat geklappt, aber ' . $pfad . ' gibt es dort nicht.',
            $ordner,
            self::vorschlagen($ordner, $pfad)
        );
    }

    /**
     * Welche Verzeichnisse gibt es dort?
     *
     * Gesucht wird an drei Stellen: im gewuenschten Pfad, in dessen
     * Elternverzeichnis und im Heimatverzeichnis des Zugangs. Bei einer
     * Subdomain liegt der Ordner meist unter public_html und heisst wie
     * die Subdomain - wer das sieht, muss nicht mehr raten.
     *
     * @return array<int, string>
     */
    private static function verzeichnisseSftp(SFTP $sftp, string $pfad, string $heim): array
    {
        $gefunden = [];

        foreach (self::suchorte($pfad, $heim) as $ort) {
            $liste = $sftp->nlist($ort);

            if (!is_array($liste)) {
                continue;
            }

            foreach ($liste as $name) {
                $name = (string) $name;

                if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                    continue;
                }

                $voll = rtrim($ort, '/') . '/' . basename($name);

                if ($sftp->is_dir($voll)) {
                    $gefunden[] = $voll;
                }
            }
        }

        return self::aufraeumen($gefunden);
    }

    /** @return array<int, string> */
    private static function verzeichnisseFtp($verbindung, string $pfad, string $heim): array
    {
        $gefunden = [];

        foreach (self::suchorte($pfad, $heim) as $ort) {
            $liste = @ftp_rawlist($verbindung, $ort);

            if (!is_array($liste)) {
                continue;
            }

            foreach ($liste as $zeile) {
                // "drwxr-xr-x 2 user group 4096 Jan 1 12:00 name"
                if (!str_starts_with((string) $zeile, 'd')) {
                    continue;
                }

                $teile = preg_split('/\s+/', (string) $zeile, 9);
                $name = trim((string) ($teile[8] ?? ''));

                if ($name === '' || $name === '.' || $name === '..' || str_starts_with($name, '.')) {
                    continue;
                }

                $gefunden[] = rtrim($ort, '/') . '/' . $name;
            }
        }

        return self::aufraeumen($gefunden);
    }

    /**
     * Wo lohnt sich das Nachsehen?
     *
     * @return array<int, string>
     */
    private static function suchorte(string $pfad, string $heim): array
    {
        $orte = [$heim, $pfad, dirname($pfad)];

        // Bei einer Subdomain liegt der Ordner fast immer hier.
        foreach ([$heim, $pfad] as $basis) {
            $orte[] = rtrim($basis, '/') . '/public_html';
        }

        $sauber = [];

        foreach ($orte as $ort) {
            $ort = self::cleanPath($ort);

            if ($ort !== '' && !in_array($ort, $sauber, true)) {
                $sauber[] = $ort;
            }
        }

        return array_slice($sauber, 0, 5);
    }

    /**
     * Welcher Ordner passt am ehesten zum gewuenschten Pfad?
     *
     * @param array<int, string> $ordner
     */
    private static function vorschlagen(array $ordner, string $pfad): string
    {
        if ($ordner === []) {
            return '';
        }

        $gesucht = mb_strtolower(basename($pfad));

        foreach ($ordner as $eintrag) {
            if (mb_strtolower(basename($eintrag)) === $gesucht) {
                return $eintrag;
            }
        }

        return '';
    }

    /**
     * @param array<int, string> $liste
     * @return array<int, string>
     */
    private static function aufraeumen(array $liste): array
    {
        $liste = array_values(array_unique($liste));
        sort($liste);

        return array_slice($liste, 0, 40);
    }

    /**
     * @param array<int, string> $ordner
     * @return array{ok:bool, message:string, ordner:array<int,string>, vorschlag:string}
     */
    private static function pruefErgebnis(
        bool $ok,
        string $message,
        array $ordner = [],
        string $vorschlag = ''
    ): array {
        return ['ok' => $ok, 'message' => $message, 'ordner' => $ordner, 'vorschlag' => $vorschlag];
    }

    /** Technische Meldungen kurz halten - der Rest steht im Protokoll. */
    private static function kurz(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return mb_strlen($text) > 160 ? mb_substr($text, 0, 157) . '…' : $text;
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
