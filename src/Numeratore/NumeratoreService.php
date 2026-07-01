<?php

/**
 * Sequential invoice numbering, per year and per sezionale.
 *
 * Uses an atomic UPSERT counter on civicrm_sdi_sequence
 * (INSERT .. ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1))
 * which is concurrency-safe on MariaDB/MySQL without explicit table locks.
 * The unique index on civicrm_sdi_invoice.numero is the final backstop.
 *
 * The DB access is injected as a PDO connection so the service can be tested
 * against a scratch database without bootstrapping CiviCRM.
 */
class CRM_Fatturapa_NumeratoreService {

  private PDO $pdo;

  public function __construct(?PDO $pdo = NULL) {
    $this->pdo = $pdo ?: self::civiPdo();
  }

  /**
   * Reserve and return the next document number, e.g. "2026/00042" or
   * "2026/00042/EXT" when a sezionale is configured.
   */
  public function next(?int $year = NULL, string $sezionale = ''): string {
    $year = $year ?: (int) date('Y');
    $seq = $this->nextSequence($year, $sezionale);
    $numero = sprintf('%d/%05d', $year, $seq);
    if ($sezionale !== '') {
      $numero .= '/' . strtoupper($sezionale);
    }
    return $numero;
  }

  /**
   * Atomically increment and return the counter for (year, sezionale).
   */
  public function nextSequence(int $year, string $sezionale = ''): int {
    // Seed the counter row once (no-op when it exists). A single-statement
    // upsert would poison lastInsertId() with the row's AUTO_INCREMENT id on
    // the INSERT path, so seed and increment are kept separate.
    $this->pdo->prepare(
      'INSERT IGNORE INTO civicrm_sdi_sequence (anno, sezionale, last_number)
       VALUES (:anno, :sezionale, 0)'
    )->execute([':anno' => $year, ':sezionale' => $sezionale]);

    $this->pdo->prepare(
      'UPDATE civicrm_sdi_sequence
       SET last_number = LAST_INSERT_ID(last_number + 1)
       WHERE anno = :anno AND sezionale = :sezionale'
    )->execute([':anno' => $year, ':sezionale' => $sezionale]);
    return (int) $this->pdo->lastInsertId();
  }

  /**
   * Build a PDO handle from the live CiviCRM DSN.
   */
  private static function civiPdo(): PDO {
    $dsn = CRM_Core_Config::singleton()->dsn;
    $parsed = DB::parseDSN($dsn);
    $pdoDsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',
      $parsed['hostspec'], $parsed['database']);
    if (!empty($parsed['port'])) {
      $pdoDsn .= ';port=' . $parsed['port'];
    }
    return new PDO($pdoDsn, $parsed['username'], $parsed['password'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
  }

}
