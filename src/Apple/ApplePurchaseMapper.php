<?php

namespace Vipertecpro\MobileEntitlements\Apple;

use Carbon\CarbonImmutable;
use Vipertecpro\MobileEntitlements\Enums\ProductType;
use Vipertecpro\MobileEntitlements\Enums\Store;
use Vipertecpro\MobileEntitlements\Support\VerifiedPurchase;

/**
 * Turns verified App Store transaction and renewal payloads into a VerifiedPurchase.
 */
class ApplePurchaseMapper
{
    /**
     * Subscription status values from GET /inApps/v1/subscriptions.
     */
    public const STATUS_ACTIVE = 1;

    public const STATUS_EXPIRED = 2;

    public const STATUS_BILLING_RETRY = 3;

    public const STATUS_GRACE_PERIOD = 4;

    public const STATUS_REVOKED = 5;

    /**
     * @param  array<string, mixed>  $transaction  Decoded JWSTransactionDecodedPayload.
     * @param  array<string, mixed>|null  $renewal  Decoded JWSRenewalInfoDecodedPayload.
     */
    public function map(
        array $transaction,
        ?array $renewal = null,
        ?string $notificationType = null,
        ?string $subtype = null,
        ?int $subscriptionStatus = null,
    ): VerifiedPurchase {
        $productType = match ($transaction['type'] ?? null) {
            'Auto-Renewable Subscription', 'Non-Renewing Subscription' => ProductType::Subscription,
            'Consumable' => ProductType::Consumable,
            default => ProductType::NonConsumable,
        };

        $expiresAt = $this->date($transaction['expiresDate'] ?? null);
        $graceExpiresAt = $this->date($renewal['gracePeriodExpiresDate'] ?? null);
        $isInBillingRetry = (bool) ($renewal['isInBillingRetryPeriod'] ?? false);

        $inGracePeriod = $graceExpiresAt !== null && $graceExpiresAt->isFuture() && $isInBillingRetry;
        $inBillingRetry = $isInBillingRetry;
        $forceInactive = false;

        if ($notificationType === 'DID_FAIL_TO_RENEW') {
            $inBillingRetry = true;
            $inGracePeriod = $subtype === 'GRACE_PERIOD' && ($graceExpiresAt === null || $graceExpiresAt->isFuture());
        }

        if ($notificationType === 'GRACE_PERIOD_EXPIRED') {
            $inGracePeriod = false;
            $forceInactive = true;
        }

        if ($notificationType === 'EXPIRED') {
            $inGracePeriod = false;
            $inBillingRetry = $subtype === 'BILLING_RETRY';
            $forceInactive = true;
        }

        if ($notificationType === 'DID_RENEW') {
            $inGracePeriod = false;
            $inBillingRetry = false;
        }

        if ($subscriptionStatus !== null) {
            $inGracePeriod = $subscriptionStatus === self::STATUS_GRACE_PERIOD;
            $inBillingRetry = in_array($subscriptionStatus, [self::STATUS_BILLING_RETRY, self::STATUS_GRACE_PERIOD], true);
            $forceInactive = in_array($subscriptionStatus, [self::STATUS_EXPIRED, self::STATUS_BILLING_RETRY, self::STATUS_REVOKED], true);
        }

        // During the Billing Grace Period access lasts until the grace period ends.
        if ($inGracePeriod && $graceExpiresAt !== null && ($expiresAt === null || $graceExpiresAt->greaterThan($expiresAt))) {
            $expiresAt = $graceExpiresAt;
        }

        $willRenew = $productType === ProductType::Subscription
            && $renewal !== null
            && (int) ($renewal['autoRenewStatus'] ?? 0) === 1
            && ! in_array($notificationType, ['EXPIRED', 'GRACE_PERIOD_EXPIRED'], true);

        $revokedAt = $this->date($transaction['revocationDate'] ?? null);
        $revocationReason = null;

        if ($revokedAt !== null) {
            $revocationReason = match (true) {
                $notificationType === 'REVOKE' => 'family_sharing_revoked',
                isset($transaction['revocationReason']) => 'refund:'.$transaction['revocationReason'],
                default => 'refund',
            };
        }

        $originalTransactionId = (string) ($transaction['originalTransactionId'] ?? $transaction['transactionId'] ?? '');

        return new VerifiedPurchase(
            store: Store::AppStore,
            productId: (string) ($transaction['productId'] ?? ''),
            productType: $productType,
            originalTransactionId: $productType === ProductType::Consumable
                ? (string) ($transaction['transactionId'] ?? $originalTransactionId)
                : $originalTransactionId,
            transactionId: isset($transaction['transactionId']) ? (string) $transaction['transactionId'] : null,
            appAccountToken: isset($transaction['appAccountToken']) ? strtolower((string) $transaction['appAccountToken']) : null,
            environment: strtolower((string) ($transaction['environment'] ?? 'production')),
            purchasedAt: $this->date($transaction['purchaseDate'] ?? null),
            expiresAt: $expiresAt,
            willRenew: $willRenew,
            inGracePeriod: $inGracePeriod,
            inBillingRetry: $inBillingRetry,
            isTrial: ($transaction['offerDiscountType'] ?? null) === 'FREE_TRIAL',
            revokedAt: $revokedAt,
            revocationReason: $revocationReason,
            quantity: max(1, (int) ($transaction['quantity'] ?? 1)),
            forceInactive: $forceInactive,
            raw: array_filter(['transaction' => $transaction, 'renewal' => $renewal]),
        );
    }

    private function date(mixed $milliseconds): ?CarbonImmutable
    {
        if (! is_numeric($milliseconds)) {
            return null;
        }

        return CarbonImmutable::createFromTimestampMs((int) $milliseconds);
    }
}
