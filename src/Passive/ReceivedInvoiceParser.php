<?php

declare(strict_types=1);

namespace Fatturapa\Passive;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;

/**
 * Parses a received FatturaPA XML (ciclo passivo) into a plain summary array
 * for bookkeeping — the inverse of XmlBuilder, reduced to what an accounting
 * workflow needs. Namespace-agnostic (received files vary in prefix usage).
 */
class ReceivedInvoiceParser
{
    /**
     * @return array{
     *   formato: string, numero: string, data: string, tipo_documento: string, divisa: string,
     *   fornitore: array{denominazione: ?string, nome: ?string, cognome: ?string, partita_iva: ?string, codice_fiscale: ?string},
     *   cessionario: array{denominazione: ?string, partita_iva: ?string, codice_fiscale: ?string},
     *   totale: ?float,
     *   linee: array<array{descrizione: string, quantita: ?float, prezzo_unitario: ?float, prezzo_totale: ?float, aliquota_iva: ?float, natura: ?string}>,
     *   riepiloghi: array<array{aliquota_iva: float, imponibile: float, imposta: float, natura: ?string}>,
     *   pagamenti: array<array{modalita: ?string, scadenza: ?string, importo: ?float, iban: ?string}>
     * }
     */
    public function parse(string $xml): array
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || $doc->documentElement === null
            || $doc->documentElement->localName !== 'FatturaElettronica') {
            throw new InvalidArgumentException('Received invoice: not a FatturaElettronica XML');
        }

        $x = new DOMXPath($doc);
        $q = fn (string $path, ?DOMElement $ctx = null): ?string =>
            ($n = $x->query('.//*[local-name()="' . str_replace('/', '"]/*[local-name()="', $path) . '"]', $ctx)->item(0))
                ? trim($n->textContent) : null;

        $cedente = $x->query('//*[local-name()="CedentePrestatore"]')->item(0);
        $cessionario = $x->query('//*[local-name()="CessionarioCommittente"]')->item(0);

        $linee = [];
        foreach ($x->query('//*[local-name()="DettaglioLinee"]') as $l) {
            /** @var DOMElement $l */
            $linee[] = [
                'descrizione' => (string) $q('Descrizione', $l),
                'quantita' => $this->toFloat($q('Quantita', $l)),
                'prezzo_unitario' => $this->toFloat($q('PrezzoUnitario', $l)),
                'prezzo_totale' => $this->toFloat($q('PrezzoTotale', $l)),
                'aliquota_iva' => $this->toFloat($q('AliquotaIVA', $l)),
                'natura' => $q('Natura', $l),
            ];
        }

        $riepiloghi = [];
        foreach ($x->query('//*[local-name()="DatiRiepilogo"]') as $r) {
            /** @var DOMElement $r */
            $riepiloghi[] = [
                'aliquota_iva' => (float) $this->toFloat($q('AliquotaIVA', $r)),
                'imponibile' => (float) $this->toFloat($q('ImponibileImporto', $r)),
                'imposta' => (float) $this->toFloat($q('Imposta', $r)),
                'natura' => $q('Natura', $r),
            ];
        }

        $pagamenti = [];
        foreach ($x->query('//*[local-name()="DettaglioPagamento"]') as $p) {
            /** @var DOMElement $p */
            $pagamenti[] = [
                'modalita' => $q('ModalitaPagamento', $p),
                'scadenza' => $q('DataScadenzaPagamento', $p),
                'importo' => $this->toFloat($q('ImportoPagamento', $p)),
                'iban' => $q('IBAN', $p),
            ];
        }

        $totale = $this->toFloat($q('ImportoTotaleDocumento'))
            ?? ($riepiloghi !== []
                ? round(array_sum(array_map(fn ($r) => $r['imponibile'] + $r['imposta'], $riepiloghi)), 2)
                : null);

        return [
            'formato' => (string) $doc->documentElement->getAttribute('versione'),
            'numero' => (string) $q('DatiGeneraliDocumento/Numero'),
            'data' => (string) $q('DatiGeneraliDocumento/Data'),
            'tipo_documento' => (string) $q('DatiGeneraliDocumento/TipoDocumento'),
            'divisa' => (string) ($q('DatiGeneraliDocumento/Divisa') ?? 'EUR'),
            'fornitore' => [
                'denominazione' => $q('Anagrafica/Denominazione', $cedente),
                'nome' => $q('Anagrafica/Nome', $cedente),
                'cognome' => $q('Anagrafica/Cognome', $cedente),
                'partita_iva' => $q('IdFiscaleIVA/IdCodice', $cedente),
                'codice_fiscale' => $q('CodiceFiscale', $cedente),
            ],
            'cessionario' => [
                'denominazione' => $q('Anagrafica/Denominazione', $cessionario),
                'partita_iva' => $q('IdFiscaleIVA/IdCodice', $cessionario),
                'codice_fiscale' => $q('CodiceFiscale', $cessionario),
            ],
            'totale' => $totale,
            'linee' => $linee,
            'riepiloghi' => $riepiloghi,
            'pagamenti' => $pagamenti,
        ];
    }

    private function toFloat(?string $s): ?float
    {
        return $s === null || $s === '' ? null : (float) $s;
    }
}
