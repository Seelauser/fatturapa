<?php

declare(strict_types=1);

namespace Fatturapa\Render;

use DOMDocument;
use RuntimeException;
use XSLTProcessor;

/**
 * Renders a FatturaPA XML into the human-readable HTML using the official
 * Agenzia delle Entrate "foglio di stile" XSL.
 *
 * The XSL is not vendored (same policy as the XSD): download
 * "Foglio di stile fattura ordinaria" from fatturapa.gov.it and place it as
 * resources/xsl/FoglioStileFatturaOrdinaria.xsl (any *.xsl in that directory
 * is picked up). Requires ext-xsl.
 */
class StylesheetRenderer
{
    public function __construct(private readonly ?string $xslPath = null)
    {
    }

    public function isAvailable(): bool
    {
        return class_exists(XSLTProcessor::class) && $this->resolveXsl() !== null;
    }

    /** @throws RuntimeException when ext-xsl or the stylesheet is missing, or the input is invalid. */
    public function renderHtml(string $invoiceXml): string
    {
        if (!class_exists(XSLTProcessor::class)) {
            throw new RuntimeException('StylesheetRenderer requires ext-xsl (php-xsl)');
        }
        $xsl = $this->resolveXsl();
        if ($xsl === null) {
            throw new RuntimeException(
                'Stylesheet not found: place the official foglio di stile as resources/xsl/*.xsl'
            );
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $xslDoc = new DOMDocument();
            if (!$xslDoc->load($xsl)) {
                throw new RuntimeException("Cannot load stylesheet $xsl");
            }
            $xmlDoc = new DOMDocument();
            if (!$xmlDoc->loadXML($invoiceXml)) {
                throw new RuntimeException('Invoice XML is not well-formed');
            }
            $proc = new XSLTProcessor();
            if (!$proc->importStylesheet($xslDoc)) {
                throw new RuntimeException("Stylesheet $xsl could not be imported");
            }
            $html = $proc->transformToXml($xmlDoc);
            if ($html === false || $html === null) {
                throw new RuntimeException('XSL transformation failed');
            }
            return $html;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function resolveXsl(): ?string
    {
        if ($this->xslPath !== null) {
            return is_file($this->xslPath) ? $this->xslPath : null;
        }
        $files = glob(dirname(__DIR__, 2) . '/resources/xsl/*.xsl') ?: [];
        return $files[0] ?? null;
    }
}
