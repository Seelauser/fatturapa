<?php

declare(strict_types=1);

namespace AlpsFatturapa\Tests;

use AlpsFatturapa\Passive\P7mExtractor;
use AlpsFatturapa\Passive\ReceivedInvoiceParser;
use AlpsFatturapa\XmlBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PassiveCycleTest extends TestCase
{
    private function receivedInvoiceXml(): string
    {
        return (new XmlBuilder())->build([
            'tipo_documento' => 'TD01',
            'numero' => 'F-2026-77',
            'data' => '2026-06-15',
            'cedente' => [
                'denominazione' => 'Fornitore Srl', 'partita_iva' => '11111111111',
                'indirizzo' => 'Via Roma 1', 'cap' => '39100', 'comune' => 'Bolzano', 'nazione' => 'IT',
            ],
            'cessionario' => [
                'denominazione' => 'Noi Srl', 'partita_iva' => '01234567890',
                'codice_destinatario' => 'ABC1234',
                'indirizzo' => 'Via Milano 2', 'cap' => '39100', 'comune' => 'Bolzano', 'nazione' => 'IT',
            ],
            'linee' => [
                ['descrizione' => 'Materiale', 'quantita' => 2, 'prezzo_unitario' => 150.0, 'aliquota_iva' => 22.0],
            ],
            'pagamento' => [
                'dettagli' => [['modalita' => 'MP05', 'iban' => 'IT60X0542811101000000123456', 'scadenza' => '2026-07-15']],
            ],
        ]);
    }

    public function testParsesReceivedInvoice(): void
    {
        $inv = (new ReceivedInvoiceParser())->parse($this->receivedInvoiceXml());

        $this->assertSame('F-2026-77', $inv['numero']);
        $this->assertSame('2026-06-15', $inv['data']);
        $this->assertSame('TD01', $inv['tipo_documento']);
        $this->assertSame('Fornitore Srl', $inv['fornitore']['denominazione']);
        $this->assertSame('11111111111', $inv['fornitore']['partita_iva']);
        $this->assertSame('01234567890', $inv['cessionario']['partita_iva']);
        $this->assertSame(366.0, $inv['totale']);
        $this->assertCount(1, $inv['linee']);
        $this->assertSame(300.0, $inv['linee'][0]['prezzo_totale']);
        $this->assertSame(66.0, $inv['riepiloghi'][0]['imposta']);
        $this->assertSame('IT60X0542811101000000123456', $inv['pagamenti'][0]['iban']);
    }

    public function testRejectsNonInvoiceXml(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ReceivedInvoiceParser())->parse('<Altro/>');
    }

    public function testExtractsXmlFromDerP7m(): void
    {
        $xml = $this->receivedInvoiceXml();
        // Simulate CAdES DER framing: binary ASN.1-ish prefix + payload + signature blob.
        $p7m = "\x30\x82\x0a\x00" . random_bytes(64) . $xml . random_bytes(128);

        $extracted = (new P7mExtractor())->extract($p7m);
        $parsed = (new ReceivedInvoiceParser())->parse($extracted);
        $this->assertSame('F-2026-77', $parsed['numero']);
    }

    public function testExtractsXmlFromBase64P7m(): void
    {
        $xml = $this->receivedInvoiceXml();
        $p7m = base64_encode("\x30\x82" . random_bytes(32) . $xml . random_bytes(32));

        $extracted = (new P7mExtractor())->extract($p7m);
        $this->assertStringContainsString('<Numero>F-2026-77</Numero>', $extracted);
    }

    public function testP7mWithoutPayloadThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new P7mExtractor())->extract(random_bytes(256));
    }
}
