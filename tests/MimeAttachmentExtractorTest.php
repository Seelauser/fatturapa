<?php

declare(strict_types=1);

namespace Fatturapa\Tests;

use Fatturapa\Notifications\MimeAttachmentExtractor;
use Fatturapa\Notifications\NotificationParser;
use Fatturapa\Notifications\SdiNotification;
use PHPUnit\Framework\TestCase;

final class MimeAttachmentExtractorTest extends TestCase
{
    private const NOTIFICA_RC = '<?xml version="1.0"?>' . "\n"
        . '<ns3:RicevutaConsegna xmlns:ns3="http://www.fatturapa.gov.it/sdi/messaggi/v1.0">'
        . '<IdentificativoSdI>777</IdentificativoSdI>'
        . '<NomeFile>IT01234567890_00001.xml</NomeFile>'
        . '</ns3:RicevutaConsegna>';

    /** A PEC-style message: transport envelope wrapping postacert.eml wrapping the XML. */
    private function pecMessage(): string
    {
        $inner =
            "From: sdi@pec.fatturapa.it\r\n" .
            "Subject: CONSEGNA: IT01234567890_00001.xml\r\n" .
            "MIME-Version: 1.0\r\n" .
            "Content-Type: multipart/mixed; boundary=\"inner-b\"\r\n" .
            "\r\n" .
            "--inner-b\r\n" .
            "Content-Type: text/plain; charset=utf-8\r\n\r\n" .
            "Ricevuta di consegna.\r\n" .
            "--inner-b\r\n" .
            "Content-Type: application/xml; name=\"IT01234567890_00001_RC_001.xml\"\r\n" .
            "Content-Disposition: attachment; filename=\"IT01234567890_00001_RC_001.xml\"\r\n" .
            "Content-Transfer-Encoding: base64\r\n\r\n" .
            chunk_split(base64_encode(self::NOTIFICA_RC), 76, "\r\n") .
            "--inner-b--\r\n";

        return
            "From: posta-certificata@pec.fatturapa.it\r\n" .
            "Subject: POSTA CERTIFICATA: CONSEGNA\r\n" .
            "MIME-Version: 1.0\r\n" .
            "Content-Type: multipart/mixed; boundary=\"outer-b\"\r\n" .
            "\r\n" .
            "--outer-b\r\n" .
            "Content-Type: text/plain; charset=utf-8\r\n\r\n" .
            "Messaggio di posta certificata.\r\n" .
            "--outer-b\r\n" .
            "Content-Type: message/rfc822; name=\"postacert.eml\"\r\n" .
            "Content-Disposition: attachment; filename=\"postacert.eml\"\r\n" .
            "\r\n" .
            $inner .
            "--outer-b--\r\n";
    }

    public function testExtractsXmlFromNestedPecMessage(): void
    {
        $attachments = (new MimeAttachmentExtractor())->extract($this->pecMessage());

        $xml = array_values(array_filter($attachments, fn ($a) => str_ends_with($a['filename'], '.xml')));
        $this->assertCount(1, $xml);
        $this->assertSame('IT01234567890_00001_RC_001.xml', $xml[0]['filename']);

        $n = (new NotificationParser())->parse($xml[0]['content']);
        $this->assertSame(SdiNotification::RC, $n->tipo);
        $this->assertSame('777', $n->identificativoSdi);
    }

    public function testDecodesQuotedPrintableAndEncodedWordFilename(): void
    {
        $msg =
            "Content-Type: multipart/mixed; boundary=\"b1\"\r\n" .
            "\r\n" .
            "--b1\r\n" .
            "Content-Type: text/plain; name=\"=?UTF-8?B?" . base64_encode('ricevuta è.txt') . "?=\"\r\n" .
            "Content-Disposition: attachment\r\n" .
            "Content-Transfer-Encoding: quoted-printable\r\n\r\n" .
            "citt=C3=A0\r\n" .
            "--b1--\r\n";
        $attachments = (new MimeAttachmentExtractor())->extract($msg);
        $this->assertCount(1, $attachments);
        $this->assertSame('ricevuta è.txt', $attachments[0]['filename']);
        $this->assertStringContainsString('città', $attachments[0]['content']);
    }

    public function testMessageWithoutAttachmentsYieldsNothing(): void
    {
        $msg = "Content-Type: text/plain\r\n\r\nhello";
        $this->assertSame([], (new MimeAttachmentExtractor())->extract($msg));
    }
}
