<?php

declare(strict_types=1);

namespace AlpsFatturapa\Tests;

use AlpsFatturapa\XmlBuilder;
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

    public function testBuildsWellFormedXml(): void
    {
        $xml = (new XmlBuilder())->build($this->sampleInvoice());

        $this->assertStringContainsString('<p:FatturaElettronica', $xml);
        $this->assertStringContainsString('versione="FPR12"', $xml);
        $this->assertStringContainsString('<Numero>2026/00042</Numero>', $xml);

        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($xml), 'XML should be well-formed');
    }

    public function testComputesVatAndDocumentTotal(): void
    {
        $xml = (new XmlBuilder())->build($this->sampleInvoice());
        // 100.00 imponibile, 22% → 22.00 imposta, 122.00 total.
        $this->assertStringContainsString('<ImponibileImporto>100.00</ImponibileImporto>', $xml);
        $this->assertStringContainsString('<Imposta>22.00</Imposta>', $xml);
        $this->assertStringContainsString('<ImportoTotaleDocumento>122.00</ImportoTotaleDocumento>', $xml);
    }

    public function testEsenteLineHasNoVat(): void
    {
        $xml = (new XmlBuilder())->build($this->sampleInvoice([
            'linee' => [
                ['descrizione' => 'Mitgliedsbeitrag', 'quantita' => 1, 'prezzo_unitario' => 50.0, 'aliquota_iva' => 0.0, 'natura' => 'N4'],
            ],
        ]));
        $this->assertStringContainsString('<Natura>N4</Natura>', $xml);
        $this->assertStringContainsString('<Imposta>0.00</Imposta>', $xml);
        $this->assertStringContainsString('<ImportoTotaleDocumento>50.00</ImportoTotaleDocumento>', $xml);
    }

    public function testFatturaPaUsesFpa12Format(): void
    {
        $xml = (new XmlBuilder())->build($this->sampleInvoice(['tipo_documento' => 'fattura_pa']));
        $this->assertStringContainsString('versione="FPA12"', $xml);
        $this->assertStringContainsString('<CodiceDestinatario>999999</CodiceDestinatario>', $xml);
    }

    public function testMissingMandatoryKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $invoice = $this->sampleInvoice();
        unset($invoice['numero']);
        (new XmlBuilder())->build($invoice);
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
        $xml = (new XmlBuilder())->build($this->sampleInvoice([
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
}
