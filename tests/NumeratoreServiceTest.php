<?php

declare(strict_types=1);

namespace Fatturapa\Tests;

use Fatturapa\NumeratoreService;
use PDO;
use PHPUnit\Framework\TestCase;

final class NumeratoreServiceTest extends TestCase
{
    private function sqliteService(): NumeratoreService
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $svc = new NumeratoreService($pdo);
        $svc->ensureTable();
        return $svc;
    }

    public function testSequentialNumbersOnSqlite(): void
    {
        $svc = $this->sqliteService();
        $this->assertSame('2026/00001', $svc->next(2026));
        $this->assertSame('2026/00002', $svc->next(2026));
        $this->assertSame('2026/00003', $svc->next(2026));
    }

    public function testSezionaleHasIndependentCounter(): void
    {
        $svc = $this->sqliteService();
        $svc->next(2026);
        $this->assertSame('2026/00001/EXT', $svc->next(2026, 'ext'));
        $this->assertSame('2026/00002/EXT', $svc->next(2026, 'EXT'));
        $this->assertSame('2026/00002', $svc->next(2026));
    }

    public function testYearsAreIndependent(): void
    {
        $svc = $this->sqliteService();
        $svc->next(2026);
        $this->assertSame('2027/00001', $svc->next(2027));
    }

    public function testInvalidTableNameRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new NumeratoreService(new PDO('sqlite::memory:'), 'bad; DROP TABLE x');
    }
}
