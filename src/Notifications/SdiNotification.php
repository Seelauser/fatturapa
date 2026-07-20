<?php

declare(strict_types=1);

namespace AlpsFatturapa\Notifications;

/**
 * Normalized SdI outcome notification (ricevuta/notifica), independent of the
 * channel it arrived on (PEC attachment, provider webhook, SDICoop).
 */
final class SdiNotification
{
    /** Invoice delivered to the recipient — the invoice is emitted. */
    public const RC = 'RC';
    /** Rejected by SdI checks — fiscally never issued; fix and resend within 5 days. */
    public const NS = 'NS';
    /** Could not be delivered — emitted anyway (B2B); deposited in the buyer's AdE area. */
    public const MC = 'MC';
    /** PA only: buyer accepted (EC01) or rejected (EC02) the invoice. */
    public const NE = 'NE';
    /** PA only: 15-day acceptance window expired; the PA can no longer reject via SdI. */
    public const DT = 'DT';
    /** PA only: attestation of transmission after unresolved non-delivery. */
    public const AT = 'AT';

    /**
     * @param array<array{codice: string, descrizione: string}> $errori NS only.
     */
    public function __construct(
        public readonly string $tipo,
        public readonly ?string $identificativoSdi,
        public readonly ?string $nomeFile,
        public readonly ?string $dataOraRicezione,
        public readonly array $errori = [],
        public readonly ?string $esitoCommittente = null,
    ) {
    }

    /** True when the invoice reached a good terminal state on this notification. */
    public function isPositive(): bool
    {
        return in_array($this->tipo, [self::RC, self::DT], true)
            || ($this->tipo === self::NE && $this->esitoCommittente === 'EC01');
    }

    /** True when the invoice must be corrected and resent. */
    public function isRejection(): bool
    {
        return $this->tipo === self::NS
            || ($this->tipo === self::NE && $this->esitoCommittente === 'EC02');
    }
}
