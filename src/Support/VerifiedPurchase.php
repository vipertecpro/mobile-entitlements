<?php

namespace Vipertecpro\MobileEntitlements\Support;

use Carbon\CarbonImmutable;
use Vipertecpro\MobileEntitlements\Enums\ProductType;
use Vipertecpro\MobileEntitlements\Enums\Store;

/**
 * A purchase as reported by the store itself, after signature or API verification.
 * Every field here comes from the store, never from the client.
 */
final readonly class VerifiedPurchase
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public Store $store,
        public string $productId,
        public ProductType $productType,
        public string $originalTransactionId,
        public ?string $transactionId = null,
        public ?string $purchaseToken = null,
        public ?string $appAccountToken = null,
        public string $environment = 'production',
        public ?CarbonImmutable $purchasedAt = null,
        public ?CarbonImmutable $expiresAt = null,
        public bool $willRenew = false,
        public bool $inGracePeriod = false,
        public bool $inBillingRetry = false,
        public bool $isTrial = false,
        public ?CarbonImmutable $revokedAt = null,
        public ?string $revocationReason = null,
        public int $quantity = 1,
        public bool $forceInactive = false,
        public array $raw = [],
    ) {}

    /**
     * Build a purchase for tests and fakes with sensible defaults.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function fake(array $attributes = []): self
    {
        $store = $attributes['store'] ?? Store::AppStore;
        $originalTransactionId = $attributes['originalTransactionId'] ?? (string) random_int(1_000_000_000, 9_999_999_999);

        return new self(
            store: $store,
            productId: $attributes['productId'] ?? 'pro_monthly',
            productType: $attributes['productType'] ?? ProductType::Subscription,
            originalTransactionId: $originalTransactionId,
            transactionId: $attributes['transactionId'] ?? $originalTransactionId,
            purchaseToken: $attributes['purchaseToken'] ?? null,
            appAccountToken: $attributes['appAccountToken'] ?? null,
            environment: $attributes['environment'] ?? 'sandbox',
            purchasedAt: $attributes['purchasedAt'] ?? CarbonImmutable::now(),
            expiresAt: array_key_exists('expiresAt', $attributes) ? $attributes['expiresAt'] : CarbonImmutable::now()->addMonth(),
            willRenew: $attributes['willRenew'] ?? true,
            inGracePeriod: $attributes['inGracePeriod'] ?? false,
            inBillingRetry: $attributes['inBillingRetry'] ?? false,
            isTrial: $attributes['isTrial'] ?? false,
            revokedAt: $attributes['revokedAt'] ?? null,
            revocationReason: $attributes['revocationReason'] ?? null,
            quantity: $attributes['quantity'] ?? 1,
            forceInactive: $attributes['forceInactive'] ?? false,
            raw: $attributes['raw'] ?? [],
        );
    }

    public function isActive(): bool
    {
        if ($this->forceInactive || $this->revokedAt !== null) {
            return false;
        }

        if ($this->inGracePeriod) {
            return true;
        }

        return $this->expiresAt === null || $this->expiresAt->isFuture();
    }

    public function purchaseTokenHash(): ?string
    {
        return $this->purchaseToken === null ? null : hash('sha256', $this->purchaseToken);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        $values = get_object_vars($this);

        return new self(...array_merge($values, $changes));
    }
}
