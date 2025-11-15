<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\PrivacyPolicyService;

final class PrivacyPolicyController
{
    public function __construct(private PrivacyPolicyService $privacyPolicyService)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $server
     * @return array{success:bool, message:string, errors?:array<int, string>}
     */
    public function accept(int $portalAccountId, array $input, array $server): array
    {
        $policyId = isset($input['policy_id']) ? (int) $input['policy_id'] : 0;
        if ($policyId <= 0) {
            return [
                'success' => false,
                'message' => 'Policy non valida.',
                'errors' => ['Identificativo policy mancante o non valido.'],
            ];
        }

        $policy = $this->privacyPolicyService->findPolicyById($policyId);
        if ($policy === null || (int) ($policy['is_active'] ?? 0) !== 1) {
            return [
                'success' => false,
                'message' => 'Policy non disponibile.',
                'errors' => ['La policy indicata non è più attiva.'],
            ];
        }

        if (!isset($input['confirm_ack']) || (string) $input['confirm_ack'] !== '1') {
            return [
                'success' => false,
                'message' => 'Conferma richiesta.',
                'errors' => ['Seleziona la casella di conferma per proseguire.'],
            ];
        }

        $ip = isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : null;
        $userAgent = isset($server['HTTP_USER_AGENT']) ? (string) $server['HTTP_USER_AGENT'] : null;

        $accepted = $this->privacyPolicyService->recordAcceptance($portalAccountId, $policyId, $ip, $userAgent);
        if (!$accepted) {
            return [
                'success' => false,
                'message' => 'Impossibile registrare il consenso.',
                'errors' => ['Riprovare più tardi.'],
            ];
        }

        return [
            'success' => true,
            'message' => 'Consenso registrato correttamente.',
        ];
    }
}
