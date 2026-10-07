<?php

use Illuminate\Support\Facades\Gate;
use Vipertecpro\MobileEntitlements\Enums\ProductType;
use Vipertecpro\MobileEntitlements\Enums\Store;
use Vipertecpro\MobileEntitlements\Models\Entitlement;
use Vipertecpro\MobileEntitlements\Support\RevenueReport;
use Vipertecpro\MobileEntitlements\Tests\Support\User;

beforeEach(function (): void {
    $this->freezeSecond();
});

/**
 * @return array<string, mixed>
 */
function revenueReport(int $days = 30): array
{
    return app(RevenueReport::class)->generate($days);
}

function reportRow(Store $store, ProductType $type, string $productId, array $attributes = []): Entitlement
{
    static $counter = 0;
    $counter++;

    return Entitlement::query()->create(array_merge([
        'store' => $store,
        'product_id' => $productId,
        'type' => $type,
        'original_transaction_id' => "report-{$counter}",
        'is_active' => true,
        'purchased_at' => now()->subDays(5),
        'expires_at' => $type === ProductType::Subscription ? now()->addDays(20) : null,
        'environment' => 'production',
        'created_at' => now()->subDays(5),
    ], $attributes));
}

/**
 * App Store: 2 active (1 trial, 1 paying monthly), 1 refunded, 1 expired 3 days ago, 1 old active from 90 days ago,
 * 1 lifetime unlock, 2 consumable rows (3 + 2 coins). Google Play: 1 active in grace, 1 voided one-time, 1 revoked sub.
 * Sandbox rows must never count.
 */
function seedReport(): void
{
    reportRow(Store::AppStore, ProductType::Subscription, 'com.example.pro.monthly', ['is_trial' => true]);
    reportRow(Store::AppStore, ProductType::Subscription, 'com.example.pro.monthly');
    reportRow(Store::AppStore, ProductType::Subscription, 'com.example.pro.yearly', [
        'is_active' => false, 'revoked_at' => now()->subDays(2), 'revocation_reason' => 'refund:1',
    ]);
    reportRow(Store::AppStore, ProductType::Subscription, 'com.example.pro.monthly', [
        'is_active' => false, 'expires_at' => now()->subDays(3), 'created_at' => now()->subDays(40),
    ]);
    reportRow(Store::AppStore, ProductType::Subscription, 'com.example.pro.yearly', ['created_at' => now()->subDays(90), 'expires_at' => now()->addMonths(9)]);
    reportRow(Store::AppStore, ProductType::NonConsumable, 'com.example.lifetime');
    reportRow(Store::AppStore, ProductType::Consumable, 'com.example.coins.100', ['quantity' => 3]);
    reportRow(Store::AppStore, ProductType::Consumable, 'com.example.coins.100', ['quantity' => 2]);

    reportRow(Store::GooglePlay, ProductType::Subscription, 'pro_monthly', ['expires_at' => now()->subDay(), 'in_grace_period' => true]);
    reportRow(Store::GooglePlay, ProductType::NonConsumable, 'lifetime_unlock', [
        'is_active' => false, 'revoked_at' => now()->subDay(), 'revocation_reason' => 'voided',
    ]);
    reportRow(Store::GooglePlay, ProductType::Subscription, 'pro_monthly', [
        'is_active' => false, 'revoked_at' => now()->subDays(4), 'revocation_reason' => 'revoked', 'created_at' => now()->subDays(60),
    ]);

    reportRow(Store::AppStore, ProductType::Subscription, 'com.example.pro.monthly', ['environment' => 'sandbox']);
    reportRow(Store::GooglePlay, ProductType::Consumable, 'coins_100', ['environment' => 'sandbox', 'quantity' => 50]);
}

it('counts subscribers, churn, refunds and sales by store', function (): void {
    seedReport();

    $report = revenueReport();

    expect($report['period']['days'])->toBe(30)
        ->and($report['stores']['appStore'])->toBe([
            'active_subscribers' => 3,
            'in_trial' => 1,
            'new_subscriptions' => 3,
            'churned' => 2,
            'refunds' => 1,
            'one_time_unlocks_sold' => 1,
            'consumables_sold' => 5,
        ])
        ->and($report['stores']['googlePlay'])->toBe([
            'active_subscribers' => 1,
            'in_trial' => 0,
            'new_subscriptions' => 1,
            'churned' => 1,
            'refunds' => 1,
            'one_time_unlocks_sold' => 1,
            'consumables_sold' => 0,
        ])
        ->and($report['total']['active_subscribers'])->toBe(4)
        ->and($report['total']['consumables_sold'])->toBe(5)
        ->and($report['total'])->not->toHaveKey('estimated_mrr');
});

it('narrows the period with --days', function (): void {
    seedReport();

    $report = revenueReport(1);

    expect($report['total'])
        ->new_subscriptions->toBe(0)
        ->churned->toBe(0)
        ->refunds->toBe(1)
        ->active_subscribers->toBe(4);
});

it('adds estimated MRR only when prices are configured', function (): void {
    config()->set('mobile-entitlements.prices', ['com.example.pro.monthly' => 9.99, 'com.example.pro.yearly' => 120 / 12]);
    seedReport();

    $report = revenueReport();

    // App Store: monthly 9.99 + yearly 10.00 (the trial pays nothing). Google: pro_monthly has no price.
    expect($report['stores']['appStore']['estimated_mrr'])->toBe(19.99)
        ->and($report['stores']['appStore']['unpriced_subscribers'])->toBe(0)
        ->and($report['stores']['googlePlay']['estimated_mrr'])->toEqual(0)
        ->and($report['stores']['googlePlay']['unpriced_subscribers'])->toBe(1)
        ->and($report['total']['estimated_mrr'])->toBe(19.99);
});

it('prints a table', function (): void {
    seedReport();

    $this->artisan('mobile-entitlements:report', ['--days' => 30])
        ->expectsOutputToContain('Active subscribers')
        ->expectsOutputToContain('Consumables sold')
        ->assertSuccessful();
});

it('prints JSON with --json', function (): void {
    seedReport();

    $this->artisan('mobile-entitlements:report', ['--json' => true])
        ->expectsOutputToContain('"active_subscribers": 4')
        ->assertSuccessful();
});

it('rejects an invalid --days value', function (string $days): void {
    $this->artisan('mobile-entitlements:report', ['--days' => $days])->assertExitCode(2);
})->with(['0', 'abc', '-3']);

describe('GET /summary', function (): void {
    it('returns the report to users the Gate allows', function (): void {
        Gate::define('viewMobileEntitlementsSummary', fn (User $user): bool => $user->email === 'owner@example.test');
        seedReport();

        $this->actingAs(User::newUser(['email' => 'owner@example.test']))
            ->getJson('/mobile-entitlements/summary?days=30')
            ->assertOk()
            ->assertJsonPath('period.days', 30)
            ->assertJsonPath('stores.appStore.active_subscribers', 3)
            ->assertJsonPath('total.refunds', 2)
            ->assertJsonMissingPath('total.estimated_mrr');
    });

    it('defaults to 30 days', function (): void {
        Gate::define('viewMobileEntitlementsSummary', fn (User $user): bool => true);

        $this->actingAs(User::newUser())->getJson('/mobile-entitlements/summary')->assertOk()->assertJsonPath('period.days', 30);
    });

    it('denies users the Gate refuses', function (): void {
        Gate::define('viewMobileEntitlementsSummary', fn (User $user): bool => false);

        $this->actingAs(User::newUser())->getJson('/mobile-entitlements/summary')->assertForbidden();
    });

    it('denies everyone when the Gate is not defined', function (): void {
        $this->actingAs(User::newUser())->getJson('/mobile-entitlements/summary')->assertForbidden();
    });

    it('requires authentication', function (): void {
        $this->getJson('/mobile-entitlements/summary')->assertUnauthorized();
    });

    it('validates days', function (): void {
        Gate::define('viewMobileEntitlementsSummary', fn (User $user): bool => true);

        $this->actingAs(User::newUser())->getJson('/mobile-entitlements/summary?days=0')->assertUnprocessable();
    });
});
