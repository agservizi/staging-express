<?php
declare(strict_types=1);

namespace App\Services;

use DateInterval;
use DateTimeImmutable;
use PDO;
use PDOException;

/**
 * Gestione autenticazione del portale clienti con sessioni persistenti opzionali.
 */
final class CustomerPortalAuthService
{
    private const SESSION_KEY = 'portal_account';
    private const REMEMBER_COOKIE = 'portal_session';
    private const REMEMBER_LIFETIME_DAYS = 14;

    public function __construct(private PDO $pdo)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    /**
     * @return array{success:bool, account?:array<string, mixed>, errors?:array<int, string>}
     */
    public function login(string $email, string $password, bool $remember = false): array
    {
    $normalizedEmail = trim((function_exists('mb_strtolower') ? mb_strtolower($email) : strtolower($email)));
        if ($normalizedEmail === '' || $password === '') {
            return [
                'success' => false,
                'errors' => ['Inserisci email e password.'],
            ];
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, customer_id, email, password_hash, invite_token, last_login_at
             FROM customer_portal_accounts
             WHERE email = :email'
        );
        $stmt->execute([':email' => $normalizedEmail]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account || !is_string($account['password_hash']) || $account['password_hash'] === '') {
            return [
                'success' => false,
                'errors' => ['Credenziali non valide.'],
            ];
        }

        if (!password_verify($password, $account['password_hash'])) {
            return [
                'success' => false,
                'errors' => ['Credenziali non valide.'],
            ];
        }

        $accountData = [
            'id' => (int) $account['id'],
            'customer_id' => (int) $account['customer_id'],
            'email' => (string) $account['email'],
        ];

        $this->persistPortalSession($accountData, $remember);

        $this->pdo->prepare(
            'UPDATE customer_portal_accounts SET last_login_at = NOW() WHERE id = :id'
        )->execute([':id' => $accountData['id']]);

        return [
            'success' => true,
            'account' => $accountData,
        ];
    }

    public function logout(): void
    {
        $sessionAccount = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_array($sessionAccount) && isset($sessionAccount['session_token'])) {
            $this->deletePersistentSession((string) $sessionAccount['session_token']);
        }

        unset($_SESSION[self::SESSION_KEY]);
        $this->clearRememberCookie();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentAccount(): ?array
    {
        $sessionAccount = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_array($sessionAccount) && isset($sessionAccount['id'])) {
            return $sessionAccount;
        }

        $cookieToken = $_COOKIE[self::REMEMBER_COOKIE] ?? null;
        if (!is_string($cookieToken) || $cookieToken === '') {
            return null;
        }

        $tokenHash = hash('sha256', $cookieToken);
        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.portal_account_id, a.email, a.customer_id
             FROM customer_portal_sessions s
             INNER JOIN customer_portal_accounts a ON a.id = s.portal_account_id
             WHERE s.session_token = :token AND s.expires_at > NOW()'
        );
        $stmt->execute([':token' => $tokenHash]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            $this->clearRememberCookie();
            return null;
        }

        $accountData = [
            'id' => (int) $session['portal_account_id'],
            'customer_id' => (int) $session['customer_id'],
            'email' => (string) $session['email'],
            'session_token' => $tokenHash,
        ];

        $_SESSION[self::SESSION_KEY] = $accountData;
        $this->refreshPersistentSession($tokenHash);

        return $accountData;
    }

    /**
     * @return array{success:bool, message:string, token?:string, errors?:array<int, string>}
     */
    public function createInvitation(int $customerId, string $email): array
    {
    $normalizedEmail = trim((function_exists('mb_strtolower') ? mb_strtolower($email) : strtolower($email)));
        if ($normalizedEmail === '') {
            return [
                'success' => false,
                'message' => 'Email non valida.',
                'errors' => ['Inserisci un indirizzo email.'],
            ];
        }

        $customerExists = $this->pdo->prepare('SELECT id FROM customers WHERE id = :id');
        $customerExists->execute([':id' => $customerId]);
        if ($customerExists->fetchColumn() === false) {
            return [
                'success' => false,
                'message' => 'Cliente non trovato.',
                'errors' => ['Indica un cliente valido.'],
            ];
        }

        $token = bin2hex(random_bytes(32));
        $inviteTokenHash = hash('sha256', $token);

        try {
            $this->pdo->beginTransaction();

            $check = $this->pdo->prepare('SELECT id FROM customer_portal_accounts WHERE email = :email');
            $check->execute([':email' => $normalizedEmail]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $update = $this->pdo->prepare(
                    'UPDATE customer_portal_accounts
                     SET invite_token = :token, invite_sent_at = NOW()
                     WHERE id = :id'
                );
                $update->execute([
                    ':token' => $inviteTokenHash,
                    ':id' => $existing['id'],
                ]);
            } else {
                $insert = $this->pdo->prepare(
                    'INSERT INTO customer_portal_accounts (customer_id, email, password_hash, invite_token, invite_sent_at)
                     VALUES (:customer_id, :email, "", :token, NOW())'
                );
                $insert->execute([
                    ':customer_id' => $customerId,
                    ':email' => $normalizedEmail,
                    ':token' => $inviteTokenHash,
                ]);
            }

            $this->pdo->commit();
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            return [
                'success' => false,
                'message' => 'Impossibile creare l\'invito.',
                'errors' => [$exception->getMessage()],
            ];
        }

        return [
            'success' => true,
            'message' => 'Invito generato correttamente.',
            'token' => $token,
        ];
    }

    /**
     * @return array{success:bool, message:string, errors?:array<int, string>}
     */
    public function completeInvitation(string $token, string $newPassword): array
    {
        $token = trim($token);
        if ($token === '') {
            return [
                'success' => false,
                'message' => 'Token non valido.',
                'errors' => ['Link di attivazione non valido o scaduto.'],
            ];
        }

        if (strlen($newPassword) < 8) {
            return [
                'success' => false,
                'message' => 'Password troppo corta.',
                'errors' => ['La password deve avere almeno 8 caratteri.'],
            ];
        }

        $tokenHash = hash('sha256', $token);
        $stmt = $this->pdo->prepare(
            'SELECT id FROM customer_portal_accounts WHERE invite_token = :token'
        );
        $stmt->execute([':token' => $tokenHash]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            return [
                'success' => false,
                'message' => 'Token non valido.',
                'errors' => ['Link di attivazione non valido o già usato.'],
            ];
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $update = $this->pdo->prepare(
            'UPDATE customer_portal_accounts
             SET password_hash = :hash, invite_token = NULL, invite_sent_at = NULL
             WHERE id = :id'
        );
        $update->execute([
            ':hash' => $hash,
            ':id' => $account['id'],
        ]);

        return [
            'success' => true,
            'message' => 'Account attivato correttamente. Ora puoi accedere.',
        ];
    }

    /**
     * @return array{success:bool, message:string, errors?:array<int, string>}
     */
    public function updatePassword(int $accountId, string $currentPassword, string $newPassword): array
    {
        if (strlen($newPassword) < 8) {
            return [
                'success' => false,
                'message' => 'Password troppo corta.',
                'errors' => ['La nuova password deve avere almeno 8 caratteri.'],
            ];
        }

        $stmt = $this->pdo->prepare(
            'SELECT password_hash FROM customer_portal_accounts WHERE id = :id'
        );
        $stmt->execute([':id' => $accountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$account || !is_string($account['password_hash']) || $account['password_hash'] === '') {
            return [
                'success' => false,
                'message' => 'Account non trovato.',
                'errors' => ['Nessun account associato.'],
            ];
        }

        if (!password_verify($currentPassword, $account['password_hash'])) {
            return [
                'success' => false,
                'message' => 'Password attuale non valida.',
                'errors' => ['La password attuale non è corretta.'],
            ];
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $update = $this->pdo->prepare(
            'UPDATE customer_portal_accounts SET password_hash = :hash WHERE id = :id'
        );
        $update->execute([
            ':hash' => $hash,
            ':id' => $accountId,
        ]);

        return [
            'success' => true,
            'message' => 'Password aggiornata correttamente.',
        ];
    }

    private function persistPortalSession(array $account, bool $remember): void
    {
        $_SESSION[self::SESSION_KEY] = $account;

        if (!$remember) {
            $this->clearRememberCookie();
            return;
        }

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        $expiresAt = (new DateTimeImmutable('now'))
            ->add(new DateInterval('P' . self::REMEMBER_LIFETIME_DAYS . 'D'))
            ->format('Y-m-d H:i:s');

        $this->pdo->prepare(
            'INSERT INTO customer_portal_sessions (portal_account_id, session_token, created_at, expires_at, user_agent, ip_address)
             VALUES (:account_id, :token, NOW(), :expires, :agent, :ip)'
        )->execute([
            ':account_id' => $account['id'],
            ':token' => $tokenHash,
            ':expires' => $expiresAt,
            ':agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        $_SESSION[self::SESSION_KEY]['session_token'] = $tokenHash;

        setcookie(self::REMEMBER_COOKIE, $rawToken, [
            'expires' => time() + (self::REMEMBER_LIFETIME_DAYS * 86400),
            'path' => '/portal',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::REMEMBER_COOKIE] = $rawToken;
    }

    private function refreshPersistentSession(string $tokenHash): void
    {
        $expiresAt = (new DateTimeImmutable('now'))
            ->add(new DateInterval('P' . self::REMEMBER_LIFETIME_DAYS . 'D'))
            ->format('Y-m-d H:i:s');

        $this->pdo->prepare(
            'UPDATE customer_portal_sessions SET expires_at = :expires WHERE session_token = :token'
        )->execute([
            ':expires' => $expiresAt,
            ':token' => $tokenHash,
        ]);

        setcookie(self::REMEMBER_COOKIE, $_COOKIE[self::REMEMBER_COOKIE] ?? '', [
            'expires' => time() + (self::REMEMBER_LIFETIME_DAYS * 86400),
            'path' => '/portal',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function deletePersistentSession(string $tokenHash): void
    {
        $this->pdo->prepare(
            'DELETE FROM customer_portal_sessions WHERE session_token = :token'
        )->execute([':token' => $tokenHash]);
    }

    private function clearRememberCookie(): void
    {
        setcookie(self::REMEMBER_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/portal',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::REMEMBER_COOKIE]);
    }
}
