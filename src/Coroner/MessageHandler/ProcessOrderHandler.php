<?php

namespace App\Coroner\MessageHandler;

use App\Coroner\Entity\Order;
use App\Coroner\Message\ProcessOrder;
use App\Coroner\Service\InventoryClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ProcessOrderHandler
{
    public function __construct(
        private InventoryClient $inventory,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ProcessOrder $message): void
    {
        $order = $this->em->find(Order::class, $message->orderId);
        if (null === $order) {
            throw new \DomainException(\sprintf('Order %d does not exist — refusing to process a phantom order.', $message->orderId));
        }

        // POISON: a non-positive quantity is invalid data, not a hiccup.
        // No amount of retrying will ever turn -2 books into a shipment.
        if ($message->quantity <= 0) {
            throw new \DomainException(\sprintf('Invalid order: quantity must be positive, got %d.', $message->quantity));
        }

        // TRANSIENT (outage) and BUG (ghost SKU) both blow up in here.
        $remaining = $this->inventory->reserve($message->sku, $message->quantity);

        $order->markShipped($remaining);
        $this->em->flush();
    }
}
