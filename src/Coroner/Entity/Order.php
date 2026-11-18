<?php

namespace App\Coroner\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A bookshop order. The demo application's only entity — realistic enough
 * to make Messenger failures hurt, small enough to clone and run in minutes.
 */
#[ORM\Entity]
#[ORM\Table(name: 'orders')]
class Order implements \JsonSerializable
{
    public const STATUS_RECEIVED = 'received';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_CONFIRMED = 'confirmed';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $sku;

    #[ORM\Column]
    private int $quantity;

    #[ORM\Column(length: 180)]
    private string $customerEmail;

    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_RECEIVED;

    #[ORM\Column(nullable: true)]
    private ?int $remainingStock = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $sku, int $quantity, string $customerEmail)
    {
        $this->sku = $sku;
        $this->quantity = $quantity;
        $this->customerEmail = $customerEmail;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getCustomerEmail(): string
    {
        return $this->customerEmail;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function markShipped(int $remainingStock): void
    {
        $this->status = self::STATUS_SHIPPED;
        $this->remainingStock = $remainingStock;
    }

    public function markConfirmed(): void
    {
        $this->status = self::STATUS_CONFIRMED;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'quantity' => $this->quantity,
            'customer_email' => $this->customerEmail,
            'status' => $this->status,
            'remaining_stock' => $this->remainingStock,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
