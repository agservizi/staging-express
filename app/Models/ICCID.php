<?php
declare(strict_types=1);

namespace App\Models;

final class ICCID
{
    public function __construct(
        public int $id,
        public string $iccid,
        public int $providerId,
        public string $status,
        public ?string $notes,
        public string $providerName
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['iccid'],
            (int) $row['provider_id'],
            (string) $row['status'],
            $row['notes'] !== null ? (string) $row['notes'] : null,
            (string) ($row['provider_name'] ?? '')
        );
    }
}
