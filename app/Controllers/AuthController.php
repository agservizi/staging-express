<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;

final class AuthController
{
    public function __construct(private AuthService $authService)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool, errors?:array<int, string>, mfa_required?:bool, redirect?:string, old?:array<string, mixed>}
     */
    public function login(array $input): array
    {
        $username = trim((string) ($input['username'] ?? ''));
        if ($username !== '') {
            $username = function_exists('mb_strtolower') ? mb_strtolower($username) : strtolower($username);
        }
        $password = (string) ($input['password'] ?? '');
        $remember = isset($input['remember_me']) && (string) $input['remember_me'] === '1';

        if ($username === '' || $password === '') {
            return [
                'success' => false,
                'errors' => ['Inserisci username e password.'],
                'old' => ['username' => $username, 'remember_me' => $remember],
            ];
        }

        $result = $this->authService->login($username, $password, $remember);
        if ($result['success'] ?? false) {
            return ['success' => true];
        }

        if (!empty($result['mfa_required'])) {
            return [
                'success' => false,
                'mfa_required' => true,
                'redirect' => 'index.php?page=login_mfa',
                'old' => ['username' => $username, 'remember_me' => $remember],
            ];
        }

        return [
            'success' => false,
            'errors' => [isset($result['error']) ? (string) $result['error'] : 'Credenziali non valide.'],
            'old' => ['username' => $username, 'remember_me' => $remember],
        ];
    }

    public function logout(): void
    {
        $this->authService->logout();
        header('Location: index.php?page=login');
        exit;
    }

    public function hasPendingMfa(): bool
    {
        return $this->authService->hasPendingMfa();
    }

    /**
     * @return array{username:string, expires_in:int}|null
     */
    public function getPendingMfa(): ?array
    {
        return $this->authService->getPendingMfa();
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool, error?:string}
     */
    public function verifyMfa(array $input): array
    {
        $code = isset($input['mfa_code']) ? (string) $input['mfa_code'] : '';
        return $this->authService->verifyPendingMfa($code);
    }

    public function cancelPendingMfa(): void
    {
        $this->authService->cancelPendingMfa();
    }

    public function cancelMfaSetup(int $userId): void
    {
        $this->authService->cancelMfaSetup($userId);
    }

    /**
     * @return array{success:bool, secret?:string, otpauth_url?:string, qr_url?:string, error?:string}
     */
    public function beginMfaSetup(int $userId, string $issuer): array
    {
        return $this->authService->beginMfaSetup($userId, $issuer);
    }

    /**
     * @return array{success:bool, secret?:string, otpauth_url?:string, qr_url?:string, error?:string}
     */
    public function getMfaSetupSecret(int $userId, string $issuer): array
    {
        return $this->authService->getMfaSetupSecret($userId, $issuer);
    }

    /**
     * @return array{success:bool, recovery_codes?:array<int,string>, error?:string}
     */
    public function confirmMfaSetup(int $userId, string $code): array
    {
        return $this->authService->confirmMfaSetup($userId, $code);
    }

    /**
     * @return array{success:bool, message?:string, error?:string}
     */
    public function disableMfa(int $userId, ?string $code = null, bool $force = false): array
    {
        return $this->authService->disableMfa($userId, $code, $force);
    }

    /**
     * @return array{success:bool, recovery_codes?:array<int,string>, error?:string}
     */
    public function regenerateRecoveryCodes(int $userId, ?string $code = null): array
    {
        return $this->authService->regenerateRecoveryCodes($userId, $code);
    }

    /**
     * @return array{mfa_enabled:bool, mfa_enabled_at:?string}|null
     */
    public function getSecurityState(int $userId): ?array
    {
        return $this->authService->getSecurityState($userId);
    }

    public function requireAuth(): void
    {
        if ($this->authService->currentUser() === null) {
            header('Location: index.php?page=login');
            exit;
        }
    }
}
