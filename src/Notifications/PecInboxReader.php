<?php

declare(strict_types=1);

namespace Fatturapa\Notifications;

use Fatturapa\Exception\TransportException;
use Fatturapa\Passive\P7mExtractor;
use Fatturapa\Passive\ReceivedInvoiceParser;
use Fatturapa\Transport\ImapClient;
use InvalidArgumentException;

/**
 * Polls a PEC inbox over IMAP and returns the SdI notifications AND incoming
 * invoices (ciclo passivo) found in unseen messages — closing the loop of the
 * PEC channel without any third-party service.
 *
 * Fetching marks messages \Seen, so repeated polls only process new mail.
 */
class PecInboxReader
{
    public function __construct(
        private readonly ImapClient $imap,
        private readonly MimeAttachmentExtractor $extractor = new MimeAttachmentExtractor(),
        private readonly NotificationParser $parser = new NotificationParser(),
        private readonly P7mExtractor $p7m = new P7mExtractor(),
        private readonly ReceivedInvoiceParser $invoiceParser = new ReceivedInvoiceParser(),
    ) {
    }

    /** Build a reader from PEC_IMAP_* / PEC_SMTP_* environment variables. */
    public static function createFromEnv(): self
    {
        $env = static fn (string $k): string => (string) (getenv($k) ?: ($_ENV[$k] ?? ''));
        $host = $env('PEC_IMAP_HOST');
        if ($host === '') {
            throw new TransportException('PEC_IMAP_HOST is not configured');
        }
        return new self(new ImapClient(
            $host,
            $env('PEC_IMAP_PORT') !== '' ? (int) $env('PEC_IMAP_PORT') : 993,
            $env('PEC_IMAP_USERNAME') !== '' ? $env('PEC_IMAP_USERNAME') : $env('PEC_SMTP_USERNAME'),
            $env('PEC_IMAP_PASSWORD') !== '' ? $env('PEC_IMAP_PASSWORD') : $env('PEC_SMTP_PASSWORD'),
        ));
    }

    /**
     * Fetch unseen messages and return every SdI notification found.
     *
     * @return array<array{filename: string, notification: SdiNotification}>
     */
    public function fetchNotifications(string $searchCriteria = 'UNSEEN'): array
    {
        return $this->fetchAll($searchCriteria)['notifications'];
    }

    /**
     * Fetch unseen messages and classify every SdI artifact: outcome
     * notifications and incoming invoices (ciclo passivo; .xml and .xml.p7m).
     *
     * @return array{
     *   notifications: array<array{filename: string, notification: SdiNotification}>,
     *   invoices: array<array{filename: string, xml: string, invoice: array<string, mixed>}>
     * }
     */
    public function fetchAll(string $searchCriteria = 'UNSEEN'): array
    {
        $this->imap->connect();
        try {
            $this->imap->select('INBOX');
            $result = ['notifications' => [], 'invoices' => []];
            foreach ($this->imap->search($searchCriteria) as $number) {
                foreach ($this->extractor->extract($this->imap->fetchMessage($number)) as $att) {
                    $this->classify($att['filename'], $att['content'], $result);
                }
            }
            return $result;
        } finally {
            $this->imap->close();
        }
    }

    /** @param array{notifications: array, invoices: array} $result */
    private function classify(string $filename, string $content, array &$result): void
    {
        if (preg_match('/\.p7m$/i', $filename)) {
            try {
                $xml = $this->p7m->extract($content);
                $result['invoices'][] = [
                    'filename' => $filename,
                    'xml' => $xml,
                    'invoice' => $this->invoiceParser->parse($xml),
                ];
            } catch (InvalidArgumentException) {
                // Signed but not a FatturaPA payload — skip.
            }
            return;
        }
        if (!preg_match('/\.xml$/i', $filename)) {
            return;
        }
        try {
            $result['notifications'][] = [
                'filename' => $filename,
                'notification' => $this->parser->parse($content),
            ];
            return;
        } catch (InvalidArgumentException) {
            // Not a notification — maybe an incoming invoice.
        }
        try {
            $result['invoices'][] = [
                'filename' => $filename,
                'xml' => $content,
                'invoice' => $this->invoiceParser->parse($content),
            ];
        } catch (InvalidArgumentException) {
            // Neither notification nor invoice — skip.
        }
    }
}
