<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\ProductService;

final class ProductController
{
    public function __construct(private ProductService $productService)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        return $this->productService->listAll();
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, pagination: array<string, int|bool>}
     */
    public function listPaginated(int $page, int $perPage = 7): array
    {
        return $this->productService->listPaginated($page, $perPage);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActive(): array
    {
        return $this->productService->listActive();
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool, message:string, errors?:array<int, string>}
     */
    public function create(array $input, ?int $userId = null): array
    {
        return $this->productService->create($input, $userId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool, message:string, errors?:array<int, string>}
     */
    public function update(int $id, array $input, ?int $userId = null): array
    {
        return $this->productService->update($id, $input, $userId);
    }

    /**
     * @return array{success:bool, message:string, errors?:array<int, string>}
     */
    public function delete(int $id): array
    {
        return $this->productService->delete($id);
    }

    /**
     * @return array{success:bool, message:string, errors?:array<int, string>}
     */
    public function restock(int $id): array
    {
        return $this->productService->restock($id);
    }

    public function find(int $id): ?array
    {
        return $this->productService->findById($id);
    }
}
