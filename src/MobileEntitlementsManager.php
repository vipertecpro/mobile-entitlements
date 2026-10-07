<?php

namespace Vipertecpro\MobileEntitlements;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Vipertecpro\MobileEntitlements\Apple\AppleJwsVerifier;
use Vipertecpro\MobileEntitlements\Apple\AppleNotificationHandler;
use Vipertecpro\MobileEntitlements\Apple\ApplePurchaseMapper;
use Vipertecpro\MobileEntitlements\Apple\AppStoreServerApiClient;
use Vipertecpro\MobileEntitlements\Enums\ProductType;
use Vipertecpro\MobileEntitlements\Enums\Store;
use Vipertecpro\MobileEntitlements\Exceptions\EntitlementOwnedByAnotherUser;
use Vipertecpro\MobileEntitlements\Exceptions\StoreApiException;
use Vipertecpro\MobileEntitlements\Exceptions\VerificationException;
use Vipertecpro\MobileEntitlements\Google\GooglePurchaseMapper;
use Vipertecpro\MobileEntitlements\Google\PlayDeveloperApiClient;
use Vipertecpro\MobileEntitlements\Models\Entitlement;
use Vipertecpro\MobileEntitlements\Models\StoreTransaction;
use Vipertecpro\MobileEntitlements\Support\EntitlementWriter;
use Vipertecpro\MobileEntitlements\Support\VerifiedPurchase;

class MobileEntitlementsManager
{
    /**
     * The Google linkedPurchaseToken of the last verified Play subscription, keyed by token hash.
     *
     * @var array<string, string|null>
     */
    protected array $linkedTokens = [];

    public function __construct(
        protected AppleJwsVerifier $appleVerifier,
        protected AppleNotificationHandler $appleNotifications,
        protected ApplePurchaseMapper $appleMapper,
        protected AppStoreServerApiClient $appStoreApi,
        protected PlayDeveloperApiClient $playApi,
        protected GooglePurchaseMapper $googleMapper,
        protected EntitlementWriter $writer,
    ) {}

    /**
     * Verify a client-provided purchase with the store. For the App Store the token is the signed
     * transaction (JWS); for Google Play it is the purchase token. $productId is only a lookup hint
     * for Google one-time products: the product id in the result always comes from the store.
     *
     * @throws VerificationException
     * @throws StoreApiException
     */
    public function verify(Store $store, string $token, ?string $productId = null): VerifiedPurchase
    {
        return match ($store) {
            Store::AppStore => $this->verifyAppStore($token),
            Store::GooglePlay => $this->verifyGooglePlay($token, $productId),
        };
    }

    /**
     * Verify the token and grant (or update) the entitlement for $user.
     *
     * @return Collection<int, Entitlement>
     *
     * @throws VerificationException
     * @throws StoreApiException
     * @throws EntitlementOwnedByAnotherUser
     */
    public function grantFromToken(Authenticatable $user, Store $store, string $token, ?string $productId = null): Collection
    {
        $purchase = $this->verify($store, $token, $productId);
        $this->assertAppAccountTokenMatches($user, $purchase);

        StoreTransaction::query()->create([
            'store' => $store,
            'notification_id' => 'sync:'.Str::uuid(),
            'type' => 'SYNC',
            'original_transaction_id' => $purchase->originalTransactionId,
            'transaction_id' => $purchase->transactionId,
            'purchase_token' => $store === Store::GooglePlay ? $token : null,
            'purchase_token_hash' => $store === Store::GooglePlay ? hash('sha256', $token) : null,
            'product_id' => $purchase->productId,
            'environment' => $purchase->environment,
            'payload' => $purchase->raw,
            'signed_payload' => $store === Store::AppStore ? $token : null,
            'received_at' => now(),
            'processed_at' => now(),
        ]);

        $linked = $this->linkedTokens[(string) $purchase->purchaseTokenHash()] ?? null;

        return new Collection([
            $this->writer->apply($purchase, 'sync', $user->getAuthIdentifier(), $linked),
        ]);
    }

    /**
     * Re-query the store for every entitlement of $user and fix drift.
     *
     * @return Collection<int, Entitlement>
     */
    public function reconcile(Authenticatable $user): Collection
    {
        return Entitlement::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->get()
            ->map(fn (Entitlement $entitlement): Entitlement => $this->reconcileEntitlement($entitlement))
            ->values();
    }

    /**
     * Refresh one entitlement from the store. Falls back to the stored dates when the store
     * cannot be asked (no App Store Server API key, or no stored Google purchase token).
     *
     * @throws StoreApiException
     */
    public function reconcileEntitlement(Entitlement $entitlement): Entitlement
    {
        $purchase = match ($entitlement->store) {
            Store::AppStore => $this->refreshAppStore($entitlement),
            Store::GooglePlay => $this->refreshGooglePlay($entitlement),
        };

        if ($purchase === null) {
            return $this->writer->refreshFromClock($entitlement, 'reconcile');
        }

        $fresh = $this->writer->apply($purchase, 'reconcile', null, $this->linkedTokens[(string) $purchase->purchaseTokenHash()] ?? null);
        $fresh->touch();

        return $fresh;
    }

    /**
     * True when $user has an active entitlement for the configured key.
     */
    public function hasEntitlement(Authenticatable $user, string $key): bool
    {
        $productIds = $this->productIdsFor($key);

        return $productIds !== [] && Entitlement::query()
            ->active()
            ->where('user_id', $user->getAuthIdentifier())
            ->whereIn('product_id', $productIds)
            ->exists();
    }

    /**
     * Entitlement keys $user currently has.
     *
     * @return list<string>
     */
    public function grantedKeys(Authenticatable $user): array
    {
        $productIds = Entitlement::query()
            ->active()
            ->where('user_id', $user->getAuthIdentifier())
            ->pluck('product_id')
            ->all();

        $keys = [];

        foreach ((array) config('mobile-entitlements.entitlements', []) as $key => $ids) {
            if (array_intersect((array) $ids, $productIds) !== []) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    public function productIdsFor(string $key): array
    {
        return array_values(array_map('strval', (array) config("mobile-entitlements.entitlements.{$key}", [])));
    }

    protected function verifyAppStore(string $signedTransaction): VerifiedPurchase
    {
        $transaction = $this->appleVerifier->verify($signedTransaction);
        $this->appleNotifications->assertBundleAndEnvironment($transaction['bundleId'] ?? null, $transaction['environment'] ?? null);

        if (! isset($transaction['transactionId'], $transaction['productId'])) {
            throw new VerificationException('Signed transaction has no transactionId or productId.');
        }

        if (! config('mobile-entitlements.apple.verify_with_server_api')) {
            return $this->appleMapper->map($transaction);
        }

        return $this->fetchFromAppStore(
            (string) $transaction['transactionId'],
            (string) ($transaction['originalTransactionId'] ?? $transaction['transactionId']),
            strtolower((string) $transaction['environment']),
        );
    }

    /**
     * Ask the App Store Server API for the current state of a transaction.
     */
    protected function fetchFromAppStore(string $transactionId, string $originalTransactionId, string $environment): VerifiedPurchase
    {
        $transaction = $this->appleVerifier->verify($this->appStoreApi->getTransactionInfo($transactionId, $environment));
        $this->appleNotifications->assertBundleAndEnvironment($transaction['bundleId'] ?? null, $transaction['environment'] ?? null);

        if (($transaction['type'] ?? null) !== 'Auto-Renewable Subscription') {
            return $this->appleMapper->map($transaction);
        }

        $status = $this->appStoreApi->getSubscriptionStatus($transactionId, $originalTransactionId, $environment);

        if ($status === null) {
            return $this->appleMapper->map($transaction);
        }

        $latest = $this->appleVerifier->verify($status['signedTransactionInfo']);
        $this->appleNotifications->assertBundleAndEnvironment($latest['bundleId'] ?? null, $latest['environment'] ?? null);
        $renewal = $status['signedRenewalInfo'] === null ? null : $this->appleVerifier->verify($status['signedRenewalInfo']);

        return $this->appleMapper->map($latest, $renewal, null, null, $status['status']);
    }

    protected function verifyGooglePlay(string $purchaseToken, ?string $productIdHint): VerifiedPurchase
    {
        $packageName = (string) config('mobile-entitlements.google.package_name');

        if ($packageName === '' || $purchaseToken === '') {
            throw new VerificationException('google.package_name is not configured or the token is empty.');
        }

        $purchase = null;

        try {
            $subscription = $this->playApi->getSubscription($packageName, $purchaseToken);
            $this->linkedTokens[hash('sha256', $purchaseToken)] = is_string($subscription['linkedPurchaseToken'] ?? null)
                ? $subscription['linkedPurchaseToken']
                : null;

            $purchase = $this->googleMapper->fromSubscription($purchaseToken, $subscription);
        } catch (StoreApiException $exception) {
            if (! $exception->isNotFound()) {
                throw $exception;
            }

            if (blank($productIdHint)) {
                throw new VerificationException('Google Play does not recognise this purchase token as a subscription.');
            }
        }

        if ($purchase !== null) {
            return $this->assertGoogleEnvironmentAccepted($purchase);
        }

        try {
            $product = $this->playApi->getProduct($packageName, (string) $productIdHint, $purchaseToken);
        } catch (StoreApiException $exception) {
            if ($exception->isNotFound()) {
                throw new VerificationException('Google Play does not recognise this purchase token.');
            }

            throw $exception;
        }

        return $this->assertGoogleEnvironmentAccepted(
            $this->googleMapper->fromProduct($purchaseToken, (string) $productIdHint, $product),
        );
    }

    /**
     * Google test purchases (licence testers) only grant access when google.accept_test_purchases is on.
     *
     * @throws VerificationException
     */
    protected function assertGoogleEnvironmentAccepted(VerifiedPurchase $purchase): VerifiedPurchase
    {
        if ($purchase->environment === 'sandbox' && ! config('mobile-entitlements.google.accept_test_purchases', false)) {
            throw new VerificationException('Google Play test purchases are not accepted.');
        }

        return $purchase;
    }

    protected function refreshAppStore(Entitlement $entitlement): ?VerifiedPurchase
    {
        if (! config('mobile-entitlements.apple.verify_with_server_api') || ! $this->appStoreApi->isConfigured()) {
            return null;
        }

        return $this->fetchFromAppStore(
            $entitlement->latest_transaction_id ?? $entitlement->original_transaction_id,
            $entitlement->original_transaction_id,
            $entitlement->environment ?? 'production',
        );
    }

    protected function refreshGooglePlay(Entitlement $entitlement): ?VerifiedPurchase
    {
        if ($entitlement->purchase_token_hash === null) {
            return null;
        }

        $token = StoreTransaction::query()
            ->where('store', Store::GooglePlay->value)
            ->where('purchase_token_hash', $entitlement->purchase_token_hash)
            ->latest('id')
            ->get()
            ->map(fn (StoreTransaction $transaction): ?string => $transaction->purchase_token)
            ->filter()
            ->first();

        if (! is_string($token)) {
            return null;
        }

        $packageName = (string) config('mobile-entitlements.google.package_name');

        try {
            if ($entitlement->type === ProductType::Subscription) {
                $subscription = $this->playApi->getSubscription($packageName, $token);
                $this->linkedTokens[hash('sha256', $token)] = is_string($subscription['linkedPurchaseToken'] ?? null)
                    ? $subscription['linkedPurchaseToken']
                    : null;

                return $this->googleMapper->fromSubscription($token, $subscription);
            }

            return $this->googleMapper->fromProduct($token, $entitlement->product_id, $this->playApi->getProduct($packageName, $entitlement->product_id, $token));
        } catch (StoreApiException $exception) {
            if ($exception->isNotFound()) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * When the app stamps purchases with the user's UUID (Apple appAccountToken / Google
     * obfuscatedExternalAccountId) and app_account_token_column is configured, a purchase stamped
     * for a different user cannot be claimed.
     *
     * @throws EntitlementOwnedByAnotherUser
     */
    protected function assertAppAccountTokenMatches(Authenticatable $user, VerifiedPurchase $purchase): void
    {
        $column = config('mobile-entitlements.app_account_token_column');

        if (blank($column) || blank($purchase->appAccountToken) || ! $user instanceof Model) {
            return;
        }

        $expected = $user->getAttribute((string) $column);

        if (filled($expected) && strtolower((string) $expected) !== strtolower((string) $purchase->appAccountToken)) {
            throw new EntitlementOwnedByAnotherUser('This purchase was made for another account.');
        }
    }

    /**
     * Resolve the configured user model for $id. Used by tests and the reconcile command.
     */
    public function findUser(int|string $id): ?Model
    {
        /** @var class-string<Model> $model */
        $model = config('mobile-entitlements.user_model');

        return $model::query()->find($id);
    }
}
