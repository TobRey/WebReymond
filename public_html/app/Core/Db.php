<?php

declare(strict_types=1);

namespace WebAtze\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Datenbankzugriff.
 *
 * Läuft auf MySQL (auf dem Server) und SQLite (für die automatischen Tests).
 * Es gibt keinen Weg an vorbereiteten Anweisungen vorbei – Werte werden
 * ausnahmslos als Parameter übergeben, nie in SQL hineingeschrieben.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $driver = (string) Config::get('db.driver', 'mysql');

        try {
            if ($driver === 'sqlite') {
                $path = (string) Config::get('db.path');
                ensure_dir(dirname($path));
                self::$pdo = new PDO('sqlite:' . $path, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                self::$pdo->exec('PRAGMA foreign_keys = ON');
                self::$pdo->exec('PRAGMA journal_mode = WAL');
                self::$pdo->exec('PRAGMA busy_timeout = 5000');
            } else {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    (string) Config::get('db.host', 'localhost'),
                    (int) Config::get('db.port', 3306),
                    (string) Config::get('db.database'),
                    (string) Config::get('db.charset', 'utf8mb4')
                );
                self::$pdo = new PDO(
                    $dsn,
                    (string) Config::get('db.username'),
                    (string) Config::get('db.password'),
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                        // Ohne das zählt MySQL bei UPDATE nur die Zeilen,
                        // in denen sich wirklich etwas geändert hat -
                        // SQLite zählt die getroffenen. Wer denselben Wert
                        // noch einmal setzt, bekäme auf dem Hosting «nichts
                        // passiert» und lokal «erledigt». Damit verhalten
                        // sich beide gleich.
                        PDO::MYSQL_ATTR_FOUND_ROWS => true,
                        PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'",
                    ]
                );
            }
        } catch (PDOException $e) {
            // Die Meldung enthält Zugangsdaten – deshalb nur ins Protokoll.
            Logger::error('Datenbankverbindung fehlgeschlagen', ['driver' => $driver]);
            throw new RuntimeException(
                'Die Datenbank ist nicht erreichbar. Bitte Zugangsdaten in app/config.php prüfen.',
                0,
                $e
            );
        }

        return self::$pdo;
    }

    /** Nur für Tests: bestehende Verbindung übernehmen. */
    public static function setConnection(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    /**
     * Welche Datenbank tatsächlich am anderen Ende hängt.
     *
     * Gefragt wird die Verbindung, nicht die Konfiguration: setConnection()
     * kann eine andere untergeschoben haben, und dann erzeugte eine
     * Antwort aus der Konfiguration Anweisungen für die falsche Datenbank.
     */
    public static function isSqlite(): bool
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        }

        return Config::get('db.driver', 'mysql') === 'sqlite';
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        [$sql, $params] = self::spreadRepeated($sql, $params);

        $statement = self::connection()->prepare($sql);
        $statement->execute(self::normalise($params));
        return $statement;
    }

    /**
     * Denselben Platzhalter mehrfach benutzen dürfen.
     *
     * SQLite erlaubt «:leer» zweimal in einer Abfrage, MySQL mit echten
     * Prepared Statements nicht. Das hat hier schon zweimal zu einer
     * Fehlerseite geführt, die nur auf dem Hosting auftrat - und beim
     * zweiten Mal in einer Abfrage, die aus Stücken zusammengesetzt war
     * und deshalb keiner Suche im Quelltext auffiel.
     *
     * Statt die Regel zu wiederholen, wird sie hier aufgehoben: Wiederholte
     * Platzhalter bekommen eigene Namen und denselben Wert. Damit
     * verhalten sich beide Datenbanken gleich, und niemand muss mehr
     * daran denken.
     *
     * Zeichenketten im SQL bleiben unangetastet - ein Doppelpunkt in
     * einem Text ist kein Platzhalter.
     *
     * @param array<string, mixed> $params
     * @return array{0:string, 1:array<string, mixed>}
     */
    private static function spreadRepeated(string $sql, array $params): array
    {
        if ($params === [] || !str_contains($sql, ':')) {
            return [$sql, $params];
        }

        $gesehen = [];
        $neu = '';
        $inText = false;
        $laenge = strlen($sql);

        for ($i = 0; $i < $laenge; $i++) {
            $zeichen = $sql[$i];

            if ($zeichen === "'") {
                // Zwei Anführungszeichen hintereinander sind eines im Text.
                $inText = !$inText || ($sql[$i + 1] ?? '') !== "'";
                $neu .= $zeichen;
                continue;
            }

            if ($inText || $zeichen !== ':') {
                $neu .= $zeichen;
                continue;
            }

            // Ein Platzhalter beginnt: Namen einlesen.
            $name = '';
            $j = $i + 1;

            while ($j < $laenge && preg_match('/[A-Za-z0-9_]/', $sql[$j]) === 1) {
                $name .= $sql[$j];
                $j++;
            }

            if ($name === '' || !array_key_exists($name, $params)) {
                $neu .= $zeichen;
                continue;
            }

            $wievielt = ($gesehen[$name] ?? 0) + 1;
            $gesehen[$name] = $wievielt;

            if ($wievielt === 1) {
                $neu .= ':' . $name;
            } else {
                $ersatz = $name . '__' . $wievielt;
                $params[$ersatz] = $params[$name];
                $neu .= ':' . $ersatz;
            }

            $i = $j - 1;
        }

        return [$neu, $params];
    }

    /** Eine Zeile oder null. */
    public static function first(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** Alle Zeilen. */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** Einzelwert der ersten Spalte. */
    public static function value(string $sql, array $params = [], mixed $default = null): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? $default : $value;
    }

    /** Datensatz einfügen, gibt die neue ID zurück. */
    public static function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::quoteIdentifier($table),
            implode(', ', array_map([self::class, 'quoteIdentifier'], $columns)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns))
        );
        self::run($sql, $data);
        return (int) self::connection()->lastInsertId();
    }

    /** Datensätze ändern, gibt die Anzahl betroffener Zeilen zurück. */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        $params = [];
        foreach ($data as $column => $value) {
            $sets[] = self::quoteIdentifier($column) . ' = :set_' . $column;
            $params['set_' . $column] = $value;
        }
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            self::quoteIdentifier($table),
            implode(', ', $sets),
            $where
        );
        return self::run($sql, array_merge($params, $whereParams))->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        $sql = sprintf('DELETE FROM %s WHERE %s', self::quoteIdentifier($table), $where);
        return self::run($sql, $params)->rowCount();
    }

    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        $ownTransaction = !$pdo->inTransaction();

        if ($ownTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($ownTransaction) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Tabellen- und Spaltennamen absichern.
     * Sie kommen ausschliesslich aus dem eigenen Code, nie aus einer Eingabe –
     * diese Prüfung ist die zweite Sicherung für den Fall eines Programmierfehlers.
     */
    public static function quoteIdentifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new RuntimeException('Unzulässiger Bezeichner: ' . $name);
        }
        return self::isSqlite() ? '"' . $name . '"' : '`' . $name . '`';
    }

    /** SQL-Schnipsel, der in beiden Datenbanken "jetzt" bedeutet. */
    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /** Booleans zu 0/1, Arrays zu JSON – SQLite mag keine echten Booleans. */
    private static function normalise(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            if (is_bool($value)) {
                $out[$key] = $value ? 1 : 0;
            } elseif (is_array($value)) {
                $out[$key] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif ($value instanceof \DateTimeInterface) {
                $out[$key] = $value->format('Y-m-d H:i:s');
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}
