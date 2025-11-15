<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\SsoService;

final class SsoController
{
    public function __construct(private SsoService $ssoService)
    {
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $user
     * @return array{success:bool,redirect?:string,status?:int,error?:string}
     */
    public function authorize(array $query, array $user): array
    {
        if (!$this->ssoService->isEnabled()) {
            return [
                'success' => false,
                'status' => 503,
                'error' => 'SSO non configurato.',
            ];
        }

        $clientId = isset($query['client_id']) ? (string) $query['client_id'] : '';
        $redirectUri = isset($query['redirect_uri']) ? (string) $query['redirect_uri'] : '';
        $state = isset($query['state']) ? (string) $query['state'] : null;
        $codeChallenge = isset($query['code_challenge']) ? (string) $query['code_challenge'] : null;
        $codeChallengeMethod = isset($query['code_challenge_method']) ? strtoupper((string) $query['code_challenge_method']) : 'PLAIN';

        if ($clientId === '' || $redirectUri === '') {
            return [
                'success' => false,
                'status' => 400,
                'error' => 'client_id e redirect_uri sono obbligatori.',
            ];
        }

        $client = $this->ssoService->findClientByIdentifier($clientId);
        if ($client === null || (int) ($client['is_active'] ?? 0) !== 1) {
            return [
                'success' => false,
                'status' => 400,
                'error' => 'Client sconosciuto o disattivato.',
            ];
        }

        if ((string) $client['redirect_uri'] !== $redirectUri) {
            return [
                'success' => false,
                'status' => 400,
                'error' => 'redirect_uri non corrisponde a quello registrato.',
            ];
        }

        if (!in_array($codeChallengeMethod, ['PLAIN', 'S256'], true)) {
            return [
                'success' => false,
                'status' => 400,
                'error' => 'Metodo code_challenge non supportato.',
            ];
        }

        if ($codeChallengeMethod === 'S256' && ($codeChallenge === null || $codeChallenge === '')) {
            return [
                'success' => false,
                'status' => 400,
                'error' => 'code_challenge richiesto per il metodo S256.',
            ];
        }

        $issue = $this->ssoService->issueAuthorizationCode(
            $client,
            (int) ($user['id'] ?? 0),
            $redirectUri,
            $state,
            $codeChallenge,
            $codeChallengeMethod === 'S256' ? 'S256' : 'plain'
        );

        $params = ['code' => $issue['code']];
        if ($state !== null && $state !== '') {
            $params['state'] = $state;
        }

        $redirect = $this->appendQueryParams($redirectUri, $params);

        return [
            'success' => true,
            'redirect' => $redirect,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{status:int,body:array<string,mixed>}
     */
    public function token(array $input): array
    {
        if (!$this->ssoService->isEnabled()) {
            return [
                'status' => 503,
                'body' => [
                    'error' => 'temporarily_unavailable',
                    'error_description' => 'SSO non configurato.',
                ],
            ];
        }

        $grantType = isset($input['grant_type']) ? (string) $input['grant_type'] : '';
        if ($grantType !== 'authorization_code') {
            return [
                'status' => 400,
                'body' => [
                    'error' => 'unsupported_grant_type',
                    'error_description' => 'Grant type non supportato.',
                ],
            ];
        }

        $code = isset($input['code']) ? (string) $input['code'] : '';
        $clientId = isset($input['client_id']) ? (string) $input['client_id'] : '';
        $clientSecret = isset($input['client_secret']) ? (string) $input['client_secret'] : null;
        $redirectUri = isset($input['redirect_uri']) ? (string) $input['redirect_uri'] : '';
        $codeVerifier = isset($input['code_verifier']) ? (string) $input['code_verifier'] : null;

        if ($code === '' || $clientId === '' || $redirectUri === '') {
            return [
                'status' => 400,
                'body' => [
                    'error' => 'invalid_request',
                    'error_description' => 'Parametri obbligatori mancanti.',
                ],
            ];
        }

        $client = $this->ssoService->findClientByIdentifier($clientId);
        if ($client === null || (int) ($client['is_active'] ?? 0) !== 1) {
            return [
                'status' => 400,
                'body' => [
                    'error' => 'invalid_client',
                    'error_description' => 'Client non valido o disattivato.',
                ],
            ];
        }

        if (!empty($client['is_confidential']) && ($clientSecret === null || $clientSecret === '')) {
            return [
                'status' => 400,
                'body' => [
                    'error' => 'invalid_client',
                    'error_description' => 'Client secret richiesto per client confidenziali.',
                ],
            ];
        }

        $exchange = $this->ssoService->exchangeAuthorizationCode($code, $client, $clientSecret, $codeVerifier, $redirectUri);
        if (!($exchange['success'] ?? false)) {
            return [
                'status' => 400,
                'body' => [
                    'error' => 'invalid_grant',
                    'error_description' => $exchange['message'] ?? 'Impossibile completare lo scambio.',
                ],
            ];
        }

        return [
            'status' => 200,
            'body' => [
                'access_token' => $exchange['token'],
                'token_type' => 'Bearer',
                'expires_in' => $this->ssoService->isEnabled() ? $this->ssoService->getTokenTtl() : 0,
                'user' => $exchange['user'] ?? null,
            ],
        ];
    }

    /**
     * @param array<string, string> $params
     */
    private function appendQueryParams(string $url, array $params): string
    {
        $parsed = parse_url($url);
        if ($parsed === false) {
            return $url;
        }

        $query = [];
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query);
        }
        foreach ($params as $key => $value) {
            $query[$key] = $value;
        }

        $parsed['query'] = http_build_query($query);

        $scheme = $parsed['scheme'] ?? 'http';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path = $parsed['path'] ?? '';
        $queryString = $parsed['query'] ?? '';
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

        $base = $scheme . '://' . $host . $port . $path;
        if ($queryString !== '') {
            $base .= '?' . $queryString;
        }

        return $base . $fragment;
    }
}
