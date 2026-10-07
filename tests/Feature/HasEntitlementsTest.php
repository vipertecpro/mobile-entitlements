<?php

use Vipertecpro\MobileEntitlements\Enums\ProductType;
use Vipertecpro\MobileEntitlements\Enums\Store;
use Vipertecpro\MobileEntitlements\Facades\MobileEntitlements;
use Vipertecpro\MobileEntitlements\Models\Entitlement;
use Vipertecpro\MobileEntitlements\Tests\Support\User;

function grant(User $user, array $attributes = []): Entitlement
{
    static $sequence = 0;
    $sequence++;

    return Entitlement::query()->create(array_merge([
        'user_id' => $user->id,
        'store' => Store::AppStore,
        'product_id' => 'com.example.pro.monthly',
        'type' => ProductType::Subscription,
        'original_transaction_id' => (string) (1000 + $sequence),
        'is_active' => true,
        'purchased_at' => now()->subDay(),
        'expires_at' => now()->addMonth(),
        'environment' => 'production',
    ], $attributes));
}

beforeEach(function (): void {
    $this->user = User::newUser();
});

it('has an entitlement while an active purchase of a mapped product exists', function (): void {
    grant($this->user);

    expect($this->user->hasEntitlement('pro'))->toBeTrue()
        ->and($this->user->hasEntitlement('lifetime'))->toBeFalse()
        ->and($this->user->hasEntitlement('unknown-key'))->toBeFalse()
        ->and(MobileEntitlements::hasEntitlement($this->user, 'pro'))->toBeTrue()
        ->and(MobileEntitlements::grantedKeys($this->user))->toBe(['pro']);
});

it('does not grant access for inactive, expired or revoked rows', function (array $attributes): void {
    grant($this->user, $attributes);

    expect($this->user->hasEntitlement('pro'))->toBeFalse()
        ->and($this->user->entitlement('pro'))->toBeNull()
        ->and($this->user->activeEntitlements()->count())->toBe(0);
})->with([
    'inactive' => [['is_active' => false]],
    'expired' => [['expires_at' => now()->subMinute()]],
    'revoked' => [['revoked_at' => now()->subMinute()]],
]);

it('ignores products that are not mapped to the key', function (): void {
    grant($this->user, ['product_id' => 'com.example.other']);

    expect($this->user->hasEntitlement('pro'))->toBeFalse()
        ->and($this->user->entitlement('pro'))->toBeNull()
        ->and(MobileEntitlements::grantedKeys($this->user))->toBe([]);
});

it('keeps access during the grace period even after expiry', function (): void {
    grant($this->user, ['expires_at' => now()->subHour(), 'in_grace_period' => true]);

    expect($this->user->hasEntitlement('pro'))->toBeTrue();
});

it('treats rows without expiry as lifetime purchases', function (): void {
    grant($this->user, ['product_id' => 'com.example.lifetime', 'type' => ProductType::NonConsumable, 'expires_at' => null]);

    expect($this->user->hasEntitlement('lifetime'))->toBeTrue();
});

it('returns the active entitlement with the latest expiry', function (): void {
    grant($this->user, ['expires_at' => now()->addDays(3)]);
    $latest = grant($this->user, ['product_id' => 'com.example.pro.yearly', 'expires_at' => now()->addYear()]);
    grant($this->user, ['expires_at' => now()->addYears(5), 'is_active' => false]);

    expect($this->user->entitlement('pro')->is($latest))->toBeTrue();
});

it('prefers a lifetime purchase over any expiry', function (): void {
    config()->set('mobile-entitlements.entitlements.pro', ['com.example.pro.monthly', 'com.example.lifetime']);
    grant($this->user, ['expires_at' => now()->addYears(2)]);
    $lifetime = grant($this->user, ['product_id' => 'com.example.lifetime', 'expires_at' => null]);

    expect($this->user->entitlement('pro')->is($lifetime))->toBeTrue();
});

it('exposes all and active entitlements as relations', function (): void {
    grant($this->user);
    grant($this->user, ['is_active' => false]);
    grant(User::newUser());

    expect($this->user->entitlements()->count())->toBe(2)
        ->and($this->user->activeEntitlements()->count())->toBe(1)
        ->and($this->user->activeEntitlements()->first())->toBeInstanceOf(Entitlement::class);
});

it('does not see entitlements of other users', function (): void {
    grant(User::newUser());

    expect($this->user->hasEntitlement('pro'))->toBeFalse();
});
