<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\DiscountService;

final class DiscountController
{
    public function __construct(private DiscountService $discountService)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        return $this->discountService->listAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActive(): array
    {
        return $this->discountService->listActive();
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool, message?:string, errors?:array<int, string>}
     */
    public function create(array $input): array
    {
        return $this->discountService->create($input);
    }

    /**
     * @return array{success:bool, message?:string, errors?:array<int, string>}
     */
    public function setStatus(int $id, bool $active): array
    {
        return $this->discountService->setStatus($id, $active);
    }

    public function find(int $id): ?array
    {
        return $this->discountService->find($id);
    }
}
