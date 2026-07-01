<?php

/**
 * Abstraction over the SdI transmission intermediary so the concrete provider
 * (Openapi.com today; Aruba / Fatture in Cloud as possible future adapters)
 * stays swappable.
 */
interface CRM_Fatturapa_SdiTransportInterface {

  /**
   * Transmit a FatturaPA XML document.
   *
   * @param string $xml Validated FatturaPA XML.
   * @param array $meta ['numero' => ..., 'tipo_documento' => ...]
   * @return array ['identificativo' => string, 'raw' => array] provider id (UUID) + raw response.
   * @throws CRM_Fatturapa_TransportException on unrecoverable transmission failure.
   */
  public function sendInvoice(string $xml, array $meta = []): array;

  /**
   * Fetch the current status/notifications for a previously sent invoice.
   *
   * @return array ['status' => string, 'raw' => array]
   */
  public function getInvoiceStatus(string $identificativo): array;

}
