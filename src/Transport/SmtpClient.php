<?php

declare(strict_types=1);

namespace Fatturapa\Transport;

use Fatturapa\Exception\TransportException;

/**
 * Minimal SMTP client for PEC delivery — implicit TLS (port 465) + AUTH LOGIN.
 *
 * Deliberately dependency-free: covers exactly what Italian PEC providers
 * (Aruba, Register, Legalmail, …) require to submit a message. Not a general
 * mail library.
 */
class SmtpClient
{
    private const TIMEOUT = 30;

    /** @var resource|null */
    private $socket;

    public function __construct(
        private readonly string $host,
        private readonly int $port = 465,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
    ) {
    }

    /**
     * Submit a raw RFC 5322 message.
     *
     * @param string[] $recipients
     * @throws TransportException on any SMTP failure.
     */
    public function send(string $from, array $recipients, string $rawMessage): void
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
            throw new TransportException("PEC SMTP: cannot connect to {$this->host}:{$this->port}: $errstr ($errno)");
        }
        $this->socket = $socket;
        stream_set_timeout($this->socket, self::TIMEOUT);

        try {
            $this->expect(220);
            $this->command('EHLO ' . gethostname(), 250);
            if ($this->username !== null) {
                $this->command('AUTH LOGIN', 334);
                $this->command(base64_encode($this->username), 334);
                $this->command(base64_encode((string) $this->password), 235);
            }
            $this->command('MAIL FROM:<' . $from . '>', 250);
            foreach ($recipients as $rcpt) {
                $this->command('RCPT TO:<' . $rcpt . '>', 250);
            }
            $this->command('DATA', 354);
            // Dot-stuffing per RFC 5321 §4.5.2.
            $data = preg_replace('/^\./m', '..', $rawMessage);
            $this->write(rtrim($data, "\r\n") . "\r\n.");
            $this->expect(250);
            $this->command('QUIT', 221);
        } finally {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    private function command(string $line, int $expectCode): void
    {
        $this->write($line);
        $this->expect($expectCode);
    }

    private function write(string $line): void
    {
        if (fwrite($this->socket, $line . "\r\n") === false) {
            throw new TransportException('PEC SMTP: connection lost while writing');
        }
    }

    private function expect(int $code): void
    {
        $response = '';
        do {
            $line = fgets($this->socket, 2048);
            if ($line === false) {
                throw new TransportException("PEC SMTP: no response (expected $code); got: " . trim($response));
            }
            $response .= $line;
        } while (isset($line[3]) && $line[3] === '-'); // multi-line reply

        if ((int) substr($line, 0, 3) !== $code) {
            // Never leak credentials that may echo in error text.
            $safe = $this->password !== null ? str_replace(base64_encode($this->password), '***', trim($response)) : trim($response);
            throw new TransportException("PEC SMTP: expected $code, got: $safe");
        }
    }
}
