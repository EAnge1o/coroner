<?php

namespace App\Coroner\Service;

/**
 * Stands in for a third-party inventory API. Deliberately contains the
 * three failure classes that give Coroner something to investigate:
 *
 *  1. TRANSIENT — an "outage" of the external API (flag file), which
 *     retries can eventually survive.
 *  2. BUG — BOOK-GHOST-000 (discontinued) is missing the nested 'count'
 *     key, so reserve() dies with a TypeError until the code is fixed.
 *  3. POISON — a negative quantity can never succeed, no matter how
 *     often the message is retried.
 */
final class InventoryClient
{
    private const CATALOG = [
        'BOOK-ORACLE-001' => ['title' => 'The Oracle of Ephesus', 'stock' => ['count' => 37]],
        'BOOK-GRIMOIRE-002' => ['title' => 'A Practical Grimoire', 'stock' => ['count' => 12]],
        'BOOK-ALGEBRA-003' => ['title' => 'Algebra for Exiles', 'stock' => ['count' => 4]],
        // Discontinued: the catalog entry has no live stock count any more.
        // Reading ['stock']['count'] below is the bug — null flows into an
        // int-typed arithmetic and PHP throws a TypeError.
        'BOOK-GHOST-000' => ['title' => 'Ghost Edition (discontinued)', 'stock' => ['reserved' => 0]],
    ];

    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * @return int the remaining stock count after the reservation
     */
    public function reserve(string $sku, int $quantity): int
    {
        if ($this->isOutage()) {
            throw new InventoryUnavailableException('Inventory API timeout: connection refused after 3 attempts (simulated outage).');
        }

        $row = self::CATALOG[$sku] ?? null;
        if (null === $row) {
            throw new \InvalidArgumentException(\sprintf('Unknown SKU "%s" in inventory catalog.', $sku));
        }

        // The bug: for BOOK-GHOST-000 the 'count' key does not exist, PHP
        // raises "Undefined array key" and $count ends up null — the int
        // arithmetic below then throws a TypeError.
        $count = $row['stock']['count'];
        $remaining = $count - $quantity;

        if ($remaining < 0) {
            throw new \DomainException(\sprintf('SKU "%s" is out of stock: requested %d, available %d.', $sku, $quantity, $count));
        }

        return $remaining;
    }

    public function isOutage(): bool
    {
        return is_file($this->projectDir.'/var/inventory-outage.flag');
    }

    public function setOutage(bool $outage): void
    {
        $flag = $this->projectDir.'/var/inventory-outage.flag';
        if ($outage) {
            if (!is_dir(\dirname($flag))) {
                mkdir(\dirname($flag), 0777, true);
            }
            touch($flag);
        } elseif (is_file($flag)) {
            unlink($flag);
        }
    }
}
