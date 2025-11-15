<?php
declare(strict_types=1);

namespace App\Models;

final class Sale
{
    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function __construct(
        public int $id,
        public int $userId,
        public ?string $customerName,
        public float $total,
        public float $vat,
        public float $discount,
        public string $paymentMethod,
        public string $createdAt,
        public array $items = []
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $items
     */
    public static function fromRow(array $row, array $items): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['user_id'],
            $row['customer_name'] !== null ? (string) $row['customer_name'] : null,
            (float) $row['total'],
            (float) $row['vat'],
            (float) $row['discount'],
            (string) $row['payment_method'],
            (string) $row['created_at'],
            $items
        );
    }
}
