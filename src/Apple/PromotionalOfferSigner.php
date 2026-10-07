<?php

namespace Vipertecpro\MobileEntitlements\Apple;

use Firebase\JWT\JWT;
use Illuminate\Support\Str;
use Vipertecpro\MobileEntitlements\Exceptions\StoreApiException;

/**
 * Signs StoreKit 2 promotional offers as a compact JWS, per Apple's "Generating JWS to sign App Store
 * requests": header {alg: ES256, kid, typ: JWT}, claims {iss, iat, aud: promotional-offer, bid, nonce,
 * productId, offerIdentifier, transactionId?}. Apple says not to include exp: the App Store enforces an
 * expiry from iat. The app passes the result to Product.PurchaseOption.promotionalOffer(_:compactJWS:).
 */
class PromotionalOfferSigner
{
    public const AUDIENCE = 'promotional-offer';

    public function __construct(
        private ?string $issuerId,
        private ?string $keyId,
        private ?string $privateKey,
        private ?string $bundleId,
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->issuerId) && filled($this->keyId) && filled($this->privateKey) && filled($this->bundleId);
    }

    public function sign(string $productId, string $offerIdentifier, ?string $transactionId = null): string
    {
        if (! $this->isConfigured()) {
            throw new StoreApiException('Promotional offer signing is not configured.');
        }

        $claims = [
            'iss' => $this->issuerId,
            'iat' => now()->getTimestamp(),
            'aud' => self::AUDIENCE,
            'bid' => $this->bundleId,
            'nonce' => (string) Str::uuid(),
            'productId' => $productId,
            'offerIdentifier' => $offerIdentifier,
        ];

        if (filled($transactionId)) {
            $claims['transactionId'] = $transactionId;
        }

        return JWT::encode($claims, $this->key(), 'ES256', $this->keyId);
    }

    private function key(): string
    {
        $key = (string) $this->privateKey;

        if (! str_contains($key, '-----BEGIN') && is_readable($key)) {
            $key = (string) file_get_contents($key);
        }

        return $key;
    }
}
