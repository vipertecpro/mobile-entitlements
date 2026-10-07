<?php

namespace Vipertecpro\MobileEntitlements\Google;

use Carbon\CarbonImmutable;
use Vipertecpro\MobileEntitlements\Enums\Store;
use Vipertecpro\MobileEntitlements\Exceptions\StoreApiException;
use Vipertecpro\MobileEntitlements\Exceptions\VerificationException;
use Vipertecpro\MobileEntitlements\Models\Entitlement;
use Vipertecpro\MobileEntitlements\Models\StoreTransaction;
use Vipertecpro\MobileEntitlements\Support\EntitlementWriter;
use Vipertecpro\MobileEntitlements\Support\NotificationRecorder;
use Vipertecpro\MobileEntitlements\Support\VerifiedPurchase;

/**
 * Verifies a Pub/Sub push of a Google Play Real-time Developer Notification, then applies the
 * state read from the Play Developer API (the notification itself is only a hint).
 */
class GoogleNotificationHandler
{
    public const SUBSCRIPTION_TYPES = [
        1 => 'SUBSCRIPTION_RECOVERED',
        2 => 'SUBSCRIPTION_RENEWED',
        3 => 'SUBSCRIPTION_CANCELED',
        4 => 'SUBSCRIPTION_PURCHASED',
        5 => 'SUBSCRIPTION_ON_HOLD',
        6 => 'SUBSCRIPTION_IN_GRACE_PERIOD',
        7 => 'SUBSCRIPTION_RESTARTED',
        8 => 'SUBSCRIPTION_PRICE_CHANGE_CONFIRMED',
        9 => 'SUBSCRIPTION_DEFERRED',
        10 => 'SUBSCRIPTION_PAUSED',
        11 => 'SUBSCRIPTION_PAUSE_SCHEDULE_CHANGED',
        12 => 'SUBSCRIPTION_REVOKED',
        13 => 'SUBSCRIPTION_EXPIRED',
        17 => 'SUBSCRIPTION_ITEMS_CHANGED',
        18 => 'SUBSCRIPTION_CANCELLATION_SCHEDULED',
        19 => 'SUBSCRIPTION_PRICE_CHANGE_UPDATED',
        20 => 'SUBSCRIPTION_PENDING_PURCHASE_CANCELED',
        22 => 'SUBSCRIPTION_PRICE_STEP_UP_CONSENT_UPDATED',
    ];

    public const ONE_TIME_TYPES = [
        1 => 'ONE_TIME_PRODUCT_PURCHASED',
        2 => 'ONE_TIME_PRODUCT_CANCELED',
    ];

    public function __construct(
        private PubSubTokenVerifier $tokenVerifier,
        private PlayDeveloperApiClient $api,
        private GooglePurchaseMapper $mapper,
        private EntitlementWriter $writer,
        private NotificationRecorder $recorder,
    ) {}

    /**
     * @param  array<string, mixed>  $body  The Pub/Sub push body.
     *
     * @throws VerificationException
     * @throws StoreApiException when Google is unavailable (the caller answers 5xx so Pub/Sub retries).
     */
    public function handle(array $body, ?string $bearerToken): ?StoreTransaction
    {
        $this->tokenVerifier->verify($bearerToken);

        $message = is_array($body['message'] ?? null) ? $body['message'] : [];
        $messageId = $message['messageId'] ?? $message['message_id'] ?? null;
        $data = is_string($message['data'] ?? null) ? base64_decode($message['data'], true) : false;
        $notification = $data === false ? null : json_decode($data, true);

        if (! is_string($messageId) || $messageId === '' || ! is_array($notification)) {
            throw new VerificationException('Pub/Sub message has no messageId or its data is not JSON.');
        }

        $packageName = config('mobile-entitlements.google.package_name');

        if (blank($packageName) || ($notification['packageName'] ?? null) !== $packageName) {
            throw new VerificationException('packageName does not match google.package_name.');
        }

        if ($this->recorder->alreadyProcessed(Store::GooglePlay, $messageId)) {
            return StoreTransaction::query()
                ->where('store', Store::GooglePlay->value)
                ->where('notification_id', $messageId)
                ->first();
        }

        [$type, $token, $productId] = $this->describe($notification);
        $storedPayload = $this->redactPurchaseToken($notification);

        $attributes = [
            'purchase_token' => $token,
            'purchase_token_hash' => $token === null ? null : hash('sha256', $token),
            'product_id' => $productId,
        ];

        try {
            $resolved = $this->fetchTruth($notification, (string) $packageName);
        } catch (StoreApiException $exception) {
            // A token Google no longer knows will never resolve: record it and stop retries.
            $this->recorder->fail(Store::GooglePlay, $messageId, $type, $attributes, $storedPayload, $exception->getMessage(), $exception->isNotFound());

            if ($exception->isNotFound()) {
                return null;
            }

            throw $exception;
        }

        if ($resolved !== null) {
            [$purchase] = $resolved;
            $attributes['product_id'] = $purchase->productId;
            $attributes['original_transaction_id'] = $purchase->originalTransactionId;
            $attributes['transaction_id'] = $purchase->transactionId;
            $attributes['environment'] = $purchase->environment;
        }

        return $this->recorder->record(
            Store::GooglePlay,
            $messageId,
            $type,
            $attributes,
            $storedPayload,
            fn (): bool|string => $this->apply($notification, $type, $resolved),
        );
    }

    /**
     * @param  array<string, mixed>  $notification
     * @param  array{0: VerifiedPurchase, 1: string|null}|null  $resolved
     */
    private function apply(array $notification, string $type, ?array $resolved): bool|string
    {
        if (isset($notification['testNotification'])) {
            return true;
        }

        if (isset($notification['voidedPurchaseNotification'])) {
            $voided = (array) $notification['voidedPurchaseNotification'];
            $token = (string) ($voided['purchaseToken'] ?? '');
            $eventTime = is_numeric($notification['eventTimeMillis'] ?? null)
                ? CarbonImmutable::createFromTimestampMs((int) $notification['eventTimeMillis'])
                : CarbonImmutable::now();

            // refundType 2 = quantity-based partial refund: access to the rest is kept.
            if ((int) ($voided['refundType'] ?? 1) === 2) {
                Entitlement::query()
                    ->where('purchase_token_hash', hash('sha256', $token))
                    ->get()
                    ->each(fn (Entitlement $entitlement) => $this->writer->touch($entitlement, 'voided_partial'));

                return true;
            }

            $this->writer->revokeByPurchaseToken($token, $eventTime, 'voided', 'voided_purchase');

            return true;
        }

        if ($resolved === null) {
            return false;
        }

        [$purchase, $linkedPurchaseToken] = $resolved;

        if ($purchase->environment === 'sandbox' && ! config('mobile-entitlements.google.accept_test_purchases', false)) {
            return 'Test purchase not applied: google.accept_test_purchases is false.';
        }

        $this->writer->apply($purchase, strtolower($type), null, $linkedPurchaseToken);

        return true;
    }

    /**
     * @param  array<string, mixed>  $notification
     * @return array{0: VerifiedPurchase, 1: string|null}|null
     */
    private function fetchTruth(array $notification, string $packageName): ?array
    {
        if (is_array($notification['subscriptionNotification'] ?? null)) {
            $sub = $notification['subscriptionNotification'];
            $token = (string) ($sub['purchaseToken'] ?? '');
            $subscription = $this->api->getSubscription($packageName, $token);
            $linked = is_string($subscription['linkedPurchaseToken'] ?? null) ? $subscription['linkedPurchaseToken'] : null;

            return [$this->mapper->fromSubscription($token, $subscription, (int) ($sub['notificationType'] ?? 0)), $linked];
        }

        if (is_array($notification['oneTimeProductNotification'] ?? null)) {
            $oneTime = $notification['oneTimeProductNotification'];
            $token = (string) ($oneTime['purchaseToken'] ?? '');
            $sku = (string) ($oneTime['sku'] ?? '');
            $product = $this->api->getProduct($packageName, $sku, $token);

            return [$this->mapper->fromProduct($token, $sku, $product), null];
        }

        return null;
    }

    /**
     * The stored payload keeps only the token hash; the token itself lives in the encrypted
     * purchase_token column.
     *
     * @param  array<string, mixed>  $notification
     * @return array<string, mixed>
     */
    private function redactPurchaseToken(array $notification): array
    {
        foreach (['subscriptionNotification', 'oneTimeProductNotification', 'voidedPurchaseNotification'] as $key) {
            if (is_array($notification[$key] ?? null) && is_string($notification[$key]['purchaseToken'] ?? null)) {
                $notification[$key]['purchaseTokenHash'] = hash('sha256', $notification[$key]['purchaseToken']);
                unset($notification[$key]['purchaseToken']);
            }
        }

        return $notification;
    }

    /**
     * @param  array<string, mixed>  $notification
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function describe(array $notification): array
    {
        if (is_array($notification['subscriptionNotification'] ?? null)) {
            $code = (int) ($notification['subscriptionNotification']['notificationType'] ?? 0);

            return [self::SUBSCRIPTION_TYPES[$code] ?? 'SUBSCRIPTION_'.$code, $notification['subscriptionNotification']['purchaseToken'] ?? null, null];
        }

        if (is_array($notification['oneTimeProductNotification'] ?? null)) {
            $code = (int) ($notification['oneTimeProductNotification']['notificationType'] ?? 0);

            return [self::ONE_TIME_TYPES[$code] ?? 'ONE_TIME_PRODUCT_'.$code, $notification['oneTimeProductNotification']['purchaseToken'] ?? null, $notification['oneTimeProductNotification']['sku'] ?? null];
        }

        if (is_array($notification['voidedPurchaseNotification'] ?? null)) {
            return ['VOIDED_PURCHASE', $notification['voidedPurchaseNotification']['purchaseToken'] ?? null, null];
        }

        if (isset($notification['testNotification'])) {
            return ['TEST', null, null];
        }

        return ['UNKNOWN', null, null];
    }
}
