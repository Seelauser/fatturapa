<?php

declare(strict_types=1);

namespace Fatturapa\Notifications;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * Parses the official SdI notification XML files (the attachments SdI sends
 * over PEC or SDICoop) into a normalized SdiNotification.
 *
 * Root elements per the SdI "MessaggiTypes" schema:
 *   RicevutaConsegna → RC, NotificaScarto → NS, NotificaMancataConsegna → MC,
 *   NotificaEsito → NE, NotificaDecorrenzaTermini → DT,
 *   AttestazioneTrasmissioneFattura → AT.
 */
class NotificationParser
{
    private const ROOT_TO_TIPO = [
        'RicevutaConsegna' => SdiNotification::RC,
        'NotificaScarto' => SdiNotification::NS,
        'NotificaMancataConsegna' => SdiNotification::MC,
        'NotificaEsito' => SdiNotification::NE,
        'NotificaDecorrenzaTermini' => SdiNotification::DT,
        'AttestazioneTrasmissioneFattura' => SdiNotification::AT,
    ];

    /**
     * @throws InvalidArgumentException when the XML is not a recognizable SdI notification.
     */
    public function parse(string $xml): SdiNotification
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || $doc->documentElement === null) {
            throw new InvalidArgumentException('SdI notification: XML is not well-formed');
        }

        $root = $doc->documentElement->localName;
        $tipo = self::ROOT_TO_TIPO[$root] ?? null;
        if ($tipo === null) {
            throw new InvalidArgumentException("SdI notification: unknown root element '$root'");
        }

        $errori = [];
        foreach ($doc->getElementsByTagName('Errore') as $err) {
            /** @var DOMElement $err */
            $errori[] = [
                'codice' => $this->childText($err, 'Codice') ?? '',
                'descrizione' => $this->childText($err, 'Descrizione') ?? '',
            ];
        }

        return new SdiNotification(
            tipo: $tipo,
            identificativoSdi: $this->firstText($doc, 'IdentificativoSdI'),
            nomeFile: $this->firstText($doc, 'NomeFile'),
            dataOraRicezione: $this->firstText($doc, 'DataOraRicezione'),
            errori: $errori,
            esitoCommittente: $this->firstText($doc, 'Esito'),
        );
    }

    private function firstText(DOMDocument $doc, string $tag): ?string
    {
        $node = $doc->getElementsByTagName($tag)->item(0);
        return $node === null ? null : trim($node->textContent);
    }

    private function childText(DOMElement $el, string $tag): ?string
    {
        foreach ($el->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $tag) {
                return trim($child->textContent);
            }
        }
        return null;
    }
}
