<?php

namespace Vipertecpro\MobileEntitlements\Apple;

use Vipertecpro\MobileEntitlements\Enums\Store;
use Vipertecpro\MobileEntitlements\Exceptions\VerificationException;
use Vipertecpro\MobileEntitlements\Models\StoreTransaction;
use Vipertecpro\MobileEntitlements\Support\EntitlementWriter;
use Vipertecpro\MobileEntitlements\Support\NotificationRecorder;

/**
 * Verifies and applies an App Store Server Notification V2 signedPayload.
 */
class AppleNotificationHandler
{
    /**
     * Notification types that change entitlement state and are applied from the signed transaction.
     */
    public const HANDLED_TYPES = [
        'SUBSCRIBED', 'DID_RENEW', 'DID_CHANGE_RENEWAL_STATUS', 'DID_CHANGE_RENEWAL_PREF', 'DID_FAIL_TO_RENEW',
        'GRACE_PERIOD_EXPIRED', 'EXPIRED', 'REFUND', 'REVOKE', 'OFFER_REDEEMED', 'ONE_TIME_CHARGE',
        'REFUND_REVERSED', 'RENEWAL_EXTENDED',
    ];

    public function __construct(
        private AppleJwsVerifier $verifier,
        private ApplePurchaseMapper $mapper,
        private EntitlementWriter $writer,
        private NotificationRecorder $recorder,
    ) {}

    /**
     * @throws VerificationException
     */
    public function handle(string $signedPayload): StoreTransaction
    {
        $notification = $this->verifier->verify($signedPayload);

        $notificationId = $notification['notificationUUID'] ?? null;
        $type = $notification['notificationType'] ?? null;

        if (! is_string($notificationId) || $notificationId === '' || ! is_string($type) || $type === '') {
            throw new VerificationException('Notification has no notificationUUID or notificationType.');
        }

        $subtype = is_string($notification['subtype'] ?? null) ? $notification['subtype'] : null;
        $data = is_array($notification['data'] ?? null) ? $notification['data'] : [];
        $context = $data ?: (array) ($notification['summary'] ?? $notification['externalPurchaseToken'] ?? $notification['appData'] ?? []);

        $this->assertBundleAndEnvironment($context['bundleId'] ?? null, $context['environment'] ?? null);

        $transaction = null;
        $renewal = null;

        if (is_string($data['signedTransactionInfo'] ?? null)) {
            $transaction = $this->verifier->verify($data['signedTransactionInfo']);
            $this->assertBundleAndEnvironment($transaction['bundleId'] ?? null, $transaction['environment'] ?? null);
        }

        if (is_string($data['signedRenewalInfo'] ?? null)) {
            $renewal = $this->verifier->verify($data['signedRenewalInfo']);
        }

        if ($this->recorder->alreadyProcessed(Store::AppStore, $notificationId)) {
            return StoreTransaction::query()
                ->where('store', Store::AppStore->value)
                ->where('notification_id', $notificationId)
                ->firstOrFail();
        }

        $decoded = $notification;

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            $decoded['data']['transactionInfo'] = $transaction;
            $decoded['data']['renewalInfo'] = $renewal;
        }

        $attributes = [
            'subtype' => $subtype,
            'original_transaction_id' => isset($transaction['originalTransactionId']) ? (string) $transaction['originalTransactionId'] : null,
            'transaction_id' => isset($transaction['transactionId']) ? (string) $transaction['transactionId'] : null,
            'product_id' => $transaction['productId'] ?? null,
            'environment' => isset($context['environment']) ? strtolower((string) $context['environment']) : null,
            'signed_payload' => $signedPayload,
        ];

        return $this->recorder->record(
            Store::AppStore,
            $notificationId,
            $type,
            $attributes,
            $decoded,
            function () use ($type, $subtype, $transaction, $renewal): bool {
                if ($type === 'TEST') {
                    return true;
                }

                if (! in_array($type, self::HANDLED_TYPES, true) || $transaction === null) {
                    return false;
                }

                $purchase = $this->mapper->map($transaction, $renewal, $type, $subtype);
                $this->writer->apply($purchase, strtolower($type));

                return true;
            },
        );
    }

    /**
     * @throws VerificationException
     */
    public function assertBundleAndEnvironment(mixed $bundleId, mixed $environment): void
    {
        $expectedBundleId = config('mobile-entitlements.apple.bundle_id');

        if (blank($expectedBundleId) || $bundleId !== $expectedBundleId) {
            throw new VerificationException('bundleId does not match apple.bundle_id.');
        }

        $environment = strtolower((string) $environment);
        $configured = strtolower((string) config('mobile-entitlements.apple.environment', 'production'));
        $acceptSandbox = (bool) config('mobile-entitlements.apple.accept_sandbox', false);

        $allowed = $environment === $configured || ($environment === 'sandbox' && $acceptSandbox);

        if (! $allowed || ! in_array($environment, ['production', 'sandbox'], true)) {
            throw new VerificationException("Environment [{$environment}] is not accepted.");
        }
    }
}
