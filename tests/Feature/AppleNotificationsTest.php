<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Vipertecpro\MobileEntitlements\Enums\ProductType;
use Vipertecpro\MobileEntitlements\Enums\Store;
use Vipertecpro\MobileEntitlements\Events\EntitlementChanged;
use Vipertecpro\MobileEntitlements\Events\EntitlementGranted;
use Vipertecpro\MobileEntitlements\Events\EntitlementRevoked;
use Vipertecpro\MobileEntitlements\Events\StoreNotificationReceived;
use Vipertecpro\MobileEntitlements\Models\Entitlement;
use Vipertecpro\MobileEntitlements\Models\StoreTransaction;
use Vipertecpro\MobileEntitlements\Tests\Support\AppleSigner;
use Vipertecpro\MobileEntitlements\Tests\Support\Fixtures;
use Vipertecpro\MobileEntitlements\Tests\Support\User;

const ENTITLEMENT_EVENTS = [EntitlementGranted::class, EntitlementChanged::class, EntitlementRevoked::class, StoreNotificationReceived::class];

function postAppleFixture(string $name): TestResponse
{
    return test()->postJson('/mobile-entitlements/apple', ['signedPayload' => Fixtures::appleNotification($name)]);
}

function entitlementFor(string $productId): Entitlement
{
    return Entitlement::query()->where('store', Store::AppStore->value)->where('product_id', $productId)->sole();
}

it('applies every App Store notification type', function (
    string $fixture,
    ?string $before,
    string $productId,
    array $expected,
    string $event,
): void {
    if ($before !== null) {
        postAppleFixture($before)->assertOk();
    }

    Event::fake(ENTITLEMENT_EVENTS);

    postAppleFixture($fixture)->assertOk()->assertJson(['processed' => true]);

    $entitlement = entitlementFor($productId);

    foreach ($expected as $attribute => $value) {
        expect($entitlement->{$attribute})->toBe($value, "{$fixture}: {$attribute}");
    }

    Event::assertDispatched($event, fn (object $dispatched): bool => $dispatched->entitlement->is($entitlement));
    Event::assertDispatched(StoreNotificationReceived::class, fn (StoreNotificationReceived $received): bool => $received->store === Store::AppStore);

    foreach (array_diff([EntitlementGranted::class, EntitlementChanged::class, EntitlementRevoked::class], [$event]) as $other) {
        Event::assertNotDispatched($other, fn (object $dispatched): bool => $dispatched->entitlement->is($entitlement));
    }
})->with([
    'SUBSCRIBED INITIAL_BUY' => ['SUBSCRIBED_INITIAL_BUY', null, 'com.example.pro.monthly',
        ['is_active' => true, 'will_renew' => true, 'is_trial' => true, 'in_grace_period' => false, 'type' => ProductType::Subscription, 'app_account_token' => 'a1b2c3d4-0000-4000-8000-000000000001', 'environment' => 'production', 'last_notification_type' => 'subscribed'],
        EntitlementGranted::class],
    'SUBSCRIBED RESUBSCRIBE' => ['SUBSCRIBED_RESUBSCRIBE', 'EXPIRED_VOLUNTARY', 'com.example.pro.monthly',
        ['is_active' => true, 'will_renew' => true, 'latest_transaction_id' => '2000000000000006'],
        EntitlementGranted::class],
    'DID_RENEW' => ['DID_RENEW', 'SUBSCRIBED_INITIAL_BUY', 'com.example.pro.monthly',
        ['is_active' => true, 'is_trial' => false, 'latest_transaction_id' => '2000000000000002'],
        EntitlementChanged::class],
    'DID_CHANGE_RENEWAL_STATUS AUTO_RENEW_DISABLED' => ['DID_CHANGE_RENEWAL_STATUS_AUTO_RENEW_DISABLED', 'SUBSCRIBED_INITIAL_BUY', 'com.example.pro.monthly',
        ['is_active' => true, 'will_renew' => false],
        EntitlementChanged::class],
    'DID_CHANGE_RENEWAL_STATUS AUTO_RENEW_ENABLED' => ['DID_CHANGE_RENEWAL_STATUS_AUTO_RENEW_ENABLED', 'DID_CHANGE_RENEWAL_STATUS_AUTO_RENEW_DISABLED', 'com.example.pro.monthly',
        ['is_active' => true, 'will_renew' => true],
        EntitlementChanged::class],
    'DID_CHANGE_RENEWAL_PREF UPGRADE' => ['DID_CHANGE_RENEWAL_PREF_UPGRADE', 'SUBSCRIBED_INITIAL_BUY', 'com.example.pro.yearly',
        ['is_active' => true, 'will_renew' => true, 'original_transaction_id' => '2000000000000001'],
        EntitlementGranted::class],
    'DID_CHANGE_RENEWAL_PREF DOWNGRADE' => ['DID_CHANGE_RENEWAL_PREF_DOWNGRADE', 'SUBSCRIBED_INITIAL_BUY', 'com.example.pro.monthly',
        ['is_active' => true, 'will_renew' => true],
        EntitlementChanged::class],
    'DID_FAIL_TO_RENEW GRACE_PERIOD' => ['DID_FAIL_TO_RENEW_GRACE_PERIOD', null, 'com.example.pro.monthly',
        ['is_active' => true, 'in_grace_period' => true, 'in_billing_retry' => true],
        EntitlementGranted::class],
    'GRACE_PERIOD_EXPIRED' => ['GRACE_PERIOD_EXPIRED', 'DID_FAIL_TO_RENEW_GRACE_PERIOD', 'com.example.pro.monthly',
        ['is_active' => false, 'in_grace_period' => false, 'in_billing_retry' => true],
        EntitlementRevoked::class],
    'EXPIRED VOLUNTARY' => ['EXPIRED_VOLUNTARY', null, 'com.example.pro.monthly',
        ['is_active' => false, 'will_renew' => false, 'in_billing_retry' => false],
        EntitlementChanged::class],
    'EXPIRED BILLING_RETRY' => ['EXPIRED_BILLING_RETRY', 'DID_FAIL_TO_RENEW_GRACE_PERIOD', 'com.example.pro.monthly',
        ['is_active' => false, 'in_grace_period' => false, 'in_billing_retry' => true, 'will_renew' => false],
        EntitlementRevoked::class],
    'EXPIRED PRICE_INCREASE' => ['EXPIRED_PRICE_INCREASE', null, 'com.example.pro.monthly',
        ['is_active' => false, 'in_billing_retry' => false],
        EntitlementChanged::class],
    'EXPIRED PRODUCT_NOT_FOR_SALE' => ['EXPIRED_PRODUCT_NOT_FOR_SALE', null, 'com.example.pro.monthly',
        ['is_active' => false],
        EntitlementChanged::class],
    'REFUND' => ['REFUND', 'SUBSCRIBED_INITIAL_BUY', 'com.example.pro.monthly',
        ['is_active' => false, 'revocation_reason' => 'refund:0'],
        EntitlementRevoked::class],
    'REVOKE' => ['REVOKE', 'SUBSCRIBED_INITIAL_BUY', 'com.example.pro.monthly',
        ['is_active' => false, 'revocation_reason' => 'family_sharing_revoked'],
        EntitlementRevoked::class],
    'OFFER_REDEEMED' => ['OFFER_REDEEMED', 'SUBSCRIBED_INITIAL_BUY', 'com.example.pro.monthly',
        ['is_active' => true, 'is_trial' => false, 'latest_transaction_id' => '2000000000000007'],
        EntitlementChanged::class],
    'ONE_TIME_CHARGE (non-consumable)' => ['ONE_TIME_CHARGE', null, 'com.example.lifetime',
        ['is_active' => true, 'type' => ProductType::NonConsumable, 'expires_at' => null, 'will_renew' => false, 'quantity' => 1],
        EntitlementGranted::class],
    'ONE_TIME_CHARGE (consumable)' => ['ONE_TIME_CHARGE_CONSUMABLE', null, 'com.example.coins.100',
        ['is_active' => true, 'type' => ProductType::Consumable, 'quantity' => 3, 'original_transaction_id' => '3000000000000002'],
        EntitlementGranted::class],
]);

it('keeps access until the billing grace period ends', function (): void {
    postAppleFixture('DID_FAIL_TO_RENEW_GRACE_PERIOD')->assertOk();

    $entitlement = entitlementFor('com.example.pro.monthly');

    expect($entitlement->expires_at->isFuture())->toBeTrue()
        ->and($entitlement->expires_at->diffInDays(now()->addDays(15), true))->toBeLessThan(1)
        ->and($entitlement->grantsAccessNow())->toBeTrue();
});

it('records revocation time for refunds', function (): void {
    postAppleFixture('SUBSCRIBED_INITIAL_BUY');
    postAppleFixture('REFUND');

    expect(entitlementFor('com.example.pro.monthly')->revoked_at)->not->toBeNull();
});

it('deactivates the old product on an upgrade', function (): void {
    postAppleFixture('SUBSCRIBED_INITIAL_BUY');
    Event::fake(ENTITLEMENT_EVENTS);

    postAppleFixture('DID_CHANGE_RENEWAL_PREF_UPGRADE');

    $monthly = entitlementFor('com.example.pro.monthly');

    expect($monthly->is_active)->toBeFalse()
        ->and($monthly->last_notification_type)->toBe('superseded')
        ->and(entitlementFor('com.example.pro.yearly')->is_active)->toBeTrue();

    Event::assertDispatched(EntitlementChanged::class, fn (EntitlementChanged $event): bool => $event->entitlement->is($monthly) && $event->cause === 'superseded');
});

it('creates one row per consumable purchase', function (): void {
    postAppleFixture('ONE_TIME_CHARGE_CONSUMABLE');

    $second = Fixtures::json('apple/notifications/ONE_TIME_CHARGE_CONSUMABLE.json');
    $second['notificationUUID'] = 'f5b8e6a2-4d4b-4c87-9c2e-9d8b5d5f0001';
    $second['data']['transactionInfo']['transactionId'] = '3000000000000003';
    $second['data']['transactionInfo']['originalTransactionId'] = '3000000000000003';

    $this->postJson('/mobile-entitlements/apple', ['signedPayload' => AppleSigner::sign(Fixtures::signAppleData($second))])->assertOk();

    expect(Entitlement::query()->where('product_id', 'com.example.coins.100')->count())->toBe(2)
        ->and(Entitlement::query()->where('product_id', 'com.example.coins.100')->sum('quantity'))->toBe(6);
});

it('stores TEST notifications without touching entitlements', function (): void {
    Event::fake(ENTITLEMENT_EVENTS);

    postAppleFixture('TEST')->assertOk()->assertJson(['processed' => true]);

    expect(Entitlement::count())->toBe(0)
        ->and(StoreTransaction::sole()->type)->toBe('TEST');

    Event::assertDispatched(StoreNotificationReceived::class, fn (StoreNotificationReceived $event): bool => $event->type === 'TEST');
    Event::assertNotDispatched(EntitlementGranted::class);
});

it('stores unknown notification types unprocessed and still answers 200', function (): void {
    $notification = Fixtures::json('apple/notifications/SUBSCRIBED_INITIAL_BUY.json');
    $notification['notificationType'] = 'SOMETHING_NEW';
    $notification['notificationUUID'] = 'f5b8e6a2-4d4b-4c87-9c2e-9d8b5d5f0002';

    $this->postJson('/mobile-entitlements/apple', ['signedPayload' => AppleSigner::sign(Fixtures::signAppleData($notification))])
        ->assertOk()
        ->assertJson(['received' => true, 'processed' => false]);

    $stored = StoreTransaction::sole();

    expect($stored->type)->toBe('SOMETHING_NEW')
        ->and($stored->processed_at)->toBeNull()
        ->and($stored->original_transaction_id)->toBe('2000000000000001')
        ->and(Entitlement::count())->toBe(0);
});

it('is idempotent on notificationUUID', function (): void {
    Event::fake(ENTITLEMENT_EVENTS);
    $signedPayload = Fixtures::appleNotification('SUBSCRIBED_INITIAL_BUY');

    $this->postJson('/mobile-entitlements/apple', ['signedPayload' => $signedPayload])->assertOk();
    $this->postJson('/mobile-entitlements/apple', ['signedPayload' => $signedPayload])->assertOk();
    $this->postJson('/mobile-entitlements/apple', ['signedPayload' => Fixtures::appleNotification('SUBSCRIBED_INITIAL_BUY')])->assertOk();

    expect(StoreTransaction::count())->toBe(1)
        ->and(Entitlement::count())->toBe(1);

    Event::assertDispatchedTimes(EntitlementGranted::class, 1);
    Event::assertDispatchedTimes(StoreNotificationReceived::class, 1);
});

it('stores the decoded payload, ids and the signed payload', function (): void {
    postAppleFixture('DID_RENEW');

    $stored = StoreTransaction::sole();

    expect($stored->store)->toBe(Store::AppStore)
        ->and($stored->notification_id)->toBe(Fixtures::json('apple/notifications/DID_RENEW.json')['notificationUUID'])
        ->and($stored->transaction_id)->toBe('2000000000000002')
        ->and($stored->product_id)->toBe('com.example.pro.monthly')
        ->and($stored->environment)->toBe('production')
        ->and($stored->payload['data']['transactionInfo']['transactionId'])->toBe('2000000000000002')
        ->and($stored->signed_payload)->not->toBeEmpty()
        ->and($stored->processed_at)->not->toBeNull();
});

it('ignores an older transaction that arrives after a newer one', function (): void {
    postAppleFixture('DID_RENEW');
    postAppleFixture('EXPIRED_VOLUNTARY');

    $entitlement = entitlementFor('com.example.pro.monthly');

    expect($entitlement->is_active)->toBeTrue()
        ->and($entitlement->latest_transaction_id)->toBe('2000000000000002');
});

it('links webhook rows to a user through appAccountToken when configured', function (): void {
    config()->set('mobile-entitlements.app_account_token_column', 'app_account_token');
    $user = User::newUser(['app_account_token' => 'a1b2c3d4-0000-4000-8000-000000000001']);

    postAppleFixture('SUBSCRIBED_INITIAL_BUY');

    expect(entitlementFor('com.example.pro.monthly')->user_id)->toBe($user->id)
        ->and($user->hasEntitlement('pro'))->toBeTrue();
});

it('leaves webhook rows unlinked when appAccountToken matching is off', function (): void {
    User::newUser(['app_account_token' => 'a1b2c3d4-0000-4000-8000-000000000001']);

    postAppleFixture('SUBSCRIBED_INITIAL_BUY');

    expect(entitlementFor('com.example.pro.monthly')->user_id)->toBeNull();
});
