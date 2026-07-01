<?php

declare(strict_types=1);

namespace AlpsFatturapa;

use PDO;

/**
 * Sequential invoice numbering, per year and per sezionale.
 *
 * Uses an atomic UPSERT counter (INSERT IGNORE seed + UPDATE ... LAST_INSERT_ID)
 * which is concurrency-safe on MariaDB/MySQL without explicit table locks.
 *
 * Framework-free: the DB access is an injected PDO connection and the sequence
 * table name is configurable, so this can back any application (CiviCRM,
 * Laravel, plain PHP) and be unit-tested against a scratch database.
 */
class NumeratoreService
{
    private PDO $pdo;
    private string $table;

    public function __construct(PDO $pdo, string $table = 'sdi_sequence')
    {
        $this->pdo = $pdo;
        // Guard the identifier — it is interpolated into SQL, never bound.
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException('Invalid sequence table name');
        }
        $this->table = $table;
    }

    /**
     * Reserve and return the next document number, e.g. "2026/00042" or
     * "2026/00042/EXT" when a sezionale is configured.
     */
    public function next(?int $year = null, string $sezionale = ''): string
    {
        $year = $year ?: (int) date('Y');
        $seq = $this->nextSequence($year, $sezionale);
        $numero = sprintf('%d/%05d', $year, $seq);
        if ($sezionale !== '') {
            $numero .= '/' . strtoupper($sezionale);
        }
        return $numero;
    }

    /** Atomically increment and return the counter for (year, sezionale). */
    public function nextSequence(int $year, string $sezionale = ''): int
    {
        // Seed the counter row once (no-op when it exists). Seed and increment
        // are separate so lastInsertId() is not poisoned by the INSERT path.
        $this->pdo->prepare(
            "INSERT IGNORE INTO {$this->table} (anno, sezionale, last_number)
             VALUES (:anno, :sezionale, 0)"
        )->execute([':anno' => $year, ':sezionale' => $sezionale]);

        $this->pdo->prepare(
            "UPDATE {$this->table}
             SET last_number = LAST_INSERT_ID(last_number + 1)
             WHERE anno = :anno AND sezionale = :sezionale"
        )->execute([':anno' => $year, ':sezionale' => $sezionale]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Create the sequence table if it does not exist (MariaDB/MySQL). */
    public function ensureTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->table} (
                anno INT NOT NULL,
                sezionale VARCHAR(20) NOT NULL DEFAULT '',
                last_number INT NOT NULL DEFAULT 0,
                PRIMARY KEY (anno, sezionale)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}
