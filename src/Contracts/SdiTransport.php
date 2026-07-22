<?php

declare(strict_types=1);

namespace Fatturapa\Contracts;

use Fatturapa\Exception\TransportException;

/**
 * Abstraction over the SdI transmission intermediary so the concrete provider
 * (Openapi.com, Aruba, Fatture in Cloud, …) stays swappable.
 */
interface SdiTransport
{
    /**
     * Transmit a FatturaPA XML document.
     *
     * @param string               $xml  Validated FatturaPA XML.
     * @param array<string, mixed> $meta ['numero' => ..., 'tipo_documento' => ...]
     * @return array{identificativo: string, raw: array} provider id (UUID) + raw response.
     * @throws TransportException on unrecoverable transmission failure.
     */
    public function sendInvoice(string $xml, array $meta = []): array;

    /**
     * Fetch the current status/notifications for a previously sent invoice.
     *
     * @return array{status: string, raw: array}
     */
    public function getInvoiceStatus(string $identificativo): array;
}
