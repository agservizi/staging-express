<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\CustomerService;

final class CustomerController
{
    public function __construct(private CustomerService $customerService)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        return $this->customerService->listAll();
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, pagination: array<string, int|bool>}
     */
    public function listPaginated(int $page, int $perPage = 10, ?string $search = null): array
    {
        return $this->customerService->listPaginated($page, $perPage, $search);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool, message?:string, id?:int, portal_account?:array<string, mixed>, errors?:array<int, string>}
     */
    public function create(array $input): array
    {
        $payload = [
            'fullname' => $input['fullname'] ?? null,
            'email' => $input['email'] ?? null,
            'phone' => $input['phone'] ?? null,
            'tax_code' => $input['tax_code'] ?? null,
            'note' => $input['note'] ?? null,
        ];

        return $this->customerService->create($payload);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool, message:string, errors?:array<int, string>, portal_account?:array<string, mixed>}
     */
    public function update(int $id, array $input): array
    {
        $payload = [
            'fullname' => $input['fullname'] ?? null,
            'email' => $input['email'] ?? null,
            'phone' => $input['phone'] ?? null,
            'tax_code' => $input['tax_code'] ?? null,
            'note' => $input['note'] ?? null,
        ];

        return $this->customerService->update($id, $payload);
    }

    /**
     * @return array{success:bool, message:string, errors?:array<int, string>}
     */
    public function delete(int $id): array
    {
        return $this->customerService->delete($id);
    }

    public function find(int $id): ?array
    {
        return $this->customerService->find($id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $term): array
    {
        return $this->customerService->search($term);
    }

    /**
     * @return array{success:bool, message:string, errors?:array<int, string>, portal_account?:array<string, mixed>}
     */
    public function resendPortalCredentials(int $id): array
    {
        return $this->customerService->resendPortalCredentials($id);
    }

    /**
     * @return array{success:bool, message:string, errors?:array<int, string>, invitation?:array<string, mixed>}
     */
    public function sendPortalInvitation(int $id): array
    {
        return $this->customerService->sendPortalInvitation($id);
    }
}
