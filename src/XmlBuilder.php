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
 * Framework-free: depends only on ext-dom. Targets XSD 1.2.3 (specifiche
 * tecniche 1.9.x, in force since 2025-04-01).
 *
 * Input array shape (all monetary values as float in EUR):
 * [
 *   'tipo_documento'    => 'TD01'|'TD04'|...|'fattura_b2b'|'fattura_b2c'|'fattura_pa' (legacy aliases → TD01),
 *   'formato'           => 'FPR12'|'FPA12',    // optional; default FPR12 (FPA12 for legacy 'fattura_pa')
 *   'numero'            => '2026/00042',
 *   'data'              => 'YYYY-MM-DD',
 *   'progressivo_invio' => 'A1B2C',            // optional, max 10 alphanum
 *   'causale'           => 'text',             // optional, split over 200-char elements
 *   'bollo'             => true|false,         // optional; omit for automatic €2 rule (exempt total > 77.47)
 *   'esigibilita_iva'   => 'I'|'D'|'S',        // optional; 'S' = split payment (PA)
 *   'cedente'     => [ denominazione | nome+cognome, partita_iva, regime_fiscale?, indirizzo, cap, comune, provincia?, nazione?, codice_fiscale? ],
 *   'cessionario' => [ denominazione | nome+cognome, partita_iva|codice_fiscale, codice_destinatario?, pec?, indirizzo, cap, comune, provincia?, nazione? ],
 *   'linee'       => [ ['descrizione'=>, 'quantita'=>, 'prezzo_unitario'=>, 'aliquota_iva'=>, 'natura'=>?,
 *                       'riferimento_normativo'=>?, 'ritenuta'=>bool?, 'sconto_percentuale'=>?, 'sconto_importo'=>?], ... ],
 *   'ritenuta'    => [                          // optional; required when a line has 'ritenuta'=>true (SdI 00411)
 *     'tipo' => 'RT01'..'RT06', 'aliquota' => 20.0, 'causale' => 'A', 'importo' => ?,  // importo auto: aliquota% of flagged lines
 *   ],
 *   'cassa'       => [                          // optional: cassa previdenziale (professionals)
 *     'tipo' => 'TC01'..'TC22', 'aliquota' => 4.0, 'aliquota_iva' => 22.0, 'natura' => ?, 'ritenuta' => bool?, 'importo' => ?,
 *   ],
 *   'ordine_acquisto' => ['id_documento'=>?, 'cig'=>?, 'cup'=>?],   // optional (PA: CIG/CUP)
 *   'pagamento'   => [                          // optional
 *     'condizioni' => 'TP01'|'TP02'|'TP03',     // default TP02
 *     'dettagli'   => [ ['modalita'=>'MP05', 'iban'=>?, 'scadenza'=>'YYYY-MM-DD'?, 'importo'=>?], ... ],
 *   ],
 * ]
 */
class XmlBuilder
{
    public const NS = 'http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2';
    /** Newest first; validate() uses the first one present on disk. */
    public const XSD_FILES = ['FatturaPA_v1.2.3.xsd', 'FatturaPA_v1.2.2.xsd'];

    /** Threshold above which exempt amounts require the €2 imposta di bollo. */
    public const BOLLO_THRESHOLD = 77.47;

    private const TIPI_DOCUMENTO = [
        'TD01', 'TD02', 'TD03', 'TD04', 'TD05', 'TD06',
        'TD16', 'TD17', 'TD18', 'TD19', 'TD20', 'TD21', 'TD22', 'TD23',
        'TD24', 'TD25', 'TD26', 'TD27', 'TD28', 'TD29',
    ];
    private const LEGACY_ALIASES = ['fattura_b2b' => 'TD01', 'fattura_b2c' => 'TD01', 'fattura_pa' => 'TD01'];

    private const NATURE = [
        'N1', 'N2.1', 'N2.2', 'N3.1', 'N3.2', 'N3.3', 'N3.4', 'N3.5', 'N3.6',
        'N4', 'N5', 'N6.1', 'N6.2', 'N6.3', 'N6.4', 'N6.5', 'N6.6', 'N6.7', 'N6.8', 'N6.9', 'N7',
    ];

    /** Default RiferimentoNormativo per natura family (overridable per line). */
    private const RIFERIMENTI_NORMATIVI = [
        'N1'   => 'Escluso art.15 DPR 633/72',
        'N2.1' => 'Non soggetta artt.7-7-septies DPR 633/72',
        'N2.2' => 'Operazione non soggetta - regime forfettario art.1 c.54-89 L.190/2014',
        'N3.1' => 'Non imponibile art.8 DPR 633/72',
        'N3.2' => 'Non imponibile art.41 DL 331/93',
        'N3.3' => 'Non imponibile art.71 DPR 633/72',
        'N3.4' => 'Non imponibile art.8-bis DPR 633/72',
        'N3.5' => 'Non imponibile art.8 c.1 lett.c DPR 633/72',
        'N3.6' => 'Non imponibile',
        'N4'   => 'Esente art.10 DPR 633/72',
        'N5'   => 'Regime del margine art.36 DL 41/95',
        'N6.1' => 'Inversione contabile art.74 c.7-8 DPR 633/72',
        'N6.2' => 'Inversione contabile art.17 c.5 DPR 633/72',
        'N6.3' => 'Inversione contabile art.17 c.6 lett.a DPR 633/72',
        'N6.4' => 'Inversione contabile art.17 c.6 lett.a-bis DPR 633/72',
        'N6.5' => 'Inversione contabile art.17 c.6 lett.b DPR 633/72',
        'N6.6' => 'Inversione contabile art.17 c.6 lett.c DPR 633/72',
        'N6.7' => 'Inversione contabile art.17 c.6 lett.a-ter DPR 633/72',
        'N6.8' => 'Inversione contabile art.17 c.6 lett.d-bis/d-ter/d-quater DPR 633/72',
        'N6.9' => 'Inversione contabile',
        'N7'   => 'IVA assolta in altro stato UE',
    ];

    /** Map internal tipo_documento to FatturaPA FormatoTrasmissione. */
    public static function formatoTrasmissione(string $tipoDocumento): string
    {
        return $tipoDocumento === 'fattura_pa' ? 'FPA12' : 'FPR12';
    }

    /**
     * Build the FatturaPA XML string.
     *
     * @throws InvalidArgumentException listing ALL validation errors, not just the first.
     */
    public function build(array $f): string
    {
        [$td, $formato] = $this->resolveTipoAndFormato($f);
        $errors = $this->collectErrors($f, $td, $formato);
        if ($errors !== []) {
            throw new InvalidArgumentException("FatturaPA: invalid invoice data:\n- " . implode("\n- ", $errors));
        }

        $ced = $f['cedente'];
        $ces = $f['cessionario'];

        $codiceDestinatario = strtoupper($ces['codice_destinatario'] ?? '');
        if ($codiceDestinatario === '') {
            $codiceDestinatario = $formato === 'FPA12' ? '999999' : '0000000';
        }

        // ---- Compute lines and riepiloghi first (emission order ≠ computation order) ----
        $lines = [];
        $riepiloghi = [];
        $esigibilita = $f['esigibilita_iva'] ?? 'I';
        $totaleDocumento = 0.0;
        $totaleEsente = 0.0;
        $imponibileRitenuta = 0.0;
        foreach (array_values($f['linee']) as $i => $linea) {
            $qta = (float) ($linea['quantita'] ?? 1);
            $prezzo = (float) $linea['prezzo_unitario'];
            $aliquota = (float) ($linea['aliquota_iva'] ?? 22.0);
            $natura = $linea['natura'] ?? null;
            $scontoPct = isset($linea['sconto_percentuale']) ? (float) $linea['sconto_percentuale'] : null;
            $scontoImp = isset($linea['sconto_importo']) ? (float) $linea['sconto_importo'] : null;
            $lordo = $qta * $prezzo;
            $sconto = $scontoPct !== null ? round($lordo * $scontoPct / 100, 2) : ($scontoImp ?? 0.0);
            $imponibile = round($lordo - $sconto, 2);
            $conRitenuta = !empty($linea['ritenuta']);

            $lines[] = [
                'numero' => $i + 1, 'descrizione' => $linea['descrizione'],
                'quantita' => $qta, 'prezzo' => $prezzo, 'totale' => $imponibile,
                'aliquota' => $aliquota, 'natura' => $natura,
                'sconto_pct' => $scontoPct, 'sconto_importo' => $sconto > 0 ? $sconto : null,
                'ritenuta' => $conRitenuta,
            ];
            if ($conRitenuta) {
                $imponibileRitenuta += $imponibile;
            }

            $key = $this->dec($aliquota) . '|' . ($natura ?? '');
            $riepiloghi[$key]['aliquota'] = $aliquota;
            $riepiloghi[$key]['natura'] = $natura;
            $riepiloghi[$key]['imponibile'] = ($riepiloghi[$key]['imponibile'] ?? 0) + $imponibile;
            if (!empty($linea['riferimento_normativo'])) {
                $riepiloghi[$key]['riferimento_normativo'] = $linea['riferimento_normativo'];
            }
        }

        // Cassa previdenziale contributes to the taxable base of its own aliquota.
        $cassa = null;
        if (!empty($f['cassa'])) {
            $c = $f['cassa'];
            $aliquotaCassa = (float) ($c['aliquota'] ?? 4.0);
            $aliquotaIvaCassa = (float) ($c['aliquota_iva'] ?? 22.0);
            $naturaCassa = $c['natura'] ?? null;
            $imponibileCassa = array_sum(array_map(fn ($l) => $l['totale'], $lines));
            $importoCassa = isset($c['importo']) ? (float) $c['importo'] : round($imponibileCassa * $aliquotaCassa / 100, 2);
            $cassa = [
                'tipo' => $c['tipo'] ?? 'TC22', 'aliquota' => $aliquotaCassa,
                'importo' => $importoCassa, 'imponibile' => $imponibileCassa,
                'aliquota_iva' => $aliquotaIvaCassa, 'natura' => $naturaCassa,
                'ritenuta' => !empty($c['ritenuta']),
            ];
            if ($cassa['ritenuta']) {
                $imponibileRitenuta += $importoCassa;
            }
            $key = $this->dec($aliquotaIvaCassa) . '|' . ($naturaCassa ?? '');
            $riepiloghi[$key]['aliquota'] = $aliquotaIvaCassa;
            $riepiloghi[$key]['natura'] = $naturaCassa;
            $riepiloghi[$key]['imponibile'] = ($riepiloghi[$key]['imponibile'] ?? 0) + $importoCassa;
        }

        foreach ($riepiloghi as &$r) {
            $r['imposta'] = $r['natura'] ? 0.0 : round($r['imponibile'] * $r['aliquota'] / 100, 2);
            $totaleDocumento += $r['imponibile'] + $r['imposta'];
            if ($r['natura']) {
                $totaleEsente += $r['imponibile'];
            }
        }
        unset($r);

        // Ritenuta d'acconto: shown in the document, withheld at payment (does not reduce the total).
        $ritenuta = null;
        if (!empty($f['ritenuta'])) {
            $rt = $f['ritenuta'];
            $aliquotaRit = (float) ($rt['aliquota'] ?? 20.0);
            $ritenuta = [
                'tipo' => $rt['tipo'] ?? 'RT01',
                'aliquota' => $aliquotaRit,
                'importo' => isset($rt['importo']) ? (float) $rt['importo'] : round($imponibileRitenuta * $aliquotaRit / 100, 2),
                'causale' => $rt['causale'] ?? 'A',
            ];
        }

        // Bollo: explicit flag wins; otherwise automatic €2 rule on exempt totals.
        $bollo = $f['bollo'] ?? ($totaleEsente > self::BOLLO_THRESHOLD);

        // ---- Emit ----
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
        $this->emitAnagrafica($doc, $cedAnag, $ced);
        $this->el($doc, $cedAnag, 'RegimeFiscale', $ced['regime_fiscale'] ?? 'RF01');
        $this->emitSede($doc, $cedente, $ced);

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
        $this->emitAnagrafica($doc, $cesAnag, $ces);
        $this->emitSede($doc, $cessionario, $ces);

        // ---- Body ----
        $body = $this->el($doc, $root, 'FatturaElettronicaBody');
        $datiGenerali = $this->el($doc, $body, 'DatiGenerali');
        // DatiGeneraliDocumento children in XSD sequence order: TipoDocumento, Divisa,
        // Data, Numero, DatiRitenuta*, DatiBollo?, DatiCassaPrevidenziale*,
        // ImportoTotaleDocumento?, Causale*
        $dgd = $this->el($doc, $datiGenerali, 'DatiGeneraliDocumento');
        $this->el($doc, $dgd, 'TipoDocumento', $td);
        $this->el($doc, $dgd, 'Divisa', 'EUR');
        $this->el($doc, $dgd, 'Data', $f['data']);
        $this->el($doc, $dgd, 'Numero', $f['numero']);
        if ($ritenuta !== null) {
            $dr = $this->el($doc, $dgd, 'DatiRitenuta');
            $this->el($doc, $dr, 'TipoRitenuta', $ritenuta['tipo']);
            $this->el($doc, $dr, 'ImportoRitenuta', $this->dec($ritenuta['importo']));
            $this->el($doc, $dr, 'AliquotaRitenuta', $this->dec($ritenuta['aliquota']));
            $this->el($doc, $dr, 'CausalePagamento', $ritenuta['causale']);
        }
        if ($bollo) {
            $db = $this->el($doc, $dgd, 'DatiBollo');
            $this->el($doc, $db, 'BolloVirtuale', 'SI');
            $this->el($doc, $db, 'ImportoBollo', '2.00');
        }
        if ($cassa !== null) {
            $dc = $this->el($doc, $dgd, 'DatiCassaPrevidenziale');
            $this->el($doc, $dc, 'TipoCassa', $cassa['tipo']);
            $this->el($doc, $dc, 'AlCassa', $this->dec($cassa['aliquota']));
            $this->el($doc, $dc, 'ImportoContributoCassa', $this->dec($cassa['importo']));
            $this->el($doc, $dc, 'ImponibileCassa', $this->dec($cassa['imponibile']));
            $this->el($doc, $dc, 'AliquotaIVA', $this->dec($cassa['aliquota_iva']));
            if ($cassa['ritenuta']) {
                $this->el($doc, $dc, 'Ritenuta', 'SI');
            }
            if ($cassa['natura']) {
                $this->el($doc, $dc, 'Natura', $cassa['natura']);
            }
        }
        $this->el($doc, $dgd, 'ImportoTotaleDocumento', $this->dec($totaleDocumento));
        if (!empty($f['causale'])) {
            foreach (str_split($f['causale'], 200) as $chunk) {
                $this->el($doc, $dgd, 'Causale', $chunk);
            }
        }

        if (!empty($f['ordine_acquisto'])) {
            $oa = $f['ordine_acquisto'];
            $doa = $this->el($doc, $datiGenerali, 'DatiOrdineAcquisto');
            if (!empty($oa['id_documento'])) {
                $this->el($doc, $doa, 'IdDocumento', $oa['id_documento']);
            }
            if (!empty($oa['cup'])) {
                $this->el($doc, $doa, 'CodiceCUP', $oa['cup']);
            }
            if (!empty($oa['cig'])) {
                $this->el($doc, $doa, 'CodiceCIG', $oa['cig']);
            }
        }

        $beni = $this->el($doc, $body, 'DatiBeniServizi');
        foreach ($lines as $l) {
            $det = $this->el($doc, $beni, 'DettaglioLinee');
            $this->el($doc, $det, 'NumeroLinea', (string) $l['numero']);
            $this->el($doc, $det, 'Descrizione', $l['descrizione']);
            $this->el($doc, $det, 'Quantita', $this->dec($l['quantita']));
            $this->el($doc, $det, 'PrezzoUnitario', $this->price($l['prezzo']));
            // DettaglioLinee order: …, PrezzoUnitario, ScontoMaggiorazione*, PrezzoTotale, AliquotaIVA, Ritenuta?, Natura?
            if ($l['sconto_importo'] !== null) {
                $sm = $this->el($doc, $det, 'ScontoMaggiorazione');
                $this->el($doc, $sm, 'Tipo', 'SC');
                if ($l['sconto_pct'] !== null) {
                    $this->el($doc, $sm, 'Percentuale', $this->dec($l['sconto_pct']));
                }
                $this->el($doc, $sm, 'Importo', $this->dec($l['sconto_importo']));
            }
            $this->el($doc, $det, 'PrezzoTotale', $this->dec($l['totale']));
            $this->el($doc, $det, 'AliquotaIVA', $this->dec($l['aliquota']));
            if ($l['ritenuta']) {
                $this->el($doc, $det, 'Ritenuta', 'SI');
            }
            if ($l['natura']) {
                $this->el($doc, $det, 'Natura', $l['natura']);
            }
        }
        foreach ($riepiloghi as $r) {
            $rie = $this->el($doc, $beni, 'DatiRiepilogo');
            $this->el($doc, $rie, 'AliquotaIVA', $this->dec($r['aliquota']));
            if ($r['natura']) {
                $this->el($doc, $rie, 'Natura', $r['natura']);
            }
            $this->el($doc, $rie, 'ImponibileImporto', $this->dec($r['imponibile']));
            $this->el($doc, $rie, 'Imposta', $this->dec($r['imposta']));
            // EsigibilitaIVA only meaningful with VAT; S (split payment) never on exempt rows.
            if (!$r['natura']) {
                $this->el($doc, $rie, 'EsigibilitaIVA', $esigibilita);
            }
            if ($r['natura']) {
                $this->el($doc, $rie, 'RiferimentoNormativo',
                    $r['riferimento_normativo'] ?? self::RIFERIMENTI_NORMATIVI[$r['natura']] ?? 'Operazione senza IVA');
            }
        }

        if (!empty($f['pagamento'])) {
            $pag = $f['pagamento'];
            $dp = $this->el($doc, $body, 'DatiPagamento');
            $this->el($doc, $dp, 'CondizioniPagamento', $pag['condizioni'] ?? 'TP02');
            foreach ($pag['dettagli'] ?? [] as $det) {
                $dd = $this->el($doc, $dp, 'DettaglioPagamento');
                $this->el($doc, $dd, 'ModalitaPagamento', $det['modalita'] ?? 'MP05');
                if (!empty($det['scadenza'])) {
                    $this->el($doc, $dd, 'DataScadenzaPagamento', $det['scadenza']);
                }
                $this->el($doc, $dd, 'ImportoPagamento', $this->dec((float) ($det['importo'] ?? $totaleDocumento)));
                if (!empty($det['iban'])) {
                    $this->el($doc, $dd, 'IBAN', strtoupper(str_replace(' ', '', $det['iban'])));
                }
            }
        }

        $xml = $doc->saveXML();
        if ($xml === false) {
            throw new InvalidArgumentException('FatturaPA: failed to serialize XML');
        }
        return $xml;
    }

    /** @return array{0: string, 1: string} [TipoDocumento TDxx, FormatoTrasmissione] */
    private function resolveTipoAndFormato(array $f): array
    {
        $raw = (string) ($f['tipo_documento'] ?? '');
        $td = self::LEGACY_ALIASES[$raw] ?? strtoupper($raw);
        $formato = strtoupper((string) ($f['formato'] ?? ''));
        if ($formato === '') {
            $formato = $raw === 'fattura_pa' ? 'FPA12' : 'FPR12';
        }
        return [$td, $formato];
    }

    /** @return string[] All field-level validation errors (empty = valid). */
    private function collectErrors(array $f, string $td, string $formato): array
    {
        $errors = [];
        foreach (['numero', 'data', 'cedente', 'cessionario', 'linee'] as $k) {
            if (empty($f[$k])) {
                $errors[] = "missing mandatory key '$k'";
            }
        }
        if (!in_array($td, self::TIPI_DOCUMENTO, true)) {
            $errors[] = "tipo_documento '$td' is not a valid TipoDocumento (TD01…TD29)";
        }
        if (!in_array($formato, ['FPR12', 'FPA12'], true)) {
            $errors[] = "formato '$formato' must be FPR12 or FPA12";
        }
        if (isset($f['progressivo_invio']) && !preg_match('/^[A-Za-z0-9]{1,10}$/', (string) $f['progressivo_invio'])) {
            $errors[] = 'progressivo_invio must be 1-10 alphanumeric characters';
        }
        if (isset($f['esigibilita_iva']) && !in_array($f['esigibilita_iva'], ['I', 'D', 'S'], true)) {
            $errors[] = "esigibilita_iva must be I, D or S";
        }
        if (!empty($f['data']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f['data'])) {
            $errors[] = 'data must be YYYY-MM-DD';
        }

        foreach (['cedente', 'cessionario'] as $role) {
            $p = $f[$role] ?? null;
            if (!is_array($p)) {
                continue;
            }
            if (empty($p['denominazione']) && (empty($p['nome']) || empty($p['cognome']))) {
                $errors[] = "$role needs denominazione or nome+cognome";
            }
            foreach (['indirizzo', 'cap', 'comune'] as $k) {
                if (empty($p[$k])) {
                    $errors[] = "$role.$k is mandatory";
                }
            }
            $nazione = $p['nazione'] ?? 'IT';
            if ($nazione === 'IT') {
                if (!empty($p['cap']) && !preg_match('/^\d{5}$/', (string) $p['cap'])) {
                    $errors[] = "$role.cap must be 5 digits";
                }
                if (!empty($p['partita_iva']) && !preg_match('/^\d{11}$/', (string) $p['partita_iva'])) {
                    $errors[] = "$role.partita_iva must be 11 digits";
                }
            }
        }
        if (is_array($f['cedente'] ?? null) && empty($f['cedente']['partita_iva'])) {
            $errors[] = 'cedente.partita_iva is mandatory';
        }
        if (is_array($f['cessionario'] ?? null)
            && empty($f['cessionario']['partita_iva']) && empty($f['cessionario']['codice_fiscale'])) {
            $errors[] = 'cessionario needs partita_iva or codice_fiscale';
        }
        $cd = strtoupper($f['cessionario']['codice_destinatario'] ?? '');
        if ($cd !== '') {
            $len = $formato === 'FPA12' ? 6 : 7;
            if (!preg_match('/^[A-Z0-9]{' . $len . '}$/', $cd)) {
                $errors[] = "codice_destinatario must be $len alphanumeric characters for $formato";
            }
        }

        foreach (array_values(is_array($f['linee'] ?? null) ? $f['linee'] : []) as $i => $linea) {
            $n = $i + 1;
            if (empty($linea['descrizione'])) {
                $errors[] = "linea $n: descrizione is mandatory";
            }
            if (!isset($linea['prezzo_unitario'])) {
                $errors[] = "linea $n: prezzo_unitario is mandatory";
            }
            $aliquota = (float) ($linea['aliquota_iva'] ?? 22.0);
            $natura = $linea['natura'] ?? null;
            if ($natura !== null && !in_array($natura, self::NATURE, true)) {
                $errors[] = "linea $n: natura '$natura' is not valid (granular sub-codes are mandatory since 2021, e.g. N2.2 not N2)";
            }
            if ($aliquota == 0.0 && $natura === null) {
                $errors[] = "linea $n: natura is mandatory when aliquota_iva is 0 (SdI check 00400)";
            }
            if ($aliquota != 0.0 && $natura !== null) {
                $errors[] = "linea $n: natura must be empty when aliquota_iva is not 0 (SdI check 00401)";
            }
        }

        $hasLineRitenuta = array_reduce(
            is_array($f['linee'] ?? null) ? $f['linee'] : [],
            fn ($carry, $l) => $carry || !empty($l['ritenuta']),
            false
        );
        if ($hasLineRitenuta && empty($f['ritenuta'])) {
            $errors[] = "a line has ritenuta=true but the 'ritenuta' block is missing (SdI check 00411)";
        }
        if (!empty($f['ritenuta']['tipo']) && !preg_match('/^RT0[1-6]$/', (string) $f['ritenuta']['tipo'])) {
            $errors[] = "ritenuta.tipo must be RT01…RT06";
        }
        if (!empty($f['cassa']['tipo']) && !preg_match('/^TC(0[1-9]|1\d|2[0-2])$/', (string) $f['cassa']['tipo'])) {
            $errors[] = "cassa.tipo must be TC01…TC22";
        }
        return $errors;
    }

    /** Anagrafica: Denominazione, or Nome+Cognome for physical persons. */
    private function emitAnagrafica(DOMDocument $doc, DOMElement $parent, array $p): void
    {
        $anag = $this->el($doc, $parent, 'Anagrafica');
        if (!empty($p['denominazione'])) {
            $this->el($doc, $anag, 'Denominazione', $p['denominazione']);
        } else {
            $this->el($doc, $anag, 'Nome', $p['nome']);
            $this->el($doc, $anag, 'Cognome', $p['cognome']);
        }
    }

    private function emitSede(DOMDocument $doc, DOMElement $parent, array $p): void
    {
        $sede = $this->el($doc, $parent, 'Sede');
        $this->el($doc, $sede, 'Indirizzo', $p['indirizzo']);
        $this->el($doc, $sede, 'CAP', $p['cap']);
        $this->el($doc, $sede, 'Comune', $p['comune']);
        if (!empty($p['provincia'])) {
            $this->el($doc, $sede, 'Provincia', strtoupper($p['provincia']));
        }
        $this->el($doc, $sede, 'Nazione', $p['nazione'] ?? 'IT');
    }

    /**
     * Validate an XML string against the official XSD (if vendored).
     *
     * @return string[] List of validation error messages; empty when valid.
     */
    public function validate(string $xml): array
    {
        $xsd = $this->xsdPath();
        if ($xsd === null) {
            return ['XSD not vendored: place ' . self::XSD_FILES[0] . ' in resources/xsd/ to enable validation'];
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

    /** Path of the newest vendored XSD, or null when none is present. */
    public function xsdPath(): ?string
    {
        foreach (self::XSD_FILES as $file) {
            $path = dirname(__DIR__) . '/resources/xsd/' . $file;
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
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

    /**
     * Unit price with full precision (2-8 decimals) so that
     * PrezzoTotale = PrezzoUnitario × Quantita holds exactly (SdI check 00423).
     */
    private function price(float $n): string
    {
        $s = rtrim(rtrim(number_format($n, 8, '.', ''), '0'), '.');
        return str_contains($s, '.') && strlen(substr($s, strpos($s, '.') + 1)) >= 2
            ? $s
            : number_format($n, 2, '.', '');
    }
}
