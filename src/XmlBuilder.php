<?php

declare(strict_types=1);

namespace AlpsFatturapa;

use DOMDocument;
use DOMElement;
use DOMNode;
use InvalidArgumentException;

/**
 * Builds FatturaPA (formato FPR12 / FPA12) XML from a plain data array.
 *
 * Framework-free: depends only on ext-dom. See README for the input array shape.
 *
 * Input array shape (all monetary values as float in EUR):
 * [
 *   'tipo_documento'    => 'fattura_b2b'|'fattura_b2c'|'fattura_pa',
 *   'numero'            => '2026/00042',
 *   'data'              => 'YYYY-MM-DD',
 *   'progressivo_invio' => 'A1B2C',            // optional, max 10 alphanum
 *   'cedente'     => [ ...supplier... ],       // denominazione, partita_iva, indirizzo, cap, comune, ...
 *   'cessionario' => [ ...customer... ],       // denominazione | nome+cognome, codice_fiscale|partita_iva, ...
 *   'linee'       => [ ['descrizione'=>, 'quantita'=>, 'prezzo_unitario'=>, 'aliquota_iva'=>, 'natura'=>?], ... ],
 * ]
 */
class XmlBuilder
{
    public const NS = 'http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2';
    public const XSD_FILE = 'FatturaPA_v1.2.2.xsd';

    /** Map internal tipo_documento to FatturaPA FormatoTrasmissione. */
    public static function formatoTrasmissione(string $tipoDocumento): string
    {
        return $tipoDocumento === 'fattura_pa' ? 'FPA12' : 'FPR12';
    }

    /**
     * Build the FatturaPA XML string.
     *
     * @throws InvalidArgumentException on missing mandatory data.
     */
    public function build(array $f): string
    {
        foreach (['tipo_documento', 'numero', 'data', 'cedente', 'cessionario', 'linee'] as $k) {
            if (empty($f[$k])) {
                throw new InvalidArgumentException("FatturaPA: missing mandatory key '$k'");
            }
        }
        $ced = $f['cedente'];
        $ces = $f['cessionario'];
        if (empty($ced['partita_iva'])) {
            throw new InvalidArgumentException('FatturaPA: cedente partita_iva is mandatory');
        }
        if (empty($ces['partita_iva']) && empty($ces['codice_fiscale'])) {
            throw new InvalidArgumentException('FatturaPA: cessionario needs partita_iva or codice_fiscale');
        }

        $formato = self::formatoTrasmissione($f['tipo_documento']);
        $codiceDestinatario = strtoupper($ces['codice_destinatario'] ?? '');
        if ($codiceDestinatario === '') {
            $codiceDestinatario = $formato === 'FPA12' ? '999999' : '0000000';
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElementNS(self::NS, 'p:FatturaElettronica');
        $root->setAttribute('versione', $formato);
        $doc->appendChild($root);

        // ---- Header ----
        $header = $this->el($doc, $root, 'FatturaElettronicaHeader');

        $dt = $this->el($doc, $header, 'DatiTrasmissione');
        $idTr = $this->el($doc, $dt, 'IdTrasmittente');
        $this->el($doc, $idTr, 'IdPaese', $ced['nazione'] ?? 'IT');
        $this->el($doc, $idTr, 'IdCodice', $ced['partita_iva']);
        $this->el($doc, $dt, 'ProgressivoInvio', $f['progressivo_invio'] ?? substr(strtoupper(bin2hex(random_bytes(5))), 0, 10));
        $this->el($doc, $dt, 'FormatoTrasmissione', $formato);
        $this->el($doc, $dt, 'CodiceDestinatario', $codiceDestinatario);
        // PEC fallback only valid for FPR12 with CodiceDestinatario 0000000.
        if ($formato === 'FPR12' && $codiceDestinatario === '0000000' && !empty($ces['pec'])) {
            $this->el($doc, $dt, 'PECDestinatario', $ces['pec']);
        }

        $cedente = $this->el($doc, $header, 'CedentePrestatore');
        $cedAnag = $this->el($doc, $cedente, 'DatiAnagrafici');
        $idFisc = $this->el($doc, $cedAnag, 'IdFiscaleIVA');
        $this->el($doc, $idFisc, 'IdPaese', $ced['nazione'] ?? 'IT');
        $this->el($doc, $idFisc, 'IdCodice', $ced['partita_iva']);
        if (!empty($ced['codice_fiscale'])) {
            $this->el($doc, $cedAnag, 'CodiceFiscale', strtoupper($ced['codice_fiscale']));
        }
        $anagrafica = $this->el($doc, $cedAnag, 'Anagrafica');
        $this->el($doc, $anagrafica, 'Denominazione', $ced['denominazione']);
        $this->el($doc, $cedAnag, 'RegimeFiscale', $ced['regime_fiscale'] ?? 'RF01');
        $cedSede = $this->el($doc, $cedente, 'Sede');
        $this->el($doc, $cedSede, 'Indirizzo', $ced['indirizzo']);
        $this->el($doc, $cedSede, 'CAP', $ced['cap']);
        $this->el($doc, $cedSede, 'Comune', $ced['comune']);
        if (!empty($ced['provincia'])) {
            $this->el($doc, $cedSede, 'Provincia', strtoupper($ced['provincia']));
        }
        $this->el($doc, $cedSede, 'Nazione', $ced['nazione'] ?? 'IT');

        $cessionario = $this->el($doc, $header, 'CessionarioCommittente');
        $cesAnag = $this->el($doc, $cessionario, 'DatiAnagrafici');
        if (!empty($ces['partita_iva'])) {
            $idFiscC = $this->el($doc, $cesAnag, 'IdFiscaleIVA');
            $this->el($doc, $idFiscC, 'IdPaese', $ces['nazione'] ?? 'IT');
            $this->el($doc, $idFiscC, 'IdCodice', $ces['partita_iva']);
        }
        if (!empty($ces['codice_fiscale'])) {
            $this->el($doc, $cesAnag, 'CodiceFiscale', strtoupper($ces['codice_fiscale']));
        }
        $anagC = $this->el($doc, $cesAnag, 'Anagrafica');
        if (!empty($ces['denominazione'])) {
            $this->el($doc, $anagC, 'Denominazione', $ces['denominazione']);
        } else {
            $this->el($doc, $anagC, 'Nome', $ces['nome']);
            $this->el($doc, $anagC, 'Cognome', $ces['cognome']);
        }
        $cesSede = $this->el($doc, $cessionario, 'Sede');
        $this->el($doc, $cesSede, 'Indirizzo', $ces['indirizzo']);
        $this->el($doc, $cesSede, 'CAP', $ces['cap']);
        $this->el($doc, $cesSede, 'Comune', $ces['comune']);
        if (!empty($ces['provincia'])) {
            $this->el($doc, $cesSede, 'Provincia', strtoupper($ces['provincia']));
        }
        $this->el($doc, $cesSede, 'Nazione', $ces['nazione'] ?? 'IT');

        // ---- Body ----
        $body = $this->el($doc, $root, 'FatturaElettronicaBody');
        $datiGenerali = $this->el($doc, $body, 'DatiGenerali');
        $dgd = $this->el($doc, $datiGenerali, 'DatiGeneraliDocumento');
        $this->el($doc, $dgd, 'TipoDocumento', 'TD01');
        $this->el($doc, $dgd, 'Divisa', 'EUR');
        $this->el($doc, $dgd, 'Data', $f['data']);
        $this->el($doc, $dgd, 'Numero', $f['numero']);

        // Aggregate riepiloghi per (aliquota, natura) while writing lines.
        $beni = $this->el($doc, $body, 'DatiBeniServizi');
        $riepiloghi = [];
        $totaleDocumento = 0.0;
        foreach (array_values($f['linee']) as $i => $linea) {
            $qta = (float) ($linea['quantita'] ?? 1);
            $prezzo = (float) $linea['prezzo_unitario'];
            $aliquota = (float) ($linea['aliquota_iva'] ?? 22.0);
            $natura = $linea['natura'] ?? null;
            $imponibile = round($qta * $prezzo, 2);

            $det = $this->el($doc, $beni, 'DettaglioLinee');
            $this->el($doc, $det, 'NumeroLinea', (string) ($i + 1));
            $this->el($doc, $det, 'Descrizione', $linea['descrizione']);
            $this->el($doc, $det, 'Quantita', $this->dec($qta));
            $this->el($doc, $det, 'PrezzoUnitario', $this->dec($prezzo));
            $this->el($doc, $det, 'PrezzoTotale', $this->dec($imponibile));
            $this->el($doc, $det, 'AliquotaIVA', $this->dec($aliquota));
            if ($natura) {
                $this->el($doc, $det, 'Natura', $natura);
            }

            $key = $this->dec($aliquota) . '|' . ($natura ?? '');
            $riepiloghi[$key]['aliquota'] = $aliquota;
            $riepiloghi[$key]['natura'] = $natura;
            $riepiloghi[$key]['imponibile'] = ($riepiloghi[$key]['imponibile'] ?? 0) + $imponibile;
        }

        foreach ($riepiloghi as $r) {
            $imposta = $r['natura'] ? 0.0 : round($r['imponibile'] * $r['aliquota'] / 100, 2);
            $totaleDocumento += $r['imponibile'] + $imposta;
            $rie = $this->el($doc, $beni, 'DatiRiepilogo');
            $this->el($doc, $rie, 'AliquotaIVA', $this->dec($r['aliquota']));
            if ($r['natura']) {
                $this->el($doc, $rie, 'Natura', $r['natura']);
            }
            $this->el($doc, $rie, 'ImponibileImporto', $this->dec($r['imponibile']));
            $this->el($doc, $rie, 'Imposta', $this->dec($imposta));
            $this->el($doc, $rie, 'EsigibilitaIVA', 'I');
            if ($r['natura']) {
                $this->el($doc, $rie, 'RiferimentoNormativo', $r['riferimento_normativo'] ?? 'Esente art.10 DPR 633/72');
            }
        }

        $this->el($doc, $dgd, 'ImportoTotaleDocumento', $this->dec($totaleDocumento));

        $xml = $doc->saveXML();
        if ($xml === false) {
            throw new InvalidArgumentException('FatturaPA: failed to serialize XML');
        }
        return $xml;
    }

    /**
     * Validate an XML string against the official XSD (if vendored).
     *
     * @return string[] List of validation error messages; empty when valid.
     */
    public function validate(string $xml): array
    {
        $xsd = $this->xsdPath();
        if (!is_file($xsd)) {
            return ['XSD not vendored: place ' . self::XSD_FILE . ' in resources/xsd/ to enable validation'];
        }

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $errors = [];
        if (!$doc->loadXML($xml)) {
            $errors[] = 'XML is not well-formed';
        } elseif (!$doc->schemaValidate($xsd)) {
            foreach (libxml_get_errors() as $e) {
                $errors[] = trim($e->message) . " (line {$e->line})";
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $errors;
    }

    public function xsdPath(): string
    {
        return dirname(__DIR__) . '/resources/xsd/' . self::XSD_FILE;
    }

    private function el(DOMDocument $doc, DOMNode $parent, string $name, ?string $value = null): DOMElement
    {
        $el = $value === null
            ? $doc->createElement($name)
            : $doc->createElement($name, htmlspecialchars($value, ENT_XML1, 'UTF-8'));
        $parent->appendChild($el);
        return $el;
    }

    private function dec(float $n): string
    {
        return number_format($n, 2, '.', '');
    }
}
