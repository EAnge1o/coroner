<?php

namespace App\Coroner\Command;

use App\Coroner\Service\InventoryClient;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('coroner:demo:reset', 'Wipe all demo orders and failed messages, clear the outage')]
final readonly class DemoResetCommand
{
    private const TABLES = ['orders', 'messenger_orders', 'messenger_failed'];

    public function __construct(
        private Connection $connection,
        private InventoryClient $inventory,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        foreach (self::TABLES as $table) {
            try {
                $this->connection->executeStatement("DELETE FROM $table");
            } catch (\Throwable) {
                // table not created yet — nothing to reset
            }
        }
        $this->inventory->setOutage(false);
        $io->success('Demo state wiped: orders, transports and outage flag are clean.');

        return Command::SUCCESS;
    }
}
