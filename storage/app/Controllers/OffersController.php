<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\OffersService;

final class OffersController
{
    public function __construct(private OffersService $offersService)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        return $this->offersService->listOffers();
    }

    /**
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   pagination: array{page:int, per_page:int, total:int, pages:int}
     * }
     */
    public function listPaginated(int $page, int $perPage, ?string $status = null): array
    {
        return $this->offersService->paginateOffers($page, $perPage, $status);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActive(): array
    {
        return $this->offersService->listActiveOffers();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function providers(): array
    {
        return $this->offersService->listProviders();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->offersService->getOffer($id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool, errors?:array<int, string>, offer_id?:int}
     */
    public function save(array $input): array
    {
        $offerId = isset($input['id']) && $input['id'] !== '' ? (int) $input['id'] : null;
        if ($offerId !== null) {
            return $this->offersService->updateOffer($offerId, $input);
        }

        return $this->offersService->createOffer($input);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->offersService->updateStatus($id, $status);
    }
}
