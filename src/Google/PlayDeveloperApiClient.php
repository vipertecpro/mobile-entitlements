<?php

namespace Vipertecpro\MobileEntitlements\Google;

use Firebase\JWT\JWT;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Vipertecpro\MobileEntitlements\Exceptions\StoreApiException;

/**
 * Google Play Developer API (androidpublisher v3) with a service-account JWT bearer grant.
 */
class PlayDeveloperApiClient
{
    public const BASE_URL = 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const SCOPE = 'https://www.googleapis.com/auth/androidpublisher';

    public function __construct(
        private HttpFactory $http,
        private Cache $cache,
        private ?string $serviceAccountJson,
    ) {}

    /**
     * purchases.subscriptionsv2.get
     *
     * @return array<string, mixed> SubscriptionPurchaseV2
     */
    public function getSubscription(string $packageName, string $purchaseToken): array
    {
        return $this->get(sprintf(
            '%s/%s/purchases/subscriptionsv2/tokens/%s',
            self::BASE_URL,
            rawurlencode($packageName),
            rawurlencode($purchaseToken),
        ));
    }

    /**
     * purchases.products.get
     *
     * @return array<string, mixed> ProductPurchase
     */
    public function getProduct(string $packageName, string $productId, string $purchaseToken): array
    {
        return $this->get(sprintf(
            '%s/%s/purchases/products/%s/tokens/%s',
            self::BASE_URL,
            rawurlencode($packageName),
            rawurlencode($productId),
            rawurlencode($purchaseToken),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $url): array
    {
        try {
            $response = $this->http->withToken($this->accessToken())->acceptJson()->timeout(15)->get($url);
        } catch (ConnectionException) {
            // The connection error names the URL, which contains the purchase token: keep it out of logs.
            throw new StoreApiException('Play Developer API is unreachable.');
        }

        if (! $response->successful() || ! is_array($response->json())) {
            throw new StoreApiException("Play Developer API returned HTTP {$response->status()}.", $response->status());
        }

        return $response->json();
    }

    public function accessToken(): string
    {
        $account = $this->serviceAccount();
        $cacheKey = 'mobile-entitlements:google-access-token:'.sha1($account['client_email']);

        $cached = $this->cache->get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $issuedAt = time();
        $assertion = JWT::encode([
            'iss' => $account['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $issuedAt,
            'exp' => $issuedAt + 3600,
        ], $account['private_key'], 'RS256', $account['private_key_id'] ?? null);

        try {
            $response = $this->http->asForm()->acceptJson()->timeout(15)->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);
        } catch (ConnectionException $exception) {
            throw new StoreApiException('Google OAuth token endpoint is unreachable: '.$exception->getMessage());
        }

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new StoreApiException("Google OAuth token request failed with HTTP {$response->status()}.", $response->status());
        }

        $this->cache->put($cacheKey, $token, max(60, (int) $response->json('expires_in', 3600) - 120));

        return $token;
    }

    /**
     * @return array{client_email: string, private_key: string, private_key_id?: string}
     */
    private function serviceAccount(): array
    {
        $json = (string) $this->serviceAccountJson;

        if ($json !== '' && ! str_starts_with(ltrim($json), '{')) {
            $json = is_readable($json) ? (string) file_get_contents($json) : '';
        }

        $account = json_decode($json, true);

        if (! is_array($account) || ! is_string($account['client_email'] ?? null) || ! is_string($account['private_key'] ?? null)) {
            throw new StoreApiException('google.service_account_json is missing or invalid.');
        }

        /** @var array{client_email: string, private_key: string, private_key_id?: string} $account */
        return $account;
    }
}
