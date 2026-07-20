<?php

declare(strict_types=1);

namespace AlpsFatturapa;

use PDO;

/**
 * Sequential invoice numbering, per year and per sezionale.
 *
 * Concurrency-safe atomic counters on MariaDB/MySQL (INSERT IGNORE seed +
 * UPDATE ... LAST_INSERT_ID), PostgreSQL and SQLite ≥3.35 (single UPSERT
 * ... RETURNING statement) — no explicit table locks on any driver.
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
        $sezionale = strtoupper($sezionale);
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
        // The display format uppercases the sezionale, so the counter key must too.
        $sezionale = strtoupper($sezionale);
        if ($this->driver() === 'mysql') {
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

        // PostgreSQL / SQLite ≥3.35: one atomic UPSERT.
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table} (anno, sezionale, last_number)
             VALUES (:anno, :sezionale, 1)
             ON CONFLICT (anno, sezionale)
             DO UPDATE SET last_number = {$this->table}.last_number + 1
             RETURNING last_number"
        );
        $stmt->execute([':anno' => $year, ':sezionale' => $sezionale]);
        return (int) $stmt->fetchColumn();
    }

    /** Create the sequence table if it does not exist. */
    public function ensureTable(): void
    {
        $suffix = $this->driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->table} (
                anno INT NOT NULL,
                sezionale VARCHAR(20) NOT NULL DEFAULT '',
                last_number INT NOT NULL DEFAULT 0,
                PRIMARY KEY (anno, sezionale)
            )" . $suffix
        );
    }

    private function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
}
