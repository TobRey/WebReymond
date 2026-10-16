<?php
declare(strict_types=1);

namespace SwipeGroove\Core;

/**
 * Versionsbasierte Migrationen: migrations/NNN_name.php gibt ein Array von SQL-Strings
 * (oder eine Closure(Db)) zurück. Bereits angewendete Versionen werden übersprungen.
 */
final class Migrator
{
    public function __construct(private Db $db, private string $dir)
    {
    }

    /** @return list<string> angewendete Versionen */
    public function migrate(): array
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS `schema_migrations` (
            `version` VARCHAR(64) NOT NULL PRIMARY KEY,
            `applied_at` DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $applied = array_flip($this->db->column('SELECT version FROM schema_migrations'));
        $files = glob($this->dir . '/*.php') ?: [];
        sort($files, SORT_NATURAL);
        $done = [];
        foreach ($files as $file) {
            $version = basename($file, '.php');
            if (isset($applied[$version])) {
                continue;
            }
            $migration = require $file;
            if (is_callable($migration)) {
                $migration($this->db);
            } elseif (is_array($migration)) {
                foreach ($migration as $sql) {
                    $this->db->exec($sql);
                }
            }
            $this->db->run('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)', [$version, $this->db->now()]);
            $done[] = $version;
        }
        return $done;
    }

    /** @return list<string> */
    public function pending(): array
    {
        try {
            $applied = array_flip($this->db->column('SELECT version FROM schema_migrations'));
        } catch (\Throwable) {
            $applied = [];
        }
        $files = glob($this->dir . '/*.php') ?: [];
        sort($files, SORT_NATURAL);
        $pending = [];
        foreach ($files as $file) {
            $version = basename($file, '.php');
            if (!isset($applied[$version])) {
                $pending[] = $version;
            }
        }
        return $pending;
    }
}
