<?php

declare(strict_types=1);

namespace Fatturapa\Tests;

use Fatturapa\Notifications\NotificationParser;
use Fatturapa\Notifications\SdiNotification;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NotificationParserTest extends TestCase
{
    public function testParsesRicevutaConsegna(): void
    {
        $n = (new NotificationParser())->parse(<<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <ns3:RicevutaConsegna xmlns:ns3="http://www.fatturapa.gov.it/sdi/messaggi/v1.0" versione="1.0">
              <IdentificativoSdI>12345678</IdentificativoSdI>
              <NomeFile>IT01234567890_00001.xml</NomeFile>
              <DataOraRicezione>2026-07-01T10:00:00.000+02:00</DataOraRicezione>
            </ns3:RicevutaConsegna>
            XML);
        $this->assertSame(SdiNotification::RC, $n->tipo);
        $this->assertSame('12345678', $n->identificativoSdi);
        $this->assertSame('IT01234567890_00001.xml', $n->nomeFile);
        $this->assertTrue($n->isPositive());
        $this->assertFalse($n->isRejection());
    }

    public function testParsesNotificaScartoWithErrors(): void
    {
        $n = (new NotificationParser())->parse(<<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <ns3:NotificaScarto xmlns:ns3="http://www.fatturapa.gov.it/sdi/messaggi/v1.0" versione="1.0">
              <IdentificativoSdI>12345679</IdentificativoSdI>
              <NomeFile>IT01234567890_00002.xml</NomeFile>
              <ListaErrori>
                <Errore><Codice>00404</Codice><Descrizione>Fattura duplicata</Descrizione></Errore>
                <Errore><Codice>00423</Codice><Descrizione>Valore PrezzoTotale non calcolato</Descrizione></Errore>
              </ListaErrori>
            </ns3:NotificaScarto>
            XML);
        $this->assertSame(SdiNotification::NS, $n->tipo);
        $this->assertTrue($n->isRejection());
        $this->assertCount(2, $n->errori);
        $this->assertSame('00404', $n->errori[0]['codice']);
    }

    public function testParsesEsitoCommittenteRejection(): void
    {
        $n = (new NotificationParser())->parse(<<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <ns3:NotificaEsito xmlns:ns3="http://www.fatturapa.gov.it/sdi/messaggi/v1.0" versione="1.0">
              <IdentificativoSdI>12345680</IdentificativoSdI>
              <EsitoCommittente>
                <IdentificativoSdI>12345680</IdentificativoSdI>
                <Esito>EC02</Esito>
              </EsitoCommittente>
            </ns3:NotificaEsito>
            XML);
        $this->assertSame(SdiNotification::NE, $n->tipo);
        $this->assertSame('EC02', $n->esitoCommittente);
        $this->assertTrue($n->isRejection());
    }

    public function testUnknownRootThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new NotificationParser())->parse('<Boh/>');
    }
}
