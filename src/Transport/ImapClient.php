<?php

declare(strict_types=1);

namespace Fatturapa\Transport;

use Fatturapa\Exception\TransportException;

/**
 * Minimal IMAP4rev1 client — implicit TLS (port 993), LOGIN, SELECT, SEARCH,
 * FETCH BODY[]. Deliberately dependency-free (ext-imap is deprecated since
 * PHP 8.4): covers exactly what reading SdI notifications from a PEC inbox
 * requires. Not a general mail library.
 */
class ImapClient
{
    private const TIMEOUT = 30;

    /** @var resource|null */
    private $socket;
    private int $tag = 0;

    public function __construct(
        private readonly string $host,
        private readonly int $port = 993,
        private readonly string $username = '',
        private readonly string $password = '',
    ) {
    }

    /** @return $this */
    public function connect(): static
    {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'ssl://' . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            self::TIMEOUT
        );
        if ($socket === false) {
            throw new TransportException("PEC IMAP: cannot connect to {$this->host}:{$this->port}: $errstr ($errno)");
        }
        $this->socket = $socket;
        stream_set_timeout($this->socket, self::TIMEOUT);
        $this->readLine(); // server greeting
        $this->command('LOGIN ' . $this->quote($this->username) . ' ' . $this->quote($this->password));
        return $this;
    }

    /** Select a mailbox (read-write; FETCH BODY[] marks messages \Seen). */
    public function select(string $mailbox = 'INBOX'): void
    {
        $this->command('SELECT ' . $this->quote($mailbox));
    }

    /**
     * Search the selected mailbox; returns message sequence numbers.
     *
     * @param string $criteria e.g. 'UNSEEN', 'UNSEEN FROM "fatturapa.it"'
     * @return int[]
     */
    public function search(string $criteria = 'UNSEEN'): array
    {
        $lines = $this->command('SEARCH ' . $criteria);
        foreach ($lines as $line) {
            if (str_starts_with($line, '* SEARCH')) {
                $ids = trim(substr($line, 8));
                return $ids === '' ? [] : array_map('intval', explode(' ', $ids));
            }
        }
        return [];
    }

    /** Fetch the full raw RFC 5322 message (marks it \Seen). */
    public function fetchMessage(int $number): string
    {
        $this->send('FETCH ' . $number . ' (BODY[])');
        $message = '';
        while (true) {
            $line = $this->readLine();
            if (preg_match('/\{(\d+)\}\s*$/', $line, $m)) {
                $message = $this->readLiteral((int) $m[1]);
                continue;
            }
            if (preg_match('/^A\d+ (OK|NO|BAD)/', $line, $m)) {
                if ($m[1] !== 'OK') {
                    throw new TransportException('PEC IMAP: FETCH failed: ' . trim($line));
                }
                break;
            }
        }
        return $message;
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            try {
                $this->command('LOGOUT');
            } catch (TransportException) {
                // Closing anyway.
            }
            fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Send a command and read until its tagged response; returns all
     * intermediate untagged lines.
     *
     * @return string[]
     */
    private function command(string $cmd): array
    {
        $tag = $this->send($cmd);
        $lines = [];
        while (true) {
            $line = $this->readLine();
            if (str_starts_with($line, $tag . ' ')) {
                if (!str_starts_with($line, $tag . ' OK')) {
                    $safe = str_replace([$this->password, $this->quote($this->password)], '***', trim($line));
                    throw new TransportException('PEC IMAP: command failed: ' . $safe);
                }
                return $lines;
            }
            $lines[] = rtrim($line, "\r\n");
        }
    }

    private function send(string $cmd): string
    {
        $tag = 'A' . ++$this->tag;
        if (fwrite($this->socket, $tag . ' ' . $cmd . "\r\n") === false) {
            throw new TransportException('PEC IMAP: connection lost while writing');
        }
        return $tag;
    }

    private function readLine(): string
    {
        $line = fgets($this->socket, 8192);
        if ($line === false) {
            throw new TransportException('PEC IMAP: connection lost while reading');
        }
        return $line;
    }

    private function readLiteral(int $bytes): string
    {
        $data = '';
        while (strlen($data) < $bytes) {
            $chunk = fread($this->socket, min(8192, $bytes - strlen($data)));
            if ($chunk === false || $chunk === '') {
                throw new TransportException('PEC IMAP: connection lost while reading literal');
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function quote(string $s): string
    {
        return '"' . addcslashes($s, "\"\\") . '"';
    }
}
