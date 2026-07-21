<?php

declare(strict_types=1);

namespace AlpsFatturapa\Tests;

use AlpsFatturapa\Lifecycle\InvoiceStore;
use AlpsFatturapa\Notifications\SdiNotification;
use PDO;
use PHPUnit\Framework\TestCase;

final class InvoiceStoreTest extends TestCase
{
    private function store(): InvoiceStore
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $store = new InvoiceStore($pdo);
        $store->ensureTable();
        return $store;
    }

    public function testLifecycleBuiltSentDelivered(): void
    {
        $store = $this->store();
        $id = $store->recordBuilt('2026/00001', '<xml/>');
        $this->assertSame(InvoiceStore::STATUS_BUILT, $store->find($id)['status']);

        $store->recordSent($id, 'IT01234567890_00001.xml');
        $this->assertSame(InvoiceStore::STATUS_SENT, $store->find($id)['status']);

        $matched = $store->applyNotification(new SdiNotification(
            tipo: SdiNotification::RC,
            identificativoSdi: '999',
            nomeFile: 'IT01234567890_00001.xml',
            dataOraRicezione: '2026-07-01T10:00:00',
        ));
        $this->assertSame($id, $matched);
        $this->assertSame(InvoiceStore::STATUS_DELIVERED, $store->find($id)['status']);
    }

    public function testScartoStoresErrorsAndAllowsListing(): void
    {
        $store = $this->store();
        $id = $store->recordBuilt('2026/00002', '<xml/>');
        $store->recordSent($id, 'IT01234567890_00002.xml');

        $store->applyNotification(new SdiNotification(
            tipo: SdiNotification::NS,
            identificativoSdi: '1000',
            nomeFile: 'IT01234567890_00002.xml',
            dataOraRicezione: null,
            errori: [['codice' => '00404', 'descrizione' => 'Fattura duplicata']],
        ));
        $row = $store->find($id);
        $this->assertSame(InvoiceStore::STATUS_REJECTED, $row['status']);
        $this->assertStringContainsString('00404', (string) $row['note']);

        $rejected = $store->listByStatus(InvoiceStore::STATUS_REJECTED);
        $this->assertCount(1, $rejected);
    }

    public function testEsitoCommittenteMapping(): void
    {
        $store = $this->store();
        $ne = fn (?string $esito) => new SdiNotification(SdiNotification::NE, '1', 'f.xml', null, [], $esito);
        $this->assertSame(InvoiceStore::STATUS_ACCEPTED, $store->statusFor($ne('EC01')));
        $this->assertSame(InvoiceStore::STATUS_REFUSED, $store->statusFor($ne('EC02')));
        $this->assertNull($store->statusFor($ne(null)));
    }

    public function testUnmatchedNotificationReturnsNull(): void
    {
        $store = $this->store();
        $this->assertNull($store->applyNotification(new SdiNotification(
            tipo: SdiNotification::RC,
            identificativoSdi: '1',
            nomeFile: 'IT99999999999_ZZZZZ.xml',
            dataOraRicezione: null,
        )));
    }
}
