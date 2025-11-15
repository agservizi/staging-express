<?php
declare(strict_types=1);

namespace App\Services;

use DateInterval;
use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;

final class SsoService
{
    private bool $enabled;
    private string $issuer;
    private int $tokenTtl;
    private int $codeTtl;
    private string $sharedSecret;

    public function __construct(private PDO $pdo, array $config)
    {
        $this->enabled = !empty($config['enabled']) && isset($config['shared_secret']) && is_string($config['shared_secret']) && $config['shared_secret'] !== '';
        $this->issuer = isset($config['issuer']) && is_string($config['issuer']) && $config['issuer'] !== '' ? $config['issuer'] : 'coresuite-express';
        $this->tokenTtl = isset($config['token_ttl']) && is_numeric($config['token_ttl']) ? max(300, (int) $config['token_ttl']) : 3600;
        $this->codeTtl = isset($config['code_ttl']) && is_numeric($config['code_ttl']) ? max(60, (int) $config['code_ttl']) : 300;
        $this->sharedSecret = $this->enabled ? (string) $config['shared_secret'] : '';
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getTokenTtl(): int
    {
        return $this->tokenTtl;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listClients(): array
    {
        $this->assertEnabled();

        $stmt = $this->pdo->query(
            'SELECT id, name, client_id, redirect_uri, is_active, is_confidential, created_at, updated_at
             FROM sso_clients
             ORDER BY created_at DESC'
        );

        return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /**
     * @return array{success:bool,message:string,client_id?:string,client_secret?:string}
     */
    public function createClient(string $name, string $redirectUri, bool $isConfidential = true): array
    {
        $this->assertEnabled();

        $normalizedName = trim($name);
        if ($normalizedName === '') {
            return [
                'success' => false,
                'message' => 'Specifica un nome per il client SSO.',
            ];
        }

        $normalizedRedirect = trim($redirectUri);
        if (!filter_var($normalizedRedirect, FILTER_VALIDATE_URL)) {
            return [
                'success' => false,
                'message' => 'L\'URL di redirect non è valido.',
            ];
        }

        $clientId = bin2hex(random_bytes(16));
        $clientSecret = bin2hex(random_bytes(32));
        $secretHash = hash('sha256', $clientSecret);

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO sso_clients (name, client_id, client_secret_hash, redirect_uri, is_active, is_confidential)
                 VALUES (:name, :client_id, :secret_hash, :redirect, 1, :confidential)'
            );
            $stmt->execute([
                ':name' => $normalizedName,
                ':client_id' => $clientId,
                ':secret_hash' => $secretHash,
                ':redirect' => $normalizedRedirect,
                ':confidential' => $isConfidential ? 1 : 0,
            ]);
        } catch (PDOException $exception) {
            return [
                'success' => false,
                'message' => 'Impossibile creare il client SSO: ' . $exception->getMessage(),
            ];
        }

        return [
            'success' => true,
            'message' => 'Client SSO creato correttamente.',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];
    }

    /**
     * @return array{success:bool,message:string,client_secret?:string}
     */
    public function rotateClientSecret(int $id): array
    {
        $this->assertEnabled();

        $client = $this->findClientById($id);
        if ($client === null) {
            return [
                'success' => false,
                'message' => 'Client SSO non trovato.',
            ];
        }

        $clientSecret = bin2hex(random_bytes(32));
        $secretHash = hash('sha256', $clientSecret);

        $stmt = $this->pdo->prepare('UPDATE sso_clients SET client_secret_hash = :hash, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            ':hash' => $secretHash,
            ':id' => $id,
        ]);

        return [
            'success' => true,
            'message' => 'Nuovo secret generato.',
            'client_secret' => $clientSecret,
        ];
    }

    /**
     * @return array{success:bool,message:string}
     */
    public function setClientStatus(int $id, bool $active): array
    {
        $this->assertEnabled();

        $stmt = $this->pdo->prepare('UPDATE sso_clients SET is_active = :active, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            ':active' => $active ? 1 : 0,
            ':id' => $id,
        ]);

        if ($stmt->rowCount() === 0) {
            return [
                'success' => false,
                'message' => 'Client SSO non trovato.',
            ];
        }

        return [
            'success' => true,
            'message' => $active ? 'Client riattivato.' : 'Client disattivato.',
        ];
    }

    /**
     * @return array{success:bool,message:string}
     */
    public function deleteClient(int $id): array
    {
        $this->assertEnabled();

        $stmt = $this->pdo->prepare('DELETE FROM sso_clients WHERE id = :id');
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            return [
                'success' => false,
                'message' => 'Client SSO non trovato.',
            ];
        }

        return [
            'success' => true,
            'message' => 'Client eliminato.',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findClientByIdentifier(string $clientIdentifier): ?array
    {
        $this->assertEnabled();

        $stmt = $this->pdo->prepare('SELECT * FROM sso_clients WHERE client_id = :client LIMIT 1');
        $stmt->execute([':client' => $clientIdentifier]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        return $client ?: null;
    }

    /**
     * @return array{code:string,expires_at:string}
     */
    public function issueAuthorizationCode(array $client, int $userId, string $redirectUri, ?string $state, ?string $codeChallenge, string $codeMethod): array
    {
        $this->assertEnabled();

        $this->cleanupExpiredAuthCodes();

        $plainCode = bin2hex(random_bytes(32));
        $codeHash = hash('sha256', $plainCode);

        $expiresAt = (new DateTimeImmutable('now'))
            ->add(new DateInterval('PT' . $this->codeTtl . 'S'))
            ->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'INSERT INTO sso_auth_codes (client_id, user_id, code_hash, code_challenge, code_method, redirect_uri, state, expires_at)
             VALUES (:client_id, :user_id, :code_hash, :challenge, :method, :redirect, :state, :expires)'
        );
        $stmt->execute([
            ':client_id' => (int) $client['id'],
            ':user_id' => $userId,
            ':code_hash' => $codeHash,
            ':challenge' => $codeChallenge,
            ':method' => $codeMethod,
            ':redirect' => $redirectUri,
            ':state' => $state,
            ':expires' => $expiresAt,
        ]);

        return [
            'code' => $plainCode,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return array{success:bool,message?:string,token?:string,expires_at?:string,user?:array<string,mixed>}
     */
    public function exchangeAuthorizationCode(string $code, array $client, ?string $clientSecret, ?string $codeVerifier, string $redirectUri): array
    {
        $this->assertEnabled();

        if ($code === '') {
            return [
                'success' => false,
                'message' => 'Codice di autorizzazione mancante.',
            ];
        }

        $hash = hash('sha256', $code);
        $stmt = $this->pdo->prepare('SELECT * FROM sso_auth_codes WHERE code_hash = :hash LIMIT 1');
        $stmt->execute([':hash' => $hash]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$record) {
            return [
                'success' => false,
                'message' => 'Codice non valido o già usato.',
            ];
        }

        if ((int) $record['client_id'] !== (int) $client['id']) {
            return [
                'success' => false,
                'message' => 'Client non autorizzato per questo codice.',
            ];
        }

        if ((string) $record['redirect_uri'] !== $redirectUri) {
            return [
                'success' => false,
                'message' => 'Redirect URI non corrispondente.',
            ];
        }

        if ($record['used_at'] !== null) {
            return [
                'success' => false,
                'message' => 'Codice già utilizzato.',
            ];
        }

        if (new DateTimeImmutable('now') > new DateTimeImmutable((string) $record['expires_at'])) {
            return [
                'success' => false,
                'message' => 'Codice scaduto.',
            ];
        }

        if (!empty($client['is_confidential'])) {
            if ($clientSecret === null || hash('sha256', $clientSecret) !== (string) $client['client_secret_hash']) {
                return [
                    'success' => false,
                    'message' => 'Client secret non valido.',
                ];
            }
        }

        if (!empty($record['code_challenge'])) {
            if ($codeVerifier === null || $codeVerifier === '') {
                return [
                    'success' => false,
                    'message' => 'Code verifier mancante.',
                ];
            }
            $expected = $record['code_method'] === 'S256'
                ? $this->base64UrlEncode(hash('sha256', $codeVerifier, true))
                : $codeVerifier;
            if (!hash_equals((string) $record['code_challenge'], $expected)) {
                return [
                    'success' => false,
                    'message' => 'Code verifier non valido.',
                ];
            }
        }

        $this->markAuthCodeAsUsed((int) $record['id']);

        $userProfile = $this->getUserProfile((int) $record['user_id']);
        if ($userProfile === null) {
            return [
                'success' => false,
                'message' => 'Utente non trovato.',
            ];
        }

        $tokenData = $this->issueAccessToken((int) $record['user_id'], $client);

        return [
            'success' => true,
            'token' => $tokenData['token'],
            'expires_at' => $tokenData['expires_at'],
            'user' => $userProfile,
        ];
    }

    /**
     * @return array{success:bool,message?:string,user?:array<string,mixed>}
     */
    public function verifyAccessToken(string $token): array
    {
        $this->assertEnabled();

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return [
                'success' => false,
                'message' => 'Token non valido.',
            ];
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $expectedSignature = $this->base64UrlEncode(hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $this->sharedSecret, true));
        if (!hash_equals($expectedSignature, $encodedSignature)) {
            return [
                'success' => false,
                'message' => 'Firma token non valida.',
            ];
        }

        $payloadJson = $this->base64UrlDecode($encodedPayload);
        if ($payloadJson === null) {
            return [
                'success' => false,
                'message' => 'Payload token non valido.',
            ];
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload) || empty($payload['sub']) || empty($payload['exp'])) {
            return [
                'success' => false,
                'message' => 'Token malformato.',
            ];
        }

        if (time() >= (int) $payload['exp']) {
            return [
                'success' => false,
                'message' => 'Token scaduto.',
            ];
        }

        $userProfile = $this->getUserProfile((int) $payload['sub']);
        if ($userProfile === null) {
            return [
                'success' => false,
                'message' => 'Utente non trovato.',
            ];
        }

        return [
            'success' => true,
            'user' => $userProfile,
        ];
    }

    /**
     * @return array{success:bool,message?:string}
     */
    public function revokeToken(string $token): array
    {
        $this->assertEnabled();

        $hash = hash('sha256', $token);
        $stmt = $this->pdo->prepare('UPDATE sso_tokens SET revoked_at = NOW() WHERE access_token_hash = :hash');
        $stmt->execute([':hash' => $hash]);

        return [
            'success' => true,
            'message' => 'Token contrassegnato come revocato.',
        ];
    }

    private function issueAccessToken(int $userId, array $client): array
    {
        $issuedAt = time();
        $expiresAt = $issuedAt + $this->tokenTtl;

        $payload = [
            'iss' => $this->issuer,
            'aud' => $client['client_id'],
            'sub' => $userId,
            'iat' => $issuedAt,
            'exp' => $expiresAt,
            'scope' => 'default',
        ];

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];

        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $signature = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $this->sharedSecret, true);
        $encodedSignature = $this->base64UrlEncode($signature);

        $token = $encodedHeader . '.' . $encodedPayload . '.' . $encodedSignature;

        $this->storeAccessToken((int) $client['id'], $userId, $token, (new DateTimeImmutable('@' . $expiresAt)));

        return [
            'token' => $token,
            'expires_at' => (new DateTimeImmutable('@' . $expiresAt))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s'),
        ];
    }

    private function storeAccessToken(int $clientId, int $userId, string $token, DateTimeImmutable $expiresAt): void
    {
        $hash = hash('sha256', $token);
        $stmt = $this->pdo->prepare(
            'INSERT INTO sso_tokens (client_id, user_id, access_token_hash, expires_at) VALUES (:client, :user, :hash, :expires)'
        );
        $stmt->execute([
            ':client' => $clientId,
            ':user' => $userId,
            ':hash' => $hash,
            ':expires' => $expiresAt->format('Y-m-d H:i:s'),
        ]);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): ?string
    {
        $padding = 4 - (strlen($data) % 4);
        if ($padding !== 4) {
            $data .= str_repeat('=', $padding);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }

    private function markAuthCodeAsUsed(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE sso_auth_codes SET used_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    private function cleanupExpiredAuthCodes(): void
    {
        $this->pdo->prepare('DELETE FROM sso_auth_codes WHERE expires_at < (NOW() - INTERVAL 1 DAY) OR used_at IS NOT NULL')->execute();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getUserProfile(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.username, u.fullname, u.role_id, u.mfa_enabled, r.name AS role_name
             FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return null;
        }

        return [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'fullname' => $user['fullname'] ?? '',
            'role_id' => (int) $user['role_id'],
            'role_name' => $user['role_name'] ?? null,
            'mfa_enabled' => (bool) ((int) ($user['mfa_enabled'] ?? 0) === 1),
        ];
    }

    private function findClientById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sso_clients WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        return $client ?: null;
    }

    private function assertEnabled(): void
    {
        if (!$this->enabled) {
            throw new RuntimeException('SSO non abilitato: configura SSO_SHARED_SECRET.');
        }
    }
}
