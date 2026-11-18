<?php

namespace App\Coroner\Command;

use App\Coroner\Service\InventoryClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('coroner:demo:heal', 'Clear the simulated inventory API outage')]
final readonly class DemoHealCommand
{
    public function __construct(private InventoryClient $inventory)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $this->inventory->setOutage(false);
        $io->success('Inventory API is back up. Transient failures can now be retried successfully — ask Coroner to retry them.');
        $io->writeln('The discontinued-SKU bug and the poisoned order will fail again: one needs a code fix, the other needs discarding.');

        return Command::SUCCESS;
    }
}
