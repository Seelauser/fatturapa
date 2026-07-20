<?php

declare(strict_types=1);

namespace AlpsFatturapa\Tests;

use AlpsFatturapa\Exception\TransportException;
use AlpsFatturapa\Transport\PecTransport;
use AlpsFatturapa\Transport\SmtpClient;
use PHPUnit\Framework\TestCase;

final class PecTransportTest extends TestCase
{
    /** @var array{from: string, recipients: string[], message: string}|null */
    private ?array $sent = null;

    private function transport(string $sdiAddress = PecTransport::SDI_FIRST_CONTACT): PecTransport
    {
        $smtp = new class ($this) extends SmtpClient {
            public function __construct(private readonly PecTransportTest $test)
            {
                parent::__construct('unused.invalid');
            }

            public function send(string $from, array $recipients, string $rawMessage): void
            {
                $this->test->capture($from, $recipients, $rawMessage);
            }
        };
        return new PecTransport(
            'mittente@pec.example.it',
            '01234567890',
            'unused.invalid',
            'user',
            'pass',
            $sdiAddress,
            465,
            $smtp,
        );
    }

    public function capture(string $from, array $recipients, string $message): void
    {
        $this->sent = ['from' => $from, 'recipients' => $recipients, 'message' => $message];
    }

    public function testSendsXmlAsAttachmentWithSdiFilename(): void
    {
        $result = $this->transport()->sendInvoice('<xml/>', ['progressivo' => '00001']);

        $this->assertSame('IT01234567890_00001.xml', $result['identificativo']);
        $this->assertNotNull($this->sent);
        $this->assertSame('mittente@pec.example.it', $this->sent['from']);
        $this->assertSame([PecTransport::SDI_FIRST_CONTACT], $this->sent['recipients']);
        $this->assertStringContainsString('filename="IT01234567890_00001.xml"', $this->sent['message']);
        $this->assertStringContainsString(base64_encode('<xml/>'), $this->sent['message']);
    }

    public function testUsesDedicatedSdiAddressWhenConfigured(): void
    {
        $this->transport('sdi71@pec.fatturapa.it')->sendInvoice('<xml/>');
        $this->assertSame(['sdi71@pec.fatturapa.it'], $this->sent['recipients']);
    }

    public function testRejectsInvalidFilename(): void
    {
        $this->expectException(TransportException::class);
        $this->transport()->sendInvoice('<xml/>', ['filename' => 'fattura.xml']);
    }

    public function testRejectsOversizeInvoice(): void
    {
        $this->expectException(TransportException::class);
        $this->transport()->sendInvoice(str_repeat('x', 5_000_001));
    }

    public function testStatusIsAsynchronous(): void
    {
        $status = $this->transport()->getInvoiceStatus('IT01234567890_00001.xml');
        $this->assertSame('pending-pec', $status['status']);
    }
}
