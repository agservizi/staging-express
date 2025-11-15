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
     * @return array{success:bool, errors?:array<int, string>}
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

        $user = $this->authService->login($username, $password, $remember);
        if ($user === null) {
            return [
                'success' => false,
                'errors' => ['Credenziali non valide.'],
                'old' => ['username' => $username, 'remember_me' => $remember],
            ];
        }

        return ['success' => true];
    }

    public function logout(): void
    {
        $this->authService->logout();
        header('Location: index.php?page=login');
        exit;
    }

    public function requireAuth(): void
    {
        if ($this->authService->currentUser() === null) {
            header('Location: index.php?page=login');
            exit;
        }
    }
}
