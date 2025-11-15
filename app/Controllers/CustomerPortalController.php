<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\CustomerPortalAuthService;
use App\Services\CustomerPortalService;

final class CustomerPortalController
{
    public function __construct(
        private CustomerPortalAuthService $authService,
        private CustomerPortalService $portalService
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool, account?:array<string, mixed>, errors?:array<int, string>}
     */
    public function login(array $input): array
    {
        $email = isset($input['email']) ? (string) $input['email'] : '';
        $password = isset($input['password']) ? (string) $input['password'] : '';
        $remember = isset($input['remember']) && (string) $input['remember'] === '1';

        return $this->authService->login($email, $password, $remember);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool, message:string, token?:string, errors?:array<int, string>}
     */
    public function invite(array $input): array
    {
        $customerId = isset($input['customer_id']) ? (int) $input['customer_id'] : 0;
        $email = isset($input['email']) ? (string) $input['email'] : '';

        return $this->authService->createInvitation($customerId, $email);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool, message:string, errors?:array<int, string>}
     */
    public function completeInvitation(array $input): array
    {
        $token = isset($input['token']) ? (string) $input['token'] : '';
        $password = isset($input['password']) ? (string) $input['password'] : '';

        return $this->authService->completeInvitation($token, $password);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool, message:string, errors?:array<int, string>}
     */
    public function updatePassword(int $accountId, array $input): array
    {
        $current = isset($input['current_password']) ? (string) $input['current_password'] : '';
        $new = isset($input['new_password']) ? (string) $input['new_password'] : '';

        return $this->authService->updatePassword($accountId, $current, $new);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool, message:string, errors?:array<int, string>}
     */
    public function createPaymentRequest(int $portalAccountId, int $customerId, array $input): array
    {
        return $this->portalService->createPaymentRequest($portalAccountId, $customerId, $input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool, message:string, errors?:array<int, string>}
     */
    public function createSupportRequest(int $customerId, int $portalAccountId, array $input): array
    {
        return $this->portalService->createSupportRequest($customerId, $portalAccountId, $input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool, message:string, errors?:array<int, string>}
     */
    public function createProductRequest(int $customerId, int $portalAccountId, array $input): array
    {
        return $this->portalService->createProductRequest($customerId, $portalAccountId, $input);
    }

    public function logout(): void
    {
        $this->authService->logout();
    }
}
