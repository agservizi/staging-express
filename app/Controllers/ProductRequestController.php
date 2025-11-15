<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\ProductRequestService;

final class ProductRequestController
{
    public function __construct(private ProductRequestService $service)
    {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   pagination: array{page:int, per_page:int, total:int, pages:int},
     *   filters: array{status:?string,type:?string,payment:?string,q:?string,from:?string,to:?string}
     * }
     */
    public function list(array $filters, int $page, int $perPage): array
    {
        return $this->service->listRequests($filters, $page, $perPage);
    }

    public function get(int $requestId): ?array
    {
        return $this->service->getRequest($requestId);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $operator
     * @return array{success:bool,message?:string,errors?:array<int,string>}
     */
    public function update(int $requestId, array $input, array $operator): array
    {
        return $this->service->updateRequest($requestId, $input, $operator);
    }

    /**
     * @return array<string, int>
     */
    public function summary(): array
    {
        return $this->service->getStatusSummary();
    }

    /**
     * @return array<int, string>
     */
    public function statusOptions(): array
    {
        return $this->service->getAllowedStatuses();
    }

    /**
     * @return array<int, string>
     */
    public function typeOptions(): array
    {
        return $this->service->getAllowedTypes();
    }

    /**
     * @return array<int, string>
     */
    public function paymentOptions(): array
    {
        return $this->service->getAllowedPaymentMethods();
    }
}
