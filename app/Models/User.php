<?php
declare(strict_types=1);

namespace App\Models;

final class User
{
    public function __construct(
        public int $id,
        public string $username,
        public int $roleId,
        public string $fullname
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['username'],
            (int) $row['role_id'],
            (string) ($row['fullname'] ?? '')
        );
    }
}
