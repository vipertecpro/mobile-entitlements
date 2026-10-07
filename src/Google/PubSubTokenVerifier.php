<?php

namespace Vipertecpro\MobileEntitlements\Google;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;
use Vipertecpro\MobileEntitlements\Exceptions\VerificationException;

/**
 * Verifies the OIDC token Pub/Sub attaches to authenticated push requests.
 */
class PubSubTokenVerifier
{
    public const CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    public const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    private const CACHE_KEY = 'mobile-entitlements:google-oidc-jwks';

    private const REFETCH_COOLDOWN_KEY = 'mobile-entitlements:google-oidc-jwks-refetched';

    private const NEGATIVE_TTL = 60;

    public function __construct(
        private HttpFactory $http,
        private Cache $cache,
        private ?string $audience,
        private ?string $serviceAccountEmail,
    ) {}

    /**
     * @return array<string, mixed> The verified claims.
     *
     * @throws VerificationException
     */
    public function verify(?string $bearerToken): array
    {
        if (blank($this->audience)) {
            throw new VerificationException('google.push_audience is not configured.');
        }

        if (blank($bearerToken)) {
            throw new VerificationException('Missing Pub/Sub bearer token.');
        }

        $header = json_decode(JWT::urlsafeB64Decode(explode('.', (string) $bearerToken)[0]), true);

        if (! is_array($header) || ($header['alg'] ?? null) !== 'RS256') {
            throw new VerificationException('Pub/Sub token must be RS256.');
        }

        $claims = $this->decode((string) $bearerToken, $this->keys(), (string) ($header['kid'] ?? ''));

        if (! in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            throw new VerificationException('Pub/Sub token issuer is not Google.');
        }

        $audiences = (array) ($claims['aud'] ?? []);

        if (! in_array($this->audience, $audiences, true)) {
            throw new VerificationException('Pub/Sub token audience does not match.');
        }

        if (filled($this->serviceAccountEmail)) {
            if (($claims['email'] ?? null) !== $this->serviceAccountEmail || ($claims['email_verified'] ?? false) !== true) {
                throw new VerificationException('Pub/Sub token was not issued to the configured service account.');
            }
        }

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $jwks
     * @return array<string, mixed>
     */
    private function decode(string $token, array $jwks, string $kid): array
    {
        $knownKid = collect($jwks['keys'] ?? [])->contains(fn (mixed $key): bool => is_array($key) && ($key['kid'] ?? null) === $kid);

        // Google rotates keys; refetch when the token names a key we have not cached. Unknown kids
        // are remembered for 60 seconds and refetches are limited to one per 60 seconds, so forged
        // tokens cannot force repeated certificate downloads.
        if (! $knownKid) {
            $negativeKey = self::CACHE_KEY.':unknown:'.sha1($kid);

            if ($this->cache->has($negativeKey) || $this->cache->has(self::REFETCH_COOLDOWN_KEY)) {
                throw new VerificationException('Pub/Sub token names an unknown signing key.');
            }

            $this->cache->put(self::REFETCH_COOLDOWN_KEY, true, self::NEGATIVE_TTL);
            $this->cache->forget(self::CACHE_KEY);
            $jwks = $this->keys();

            $knownKid = collect($jwks['keys'] ?? [])->contains(fn (mixed $key): bool => is_array($key) && ($key['kid'] ?? null) === $kid);

            if (! $knownKid) {
                $this->cache->put($negativeKey, true, self::NEGATIVE_TTL);

                throw new VerificationException('Pub/Sub token names an unknown signing key.');
            }
        }

        $previousLeeway = JWT::$leeway;
        JWT::$leeway = 60;

        try {
            $payload = JWT::decode($token, JWK::parseKeySet($jwks, 'RS256'));
        } catch (Throwable $exception) {
            throw new VerificationException('Pub/Sub token did not verify: '.$exception->getMessage(), 0, $exception);
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        if (! isset($payload->exp)) {
            throw new VerificationException('Pub/Sub token has no expiry.');
        }

        /** @var array<string, mixed> $claims */
        $claims = json_decode((string) json_encode($payload), true);

        return $claims;
    }

    /**
     * @return array<string, mixed>
     */
    private function keys(): array
    {
        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = $this->http->acceptJson()->timeout(10)->get(self::CERTS_URL);
        } catch (Throwable $exception) {
            throw new VerificationException('Could not fetch Google OIDC certificates.', 0, $exception);
        }

        $jwks = $response->json();

        if (! $response->successful() || ! is_array($jwks) || ! is_array($jwks['keys'] ?? null)) {
            throw new VerificationException('Google OIDC certificates response is invalid.');
        }

        $ttl = 3600;

        if (preg_match('/max-age=(\d+)/', (string) $response->header('Cache-Control'), $matches) === 1) {
            $ttl = max(60, min(86400, (int) $matches[1]));
        }

        $this->cache->put(self::CACHE_KEY, $jwks, $ttl);

        return $jwks;
    }
}
