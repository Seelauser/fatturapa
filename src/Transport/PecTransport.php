<?php

declare(strict_types=1);

namespace Fatturapa\Transport;

use Fatturapa\Contracts\SdiTransport;
use Fatturapa\Exception\TransportException;

/**
 * SdI transport over the PEC channel — no intermediary service needed, only
 * the sender's own PEC mailbox.
 *
 * How the channel works (fatturapa.gov.it, "Inviare la FatturaPA via PEC"):
 *  - The FIRST invoice ever is sent to sdi01@pec.fatturapa.it; SdI replies by
 *    PEC assigning a dedicated address to use for all subsequent sends —
 *    configure it here once received.
 *  - The attachment filename MUST follow the SdI pattern
 *    IT<IdFiscale>_<progressivo 5 alfanum>.xml (unique per transmitter).
 *  - All SdI notifications (RC consegna, NS scarto, MC, …) arrive as messages
 *    in the sender's PEC inbox; parse the attached XML with NotificationParser.
 *  - Limits: message ≤ 30 MB, invoice file ≤ 5 MB. Asynchronous by nature —
 *    there is no synchronous accept/reject.
 */
class PecTransport implements SdiTransport
{
    public const SDI_FIRST_CONTACT = 'sdi01@pec.fatturapa.it';

    private SmtpClient $smtp;

    /**
     * @param string $pecAddress  Your PEC address (the envelope/From sender).
     * @param string $cedentePiva 11-digit partita IVA of the cedente, used in the SdI filename.
     * @param string $sdiAddress  Destination: SDI_FIRST_CONTACT for the very first send,
     *                            then the dedicated address SdI assigned to you.
     */
    public function __construct(
        private readonly string $pecAddress,
        private readonly string $cedentePiva,
        string $smtpHost,
        string $smtpUsername,
        string $smtpPassword,
        private readonly string $sdiAddress = self::SDI_FIRST_CONTACT,
        int $smtpPort = 465,
        ?SmtpClient $smtp = null,
    ) {
        $this->smtp = $smtp ?? new SmtpClient($smtpHost, $smtpPort, $smtpUsername, $smtpPassword);
    }

    /** Build a transport from PEC_* environment variables. */
    public static function createFromEnv(): self
    {
        $env = static fn (string $k): string => (string) (getenv($k) ?: ($_ENV[$k] ?? ''));
        foreach (['PEC_ADDRESS', 'PEC_SMTP_HOST', 'PEC_SMTP_USERNAME', 'PEC_SMTP_PASSWORD', 'CEDENTE_PIVA'] as $k) {
            if ($env($k) === '') {
                throw new TransportException("$k is not configured");
            }
        }
        return new self(
            $env('PEC_ADDRESS'),
            $env('CEDENTE_PIVA'),
            $env('PEC_SMTP_HOST'),
            $env('PEC_SMTP_USERNAME'),
            $env('PEC_SMTP_PASSWORD'),
            $env('PEC_SDI_ADDRESS') !== '' ? $env('PEC_SDI_ADDRESS') : self::SDI_FIRST_CONTACT,
            $env('PEC_SMTP_PORT') !== '' ? (int) $env('PEC_SMTP_PORT') : 465,
        );
    }

    public function sendInvoice(string $xml, array $meta = []): array
    {
        if (strlen($xml) > 5_000_000) {
            throw new TransportException('SdI rejects invoice files over 5 MB');
        }
        $filename = $meta['filename'] ?? $this->sdiFilename($meta['progressivo'] ?? null);
        if (!preg_match('/^IT\d{11}_[A-Za-z0-9]{1,5}\.xml$/', $filename)) {
            throw new TransportException("PEC filename '$filename' does not match the SdI pattern IT<piva>_<progressivo>.xml");
        }

        $boundary = '=_' . bin2hex(random_bytes(16));
        $message =
            "From: <{$this->pecAddress}>\r\n" .
            "To: <{$this->sdiAddress}>\r\n" .
            "Subject: Invio fattura {$filename}\r\n" .
            "MIME-Version: 1.0\r\n" .
            "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n" .
            "\r\n" .
            "--$boundary\r\n" .
            "Content-Type: text/plain; charset=utf-8\r\n\r\n" .
            "Trasmissione fattura elettronica {$filename} al Sistema di Interscambio.\r\n" .
            "--$boundary\r\n" .
            "Content-Type: application/xml; name=\"$filename\"\r\n" .
            "Content-Disposition: attachment; filename=\"$filename\"\r\n" .
            "Content-Transfer-Encoding: base64\r\n\r\n" .
            chunk_split(base64_encode($xml), 76, "\r\n") .
            "--$boundary--\r\n";

        $this->smtp->send($this->pecAddress, [$this->sdiAddress], $message);

        return ['identificativo' => $filename, 'raw' => ['channel' => 'pec', 'to' => $this->sdiAddress]];
    }

    /**
     * The PEC channel is asynchronous: SdI outcomes arrive as messages in the
     * sender's PEC inbox. Feed the attached XML files to NotificationParser.
     */
    public function getInvoiceStatus(string $identificativo): array
    {
        return [
            'status' => 'pending-pec',
            'raw' => ['note' => 'Check the PEC inbox for SdI notifications regarding ' . $identificativo],
        ];
    }

    /** SdI transmission filename: IT<piva>_<progressivo>.xml, progressivo unique per transmitter. */
    public function sdiFilename(?string $progressivo = null): string
    {
        $progressivo ??= substr(strtoupper(bin2hex(random_bytes(3))), 0, 5);
        return 'IT' . $this->cedentePiva . '_' . $progressivo . '.xml';
    }
}
