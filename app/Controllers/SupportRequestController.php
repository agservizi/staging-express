<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\SupportRequestService;

final class SupportRequestController
{
    public function __construct(private SupportRequestService $supportRequestService)
    {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   pagination: array{page:int, per_page:int, total:int, pages:int},
     *   filters: array{status:?string,type:?string,q:?string,from:?string,to:?string}
     * }
     */
    public function list(array $filters, int $page, int $perPage): array
    {
        return $this->supportRequestService->listRequests($filters, $page, $perPage);
    }

    public function find(int $requestId): ?array
    {
        if ($requestId <= 0) {
            return null;
        }

        return $this->supportRequestService->getRequest($requestId);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $operator
     * @return array{success:bool,message?:string,errors?:array<int,string>}
     */
    public function update(array $input, array $operator): array
    {
        $requestId = isset($input['request_id']) ? (int) $input['request_id'] : 0;
        if ($requestId <= 0) {
            return [
                'success' => false,
                'errors' => ['Richiesta non valida.'],
            ];
        }

        $status = isset($input['status']) ? (string) $input['status'] : '';
        $note = isset($input['resolution_note']) ? trim((string) $input['resolution_note']) : null;
        if ($note === '') {
            $note = null;
        }
        $append = isset($input['append_note']) && $input['append_note'] === '1';

        return $this->supportRequestService->updateRequest($requestId, $status, $note, $append, $operator);
    }

    /**
     * @return array<string, int>
     */
    public function statusSummary(): array
    {
        return $this->supportRequestService->getStatusSummary();
    }

    /**
     * @return array<int, string>
     */
    public function statusOptions(): array
    {
        return $this->supportRequestService->getAllowedStatuses();
    }

    /**
     * @return array<int, string>
     */
    public function typeOptions(): array
    {
        return $this->supportRequestService->getAllowedTypes();
    }
}
