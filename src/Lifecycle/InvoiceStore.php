<?php

declare(strict_types=1);

namespace Fatturapa\Lifecycle;

use Fatturapa\Notifications\SdiNotification;
use PDO;

/**
 * Persisted invoice lifecycle: built → sent → delivered / rejected /
 * undelivered (+ PA: accepted / refused / expired).
 *
 * Portable PDO storage (MariaDB/MySQL, PostgreSQL, SQLite) in the same spirit
 * as NumeratoreService. Notifications are matched by the SdI transmission
 * filename (PEC channel) or the provider identificativo.
 */
class InvoiceStore
{
    public const STATUS_BUILT = 'built';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';       // RC
    public const STATUS_REJECTED = 'rejected';         // NS — fix and resend within 5 days
    public const STATUS_UNDELIVERED = 'undelivered';   // MC — emitted anyway (B2B)
    public const STATUS_ACCEPTED = 'accepted';         // NE/EC01 (PA)
    public const STATUS_REFUSED = 'refused';           // NE/EC02 (PA)
    public const STATUS_EXPIRED = 'expired';           // DT (PA) — can no longer be refused

    private const NOTIFICATION_STATUS = [
        SdiNotification::RC => self::STATUS_DELIVERED,
        SdiNotification::NS => self::STATUS_REJECTED,
        SdiNotification::MC => self::STATUS_UNDELIVERED,
        SdiNotification::DT => self::STATUS_EXPIRED,
        SdiNotification::AT => self::STATUS_UNDELIVERED,
    ];

    private PDO $pdo;
    private string $table;

    public function __construct(PDO $pdo, string $table = 'sdi_invoices')
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException('Invalid invoice table name');
        }
        $this->pdo = $pdo;
        $this->table = $table;
    }

    public function ensureTable(): void
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $autoId = match ($driver) {
            'mysql' => 'BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'pgsql' => 'BIGSERIAL PRIMARY KEY',
            default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        };
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->table} (
                id $autoId,
                numero VARCHAR(50) NOT NULL,
                tipo_documento VARCHAR(10) NOT NULL DEFAULT 'TD01',
                status VARCHAR(20) NOT NULL,
                identificativo VARCHAR(100) NULL,
                xml TEXT NOT NULL,
                note TEXT NULL,
                created_at VARCHAR(30) NOT NULL,
                updated_at VARCHAR(30) NOT NULL
            )$suffix"
        );
    }

    /** Record a freshly built invoice; returns its row id. */
    public function recordBuilt(string $numero, string $xml, string $tipoDocumento = 'TD01'): int
    {
        $now = $this->now();
        $this->pdo->prepare(
            "INSERT INTO {$this->table} (numero, tipo_documento, status, xml, created_at, updated_at)
             VALUES (:numero, :td, :status, :xml, :now, :now2)"
        )->execute([
            ':numero' => $numero, ':td' => $tipoDocumento,
            ':status' => self::STATUS_BUILT, ':xml' => $xml,
            ':now' => $now, ':now2' => $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Mark as sent, storing the transport identificativo (SdI filename or provider uuid). */
    public function recordSent(int $id, string $identificativo): void
    {
        $this->update($id, self::STATUS_SENT, $identificativo, null);
    }

    /**
     * Apply an SdI notification; matches by identificativo = notification
     * NomeFile (minus any _RC/_NS suffix) or exact match.
     * Returns the affected row id, or null when no invoice matched.
     */
    public function applyNotification(SdiNotification $n): ?int
    {
        $status = $this->statusFor($n);
        if ($status === null || $n->nomeFile === null) {
            return null;
        }
        $row = $this->findByIdentificativo($n->nomeFile);
        if ($row === null) {
            return null;
        }
        $note = $n->errori !== []
            ? json_encode($n->errori, JSON_UNESCAPED_UNICODE)
            : null;
        $this->update((int) $row['id'], $status, null, $note);
        return (int) $row['id'];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findByIdentificativo(string $identificativo): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->table} WHERE identificativo = :i ORDER BY id DESC"
        );
        $stmt->execute([':i' => $identificativo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<array<string, mixed>> */
    public function listByStatus(string $status): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE status = :s ORDER BY id");
        $stmt->execute([':s' => $status]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Map a notification to the resulting lifecycle status (null = no state change). */
    public function statusFor(SdiNotification $n): ?string
    {
        if ($n->tipo === SdiNotification::NE) {
            return match ($n->esitoCommittente) {
                'EC01' => self::STATUS_ACCEPTED,
                'EC02' => self::STATUS_REFUSED,
                default => null,
            };
        }
        return self::NOTIFICATION_STATUS[$n->tipo] ?? null;
    }

    private function update(int $id, string $status, ?string $identificativo, ?string $note): void
    {
        $sql = "UPDATE {$this->table} SET status = :status, updated_at = :now";
        $params = [':status' => $status, ':now' => $this->now(), ':id' => $id];
        if ($identificativo !== null) {
            $sql .= ', identificativo = :ident';
            $params[':ident'] = $identificativo;
        }
        if ($note !== null) {
            $sql .= ', note = :note';
            $params[':note'] = $note;
        }
        $this->pdo->prepare($sql . ' WHERE id = :id')->execute($params);
    }

    private function now(): string
    {
        return date('c');
    }
}
