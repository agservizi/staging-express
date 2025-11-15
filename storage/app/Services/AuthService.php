<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;
use Throwable;

final class AuthService
{
    private const REMEMBER_COOKIE = 'coresuite_remember';
    private const REMEMBER_LIFETIME_DAYS = 30;
    private bool $rememberTableChecked = false;

    public function __construct(private PDO $pdo)
    {
    }

    public function register(string $username, string $password, int $roleId, ?string $fullname = null): bool
    {
        $normalizedUsername = trim($username);
        if ($normalizedUsername !== '') {
            $normalizedUsername = function_exists('mb_strtolower') ? mb_strtolower($normalizedUsername) : strtolower($normalizedUsername);
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, password_hash, role_id, fullname) VALUES (:u, :p, :r, :f)'
        );

        return $stmt->execute([
            ':u' => $normalizedUsername,
            ':p' => $hash,
            ':r' => $roleId,
            ':f' => $fullname,
        ]);
    }

    public function login(string $username, string $password, bool $remember = false): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, username, password_hash, role_id, fullname FROM users WHERE username = :u LIMIT 1'
        );
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $sessionUser = [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'role_id' => (int) $user['role_id'],
                'fullname' => $user['fullname'] ?? '',
            ];
            $_SESSION['user'] = $sessionUser;

            if ($remember) {
                $this->createRememberToken($sessionUser['id']);
            } else {
                $this->clearRememberCookie();
            }

            return $sessionUser;
        }

        return null;
    }

    public function logout(): void
    {
        $this->removeRememberToken();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }

    public function currentUser(): ?array
    {
        $user = $_SESSION['user'] ?? null;
        if (is_array($user)) {
            return $user;
        }

        $auto = $this->attemptAutoLogin();
        if ($auto !== null) {
            $_SESSION['user'] = $auto;
        }

        return $auto;
    }

    public function hasRole(string $roleName): bool
    {
        $user = $this->currentUser();
        if ($user === null) {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT name FROM roles WHERE id = :id');
        $stmt->execute([':id' => $user['role_id']]);
        $role = $stmt->fetchColumn();

        return $role === $roleName;
    }

    private function createRememberToken(int $userId): void
    {
        try {
            $this->ensureRememberTableExists();
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', time() + (self::REMEMBER_LIFETIME_DAYS * 86400));

            $this->pdo->beginTransaction();
            $delete = $this->pdo->prepare('DELETE FROM user_remember_tokens WHERE user_id = :uid');
            $delete->execute([':uid' => $userId]);

            $insert = $this->pdo->prepare(
                'INSERT INTO user_remember_tokens (user_id, token_hash, expires_at) VALUES (:uid, :hash, :expires)'
            );
            $insert->execute([
                ':uid' => $userId,
                ':hash' => $tokenHash,
                ':expires' => $expiresAt,
            ]);
            if ($this->pdo->inTransaction()) {
                $this->pdo->commit();
            }

            $cookieOptions = [
                'expires' => time() + (self::REMEMBER_LIFETIME_DAYS * 86400),
                'path' => '/',
                'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Lax',
            ];

            setcookie(self::REMEMBER_COOKIE, $token, $cookieOptions);
            $_COOKIE[self::REMEMBER_COOKIE] = $token;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('Remember-me token creation failed: ' . $exception->getMessage());
            $this->clearRememberCookie();
        }
    }

    private function clearRememberCookie(): void
    {
        setcookie(self::REMEMBER_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::REMEMBER_COOKIE]);
    }

    private function removeRememberToken(): void
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? null;
        if (!is_string($cookie) || $cookie === '') {
            $this->clearRememberCookie();
            return;
        }

        try {
            $this->ensureRememberTableExists();
            $stmt = $this->pdo->prepare('DELETE FROM user_remember_tokens WHERE token_hash = :hash');
            $stmt->execute([':hash' => hash('sha256', $cookie)]);
        } catch (PDOException $exception) {
            error_log('Remember-me token removal failed: ' . $exception->getMessage());
        } finally {
            $this->clearRememberCookie();
        }
    }

    private function attemptAutoLogin(): ?array
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? null;
        if (!is_string($cookie) || $cookie === '') {
            return null;
        }

        try {
            $this->ensureRememberTableExists();
            $stmt = $this->pdo->prepare(
                'SELECT u.id, u.username, u.role_id, u.fullname
                 FROM user_remember_tokens t
                 INNER JOIN users u ON u.id = t.user_id
                 WHERE t.token_hash = :hash AND t.expires_at > NOW()
                 LIMIT 1'
            );
            $stmt->execute([':hash' => hash('sha256', $cookie)]);
            $user = $stmt->fetch();

            if (!$user) {
                $this->clearRememberCookie();
                return null;
            }

            $sessionUser = [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'role_id' => (int) $user['role_id'],
                'fullname' => $user['fullname'] ?? '',
            ];

            $this->createRememberToken($sessionUser['id']);

            return $sessionUser;
        } catch (PDOException $exception) {
            error_log('Remember-me auto-login failed: ' . $exception->getMessage());
            $this->clearRememberCookie();
            return null;
        }
    }

    private function ensureRememberTableExists(): void
    {
        if ($this->rememberTableChecked) {
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_remember_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token_hash CHAR(64) NOT NULL UNIQUE,
                expires_at DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                CONSTRAINT fk_user_remember_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )'
        );

        $this->rememberTableChecked = true;
    }
}
