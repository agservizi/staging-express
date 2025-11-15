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
    private const SESSION_PENDING_MFA = 'auth_pending_mfa';
    private const SESSION_MFA_SETUP = 'auth_mfa_setup';
    private const PENDING_MFA_TTL = 600; // seconds

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

    /**
     * @return array{success:bool, mfa_required?:bool, username?:string, error?:string}
     */
    public function login(string $username, string $password, bool $remember = false): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, username, password_hash, role_id, fullname, mfa_enabled, mfa_secret FROM users WHERE username = :u LIMIT 1'
        );
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            return [
                'success' => false,
                'error' => 'Credenziali non valide.',
            ];
        }

        session_regenerate_id(true);
        $sessionUser = [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'role_id' => (int) $user['role_id'],
            'fullname' => $user['fullname'] ?? '',
        ];

        $requiresMfa = ((int) ($user['mfa_enabled'] ?? 0) === 1) && isset($user['mfa_secret']) && (string) $user['mfa_secret'] !== '';

        if ($requiresMfa) {
            $this->storePendingMfa($sessionUser, $remember);
            return [
                'success' => false,
                'mfa_required' => true,
                'username' => $sessionUser['username'],
            ];
        }

        $this->finalizeLogin($sessionUser, $remember);

        return ['success' => true];
    }

    public function logout(): void
    {
        $this->removeRememberToken();
        $this->cancelPendingMfa();
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

    public function hasPendingMfa(): bool
    {
        $pending = $_SESSION[self::SESSION_PENDING_MFA] ?? null;
        if (!is_array($pending) || !isset($pending['created_at'])) {
            return false;
        }

        if ((time() - (int) $pending['created_at']) > self::PENDING_MFA_TTL) {
            $this->cancelPendingMfa();
            return false;
        }

        return true;
    }

    /**
     * @return array{username:string, expires_in:int}|null
     */
    public function getPendingMfa(): ?array
    {
        if (!$this->hasPendingMfa()) {
            return null;
        }

        $pending = $_SESSION[self::SESSION_PENDING_MFA];
        $expiresIn = max(0, self::PENDING_MFA_TTL - (time() - (int) $pending['created_at']));

        return [
            'username' => (string) ($pending['user']['username'] ?? ''),
            'expires_in' => $expiresIn,
        ];
    }

    /**
     * @return array{success:bool, error?:string}
     */
    public function verifyPendingMfa(string $code): array
    {
        if (!$this->hasPendingMfa()) {
            return [
                'success' => false,
                'error' => 'Sessione di verifica non valida o scaduta.',
            ];
        }

        $pending = $_SESSION[self::SESSION_PENDING_MFA];
        $userId = (int) ($pending['user']['id'] ?? 0);
        if ($userId <= 0) {
            $this->cancelPendingMfa();
            return [
                'success' => false,
                'error' => 'Utente non valido.',
            ];
        }

        $normalizedCode = $this->sanitizeOtpCode($code);
        if ($normalizedCode === '') {
            return [
                'success' => false,
                'error' => 'Inserisci un codice valido.',
            ];
        }

        if ($this->verifyUserTotp($userId, $normalizedCode) === true) {
            $remember = (bool) ($pending['remember'] ?? false);
            $this->finalizeLogin($pending['user'], $remember);
            return ['success' => true];
        }

        if ($this->useRecoveryCode($userId, $normalizedCode)) {
            $remember = (bool) ($pending['remember'] ?? false);
            $this->finalizeLogin($pending['user'], $remember);
            return ['success' => true];
        }

        return [
            'success' => false,
            'error' => 'Codice non valido. Verifica e riprova.',
        ];
    }

    public function cancelPendingMfa(): void
    {
        unset($_SESSION[self::SESSION_PENDING_MFA]);
    }

    public function cancelMfaSetup(int $userId): void
    {
        $setupState = $_SESSION[self::SESSION_MFA_SETUP] ?? null;
        if (is_array($setupState) && array_key_exists($userId, $setupState)) {
            unset($_SESSION[self::SESSION_MFA_SETUP][$userId]);
        }
    }

    /**
     * @return array{success:bool, secret?:string, otpauth_url?:string, qr_url?:string, error?:string}
     */
    public function beginMfaSetup(int $userId, string $issuer): array
    {
        $user = $this->findUserById($userId);
        if ($user === null) {
            return [
                'success' => false,
                'error' => 'Utente non trovato.',
            ];
        }

        if ((int) ($user['mfa_enabled'] ?? 0) === 1) {
            return [
                'success' => false,
                'error' => 'L’autenticazione a due fattori è già attiva.',
            ];
        }

        $secret = $this->generateMfaSecret();
        if (!isset($_SESSION[self::SESSION_MFA_SETUP]) || !is_array($_SESSION[self::SESSION_MFA_SETUP])) {
            $_SESSION[self::SESSION_MFA_SETUP] = [];
        }
        $_SESSION[self::SESSION_MFA_SETUP][$userId] = [
            'secret' => $secret,
            'created_at' => time(),
        ];

        $label = $user['username'] ?? ('utente-' . $userId);
        $otpauth = $this->buildOtpAuthUrl($issuer, (string) $label, $secret);
        $qrUrl = 'https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl=' . rawurlencode($otpauth);
        $fallbackQr = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . rawurlencode($otpauth);

        return [
            'success' => true,
            'secret' => $secret,
            'otpauth_url' => $otpauth,
            'qr_url' => $qrUrl,
            'qr_fallback_url' => $fallbackQr,
        ];
    }

    /**
     * @return array{success:bool, secret?:string, otpauth_url?:string, qr_url?:string, error?:string}
     */
    public function getMfaSetupSecret(int $userId, string $issuer): array
    {
        $setupState = $_SESSION[self::SESSION_MFA_SETUP] ?? null;
        $pending = is_array($setupState) ? ($setupState[$userId] ?? null) : null;
        if (!is_array($pending) || !isset($pending['secret'])) {
            return $this->beginMfaSetup($userId, $issuer);
        }

        $secret = (string) $pending['secret'];
        $user = $this->findUserById($userId);
        if ($user === null) {
            return [
                'success' => false,
                'error' => 'Utente non trovato.',
            ];
        }

        if ((int) ($user['mfa_enabled'] ?? 0) === 1) {
            unset($_SESSION[self::SESSION_MFA_SETUP][$userId]);
            return [
                'success' => false,
                'error' => 'L’autenticazione a due fattori è già attiva.',
            ];
        }

        $label = $user['username'] ?? ('utente-' . $userId);
        $otpauth = $this->buildOtpAuthUrl($issuer, (string) $label, $secret);
        $qrUrl = 'https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl=' . rawurlencode($otpauth);
        $fallbackQr = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . rawurlencode($otpauth);

        return [
            'success' => true,
            'secret' => $secret,
            'otpauth_url' => $otpauth,
            'qr_url' => $qrUrl,
            'qr_fallback_url' => $fallbackQr,
        ];
    }

    /**
     * @return array{success:bool, recovery_codes?:array<int,string>, error?:string}
     */
    public function confirmMfaSetup(int $userId, string $code): array
    {
        $setupState = $_SESSION[self::SESSION_MFA_SETUP] ?? null;
        $pending = is_array($setupState) ? ($setupState[$userId] ?? null) : null;
        if (!is_array($pending) || empty($pending['secret'])) {
            return [
                'success' => false,
                'error' => 'Configurazione non trovata: genera un nuovo codice QR.',
            ];
        }

        $secret = (string) $pending['secret'];
        $normalizedCode = $this->sanitizeOtpCode($code);
        if ($normalizedCode === '') {
            return [
                'success' => false,
                'error' => 'Inserisci il codice generato dall’app.',
            ];
        }

        if (!$this->verifyTotp($secret, $normalizedCode)) {
            return [
                'success' => false,
                'error' => 'Codice non valido. Verifica e riprova.',
            ];
        }

        if (is_array($setupState)) {
            unset($_SESSION[self::SESSION_MFA_SETUP][$userId]);
        }

        try {
            $stmt = $this->pdo->prepare(
                'UPDATE users SET mfa_secret = :secret, mfa_enabled = 1, mfa_enabled_at = NOW() WHERE id = :id'
            );
            $stmt->execute([
                ':secret' => $secret,
                ':id' => $userId,
            ]);
        } catch (PDOException $exception) {
            return [
                'success' => false,
                'error' => 'Errore durante il salvataggio della configurazione MFA.',
            ];
        }

        $this->removeRememberTokensForUser($userId);
        $codes = $this->generateRecoveryCodesForUser($userId);

        unset($_SESSION['mfa_enforcement_prompted']);

        return [
            'success' => true,
            'recovery_codes' => $codes,
        ];
    }

    /**
     * @return array{success:bool, message?:string, error?:string}
     */
    public function disableMfa(int $userId, ?string $code = null, bool $force = false): array
    {
        $user = $this->findUserById($userId);
        if ($user === null) {
            return [
                'success' => false,
                'error' => 'Utente non trovato.',
            ];
        }

        if ((int) ($user['mfa_enabled'] ?? 0) !== 1) {
            return [
                'success' => true,
                'message' => 'L’autenticazione a due fattori è già disattivata.',
            ];
        }

        if (!$force) {
            $normalizedCode = $this->sanitizeOtpCode((string) $code);
            if ($normalizedCode === '') {
                return [
                    'success' => false,
                    'error' => 'Inserisci un codice valido per disattivare l’MFA.',
                ];
            }

            $userId = (int) $user['id'];
            if ($this->verifyUserTotp($userId, $normalizedCode) !== true && $this->useRecoveryCode($userId, $normalizedCode) !== true) {
                return [
                    'success' => false,
                    'error' => 'Codice non valido. Impossibile disattivare l’MFA.',
                ];
            }
        }

        try {
            $stmt = $this->pdo->prepare(
                'UPDATE users SET mfa_secret = NULL, mfa_enabled = 0, mfa_enabled_at = NULL WHERE id = :id'
            );
            $stmt->execute([':id' => $userId]);

            $deleteCodes = $this->pdo->prepare('DELETE FROM user_mfa_recovery_codes WHERE user_id = :id');
            $deleteCodes->execute([':id' => $userId]);
        } catch (PDOException $exception) {
            return [
                'success' => false,
                'error' => 'Errore durante la disattivazione dell’MFA.',
            ];
        }

        $this->removeRememberTokensForUser($userId);

        unset($_SESSION['mfa_enforcement_prompted']);

        return [
            'success' => true,
            'message' => 'Autenticazione a due fattori disattivata.',
        ];
    }

    /**
     * @return array{success:bool, recovery_codes?:array<int,string>, error?:string}
     */
    public function regenerateRecoveryCodes(int $userId, ?string $code = null): array
    {
        $user = $this->findUserById($userId);
        if ($user === null) {
            return [
                'success' => false,
                'error' => 'Utente non trovato.',
            ];
        }

        if ((int) ($user['mfa_enabled'] ?? 0) !== 1) {
            return [
                'success' => false,
                'error' => 'Abilita l’MFA prima di rigenerare i codici.',
            ];
        }

        $normalizedCode = $this->sanitizeOtpCode((string) $code);
        if ($normalizedCode === '' || ($this->verifyUserTotp((int) $user['id'], $normalizedCode) !== true && $this->useRecoveryCode((int) $user['id'], $normalizedCode) !== true)) {
            return [
                'success' => false,
                'error' => 'Codice non valido. Impossibile rigenerare i codici di recupero.',
            ];
        }

        $codes = $this->generateRecoveryCodesForUser((int) $user['id']);

        return [
            'success' => true,
            'recovery_codes' => $codes,
        ];
    }

    /**
     * @return array{mfa_enabled:bool, mfa_enabled_at:?string}|null
     */
    public function getSecurityState(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT mfa_enabled, mfa_enabled_at FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return [
            'mfa_enabled' => (int) ($row['mfa_enabled'] ?? 0) === 1,
            'mfa_enabled_at' => $row['mfa_enabled_at'] ?? null,
        ];
    }

    private function finalizeLogin(array $sessionUser, bool $remember): void
    {
        $_SESSION['user'] = $sessionUser;
        $this->cancelPendingMfa();

        if ($remember) {
            $this->createRememberToken((int) $sessionUser['id']);
        } else {
            $this->clearRememberCookie();
        }
    }

    /**
     * @param array{id:int,username:string,role_id:int,fullname:string} $sessionUser
     */
    private function storePendingMfa(array $sessionUser, bool $remember): void
    {
        $_SESSION[self::SESSION_PENDING_MFA] = [
            'user' => $sessionUser,
            'remember' => $remember,
            'created_at' => time(),
        ];
        $this->clearRememberCookie();
    }

    private function verifyUserTotp(int $userId, string $code): bool
    {
        $stmt = $this->pdo->prepare('SELECT mfa_secret FROM users WHERE id = :id AND mfa_enabled = 1 LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $secret = $stmt->fetchColumn();
        if (!is_string($secret) || $secret === '') {
            return false;
        }

        return $this->verifyTotp($secret, $code);
    }

    private function verifyTotp(string $secret, string $code): bool
    {
        if (!preg_match('/^[0-9]{6}$/', $code)) {
            return false;
        }

        $binarySecret = $this->base32Decode($secret);
        if ($binarySecret === '') {
            return false;
        }

        $time = (int) floor(time() / 30);
        $window = 1;
        for ($i = -$window; $i <= $window; $i++) {
            $generated = $this->generateTotp($binarySecret, $time + $i);
            if (hash_equals($generated, $code)) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeOtpCode(string $code): string
    {
        $normalized = preg_replace('/[^0-9a-z]/i', '', $code) ?? '';
        return strtoupper(substr($normalized, 0, 16));
    }

    private function generateTotp(string $secret, int $timeSlice): string
    {
        $timeBytes = pack('N*', 0, $timeSlice);
        $hash = hash_hmac('sha1', $timeBytes, $secret, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        $otp = $binary % 1_000_000;

        return str_pad((string) $otp, 6, '0', STR_PAD_LEFT);
    }

    private function buildOtpAuthUrl(string $issuer, string $accountName, string $secret): string
    {
        $encodedIssuer = rawurlencode($issuer);
        $encodedAccount = rawurlencode($accountName);

        return sprintf('otpauth://totp/%s:%s?secret=%s&issuer=%s&period=30', $encodedIssuer, $encodedAccount, $secret, $encodedIssuer);
    }

    private function generateMfaSecret(): string
    {
        $random = random_bytes(20);
        return $this->base32Encode($random);
    }

    private function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = 0;
        $buffer = 0;
        $output = '';

        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $buffer = ($buffer << 8) | ord($data[$i]);
            $bits += 8;

            while ($bits >= 5) {
                $index = ($buffer >> ($bits - 5)) & 0x1F;
                $output .= $alphabet[$index];
                $bits -= 5;
            }
        }

        if ($bits > 0) {
            $index = ($buffer << (5 - $bits)) & 0x1F;
            $output .= $alphabet[$index];
        }

        return $output;
    }

    private function base32Decode(string $value): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $value = strtoupper(preg_replace('/[^A-Z2-7]/', '', $value) ?? '');
        if ($value === '') {
            return '';
        }

        $lookup = [];
        for ($i = 0, $len = strlen($alphabet); $i < $len; $i++) {
            $lookup[$alphabet[$i]] = $i;
        }

        $bits = 0;
        $buffer = 0;
        $output = '';

        $valueLen = strlen($value);
        for ($i = 0; $i < $valueLen; $i++) {
            $char = $value[$i];
            if (!isset($lookup[$char])) {
                return '';
            }

            $buffer = ($buffer << 5) | $lookup[$char];
            $bits += 5;

            if ($bits >= 8) {
                $bits -= 8;
                $output .= chr(($buffer >> $bits) & 0xFF);
            }
        }

        return $output;
    }

    /**
     * @return array<int, string>
     */
    private function generateRecoveryCodesForUser(int $userId): array
    {
        $codes = [];
        try {
            $this->pdo->beginTransaction();
            $delete = $this->pdo->prepare('DELETE FROM user_mfa_recovery_codes WHERE user_id = :id');
            $delete->execute([':id' => $userId]);

            for ($i = 0; $i < 8; $i++) {
                $code = $this->generateRecoveryCode();
                $hash = password_hash($code, PASSWORD_DEFAULT);
                if ($hash === false) {
                    throw new PDOException('Impossibile generare hash codice recupero.');
                }

                $insert = $this->pdo->prepare(
                    'INSERT INTO user_mfa_recovery_codes (user_id, code_hash) VALUES (:id, :hash)'
                );
                $insert->execute([
                    ':id' => $userId,
                    ':hash' => $hash,
                ]);

                $codes[] = $code;
            }

            if ($this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('Generazione codici di recupero fallita: ' . $exception->getMessage());
            return [];
        }

        return $codes;
    }

    private function generateRecoveryCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 10; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return substr($code, 0, 5) . '-' . substr($code, 5);
    }

    private function useRecoveryCode(int $userId, string $code): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, code_hash FROM user_mfa_recovery_codes WHERE user_id = :id AND used_at IS NULL'
        );
        $stmt->execute([':id' => $userId]);
        $codes = $stmt->fetchAll();
        if (!is_array($codes) || $codes === []) {
            return false;
        }

        foreach ($codes as $row) {
            if (password_verify($code, (string) $row['code_hash'])) {
                $update = $this->pdo->prepare('UPDATE user_mfa_recovery_codes SET used_at = NOW() WHERE id = :id');
                $update->execute([':id' => (int) $row['id']]);
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findUserById(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, username, fullname, mfa_enabled FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        return $user !== false ? $user : null;
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

    private function removeRememberTokensForUser(int $userId): void
    {
        try {
            $this->ensureRememberTableExists();
            $stmt = $this->pdo->prepare('DELETE FROM user_remember_tokens WHERE user_id = :id');
            $stmt->execute([':id' => $userId]);
        } catch (PDOException $exception) {
            error_log('Errore durante la rimozione dei remember token: ' . $exception->getMessage());
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
