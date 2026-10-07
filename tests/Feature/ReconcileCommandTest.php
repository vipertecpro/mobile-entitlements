<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Vipertecpro\MobileEntitlements\Apple\AppStoreServerApiClient;
use Vipertecpro\MobileEntitlements\Enums\ProductType;
use Vipertecpro\MobileEntitlements\Enums\Store;
use Vipertecpro\MobileEntitlements\Events\EntitlementRevoked;
use Vipertecpro\MobileEntitlements\Facades\MobileEntitlements;
use Vipertecpro\MobileEntitlements\Models\Entitlement;
use Vipertecpro\MobileEntitlements\Models\StoreTransaction;
use Vipertecpro\MobileEntitlements\Support\VerifiedPurchase;
use Vipertecpro\MobileEntitlements\Tests\Support\AppleSigner;
use Vipertecpro\MobileEntitlements\Tests\Support\Fixtures;
use Vipertecpro\MobileEntitlements\Tests\Support\GoogleApi;
use Vipertecpro\MobileEntitlements\Tests\Support\User;

beforeEach(function (): void {
    GoogleApi::fake();
    $this->user = User::newUser();
});

function googleRowWithToken(User $user, string $token, array $attributes = []): Entitlement
{
    StoreTransaction::query()->create([
        'store' => Store::GooglePlay,
        'notification_id' => 'sync:'.$token,
        'type' => 'SYNC',
        'purchase_token' => $token,
        'purchase_token_hash' => hash('sha256', $token),
        'received_at' => now(),
        'processed_at' => now(),
    ]);

    return Entitlement::query()->create(array_merge([
        'user_id' => $user->id,
        'store' => Store::GooglePlay,
        'product_id' => 'pro_monthly',
        'type' => ProductType::Subscription,
        'original_transaction_id' => hash('sha256', $token),
        'purchase_token_hash' => hash('sha256', $token),
        'is_active' => true,
        'purchased_at' => now()->subMonths(2),
        'expires_at' => now()->subDay(),
        'will_renew' => true,
    ], $attributes));
}

function appleRow(User $user, array $attributes = []): Entitlement
{
    return Entitlement::query()->create(array_merge([
        'user_id' => $user->id,
        'store' => Store::AppStore,
        'product_id' => 'com.example.pro.monthly',
        'type' => ProductType::Subscription,
        'original_transaction_id' => '2000000000000001',
        'latest_transaction_id' => '2000000000000001',
        'is_active' => true,
        'purchased_at' => now()->subMonths(2),
        'expires_at' => now()->subDay(),
        'environment' => 'production',
    ], $attributes));
}

it('fixes a Google row whose renewal webhook was lost', function (): void {
    $row = googleRowWithToken($this->user, 'lost-renewal-token');
    GoogleApi::subscription('lost-renewal-token', Fixtures::play('subscription_active'));

    $this->artisan('mobile-entitlements:reconcile')->assertSuccessful();

    expect($row->fresh()->grantsAccessNow())->toBeTrue()
        ->and($row->fresh()->expires_at->isFuture())->toBeTrue()
        ->and($row->fresh()->last_notification_type)->toBe('reconcile');
});

it('revokes a Google row whose expiry webhook was lost', function (): void {
    Event::fake([EntitlementRevoked::class]);
    $row = googleRowWithToken($this->user, 'lost-expiry-token');
    GoogleApi::subscription('lost-expiry-token', Fixtures::play('subscription_expired'));

    $this->artisan('mobile-entitlements:reconcile')->assertSuccessful();

    expect($row->fresh()->is_active)->toBeFalse();
    Event::assertDispatched(EntitlementRevoked::class, fn (EntitlementRevoked $event): bool => $event->cause === 'reconcile');
});

it('falls back to the stored expiry when the App Store Server API is not configured', function (): void {
    $row = appleRow($this->user, ['in_grace_period' => true]);

    $this->artisan('mobile-entitlements:reconcile')->assertSuccessful();

    expect($row->fresh())
        ->is_active->toBeFalse()
        ->in_grace_period->toBeFalse();

    Http::assertNothingSent();
});

it('asks the App Store Server API when configured', function (): void {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($key, $pem);
    config()->set([
        'mobile-entitlements.apple.verify_with_server_api' => true,
        'mobile-entitlements.apple.issuer_id' => 'issuer',
        'mobile-entitlements.apple.key_id' => 'KEYID12345',
        'mobile-entitlements.apple.private_key' => $pem,
    ]);

    $renewed = AppleSigner::transaction(['transactionId' => '2000000000000010', 'purchaseDate' => now()->subMinute()->getTimestampMs()]);
    Http::fake([
        AppStoreServerApiClient::PRODUCTION_URL.'/inApps/v1/transactions/*' => Http::response(['signedTransactionInfo' => $renewed]),
        AppStoreServerApiClient::PRODUCTION_URL.'/inApps/v1/subscriptions/*' => Http::response(['data' => [['lastTransactions' => [[
            'originalTransactionId' => '2000000000000001',
            'status' => 1,
            'signedTransactionInfo' => $renewed,
            'signedRenewalInfo' => AppleSigner::sign(['originalTransactionId' => '2000000000000001', 'autoRenewStatus' => 1, 'productId' => 'com.example.pro.monthly', 'environment' => 'Production']),
        ]]]]]),
    ]);

    $row = appleRow($this->user);

    $this->artisan('mobile-entitlements:reconcile')->assertSuccessful();

    expect($row->fresh())
        ->is_active->toBeTrue()
        ->will_renew->toBeTrue()
        ->latest_transaction_id->toBe('2000000000000010');
});

it('only touches the given user with --user', function (): void {
    $mine = appleRow($this->user);
    $theirs = appleRow(User::newUser(), ['original_transaction_id' => '2000000000000099']);

    $this->artisan('mobile-entitlements:reconcile', ['--user' => $this->user->id])->assertSuccessful();

    expect($mine->fresh()->is_active)->toBeFalse()
        ->and($theirs->fresh()->is_active)->toBeTrue();
});

it('refreshes rows not seen for longer than --stale', function (): void {
    $row = googleRowWithToken($this->user, 'stale-token', ['expires_at' => now()->addDays(3), 'will_renew' => true]);
    Entitlement::query()->whereKey($row->id)->update(['updated_at' => now()->subDays(2)]);
    GoogleApi::subscription('stale-token', Fixtures::play('subscription_canceled'));

    $this->artisan('mobile-entitlements:reconcile', ['--stale' => '36h'])->assertSuccessful();

    expect($row->fresh()->will_renew)->toBeFalse();
});

it('leaves fresh, unexpired rows alone', function (): void {
    $row = googleRowWithToken($this->user, 'fresh-token', ['expires_at' => now()->addDays(3)]);

    $this->artisan('mobile-entitlements:reconcile', ['--stale' => '24h'])->assertSuccessful();

    expect($row->fresh()->last_notification_type)->toBeNull();
    Http::assertNothingSent();
});

it('reports store failures and keeps going', function (): void {
    googleRowWithToken($this->user, 'broken-token');
    GoogleApi::subscription('broken-token', [], 500);
    $apple = appleRow($this->user);

    $this->artisan('mobile-entitlements:reconcile')->assertFailed();

    expect($apple->fresh()->is_active)->toBeFalse();
});

it('rejects an invalid --stale value', function (): void {
    $this->artisan('mobile-entitlements:reconcile', ['--stale' => 'tomorrow'])->assertExitCode(2);
});

it('reconciles one user through the facade', function (): void {
    $fake = MobileEntitlements::fake([
        'token' => VerifiedPurchase::fake([
            'store' => Store::AppStore,
            'originalTransactionId' => '2000000000000001',
            'productId' => 'com.example.pro.monthly',
            'purchasedAt' => now()->toImmutable(),
            'expiresAt' => now()->addMonth()->toImmutable(),
        ]),
    ]);
    $row = appleRow($this->user);

    $result = MobileEntitlements::reconcile($this->user);

    expect($result)->toHaveCount(1)
        ->and($row->fresh()->grantsAccessNow())->toBeTrue();
});
