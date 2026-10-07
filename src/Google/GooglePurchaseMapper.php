<?php

namespace Vipertecpro\MobileEntitlements\Google;

use Carbon\CarbonImmutable;
use Throwable;
use Vipertecpro\MobileEntitlements\Enums\ProductType;
use Vipertecpro\MobileEntitlements\Enums\Store;
use Vipertecpro\MobileEntitlements\Exceptions\StoreApiException;
use Vipertecpro\MobileEntitlements\Support\VerifiedPurchase;

/**
 * Turns Play Developer API resources into a VerifiedPurchase.
 *
 * Google has no original transaction id; the sha256 of the purchase token is used instead. A
 * subscription keeps the same token across renewals; upgrades and resubscribes create a new token
 * that names the old one in linkedPurchaseToken.
 */
class GooglePurchaseMapper
{
    /**
     * @param  list<string>  $consumableProductIds
     */
    public function __construct(private array $consumableProductIds = []) {}

    /**
     * @param  array<string, mixed>  $subscription  SubscriptionPurchaseV2
     */
    public function fromSubscription(string $purchaseToken, array $subscription, ?int $notificationType = null): VerifiedPurchase
    {
        $lineItems = array_values(array_filter((array) ($subscription['lineItems'] ?? []), 'is_array'));

        if ($lineItems === []) {
            throw new StoreApiException('SubscriptionPurchaseV2 has no line items.');
        }

        usort($lineItems, fn (array $a, array $b): int => strcmp((string) ($b['expiryTime'] ?? ''), (string) ($a['expiryTime'] ?? '')));
        $lineItem = $lineItems[0];

        $state = (string) ($subscription['subscriptionState'] ?? 'SUBSCRIPTION_STATE_UNSPECIFIED');

        $forceInactive = in_array($state, [
            'SUBSCRIPTION_STATE_UNSPECIFIED',
            'SUBSCRIPTION_STATE_PENDING',
            'SUBSCRIPTION_STATE_PAUSED',
            'SUBSCRIPTION_STATE_ON_HOLD',
            'SUBSCRIPTION_STATE_EXPIRED',
            'SUBSCRIPTION_STATE_PENDING_PURCHASE_CANCELED',
        ], true);

        $revokedAt = null;
        $revocationReason = null;

        // SUBSCRIPTION_REVOKED (12): revoked before expiry, access ends now.
        if ($notificationType === 12) {
            $revokedAt = CarbonImmutable::now();
            $revocationReason = 'revoked';
        }

        return new VerifiedPurchase(
            store: Store::GooglePlay,
            productId: (string) ($lineItem['productId'] ?? ''),
            productType: ProductType::Subscription,
            originalTransactionId: hash('sha256', $purchaseToken),
            transactionId: $this->stringOrNull($lineItem['latestSuccessfulOrderId'] ?? $subscription['latestOrderId'] ?? null),
            purchaseToken: $purchaseToken,
            appAccountToken: $this->stringOrNull($subscription['externalAccountIdentifiers']['obfuscatedExternalAccountId'] ?? null),
            environment: array_key_exists('testPurchase', $subscription) ? 'sandbox' : 'production',
            purchasedAt: $this->rfc3339($subscription['startTime'] ?? null),
            expiresAt: $this->rfc3339($lineItem['expiryTime'] ?? null),
            willRenew: (bool) ($lineItem['autoRenewingPlan']['autoRenewEnabled'] ?? false) && ! $forceInactive && $state !== 'SUBSCRIPTION_STATE_CANCELED',
            inGracePeriod: $state === 'SUBSCRIPTION_STATE_IN_GRACE_PERIOD',
            inBillingRetry: in_array($state, ['SUBSCRIPTION_STATE_ON_HOLD', 'SUBSCRIPTION_STATE_IN_GRACE_PERIOD'], true),
            isTrial: isset($lineItem['offerPhase']['freeTrial']),
            revokedAt: $revokedAt,
            revocationReason: $revocationReason,
            quantity: 1,
            forceInactive: $forceInactive,
            raw: ['subscription' => $subscription],
        );
    }

    /**
     * @param  array<string, mixed>  $product  ProductPurchase
     */
    public function fromProduct(string $purchaseToken, string $requestedProductId, array $product): VerifiedPurchase
    {
        // The product id is part of the URL Google validated; prefer the id Google echoes back.
        $productId = $this->stringOrNull($product['productId'] ?? null) ?? $requestedProductId;
        $purchaseState = (int) ($product['purchaseState'] ?? 0);

        $productType = in_array($productId, $this->consumableProductIds, true)
            ? ProductType::Consumable
            : ProductType::NonConsumable;

        return new VerifiedPurchase(
            store: Store::GooglePlay,
            productId: $productId,
            productType: $productType,
            originalTransactionId: hash('sha256', $purchaseToken),
            transactionId: $this->stringOrNull($product['orderId'] ?? null),
            purchaseToken: $purchaseToken,
            appAccountToken: $this->stringOrNull($product['obfuscatedExternalAccountId'] ?? null),
            environment: (array_key_exists('purchaseType', $product) && (int) $product['purchaseType'] === 0) ? 'sandbox' : 'production',
            purchasedAt: is_numeric($product['purchaseTimeMillis'] ?? null)
                ? CarbonImmutable::createFromTimestampMs((int) $product['purchaseTimeMillis'])
                : null,
            expiresAt: null,
            willRenew: false,
            quantity: max(1, (int) ($product['quantity'] ?? 1)),
            // 0 purchased, 1 canceled, 2 pending: only a completed purchase grants access.
            forceInactive: $purchaseState !== 0,
            raw: ['product' => $product],
        );
    }

    private function rfc3339(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
