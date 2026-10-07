<?php

use Illuminate\Support\Facades\Route;
use Vipertecpro\MobileEntitlements\Enums\ProductType;
use Vipertecpro\MobileEntitlements\Enums\Store;
use Vipertecpro\MobileEntitlements\Models\Entitlement;
use Vipertecpro\MobileEntitlements\Tests\Support\User;

beforeEach(function (): void {
    Route::middleware('entitled:pro')->get('/premium', fn () => response()->json(['ok' => true]));
    Route::middleware('entitled:pro,lifetime')->get('/premium-or-lifetime', fn () => response()->json(['ok' => true]));

    $this->user = User::newUser();
});

function entitle(User $user, string $productId, array $attributes = []): void
{
    Entitlement::query()->create(array_merge([
        'user_id' => $user->id,
        'store' => Store::GooglePlay,
        'product_id' => $productId,
        'type' => ProductType::Subscription,
        'original_transaction_id' => hash('sha256', $productId.$user->id),
        'is_active' => true,
        'expires_at' => now()->addMonth(),
    ], $attributes));
}

it('answers 402 JSON when the entitlement is missing', function (): void {
    $this->actingAs($this->user)->getJson('/premium')
        ->assertStatus(402)
        ->assertExactJson(['message' => 'An active entitlement is required.', 'entitlements' => ['pro']]);
});

it('lets entitled users through', function (): void {
    entitle($this->user, 'pro_monthly');

    $this->actingAs($this->user)->getJson('/premium')->assertOk()->assertJson(['ok' => true]);
});

it('blocks once the entitlement has expired', function (): void {
    entitle($this->user, 'pro_monthly', ['expires_at' => now()->subSecond()]);

    $this->actingAs($this->user)->getJson('/premium')->assertStatus(402);
});

it('accepts any of several keys', function (): void {
    entitle($this->user, 'lifetime_unlock', ['expires_at' => null]);

    $this->actingAs($this->user)->getJson('/premium-or-lifetime')->assertOk();
    $this->actingAs($this->user)->getJson('/premium')->assertStatus(402);
});

it('answers 401 for guests', function (): void {
    $this->getJson('/premium')->assertUnauthorized();
});

it('redirects browser requests when redirect_to is configured', function (): void {
    config()->set('mobile-entitlements.middleware.redirect_to', '/pricing');

    $this->actingAs($this->user)->get('/premium')->assertRedirect('/pricing');
    $this->actingAs($this->user)->getJson('/premium')->assertStatus(402);
});
