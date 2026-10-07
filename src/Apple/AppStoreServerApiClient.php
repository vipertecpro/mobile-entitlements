<?php

namespace Vipertecpro\MobileEntitlements\Apple;

use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Vipertecpro\MobileEntitlements\Exceptions\StoreApiException;

/**
 * Minimal App Store Server API client: Get Transaction Info and Get All Subscription Statuses.
 */
class AppStoreServerApiClient
{
    public const PRODUCTION_URL = 'https://api.storekit.apple.com';

    public const SANDBOX_URL = 'https://api.storekit-sandbox.apple.com';

    public function __construct(
        private HttpFactory $http,
        private ?string $issuerId,
        private ?string $keyId,
        private ?string $privateKey,
        private ?string $bundleId,
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->issuerId) && filled($this->keyId) && filled($this->privateKey) && filled($this->bundleId);
    }

    /**
     * GET /inApps/v1/transactions/{transactionId}. Returns the signedTransactionInfo JWS.
     */
    public function getTransactionInfo(string $transactionId, string $environment): string
    {
        $json = $this->get('/inApps/v1/transactions/'.rawurlencode($transactionId), $environment);

        if (! is_string($json['signedTransactionInfo'] ?? null)) {
            throw new StoreApiException('App Store Server API response has no signedTransactionInfo.');
        }

        return $json['signedTransactionInfo'];
    }

    /**
     * GET /inApps/v1/subscriptions/{transactionId}. Returns the lastTransactions item for the
     * original transaction: {originalTransactionId, status, signedTransactionInfo, signedRenewalInfo}.
     *
     * @return array{originalTransactionId: string, status: int, signedTransactionInfo: string, signedRenewalInfo: string|null}|null
     */
    public function getSubscriptionStatus(string $transactionId, string $originalTransactionId, string $environment): ?array
    {
        $json = $this->get('/inApps/v1/subscriptions/'.rawurlencode($transactionId), $environment);

        foreach ((array) ($json['data'] ?? []) as $group) {
            foreach ((array) ($group['lastTransactions'] ?? []) as $item) {
                if ((string) ($item['originalTransactionId'] ?? '') === $originalTransactionId && is_string($item['signedTransactionInfo'] ?? null)) {
                    return [
                        'originalTransactionId' => $originalTransactionId,
                        'status' => (int) ($item['status'] ?? 0),
                        'signedTransactionInfo' => $item['signedTransactionInfo'],
                        'signedRenewalInfo' => is_string($item['signedRenewalInfo'] ?? null) ? $item['signedRenewalInfo'] : null,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path, string $environment): array
    {
        if (! $this->isConfigured()) {
            throw new StoreApiException('App Store Server API credentials are not configured.');
        }

        $baseUrl = strtolower($environment) === 'sandbox' ? self::SANDBOX_URL : self::PRODUCTION_URL;

        try {
            /** @var Response $response */
            $response = $this->http->withToken($this->token())
                ->acceptJson()
                ->timeout(15)
                ->get($baseUrl.$path);
        } catch (ConnectionException $exception) {
            throw new StoreApiException('App Store Server API is unreachable: '.$exception->getMessage());
        }

        if (! $response->successful() || ! is_array($response->json())) {
            throw new StoreApiException("App Store Server API returned HTTP {$response->status()}.", $response->status());
        }

        return $response->json();
    }

    /**
     * A short-lived ES256 token as described in "Generating JSON Web Tokens for API requests".
     */
    public function token(): string
    {
        $key = (string) $this->privateKey;

        if (! str_contains($key, '-----BEGIN') && is_readable($key)) {
            $key = (string) file_get_contents($key);
        }

        $issuedAt = time();

        return JWT::encode([
            'iss' => $this->issuerId,
            'iat' => $issuedAt,
            'exp' => $issuedAt + 300,
            'aud' => 'appstoreconnect-v1',
            'bid' => $this->bundleId,
        ], $key, 'ES256', $this->keyId);
    }
}
