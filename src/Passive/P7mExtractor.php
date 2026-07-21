<?php

declare(strict_types=1);

namespace AlpsFatturapa\Passive;

use InvalidArgumentException;

/**
 * Extracts the XML payload from a CAdES-signed FatturaPA file (.xml.p7m).
 *
 * Signature verification is intentionally out of scope — SdI already verified
 * the signature before delivering the file; here we only need the content.
 * Works on raw DER and on base64-encoded p7m (both occur in the wild).
 */
class P7mExtractor
{
    /**
     * @throws InvalidArgumentException when no FatturaPA XML payload is found.
     */
    public function extract(string $p7m): string
    {
        foreach ([$p7m, $this->maybeBase64($p7m)] as $candidate) {
            if ($candidate === null) {
                continue;
            }
            $xml = $this->sliceXml($candidate);
            if ($xml !== null) {
                return $xml;
            }
        }
        throw new InvalidArgumentException('p7m: no FatturaElettronica XML payload found');
    }

    /** True when the blob looks like a p7m container rather than plain XML. */
    public function isP7m(string $data): bool
    {
        $trimmed = ltrim($data);
        return !str_starts_with($trimmed, '<?xml') && !str_starts_with($trimmed, '<');
    }

    /**
     * Locate the embedded XML document: from the XML declaration (or root
     * element) to the closing FatturaElettronica tag. DER wraps but does not
     * transform the payload, so a byte slice yields the original document.
     */
    private function sliceXml(string $data): ?string
    {
        $start = strpos($data, '<?xml');
        if ($start === false) {
            $start = strpos($data, ':FatturaElettronica');
            if ($start !== false) {
                $start = strrpos(substr($data, 0, $start), '<') ?: null;
            }
            if ($start === null || $start === false) {
                return null;
            }
        }
        $endTag = ':FatturaElettronica>';
        $end = strrpos($data, $endTag);
        if ($end === false || $end < $start) {
            return null;
        }
        return substr($data, $start, $end + strlen($endTag) - $start);
    }

    private function maybeBase64(string $data): ?string
    {
        $clean = preg_replace('/\s+/', '', $data);
        if ($clean === null || $clean === '' || !preg_match('#^[A-Za-z0-9+/=]+$#', $clean)) {
            return null;
        }
        $decoded = base64_decode($clean, true);
        return $decoded === false ? null : $decoded;
    }
}
