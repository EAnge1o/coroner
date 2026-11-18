<?php

namespace App\Tests\Coroner;

use App\Coroner\Service\InventoryClient;
use App\Coroner\Service\InventoryUnavailableException;
use PHPUnit\Framework\TestCase;

final class InventoryClientTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/coroner-inventory-test-'.uniqid();
        mkdir($this->projectDir.'/var', 0777, true);
    }

    protected function tearDown(): void
    {
        (new InventoryClient($this->projectDir))->setOutage(false);
        @rmdir($this->projectDir.'/var');
        @rmdir($this->projectDir);
    }

    public function testReserveReturnsRemainingStock(): void
    {
        $client = new InventoryClient($this->projectDir);

        $this->assertSame(35, $client->reserve('BOOK-ORACLE-001', 2));
        $this->assertSame(3, $client->reserve('BOOK-ALGEBRA-003', 1));
    }

    public function testReserveThrowsWhenOutOfStock(): void
    {
        $client = new InventoryClient($this->projectDir);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('out of stock');

        $client->reserve('BOOK-ALGEBRA-003', 5);
    }

    public function testReserveThrowsOnUnknownSku(): void
    {
        $client = new InventoryClient($this->projectDir);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown SKU');

        $client->reserve('BOOK-NONEXISTENT-999', 1);
    }

    public function testDiscontinuedSkuDiesWithTypeErrorUntilTheCodeIsFixed(): void
    {
        $client = new InventoryClient($this->projectDir);

        // The bug: BOOK-GHOST-000 has no 'count' key; the raised warning
        // flows into null and the typed stock math throws. This is the
        // "fix the code, then retry" failure class.
        $this->expectException(\TypeError::class);

        @$client->reserve('BOOK-GHOST-000', 2);
    }

    public function testOutageBlocksEverythingUntilHealed(): void
    {
        $client = new InventoryClient($this->projectDir);

        $client->setOutage(true);
        try {
            $client->reserve('BOOK-ORACLE-001', 1);
            $this->fail('Expected InventoryUnavailableException during outage.');
        } catch (InventoryUnavailableException $e) {
            $this->assertStringContainsString('simulated outage', $e->getMessage());
        }

        $client->setOutage(false);
        $this->assertSame(36, $client->reserve('BOOK-ORACLE-001', 1));
    }
}
