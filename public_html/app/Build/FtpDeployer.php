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
        Crypto::wipe($password);

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

        $sauber = self::normalizeHost((string) $target['host'], (int) $target['port'] ?: 22);
        $host = $sauber['host'];
        $port = $sauber['port'];

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

        $sauber = self::normalizeHost((string) $target['host'], (int) $target['port'] ?: 21);
        $host = $sauber['host'];
        $port = $sauber['port'];

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

        // Und manche nennen darin eine interne Adresse, weil sie hinter
        // NAT stehen - auf geteiltem Hosting eher Regel als Ausnahme.
        // Der Verbindungsversuch geht dann an eine Adresse, die es von
        // hier aus nicht gibt, und das sieht aus wie eine Zeitueber-
        // schreitung. Merkt man an einer Auflistung, die leer bleibt,
        // obwohl die Anmeldung sass.
        if (defined('FTP_USEPASVADDRESS') && @ftp_nlist($connection, '.') === false) {
            @ftp_set_option($connection, FTP_USEPASVADDRESS, false);
        }

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
            Crypto::wipe($password);
        }

        return $files;
    }

    /** @return array<string, string> */
    private static function fetchViaSftp(array $target, string $password, string $remote, float $budget): array
    {
        if (!class_exists(SFTP::class)) {
            return [];
        }

        $sauber = self::normalizeHost((string) $target['host'], (int) $target['port'] ?: 22);
        $sftp = new SFTP($sauber['host'], $sauber['port'], 20);

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

        $sauber = self::normalizeHost((string) $target['host'], (int) $target['port'] ?: 21);
        $host = $sauber['host'];
        $port = $sauber['port'];

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

        // Leere Antwort trotz sitzender Anmeldung: fast immer eine
        // interne Adresse aus dem Passivmodus. Einmal ohne sie.
        if ($names === false && defined('FTP_USEPASVADDRESS')) {
            @ftp_set_option($connection, FTP_USEPASVADDRESS, false);
            $names = @ftp_nlist($connection, $remote);
        }

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

        // Was hier ankommt, ist kopiert - und oft ist mehr mitgekommen
        // als der Servername. Einmal zurechtruecken, bevor es in die
        // Datenbank geht: Sonst steht der Fehler dauerhaft darin und der
        // Test sucht ihn jedes Mal neu.
        $sauber = self::normalizeHost(
            (string) ($data['host'] ?? ''),
            (int) ($data['port'] ?? 22)
        );

        $values = [
            'protocol' => in_array($data['protocol'] ?? '', ['ftp', 'ftps', 'sftp'], true) ? $data['protocol'] : 'sftp',
            'host' => $sauber['host'],
            'port' => $sauber['port'],
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
     * Den Servernamen zurechtruecken.
     *
     * In dieses Feld wird kopiert, was der Anbieter irgendwo anzeigt -
     * und das ist oft mehr als ein Servername: ein "ftp://" davor, ein
     * Pfad dahinter, ein ":21" am Ende. Wortwoertlich verwendet ergibt
     * jedes davon denselben roten Kasten, und keiner sagt warum.
     *
     * @return array{host:string, port:int, hinweis:string}
     */
    public static function normalizeHost(string $host, int $port): array
    {
        $roh = trim($host);
        $hinweis = '';

        if (preg_match('#^([a-z][a-z0-9+.\-]*)://#i', $roh, $treffer) === 1) {
            $roh = substr($roh, strlen($treffer[0]));
            $hinweis = 'Das „' . $treffer[1] . '://“ gehoert nicht in dieses Feld.';
        }

        // "benutzer:passwort@server" - der Teil davor ist kein Server.
        $at = strrpos($roh, '@');

        if ($at !== false) {
            $roh = substr($roh, $at + 1);
            $hinweis = 'Der Benutzername gehoert in sein eigenes Feld, nicht vor den Server.';
        }

        $schraeg = strpos($roh, '/');

        if ($schraeg !== false) {
            $roh = substr($roh, 0, $schraeg);
            $hinweis = 'Der Pfad hinter dem Servernamen gehoert ins Feld „Verzeichnis“.';
        }

        // Ein angehaengter Port wandert ins Portfeld. Die Bedingung
        // schuetzt IPv6-Adressen, die selbst voller Doppelpunkte sind.
        if (preg_match('/^([^:]+):(\d{1,5})$/', $roh, $treffer) === 1) {
            $roh = $treffer[1];
            $port = max(1, min(65535, (int) $treffer[2]));
            $hinweis = 'Der Port stand am Servernamen und ist jetzt im Portfeld.';
        }

        $roh = trim($roh, " \t\n\r\0\x0B.");

        return [
            'host' => mb_substr($roh, 0, 190),
            'port' => max(1, min(65535, $port)),
            'hinweis' => $hinweis,
        ];
    }

    /**
     * Loest dieser Name auf?
     *
     * gethostbyname() gibt bei Misserfolg die Eingabe unveraendert
     * zurueck - das ist die Pruefung, die ohne zusaetzliche Erweiterung
     * ueberall funktioniert.
     */
    private static function loestAuf(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return @gethostbyname($host) !== $host;
    }

    /**
     * Die Verbindung pruefen - Stufe fuer Stufe.
     *
     * Frueher war das Ergebnis ein einziges Ja oder Nein, und es war am
     * Ende schlicht der Rueckgabewert von ftp_chdir(). Eine tadellose
     * Anmeldung mit falschem Ordner sah damit exakt so aus wie ein
     * Server, den es gar nicht gibt. Beides "Verbindung fehlgeschlagen",
     * beide Male dieselbe Ratlosigkeit.
     *
     * Jetzt wird jede Stufe einzeln gemeldet: Servername, Verbindung,
     * Anmeldung, Passivmodus, Startordner, Inhalt, Zielordner,
     * Schreibprobe. Die erste rote Stufe ist die Diagnose - und die
     * gruenen davor sind der Beweis, dass alles andere stimmt.
     *
     * @return array{ok:bool, message:string, stufen:array<int, array{name:string, ok:bool, info:string}>, ordner:array<int,string>, vorschlag:string}
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
            return self::pruefErgebnis(false, 'Die Zugangsdaten lassen sich nicht entschluesseln.');
        }

        $sauber = self::normalizeHost((string) $target['host'], (int) $target['port']);
        $host = $sauber['host'];
        $port = $sauber['port'];
        $user = (string) $target['username'];
        $protokoll = (string) $target['protocol'];
        $pfad = self::cleanPath((string) $target['remote_path']);

        // Ein Handschlag ueber SFTP kann auf schwachen Servern dauern.
        @set_time_limit(90);

        $stufen = [];

        // ------------------------------------------------- Stufe: Name
        if (!self::loestAuf($host)) {
            $stufen[] = self::stufe('Servername', false, self::namensHilfe($host));

            Crypto::wipe($password);

            return self::pruefErgebnis(false, self::namensHilfe($host), [], '', $stufen);
        }

        $stufen[] = self::stufe('Servername', true, $host . ' loest auf.');

        if ($sauber['hinweis'] !== '') {
            $stufen[] = self::stufe('Hinweis', true, $sauber['hinweis']);
        }

        try {
            return $protokoll === 'sftp'
                ? self::testSftp($host, $port, $user, $password, $pfad, $stufen)
                : self::testFtp($host, $port, $user, $password, $pfad, $protokoll === 'ftps', $stufen);
        } catch (\Throwable $e) {
            Logger::warning('Verbindungstest fehlgeschlagen', [
                'host' => $host,
                'protokoll' => $protokoll,
                'grund' => $e->getMessage(),
            ]);

            $stufen[] = self::stufe('Abbruch', false, self::kurz($e->getMessage()));

            return self::pruefErgebnis(
                false,
                'Die Verbindung ist fehlgeschlagen: ' . self::kurz($e->getMessage()),
                [],
                '',
                $stufen
            );
        } finally {
            Crypto::wipe($password);
        }
    }

    /**
     * Was tun, wenn es den Servernamen nicht gibt?
     *
     * Genau hier faellt der haeufigste Fall an: "ftp." vor die Domain
     * geschrieben, weil es bei manchen Anbietern so heisst. Bei cPanel -
     * und damit bei GoDaddy - gibt es diesen Eintrag nicht.
     */
    private static function namensHilfe(string $host): string
    {
        $meldung = 'Diesen Servernamen gibt es nicht: ' . $host
            . '. Es wurde also nie ein Server abgewiesen - es wurde keiner gefunden.';

        if (str_starts_with($host, 'ftp.')) {
            $ohne = substr($host, 4);

            if (self::loestAuf($ohne)) {
                return $meldung . ' Nimm ' . $ohne . ' ohne das „ftp.“ davor:'
                    . ' bei cPanel (und damit bei GoDaddy) gibt es keinen ftp-Eintrag.';
            }

            return $meldung . ' Bei cPanel gibt es kein „ftp.“ vor der Domain.'
                . ' Nimm die Domain selbst oder den Servernamen, der in cPanel'
                . ' rechts unter „Allgemeine Informationen“ steht.';
        }

        return $meldung . ' Tippfehler? Sonst hilft der Servername aus cPanel'
            . ' (rechts unter „Allgemeine Informationen“) oder die Domain selbst.';
    }

    /** Eine einzelne Stufe des Tests. */
    private static function stufe(string $name, bool $ok, string $info): array
    {
        return ['name' => $name, 'ok' => $ok, 'info' => $info];
    }

    /**
     * Der Ordner, der vermutlich gemeint ist.
     *
     * Zwei Faelle, und sie sehen von aussen gleich aus:
     *
     * Ein cPanel-Unterkonto (Benutzername mit @) wird beim Anlegen auf
     * sein Verzeichnis festgenagelt. Nach der Anmeldung ist man bereits
     * darin - der Pfad, den man in cPanel gesehen hat, existiert von
     * dort aus nicht mehr, weil er die Wurzel geworden ist. Richtig ist
     * dann "/".
     *
     * Das Hauptkonto sieht dagegen public_html neben sich liegen. Dann
     * ist der volle Pfad richtig.
     *
     * Unterschieden wird an dem, was oben liegt: eine Startseite an der
     * Wurzel heisst festgenagelt, ein public_html daneben heisst
     * Hauptkonto.
     *
     * @param array<int, string> $obenAuf Namen im Startordner
     */
    private static function ordnerVorschlag(array $obenAuf, string $heim, string $user): string
    {
        $klein = array_map('mb_strtolower', $obenAuf);

        foreach (['public_html', 'httpdocs', 'www', 'web'] as $name) {
            if (in_array($name, $klein, true)) {
                return self::cleanPath(rtrim($heim, '/') . '/' . $name);
            }
        }

        // Keine Web-Wurzel daneben, aber eine Startseite darin: Das
        // Konto sitzt bereits im Zielordner.
        foreach (['index.html', 'index.php', 'index.htm', 'wp-config.php', 'assets'] as $name) {
            if (in_array($name, $klein, true)) {
                return $heim === '' ? '/' : self::cleanPath($heim);
            }
        }

        // Ein Unterkonto ohne erkennbaren Inhalt: Die @-Form ist bei
        // cPanel der zuverlaessigste Hinweis auf ein festgenageltes Konto.
        if (str_contains($user, '@')) {
            return $heim === '' ? '/' : self::cleanPath($heim);
        }

        return '';
    }

    /** @return array{ok:bool, message:string, stufen:array<int, array{name:string, ok:bool, info:string}>, ordner:array<int,string>, vorschlag:string} */
    private static function testSftp(
        string $host,
        int $port,
        string $user,
        string $password,
        string $pfad,
        array $stufen = []
    ): array {
        if (!class_exists(SFTP::class)) {
            $stufen[] = self::stufe('Verbindung', false, 'Die Bibliothek fuer SFTP fehlt im Paket.');

            return self::pruefErgebnis(false, 'Die Bibliothek fuer SFTP fehlt im Paket.', [], '', $stufen);
        }

        $sftp = new SFTP($host, $port ?: 22, 20);

        if (!$sftp->isConnected()) {
            $meldung = 'Der Server ist da, nimmt auf Port ' . ($port ?: 22)
                . ' aber keine SSH-Verbindung an. Bei cPanel ist SFTP oft nicht'
                . ' freigeschaltet - dann ist FTP auf Port 21 der richtige Weg.';
            $stufen[] = self::stufe('Verbindung', false, $meldung);

            return self::pruefErgebnis(false, $meldung, [], '', $stufen);
        }

        $stufen[] = self::stufe('Verbindung', true, 'Port ' . ($port ?: 22) . ' antwortet.');

        if (!$sftp->login($user, $password)) {
            $meldung = self::anmeldeHilfe($user);
            $stufen[] = self::stufe('Anmeldung', false, $meldung);

            return self::pruefErgebnis(false, $meldung, [], '', $stufen);
        }

        $stufen[] = self::stufe('Anmeldung', true, 'Benutzer ' . $user . ' angenommen.');

        $daHeim = (string) ($sftp->pwd() ?: '/');
        $stufen[] = self::stufe('Startordner', true, 'Nach der Anmeldung stehst du in ' . $daHeim . '.');

        $obenAuf = [];

        foreach ((array) ($sftp->nlist($daHeim) ?: []) as $eintrag) {
            $name = basename((string) $eintrag);

            if ($name !== '' && $name !== '.' && $name !== '..') {
                $obenAuf[] = $name;
            }
        }

        $stufen[] = self::stufe(
            'Inhalt lesen',
            $obenAuf !== [],
            $obenAuf === []
                ? 'Der Startordner liess sich nicht auflisten.'
                : count($obenAuf) . ' Eintraege im Startordner.'
        );

        $ordner = self::verzeichnisseSftp($sftp, $pfad, $daHeim);
        $vorhanden = $sftp->is_dir($pfad);

        $stufen[] = self::stufe(
            'Zielordner',
            $vorhanden,
            $vorhanden
                ? $pfad . ' ist vorhanden.'
                : $pfad . ' gibt es von diesem Zugang aus nicht.'
        );

        if ($vorhanden) {
            $probe = rtrim($pfad, '/') . '/.webatze-probe-' . bin2hex(random_bytes(4));
            $konnte = $sftp->put($probe, 'webatze');

            if ($konnte) {
                $sftp->delete($probe);
            }

            $stufen[] = self::stufe(
                'Schreibprobe',
                (bool) $konnte,
                $konnte
                    ? 'Datei angelegt und wieder entfernt - der Zugang darf schreiben.'
                    : 'Anmeldung und Ordner stimmen, aber der Zugang darf dort nicht schreiben.'
            );

            $vorhanden = (bool) $konnte;
        }

        $sftp->disconnect();

        $vorschlag = $vorhanden ? '' : self::ordnerVorschlag($obenAuf, $daHeim, $user);

        return self::pruefErgebnis(
            $vorhanden,
            $vorhanden
                ? 'Alles bereit: angemeldet, ' . $pfad . ' vorhanden und beschreibbar.'
                : self::zielHilfe($pfad, $vorschlag, $user),
            $ordner,
            $vorschlag !== '' ? $vorschlag : self::vorschlagen($ordner, $pfad),
            $stufen
        );
    }

    /** @return array{ok:bool, message:string, stufen:array<int, array{name:string, ok:bool, info:string}>, ordner:array<int,string>, vorschlag:string} */
    private static function testFtp(
        string $host,
        int $port,
        string $user,
        string $password,
        string $pfad,
        bool $verschluesselt,
        array $stufen = []
    ): array {
        if (!function_exists('ftp_connect')) {
            $meldung = 'Dieser Server kann kein FTP - die passende PHP-Erweiterung ist nicht '
                . 'eingebaut. Stelle im Formular auf SFTP um (Port 22). Das bringt '
                . 'WebAtze selbst mit und ist ausserdem verschluesselt.';
            $stufen[] = self::stufe('Verbindung', false, $meldung);

            return self::pruefErgebnis(false, $meldung, [], '', $stufen);
        }

        // Ohne diese Pruefung ist ein PHP ohne OpenSSL kein Fehler,
        // den man melden kann, sondern ein Absturz.
        if ($verschluesselt && !function_exists('ftp_ssl_connect')) {
            $meldung = 'Dieses PHP kann kein verschluesseltes FTP (es fehlt OpenSSL). '
                . 'Stelle auf einfaches FTP um oder - besser - auf SFTP.';
            $stufen[] = self::stufe('Verbindung', false, $meldung);

            return self::pruefErgebnis(false, $meldung, [], '', $stufen);
        }

        $port = $port ?: 21;

        $verbindung = $verschluesselt
            ? @ftp_ssl_connect($host, $port, 15)
            : @ftp_connect($host, $port, 15);

        if ($verbindung === false) {
            $meldung = 'Der Servername stimmt, aber auf Port ' . $port . ' antwortet nichts. '
                . ($port === 22
                    ? 'Port 22 ist SSH - fuer FTP ist es 21.'
                    : 'Ist der Port richtig? FTP ist 21, FTP mit Verschluesselung meist auch 21, SFTP ist 22.');
            $stufen[] = self::stufe('Verbindung', false, $meldung);

            return self::pruefErgebnis(false, $meldung, [], '', $stufen);
        }

        $stufen[] = self::stufe('Verbindung', true, 'Port ' . $port . ' antwortet.');

        if (!@ftp_login($verbindung, $user, $password)) {
            @ftp_close($verbindung);

            $meldung = self::anmeldeHilfe($user);
            $stufen[] = self::stufe('Anmeldung', false, $meldung);

            return self::pruefErgebnis(false, $meldung, [], '', $stufen);
        }

        $stufen[] = self::stufe('Anmeldung', true, 'Benutzer ' . $user . ' angenommen.');

        $passiv = @ftp_pasv($verbindung, true);
        $stufen[] = self::stufe(
            'Passivmodus',
            (bool) $passiv,
            $passiv
                ? 'Passivmodus aktiv - das ist der Modus, der durch Firewalls kommt.'
                : 'Der Server lehnt den Passivmodus ab. Ohne ihn scheitert jede Uebertragung.'
        );

        $daHeim = (string) (@ftp_pwd($verbindung) ?: '/');
        $stufen[] = self::stufe('Startordner', true, 'Nach der Anmeldung stehst du in ' . $daHeim . '.');

        $obenAuf = self::namenOben($verbindung, $daHeim);

        // Meldet der Server im Passivmodus eine interne Adresse - auf
        // geteiltem Hosting hinter NAT die Regel -, laeuft die
        // Datenverbindung ins Leere und sieht aus wie eine Zeitueber-
        // schreitung. Dann noch einmal, mit der Adresse, die wir kennen.
        if ($obenAuf === [] && defined('FTP_USEPASVADDRESS')) {
            @ftp_set_option($verbindung, FTP_USEPASVADDRESS, false);
            $obenAuf = self::namenOben($verbindung, $daHeim);

            if ($obenAuf !== []) {
                $stufen[] = self::stufe(
                    'Passivadresse',
                    true,
                    'Der Server nannte im Passivmodus eine interne Adresse. '
                    . 'Ich habe stattdessen die bekannte verwendet - das ist beim Hochladen genauso noetig.'
                );
            }
        }

        $stufen[] = self::stufe(
            'Inhalt lesen',
            $obenAuf !== [],
            $obenAuf === []
                ? 'Der Startordner liess sich nicht auflisten - meist eine blockierte Datenverbindung.'
                : count($obenAuf) . ' Eintraege im Startordner: ' . implode(', ', array_slice($obenAuf, 0, 8))
        );

        $ordner = self::verzeichnisseFtp($verbindung, $pfad, $daHeim);
        $vorhanden = @ftp_chdir($verbindung, $pfad);

        $stufen[] = self::stufe(
            'Zielordner',
            (bool) $vorhanden,
            $vorhanden
                ? $pfad . ' ist vorhanden.'
                : $pfad . ' gibt es von diesem Zugang aus nicht.'
        );

        if ($vorhanden) {
            $konnte = self::schreibprobeFtp($verbindung, $pfad);

            $stufen[] = self::stufe(
                'Schreibprobe',
                $konnte,
                $konnte
                    ? 'Datei angelegt und wieder entfernt - der Zugang darf schreiben.'
                    : 'Anmeldung und Ordner stimmen, aber der Zugang darf dort nicht schreiben.'
            );

            $vorhanden = $konnte;
        }

        @ftp_close($verbindung);

        $vorschlag = $vorhanden ? '' : self::ordnerVorschlag($obenAuf, $daHeim, $user);

        return self::pruefErgebnis(
            $vorhanden,
            $vorhanden
                ? 'Alles bereit: angemeldet, ' . $pfad . ' vorhanden und beschreibbar.'
                : self::zielHilfe($pfad, $vorschlag, $user),
            $ordner,
            $vorschlag !== '' ? $vorschlag : self::vorschlagen($ordner, $pfad),
            $stufen
        );
    }

    /**
     * Die Namen im Startordner - Dateien wie Ordner.
     *
     * @return array<int, string>
     */
    private static function namenOben($verbindung, string $heim): array
    {
        $namen = [];

        foreach ((array) (@ftp_nlist($verbindung, $heim) ?: []) as $eintrag) {
            $name = basename((string) $eintrag);

            if ($name !== '' && $name !== '.' && $name !== '..') {
                $namen[] = $name;
            }
        }

        return array_values(array_unique($namen));
    }

    /**
     * Darf dieser Zugang dort wirklich schreiben?
     *
     * Ohne diese Probe heisst "gruen" nur, dass der Ordner existiert.
     * Ein nur lesender Zugang faellt dann erst beim Hochladen auf - also
     * genau dann, wenn es eilig ist.
     */
    private static function schreibprobeFtp($verbindung, string $pfad): bool
    {
        $name = '.webatze-probe-' . bin2hex(random_bytes(4));
        $ziel = rtrim($pfad, '/') . '/' . $name;
        $tmp = tempnam(sys_get_temp_dir(), 'wa');

        if ($tmp === false) {
            return false;
        }

        file_put_contents($tmp, 'webatze');

        $ok = @ftp_put($verbindung, $ziel, $tmp, FTP_BINARY);

        @unlink($tmp);

        if ($ok) {
            @ftp_delete($verbindung, $ziel);
        }

        return (bool) $ok;
    }

    private static function anmeldeHilfe(string $user): string
    {
        $meldung = 'Der Server ist erreichbar, lehnt aber die Anmeldung ab. '
            . 'Benutzername oder Passwort stimmt nicht.';

        if (!str_contains($user, '@')) {
            return $meldung . ' Bei cPanel gehoert der volle Name dazu, also'
                . ' benutzer@deine-domain.ch statt nur benutzer.';
        }

        return $meldung . ' Das Passwort ist das, das beim Anlegen des FTP-Kontos'
            . ' vergeben wurde - nicht das cPanel-Passwort. In cPanel unter'
            . ' „FTP-Konten“ laesst es sich neu setzen.';
    }

    /** Was tun, wenn der Zielordner nicht passt? */
    private static function zielHilfe(string $pfad, string $vorschlag, string $user): string
    {
        $meldung = 'Anmeldung geklappt - aber ' . $pfad . ' gibt es von diesem Zugang aus nicht.';

        if ($vorschlag !== '' && $vorschlag !== $pfad) {
            $meldung .= ' Trage ' . $vorschlag . ' ein.';
        }

        if (str_contains($user, '@')) {
            $meldung .= ' Ein cPanel-Unterkonto (Name mit @) sitzt bereits in seinem Ordner:'
                . ' Was in cPanel als Verzeichnis stand, ist von hier aus die Wurzel „/“.';
        }

        return $meldung;
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
     * @param array<int, array{name:string, ok:bool, info:string}> $stufen
     * @return array{ok:bool, message:string, stufen:array<int, array{name:string, ok:bool, info:string}>, ordner:array<int,string>, vorschlag:string}
     */
    private static function pruefErgebnis(
        bool $ok,
        string $message,
        array $ordner = [],
        string $vorschlag = '',
        array $stufen = []
    ): array {
        return [
            'ok' => $ok,
            'message' => $message,
            'stufen' => $stufen,
            'ordner' => $ordner,
            'vorschlag' => $vorschlag,
        ];
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
