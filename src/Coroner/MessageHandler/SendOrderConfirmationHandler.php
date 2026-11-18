<?php

namespace App\Coroner\MessageHandler;

use App\Coroner\Entity\Order;
use App\Coroner\Message\SendOrderConfirmation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendOrderConfirmationHandler
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function __invoke(SendOrderConfirmation $message): void
    {
        $order = $this->em->find(Order::class, $message->orderId);
        if (null === $order) {
            throw new \DomainException(\sprintf('Order %d does not exist — cannot confirm a phantom order.', $message->orderId));
        }

        $order->markConfirmed();
        $this->em->flush();
    }
}
