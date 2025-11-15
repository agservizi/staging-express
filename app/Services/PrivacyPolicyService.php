<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class PrivacyPolicyService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getActivePolicy(): ?array
    {
        $stmt = $this->pdo->query(
            'SELECT id, version, title, content, is_active, created_at, updated_at
             FROM privacy_policies
             WHERE is_active = 1
             ORDER BY updated_at DESC, id DESC
             LIMIT 1'
        );

        $policy = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

        return $policy !== false ? $policy : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPolicyById(int $policyId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, version, title, content, is_active, created_at, updated_at
             FROM privacy_policies
             WHERE id = :id'
        );
        $stmt->execute([':id' => $policyId]);
        $policy = $stmt->fetch(PDO::FETCH_ASSOC);

        return $policy !== false ? $policy : null;
    }

    public function hasAcceptedPolicy(int $portalAccountId, int $policyId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM privacy_policy_acceptances
             WHERE portal_account_id = :account AND policy_id = :policy
             LIMIT 1'
        );
        $stmt->execute([
            ':account' => $portalAccountId,
            ':policy' => $policyId,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function recordAcceptance(int $portalAccountId, int $policyId, ?string $ipAddress, ?string $userAgent): bool
    {
        if ($this->hasAcceptedPolicy($portalAccountId, $policyId)) {
            return true;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO privacy_policy_acceptances (portal_account_id, policy_id, ip_address, user_agent)
             VALUES (:account, :policy, :ip, :ua)'
        );

        return $stmt->execute([
            ':account' => $portalAccountId,
            ':policy' => $policyId,
            ':ip' => $this->truncateOrNull($ipAddress, 45),
            ':ua' => $this->truncateOrNull($userAgent, 255),
        ]);
    }

    private function truncateOrNull(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($trimmed, 0, $length);
        }

        return substr($trimmed, 0, $length);
    }
}
