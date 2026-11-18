<?php

namespace App\Coroner\Message;

final class SendOrderConfirmation implements \JsonSerializable
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $customerEmail,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'order_id' => $this->orderId,
            'customer_email' => $this->customerEmail,
        ];
    }
}
