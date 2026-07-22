<?php

declare(strict_types=1);

namespace Fatturapa\Tests;

use Fatturapa\Render\StylesheetRenderer;
use Fatturapa\XmlBuilder;
use PHPUnit\Framework\TestCase;

final class StylesheetRendererTest extends TestCase
{
    public function testRendersHtmlWithOfficialStylesheet(): void
    {
        $renderer = new StylesheetRenderer();
        if (!$renderer->isAvailable()) {
            $this->markTestSkipped('ext-xsl or resources/xsl/*.xsl not available');
        }

        $xml = (new XmlBuilder())->build([
            'tipo_documento' => 'TD01',
            'numero' => '2026/00042',
            'data' => '2026-07-01',
            'cedente' => [
                'denominazione' => 'ACME Srl', 'partita_iva' => '01234567890',
                'indirizzo' => 'Via Roma 1', 'cap' => '39100', 'comune' => 'Bolzano', 'nazione' => 'IT',
            ],
            'cessionario' => [
                'nome' => 'Anna', 'cognome' => 'Gruber', 'codice_fiscale' => 'GRBNNA80A01A952G',
                'indirizzo' => 'Dorfweg 5', 'cap' => '39100', 'comune' => 'Bozen', 'nazione' => 'IT',
            ],
            'linee' => [
                ['descrizione' => 'Servizio', 'quantita' => 1, 'prezzo_unitario' => 100.0, 'aliquota_iva' => 22.0],
            ],
        ]);

        $html = $renderer->renderHtml($xml);
        $this->assertStringContainsString('ACME Srl', $html);
        $this->assertStringContainsString('2026/00042', $html);
    }

    public function testMissingStylesheetReportsCleanly(): void
    {
        $renderer = new StylesheetRenderer('/nonexistent/style.xsl');
        $this->assertFalse($renderer->isAvailable());
        $this->expectException(\RuntimeException::class);
        $renderer->renderHtml('<xml/>');
    }
}
