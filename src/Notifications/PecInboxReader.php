<?php

declare(strict_types=1);

namespace AlpsFatturapa\Notifications;

use AlpsFatturapa\Exception\TransportException;
use AlpsFatturapa\Transport\ImapClient;
use InvalidArgumentException;

/**
 * Polls a PEC inbox over IMAP and returns the SdI notifications found in
 * unseen messages — closing the loop of the PEC channel without any
 * third-party service.
 *
 * Fetching marks messages \Seen, so repeated polls only process new mail.
 */
class PecInboxReader
{
    public function __construct(
        private readonly ImapClient $imap,
        private readonly MimeAttachmentExtractor $extractor = new MimeAttachmentExtractor(),
        private readonly NotificationParser $parser = new NotificationParser(),
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
        $this->imap->connect();
        try {
            $this->imap->select('INBOX');
            $found = [];
            foreach ($this->imap->search($searchCriteria) as $number) {
                foreach ($this->extractor->extract($this->imap->fetchMessage($number)) as $att) {
                    if (!preg_match('/\.xml$/i', $att['filename'])) {
                        continue;
                    }
                    try {
                        $found[] = [
                            'filename' => $att['filename'],
                            'notification' => $this->parser->parse($att['content']),
                        ];
                    } catch (InvalidArgumentException) {
                        // Not an SdI notification (e.g. the echoed invoice itself) — skip.
                    }
                }
            }
            return $found;
        } finally {
            $this->imap->close();
        }
    }
}
