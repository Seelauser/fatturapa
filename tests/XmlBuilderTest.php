<?php

declare(strict_types=1);

namespace Fatturapa\Tests;

use Fatturapa\XmlBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class XmlBuilderTest extends TestCase
{
    private function sampleInvoice(array $overrides = []): array
    {
        return array_replace([
            'tipo_documento' => 'fattura_b2c',
            'numero'         => '2026/00042',
            'data'           => '2026-07-01',
            'cedente' => [
                'denominazione' => 'Musterverein Südtirol',
                'partita_iva'   => '01234567890',
                'regime_fiscale' => 'RF01',
                'indirizzo' => 'Hauptplatz 1', 'cap' => '39100', 'comune' => 'Bozen',
                'provincia' => 'BZ', 'nazione' => 'IT',
            ],
            'cessionario' => [
                'nome' => 'Anna', 'cognome' => 'Gruber', 'codice_fiscale' => 'GRBNNA80A01A952G',
                'indirizzo' => 'Dorfweg 5', 'cap' => '39100', 'comune' => 'Bozen',
                'provincia' => 'BZ', 'nazione' => 'IT',
            ],
            'linee' => [
                ['descrizione' => 'Servizio', 'quantita' => 1, 'prezzo_unitario' => 100.0, 'aliquota_iva' => 22.0],
            ],
        ], $overrides);
    }

    /** Build and, when the XSD is vendored, assert schema validity too. */
    private function buildValid(array $invoice): string
    {
        $builder = new XmlBuilder();
        $xml = $builder->build($invoice);
        if ($builder->xsdPath() !== null) {
            $this->assertSame([], $builder->validate($xml), 'XML should be XSD-valid');
        }
        return $xml;
    }

    public function testBuildsWellFormedXml(): void
    {
        $xml = $this->buildValid($this->sampleInvoice());

        $this->assertStringContainsString('<p:FatturaElettronica', $xml);
        $this->assertStringContainsString('versione="FPR12"', $xml);
        $this->assertStringContainsString('<TipoDocumento>TD01</TipoDocumento>', $xml);
        $this->assertStringContainsString('<Numero>2026/00042</Numero>', $xml);

        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($xml), 'XML should be well-formed');
    }

    public function testComputesVatAndDocumentTotal(): void
    {
        $xml = $this->buildValid($this->sampleInvoice());
        // 100.00 imponibile, 22% → 22.00 imposta, 122.00 total.
        $this->assertStringContainsString('<ImponibileImporto>100.00</ImponibileImporto>', $xml);
        $this->assertStringContainsString('<Imposta>22.00</Imposta>', $xml);
        $this->assertStringContainsString('<ImportoTotaleDocumento>122.00</ImportoTotaleDocumento>', $xml);
    }

    public function testEsenteLineHasNoVat(): void
    {
        $xml = $this->buildValid($this->sampleInvoice([
            'linee' => [
                ['descrizione' => 'Mitgliedsbeitrag', 'quantita' => 1, 'prezzo_unitario' => 50.0, 'aliquota_iva' => 0.0, 'natura' => 'N4'],
            ],
        ]));
        $this->assertStringContainsString('<Natura>N4</Natura>', $xml);
        $this->assertStringContainsString('<Imposta>0.00</Imposta>', $xml);
        $this->assertStringContainsString('<ImportoTotaleDocumento>50.00</ImportoTotaleDocumento>', $xml);
        // Below the €77.47 threshold: no bollo.
        $this->assertStringNotContainsString('<DatiBollo>', $xml);
    }

    public function testBolloAppliedAutomaticallyOnExemptOverThreshold(): void
    {
        $xml = $this->buildValid($this->sampleInvoice([
            'linee' => [
                ['descrizione' => 'Quota', 'quantita' => 1, 'prezzo_unitario' => 80.0, 'aliquota_iva' => 0.0, 'natura' => 'N4'],
            ],
        ]));
        $this->assertStringContainsString('<BolloVirtuale>SI</BolloVirtuale>', $xml);
        $this->assertStringContainsString('<ImportoBollo>2.00</ImportoBollo>', $xml);
    }

    public function testBolloExplicitOverride(): void
    {
        $xml = $this->buildValid($this->sampleInvoice([
            'bollo' => false,
            'linee' => [
                ['descrizione' => 'Quota', 'quantita' => 1, 'prezzo_unitario' => 80.0, 'aliquota_iva' => 0.0, 'natura' => 'N4'],
            ],
        ]));
        $this->assertStringNotContainsString('<DatiBollo>', $xml);
    }

    public function testFatturaPaUsesFpa12Format(): void
    {
        $xml = $this->buildValid($this->sampleInvoice(['tipo_documento' => 'fattura_pa']));
        $this->assertStringContainsString('versione="FPA12"', $xml);
        $this->assertStringContainsString('<CodiceDestinatario>999999</CodiceDestinatario>', $xml);
    }

    public function testSplitPaymentAndCigCup(): void
    {
        $xml = $this->buildValid($this->sampleInvoice([
            'tipo_documento' => 'TD01',
            'formato' => 'FPA12',
            'esigibilita_iva' => 'S',
            'ordine_acquisto' => ['id_documento' => 'ORD-1', 'cig' => 'Z123456789', 'cup' => 'B23D22000000001'],
            'cessionario' => [
                'denominazione' => 'Comune di Bolzano', 'codice_fiscale' => '00389240219',
                'codice_destinatario' => 'UF9XI2',
                'indirizzo' => 'Vicolo Gumer 7', 'cap' => '39100', 'comune' => 'Bolzano', 'nazione' => 'IT',
            ],
        ]));
        $this->assertStringContainsString('<EsigibilitaIVA>S</EsigibilitaIVA>', $xml);
        $this->assertStringContainsString('<CodiceCIG>Z123456789</CodiceCIG>', $xml);
        $this->assertStringContainsString('<CodiceCUP>B23D22000000001</CodiceCUP>', $xml);
    }

    public function testCreditNoteTd04(): void
    {
        $xml = $this->buildValid($this->sampleInvoice(['tipo_documento' => 'TD04']));
        $this->assertStringContainsString('<TipoDocumento>TD04</TipoDocumento>', $xml);
    }

    public function testCedenteAsPhysicalPerson(): void
    {
        $invoice = $this->sampleInvoice();
        unset($invoice['cedente']['denominazione']);
        $invoice['cedente']['nome'] = 'Max';
        $invoice['cedente']['cognome'] = 'Muster';
        $invoice['cedente']['regime_fiscale'] = 'RF19';
        $xml = $this->buildValid($invoice);
        $this->assertStringContainsString('<Nome>Max</Nome>', $xml);
        $this->assertStringContainsString('<RegimeFiscale>RF19</RegimeFiscale>', $xml);
    }

    public function testForfettarioLineUsesN22RiferimentoDefault(): void
    {
        $xml = $this->buildValid($this->sampleInvoice([
            'linee' => [
                ['descrizione' => 'Consulenza', 'quantita' => 1, 'prezzo_unitario' => 60.0, 'aliquota_iva' => 0.0, 'natura' => 'N2.2'],
            ],
        ]));
        $this->assertStringContainsString('regime forfettario', $xml);
    }

    public function testDatiPagamento(): void
    {
        $xml = $this->buildValid($this->sampleInvoice([
            'pagamento' => [
                'condizioni' => 'TP02',
                'dettagli' => [['modalita' => 'MP05', 'iban' => 'IT60 X054 2811 1010 0000 0123 456', 'scadenza' => '2026-08-01']],
            ],
        ]));
        $this->assertStringContainsString('<CondizioniPagamento>TP02</CondizioniPagamento>', $xml);
        $this->assertStringContainsString('<ModalitaPagamento>MP05</ModalitaPagamento>', $xml);
        $this->assertStringContainsString('<IBAN>IT60X0542811101000000123456</IBAN>', $xml);
        $this->assertStringContainsString('<ImportoPagamento>122.00</ImportoPagamento>', $xml);
    }

    public function testUnitPriceKeepsFullPrecision(): void
    {
        // SdI check 00423: PrezzoTotale must equal PrezzoUnitario × Quantita.
        $xml = $this->buildValid($this->sampleInvoice([
            'linee' => [
                ['descrizione' => 'x', 'quantita' => 3, 'prezzo_unitario' => 0.333, 'aliquota_iva' => 22.0],
            ],
        ]));
        $this->assertStringContainsString('<PrezzoUnitario>0.333</PrezzoUnitario>', $xml);
        $this->assertStringContainsString('<PrezzoTotale>1.00</PrezzoTotale>', $xml);
    }

    public function testMissingMandatoryKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $invoice = $this->sampleInvoice();
        unset($invoice['numero']);
        (new XmlBuilder())->build($invoice);
    }

    public function testCollectsAllErrorsAtOnce(): void
    {
        $invoice = $this->sampleInvoice();
        unset($invoice['cessionario']['indirizzo'], $invoice['cedente']['denominazione']);
        $invoice['linee'][0]['aliquota_iva'] = 0.0; // natura missing
        try {
            (new XmlBuilder())->build($invoice);
            $this->fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('cessionario.indirizzo', $e->getMessage());
            $this->assertStringContainsString('cedente needs denominazione or nome+cognome', $e->getMessage());
            $this->assertStringContainsString('natura is mandatory when aliquota_iva is 0', $e->getMessage());
        }
    }

    public function testNaturaWithNonZeroAliquotaThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new XmlBuilder())->build($this->sampleInvoice([
            'linee' => [
                ['descrizione' => 'x', 'quantita' => 1, 'prezzo_unitario' => 10.0, 'aliquota_iva' => 22.0, 'natura' => 'N4'],
            ],
        ]));
    }

    public function testGenericNaturaRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new XmlBuilder())->build($this->sampleInvoice([
            'linee' => [
                ['descrizione' => 'x', 'quantita' => 1, 'prezzo_unitario' => 10.0, 'aliquota_iva' => 0.0, 'natura' => 'N2'],
            ],
        ]));
    }

    public function testInvalidTipoDocumentoThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new XmlBuilder())->build($this->sampleInvoice(['tipo_documento' => 'TD99']));
    }

    public function testCessionarioWithoutTaxIdThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $invoice = $this->sampleInvoice();
        unset($invoice['cessionario']['codice_fiscale'], $invoice['cessionario']['partita_iva']);
        (new XmlBuilder())->build($invoice);
    }

    public function testAggregatesRiepilogoAcrossLinesWithSameRate(): void
    {
        $xml = $this->buildValid($this->sampleInvoice([
            'linee' => [
                ['descrizione' => 'A', 'quantita' => 1, 'prezzo_unitario' => 100.0, 'aliquota_iva' => 22.0],
                ['descrizione' => 'B', 'quantita' => 2, 'prezzo_unitario' => 50.0, 'aliquota_iva' => 22.0],
            ],
        ]));
        // 100 + 100 = 200 imponibile at 22% → single riepilogo, 44.00 imposta, 244.00 total.
        $this->assertStringContainsString('<ImponibileImporto>200.00</ImponibileImporto>', $xml);
        $this->assertStringContainsString('<Imposta>44.00</Imposta>', $xml);
        $this->assertStringContainsString('<ImportoTotaleDocumento>244.00</ImportoTotaleDocumento>', $xml);
    }
    public function testRitenutaAcconto(): void
    {
        $invoice = $this->sampleInvoice([
            'ritenuta' => ['tipo' => 'RT01', 'aliquota' => 20.0, 'causale' => 'A'],
            'linee' => [
                ['descrizione' => 'Consulenza', 'quantita' => 1, 'prezzo_unitario' => 1000.0, 'aliquota_iva' => 22.0, 'ritenuta' => true],
            ],
        ]);
        $xml = $this->buildValid($invoice);
        $this->assertStringContainsString('<TipoRitenuta>RT01</TipoRitenuta>', $xml);
        $this->assertStringContainsString('<ImportoRitenuta>200.00</ImportoRitenuta>', $xml);
        $this->assertStringContainsString('<CausalePagamento>A</CausalePagamento>', $xml);
        $this->assertStringContainsString('<Ritenuta>SI</Ritenuta>', $xml);
        // Ritenuta does not reduce the document total.
        $this->assertStringContainsString('<ImportoTotaleDocumento>1220.00</ImportoTotaleDocumento>', $xml);
    }

    public function testLineRitenutaWithoutBlockThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new XmlBuilder())->build($this->sampleInvoice([
            'linee' => [
                ['descrizione' => 'x', 'quantita' => 1, 'prezzo_unitario' => 100.0, 'aliquota_iva' => 22.0, 'ritenuta' => true],
            ],
        ]));
    }

    public function testCassaPrevidenziale(): void
    {
        // 1000 + 4% INPS rivalsa = 1040 imponibile at 22% -> 228.80 IVA, 1268.80 total.
        $xml = $this->buildValid($this->sampleInvoice([
            'cassa' => ['tipo' => 'TC22', 'aliquota' => 4.0, 'aliquota_iva' => 22.0],
            'linee' => [
                ['descrizione' => 'Consulenza', 'quantita' => 1, 'prezzo_unitario' => 1000.0, 'aliquota_iva' => 22.0],
            ],
        ]));
        $this->assertStringContainsString('<TipoCassa>TC22</TipoCassa>', $xml);
        $this->assertStringContainsString('<ImportoContributoCassa>40.00</ImportoContributoCassa>', $xml);
        $this->assertStringContainsString('<ImponibileImporto>1040.00</ImponibileImporto>', $xml);
        $this->assertStringContainsString('<Imposta>228.80</Imposta>', $xml);
        $this->assertStringContainsString('<ImportoTotaleDocumento>1268.80</ImportoTotaleDocumento>', $xml);
    }

    public function testLineSconto(): void
    {
        // 100 with 10% discount -> 90 imponibile, 19.80 IVA.
        $xml = $this->buildValid($this->sampleInvoice([
            'linee' => [
                ['descrizione' => 'Servizio', 'quantita' => 1, 'prezzo_unitario' => 100.0, 'aliquota_iva' => 22.0, 'sconto_percentuale' => 10.0],
            ],
        ]));
        $this->assertStringContainsString('<Tipo>SC</Tipo>', $xml);
        $this->assertStringContainsString('<Percentuale>10.00</Percentuale>', $xml);
        $this->assertStringContainsString('<PrezzoTotale>90.00</PrezzoTotale>', $xml);
        $this->assertStringContainsString('<ImportoTotaleDocumento>109.80</ImportoTotaleDocumento>', $xml);
    }
    public function testBolloNotAppliedToReverseChargeOrExport(): void
    {
        // N6.7 (reverse charge) and N3.2 (intra-EU) are not subject to bollo.
        $xml = $this->buildValid($this->sampleInvoice([
            'cessionario' => [
                'denominazione' => 'Cliente Srl', 'partita_iva' => '09876543210',
                'indirizzo' => 'Via Milano 2', 'cap' => '20100', 'comune' => 'Milano', 'nazione' => 'IT',
            ],
            'linee' => [
                ['descrizione' => 'Subappalto', 'quantita' => 1, 'prezzo_unitario' => 500.0, 'aliquota_iva' => 0.0, 'natura' => 'N6.7'],
            ],
        ]));
        $this->assertStringNotContainsString('<DatiBollo>', $xml);
    }

    public function testImportoPagamentoDefaultsNetOfRitenuta(): void
    {
        // 1000 + 220 IVA = 1220 total; 200 ritenuta withheld -> 1020.00 due.
        $xml = $this->buildValid($this->sampleInvoice([
            'ritenuta' => ['tipo' => 'RT01', 'aliquota' => 20.0, 'causale' => 'A'],
            'pagamento' => ['dettagli' => [['modalita' => 'MP05']]],
            'linee' => [
                ['descrizione' => 'Consulenza', 'quantita' => 1, 'prezzo_unitario' => 1000.0, 'aliquota_iva' => 22.0, 'ritenuta' => true],
            ],
        ]));
        $this->assertStringContainsString('<ImportoPagamento>1020.00</ImportoPagamento>', $xml);
    }
}
