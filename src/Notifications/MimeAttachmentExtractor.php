<?php

declare(strict_types=1);

namespace AlpsFatturapa\Notifications;

/**
 * Extracts file attachments from a raw RFC 5322 (MIME) message, recursing into
 * nested message/rfc822 parts.
 *
 * PEC messages need the recursion: the provider's "busta di trasporto" wraps
 * the original message as a postacert.eml attachment, and the SdI notification
 * XML sits inside that inner message.
 */
class MimeAttachmentExtractor
{
    /**
     * @return array<array{filename: string, content: string}>
     */
    public function extract(string $rawMessage): array
    {
        [$headers, $body] = $this->splitMessage($rawMessage);
        return $this->extractFromPart($headers, $body);
    }

    /** @return array<array{filename: string, content: string}> */
    private function extractFromPart(string $headers, string $body): array
    {
        $contentType = $this->header($headers, 'Content-Type');

        if (preg_match('#multipart/[a-z-]+#i', $contentType)
            && preg_match('/boundary\s*=\s*"?([^";\r\n]+)"?/i', $contentType, $m)
        ) {
            $attachments = [];
            foreach ($this->splitMultipart($body, $m[1]) as $part) {
                [$partHeaders, $partBody] = $this->splitMessage($part);
                $attachments = array_merge($attachments, $this->extractFromPart($partHeaders, $partBody));
            }
            return $attachments;
        }

        if (preg_match('#message/rfc822#i', $contentType)) {
            // Nested message (PEC postacert.eml): decode and recurse into it.
            return $this->extract($this->decodeBody($headers, $body));
        }

        $filename = $this->filename($headers);
        if ($filename === null) {
            return [];
        }
        return [['filename' => $filename, 'content' => $this->decodeBody($headers, $body)]];
    }

    /** @return array{0: string, 1: string} [headers, body] */
    private function splitMessage(string $raw): array
    {
        $raw = ltrim($raw, "\r\n");
        $pos = strpos($raw, "\r\n\r\n");
        $len = 4;
        if ($pos === false) {
            $pos = strpos($raw, "\n\n");
            $len = 2;
        }
        if ($pos === false) {
            return [$raw, ''];
        }
        // Unfold continuation lines in the header block.
        $headers = preg_replace('/\r?\n[ \t]+/', ' ', substr($raw, 0, $pos));
        return [$headers, substr($raw, $pos + $len)];
    }

    /** @return string[] */
    private function splitMultipart(string $body, string $boundary): array
    {
        $pieces = preg_split('/\r?\n?--' . preg_quote($boundary, '/') . '(?:--)?[ \t]*\r?\n?/', $body);
        // First piece is the preamble, last (after --boundary--) the epilogue.
        return array_values(array_filter(array_slice($pieces, 1, -1), fn ($p) => trim($p) !== ''));
    }

    private function header(string $headers, string $name): string
    {
        return preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/im', $headers, $m) ? trim($m[1]) : '';
    }

    private function filename(string $headers): ?string
    {
        $disposition = $this->header($headers, 'Content-Disposition');
        $contentType = $this->header($headers, 'Content-Type');
        foreach ([$disposition, $contentType] as $h) {
            if (preg_match('/(?:filename|name)\s*=\s*"?([^";\r\n]+)"?/i', $h, $m)) {
                return $this->decodeEncodedWord(trim($m[1]));
            }
        }
        return null;
    }

    private function decodeBody(string $headers, string $body): string
    {
        return match (strtolower($this->header($headers, 'Content-Transfer-Encoding'))) {
            'base64' => (string) base64_decode($body, false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    /** Decode RFC 2047 encoded-words in filenames (=?utf-8?B?...?=). */
    private function decodeEncodedWord(string $s): string
    {
        return preg_replace_callback(
            '/=\?([^?]+)\?([BQ])\?([^?]*)\?=/i',
            function (array $m): string {
                $text = strtoupper($m[2]) === 'B'
                    ? (string) base64_decode($m[3], false)
                    : quoted_printable_decode(str_replace('_', ' ', $m[3]));
                $charset = strtoupper($m[1]);
                return $charset === 'UTF-8' || $charset === 'US-ASCII'
                    ? $text
                    : (string) mb_convert_encoding($text, 'UTF-8', $charset);
            },
            $s
        ) ?? $s;
    }
}
