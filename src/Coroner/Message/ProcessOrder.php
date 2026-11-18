<?php

namespace App\Coroner\Message;

/**
 * Carries a full snapshot of the order so that, when the message dies,
 * the failed queue holds all the evidence a coroner needs.
 */
final class ProcessOrder implements \JsonSerializable
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $sku,
        public readonly int $quantity,
        public readonly string $customerEmail,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'order_id' => $this->orderId,
            'sku' => $this->sku,
            'quantity' => $this->quantity,
            'customer_email' => $this->customerEmail,
        ];
    }
}
