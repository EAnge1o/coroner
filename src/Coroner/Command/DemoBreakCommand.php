<?php

namespace App\Coroner\Command;

use App\Coroner\Entity\Order;
use App\Coroner\Message\ProcessOrder;
use App\Coroner\Message\SendOrderConfirmation;
use App\Coroner\Service\InventoryClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * Seeds the demo: healthy orders first, then an outage, a discontinued
 * SKU and a poisoned order. Leaves the app with a graveyard of failures
 * for Coroner to investigate.
 */
#[AsCommand('coroner:demo:break', 'Seed the demo app with orders and a graveyard of failed messages')]
final readonly class DemoBreakCommand
{
    public function __construct(
        private InventoryClient $inventory,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        #[Autowire(service: 'messenger.receiver_locator')]
        private ServiceProviderInterface $receiverLocator,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $this->ensureDatabase($io);

        $io->section('Act 1 — a quiet morning: three healthy orders');
        $this->inventory->setOutage(false);
        $healthy = [
            ['BOOK-ORACLE-001', 2, 'ada@lovelace.dev'],
            ['BOOK-GRIMOIRE-002', 1, 'alan@turing.dev'],
            ['BOOK-ALGEBRA-003', 1, 'grace@hopper.dev'],
        ];
        foreach ($healthy as [$sku, $qty, $email]) {
            $this->dispatchOrder($io, $sku, $qty, $email);
        }
        $this->consume($io, 30);
        $this->confirmShippedOrders($io);
        $this->consume($io, 30);

        $io->section('Act 2 — disaster: outage, a discontinued SKU and poisoned data');
        $this->inventory->setOutage(true);
        $this->dispatchOrder($io, 'BOOK-GHOST-000', 2, 'neo@matrix.dev'); // bug
        $this->dispatchOrder($io, 'BOOK-ORACLE-001', -2, 'poison@shop.test'); // poison
        $this->dispatchOrder($io, 'BOOK-ORACLE-001', 1, 'curie@radium.dev'); // transient
        $this->dispatchOrder($io, 'BOOK-ORACLE-001', 1, 'hamming@codes.dev'); // transient
        $this->dispatchOrder($io, 'BOOK-GRIMOIRE-002', 1, 'lovelace@analytical.dev'); // transient
        $this->consume($io, 60);
        // The outage stays ON: run coroner:demo:heal before retrying the
        // transient failures, or watch them die again.

        $this->printSummary($io);

        $io->success('Graveyard seeded. Now ask about it: bin/console coroner:chat — or plug the MCP server into your AI client.');
        $io->writeln('Try: <comment>claude mcp add coroner -- php bin/console mcp:server coroner</comment>');

        return Command::SUCCESS;
    }

    private function dispatchOrder(SymfonyStyle $io, string $sku, int $qty, string $email): void
    {
        $order = new Order($sku, $qty, $email);
        $this->em->persist($order);
        $this->em->flush();

        $this->bus->dispatch(new ProcessOrder($order->getId(), $sku, $qty, $email));
        $io->writeln(\sprintf('  dispatched order #%d: %s x%d for %s', $order->getId(), $sku, $qty, $email));
    }

    private function confirmShippedOrders(SymfonyStyle $io): void
    {
        $orders = $this->em->getRepository(Order::class)->findAll();
        foreach ($orders as $order) {
            if (Order::STATUS_SHIPPED === $order->getStatus()) {
                $this->bus->dispatch(new SendOrderConfirmation($order->getId(), $order->getCustomerEmail()));
                $io->writeln(\sprintf('  dispatched confirmation for order #%d', $order->getId()));
            }
        }
    }

    private function consume(SymfonyStyle $io, int $timeLimit): void
    {
        $io->writeln('  <comment>running messenger:consume orders ...</comment>');
        $process = new Process(
            [PHP_BINARY, $this->projectDir.'/bin/console', 'messenger:consume', 'orders', '--time-limit='.$timeLimit],
            $this->projectDir,
        );
        $process->setTimeout($timeLimit + 30);
        $process->run();
        if (!$process->isSuccessful()) {
            $io->warning('consume exited non-zero: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        // The worker updated rows behind our back — drop the stale
        // identity map so the next read sees the database, not us.
        $this->em->clear();
    }

    private function printSummary(SymfonyStyle $io): void
    {
        $io->section('Orders');
        $rows = [];
        foreach ($this->em->getRepository(Order::class)->findAll() as $order) {
            $rows[] = [$order->getId(), $order->getSku(), $order->getQuantity(), $order->getCustomerEmail(), $order->getStatus()];
        }
        $io->table(['Id', 'SKU', 'Qty', 'Email', 'Status'], $rows);

        $io->section('Failed messages (the graveyard)');
        $receiver = $this->receiver();
        $rows = [];
        foreach ($receiver->all(50) as $envelope) {
            $rows[] = [
                (string) $envelope->last(TransportMessageIdStamp::class)?->getId(),
                $envelope->getMessage()::class,
                strtok($envelope->last(ErrorDetailsStamp::class)?->getExceptionMessage() ?? '', "\n"),
            ];
        }
        $io->table(['Id', 'Class', 'Error'], $rows);
    }

    private function receiver(): ListableReceiverInterface
    {
        $receiver = $this->receiverLocator->get('failed');
        if (!$receiver instanceof ListableReceiverInterface) {
            throw new \RuntimeException('The "failed" transport does not support listing messages.');
        }

        return $receiver;
    }

    private function ensureDatabase(SymfonyStyle $io): void
    {
        $dataDir = $this->projectDir.'/var/data';
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }

        // SQLite creates its file on connect and does not support
        // database:create — the schema below is all it needs.
        if (!str_starts_with((string) ($_SERVER['DATABASE_URL'] ?? ($_ENV['DATABASE_URL'] ?? '')), 'sqlite')) {
            $create = new Process([PHP_BINARY, $this->projectDir.'/bin/console', 'doctrine:database:create', '--if-not-exists'], $this->projectDir);
            $create->run();
            $io->writeln('  <comment>'.trim($create->getOutput() ?: $create->getErrorOutput()).'</comment>');
        }

        $schema = new Process([PHP_BINARY, $this->projectDir.'/bin/console', 'doctrine:schema:update', '--force', '--complete'], $this->projectDir);
        $schema->run();
        $io->writeln('  <comment>'.trim($schema->getOutput() ?: $schema->getErrorOutput()).'</comment>');
    }
}
