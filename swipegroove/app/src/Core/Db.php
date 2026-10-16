<?php
declare(strict_types=1);

namespace SwipeGroove\Core;

use PDO;
use PDOStatement;

/**
 * Dünne PDO-Hülle. Ausschliesslich vorbereitete Abfragen.
 */
final class Db
{
    private PDO $pdo;
    private int $txDepth = 0;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function connect(string $host, int $port, string $name, string $user, string $pass, ?string $socket = null): self
    {
        $dsn = $socket
            ? "mysql:unix_socket={$socket};dbname={$name};charset=utf8mb4"
            : "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION', time_zone='+00:00'",
        ]);
        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @param array<int|string,mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $key = is_int($k) ? $k + 1 : $k;
            $type = match (true) {
                is_int($v) => PDO::PARAM_INT,
                is_bool($v) => PDO::PARAM_INT,
                $v === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($key, is_bool($v) ? (int) $v : $v, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /** @return array<string,mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @return list<mixed> */
    public function column(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    public function exec(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /** @param array<string,mixed> $data */
    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')';
        $this->run($sql, array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        foreach (array_keys($data) as $c) {
            $sets[] = '`' . $c . '` = ?';
        }
        $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE ' . $where;
        return $this->exec($sql, array_merge(array_values($data), array_values($whereParams)));
    }

    public function lastId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Transaktion mit Unterstützung für Verschachtelung (nur äusserste wird committed).
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->txDepth === 0) {
            $this->pdo->beginTransaction();
        }
        $this->txDepth++;
        try {
            $result = $fn($this);
            $this->txDepth--;
            if ($this->txDepth === 0) {
                $this->pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            $this->txDepth--;
            if ($this->txDepth === 0 && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function inTransaction(): bool
    {
        return $this->txDepth > 0;
    }

    public function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
