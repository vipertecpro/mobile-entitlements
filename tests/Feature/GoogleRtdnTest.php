<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Vipertecpro\MobileEntitlements\Enums\ProductType;
use Vipertecpro\MobileEntitlements\Enums\Store;
use Vipertecpro\MobileEntitlements\Events\EntitlementChanged;
use Vipertecpro\MobileEntitlements\Events\EntitlementGranted;
use Vipertecpro\MobileEntitlements\Events\EntitlementRevoked;
use Vipertecpro\MobileEntitlements\Events\StoreNotificationReceived;
use Vipertecpro\MobileEntitlements\Models\Entitlement;
use Vipertecpro\MobileEntitlements\Models\StoreTransaction;
use Vipertecpro\MobileEntitlements\Tests\Support\Fixtures;
use Vipertecpro\MobileEntitlements\Tests\Support\GoogleApi;
use Vipertecpro\MobileEntitlements\Tests\Support\GoogleKeys;

const SUB_TOKEN = 'gp-sub-token-1.AO-J1OyTestSubscriptionToken';
const OTP_TOKEN = 'gp-otp-token-1.AO-J1OyTestOneTimeToken';
const GOOGLE_EVENTS = [EntitlementGranted::class, EntitlementChanged::class, EntitlementRevoked::class, StoreNotificationReceived::class];

beforeEach(function (): void {
    GoogleApi::fake();
});

/**
 * Point the Play API at $play for the token the fixture names, then push the fixture.
 */
function pushGoogle(string $fixture, ?string $play = null, ?string $messageId = null, ?string $token = null): TestResponse
{
    if ($play !== null) {
        str_starts_with($play, 'product')
            ? GoogleApi::product('lifetime_unlock', OTP_TOKEN, Fixtures::play($play))
            : GoogleApi::subscription(SUB_TOKEN, Fixtures::play($play));
    }

    return test()->postJson(
        '/mobile-entitlements/google',
        Fixtures::googlePush($fixture, $messageId),
        ['Authorization' => 'Bearer '.($token ?? GoogleKeys::pushToken())],
    );
}

function googleEntitlement(string $productId): Entitlement
{
    return Entitlement::query()->where('store', Store::GooglePlay->value)->where('product_id', $productId)->sole();
}

it('applies every Google Play notification type from the Play Developer API', function (
    string $fixture,
    string $play,
    ?array $before,
    string $productId,
    array $expected,
    string $event,
): void {
    if ($before !== null) {
        pushGoogle($before[0], $before[1])->assertOk();
    }

    Event::fake(GOOGLE_EVENTS);

    pushGoogle($fixture, $play)->assertOk()->assertJson(['processed' => true]);

    $entitlement = googleEntitlement($productId);

    foreach ($expected as $attribute => $value) {
        expect($entitlement->{$attribute})->toBe($value, "{$fixture}: {$attribute}");
    }

    Event::assertDispatched($event, fn (object $dispatched): bool => $dispatched->entitlement->is($entitlement));
    Event::assertDispatched(StoreNotificationReceived::class, fn (StoreNotificationReceived $received): bool => $received->store === Store::GooglePlay && $received->type === $fixture);

    foreach (array_diff([EntitlementGranted::class, EntitlementChanged::class, EntitlementRevoked::class], [$event]) as $other) {
        Event::assertNotDispatched($other);
    }
})->with([
    '1 SUBSCRIPTION_RECOVERED' => ['SUBSCRIPTION_RECOVERED', 'subscription_active', ['SUBSCRIPTION_ON_HOLD', 'subscription_on_hold'], 'pro_monthly',
        ['is_active' => true, 'in_billing_retry' => false, 'will_renew' => true], EntitlementGranted::class],
    '2 SUBSCRIPTION_RENEWED' => ['SUBSCRIPTION_RENEWED', 'subscription_active', ['SUBSCRIPTION_PURCHASED', 'subscription_purchased'], 'pro_monthly',
        ['is_active' => true, 'is_trial' => false, 'latest_transaction_id' => 'GPA.3301-0000-0000-00001..1'], EntitlementChanged::class],
    '3 SUBSCRIPTION_CANCELED' => ['SUBSCRIPTION_CANCELED', 'subscription_canceled', ['SUBSCRIPTION_PURCHASED', 'subscription_purchased'], 'pro_monthly',
        ['is_active' => true, 'will_renew' => false], EntitlementChanged::class],
    '4 SUBSCRIPTION_PURCHASED' => ['SUBSCRIPTION_PURCHASED', 'subscription_purchased', null, 'pro_monthly',
        ['is_active' => true, 'will_renew' => true, 'is_trial' => true, 'environment' => 'production', 'type' => ProductType::Subscription, 'app_account_token' => 'b2c3d4e5-0000-4000-8000-000000000002', 'original_transaction_id' => hash('sha256', SUB_TOKEN), 'purchase_token_hash' => hash('sha256', SUB_TOKEN)],
        EntitlementGranted::class],
    '5 SUBSCRIPTION_ON_HOLD' => ['SUBSCRIPTION_ON_HOLD', 'subscription_on_hold', ['SUBSCRIPTION_PURCHASED', 'subscription_purchased'], 'pro_monthly',
        ['is_active' => false, 'in_billing_retry' => true, 'in_grace_period' => false], EntitlementRevoked::class],
    '6 SUBSCRIPTION_IN_GRACE_PERIOD' => ['SUBSCRIPTION_IN_GRACE_PERIOD', 'subscription_in_grace_period', ['SUBSCRIPTION_PURCHASED', 'subscription_purchased'], 'pro_monthly',
        ['is_active' => true, 'in_grace_period' => true, 'in_billing_retry' => true], EntitlementChanged::class],
    '7 SUBSCRIPTION_RESTARTED' => ['SUBSCRIPTION_RESTARTED', 'subscription_active', ['SUBSCRIPTION_CANCELED', 'subscription_canceled'], 'pro_monthly',
        ['is_active' => true, 'will_renew' => true], EntitlementChanged::class],
    '10 SUBSCRIPTION_PAUSED' => ['SUBSCRIPTION_PAUSED', 'subscription_paused', ['SUBSCRIPTION_PURCHASED', 'subscription_purchased'], 'pro_monthly',
        ['is_active' => false], EntitlementRevoked::class],
    '12 SUBSCRIPTION_REVOKED' => ['SUBSCRIPTION_REVOKED', 'subscription_expired', ['SUBSCRIPTION_PURCHASED', 'subscription_purchased'], 'pro_monthly',
        ['is_active' => false, 'revocation_reason' => 'revoked', 'will_renew' => false], EntitlementRevoked::class],
    '13 SUBSCRIPTION_EXPIRED' => ['SUBSCRIPTION_EXPIRED', 'subscription_expired', ['SUBSCRIPTION_CANCELED', 'subscription_canceled'], 'pro_monthly',
        ['is_active' => false, 'will_renew' => false], EntitlementRevoked::class],
    'ONE_TIME_PRODUCT_PURCHASED (1)' => ['ONE_TIME_PRODUCT_PURCHASED', 'product_purchased', null, 'lifetime_unlock',
        ['is_active' => true, 'type' => ProductType::NonConsumable, 'expires_at' => null, 'latest_transaction_id' => 'GPA.3302-0000-0000-00001', 'environment' => 'production'],
        EntitlementGranted::class],
    'ONE_TIME_PRODUCT_CANCELED (2)' => ['ONE_TIME_PRODUCT_CANCELED', 'product_canceled', null, 'lifetime_unlock',
        ['is_active' => false], EntitlementChanged::class],
]);

it('revokes a voided purchase', function (): void {
    pushGoogle('ONE_TIME_PRODUCT_PURCHASED', 'product_purchased')->assertOk();
    Event::fake(GOOGLE_EVENTS);

    pushGoogle('VOIDED_PURCHASE')->assertOk()->assertJson(['processed' => true]);

    $entitlement = googleEntitlement('lifetime_unlock');

    expect($entitlement->is_active)->toBeFalse()
        ->and($entitlement->revoked_at)->not->toBeNull()
        ->and($entitlement->revocation_reason)->toBe('voided');

    Event::assertDispatched(EntitlementRevoked::class, fn (EntitlementRevoked $event): bool => $event->cause === 'voided_purchase');
});

it('keeps access on a partial (quantity-based) void', function (): void {
    pushGoogle('ONE_TIME_PRODUCT_PURCHASED', 'product_purchased')->assertOk();
    Event::fake(GOOGLE_EVENTS);

    pushGoogle('VOIDED_PURCHASE_PARTIAL')->assertOk();

    expect(googleEntitlement('lifetime_unlock')->grantsAccessNow())->toBeTrue();
    Event::assertDispatched(EntitlementChanged::class, fn (EntitlementChanged $event): bool => $event->cause === 'voided_partial');
    Event::assertNotDispatched(EntitlementRevoked::class);
});

it('treats configured Google products as consumables', function (): void {
    config()->set('mobile-entitlements.google.accept_test_purchases', true);
    GoogleApi::product('coins_100', OTP_TOKEN, Fixtures::play('product_consumable'));
    $push = Fixtures::googlePush('ONE_TIME_PRODUCT_PURCHASED');
    $notification = json_decode(base64_decode($push['message']['data']), true);
    $notification['oneTimeProductNotification']['sku'] = 'coins_100';
    $push['message']['data'] = base64_encode(json_encode($notification));

    $this->postJson('/mobile-entitlements/google', $push, ['Authorization' => 'Bearer '.GoogleKeys::pushToken()])->assertOk();

    $entitlement = googleEntitlement('coins_100');

    expect($entitlement->type)->toBe(ProductType::Consumable)
        ->and($entitlement->quantity)->toBe(2)
        ->and($entitlement->environment)->toBe('sandbox');
});

it('stores test notifications without calling the Play API', function (): void {
    pushGoogle('TEST')->assertOk()->assertJson(['processed' => true]);

    expect(StoreTransaction::sole()->type)->toBe('TEST')
        ->and(Entitlement::count())->toBe(0);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'androidpublisher'));
});

it('is idempotent on messageId', function (): void {
    Event::fake(GOOGLE_EVENTS);

    pushGoogle('SUBSCRIPTION_PURCHASED', 'subscription_purchased', 'message-1')->assertOk();
    pushGoogle('SUBSCRIPTION_PURCHASED', 'subscription_purchased', 'message-1')->assertOk();

    expect(StoreTransaction::count())->toBe(1)
        ->and(Entitlement::count())->toBe(1);

    Event::assertDispatchedTimes(EntitlementGranted::class, 1);
    Http::assertSentCount(3); // certs, OAuth token, one subscriptionsv2.get
});

it('calls the Play Developer API with a cached service-account access token', function (): void {
    pushGoogle('SUBSCRIPTION_PURCHASED', 'subscription_purchased', 'message-1')->assertOk();
    pushGoogle('SUBSCRIPTION_RENEWED', 'subscription_active', 'message-2')->assertOk();

    expect(GoogleApi::$tokenRequests)->toBe(1);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/com.example.app/purchases/subscriptionsv2/tokens/'.rawurlencode(SUB_TOKEN)
            && $request->hasHeader('Authorization', 'Bearer ya29.test-access-token');
    });

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://oauth2.googleapis.com/token') {
            return false;
        }

        [$header, $claims] = array_map(
            fn (string $part): array => json_decode(base64_decode(strtr($part, '-_', '+/')), true),
            array_slice(explode('.', $request['assertion']), 0, 2),
        );

        return $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
            && $header['alg'] === 'RS256'
            && $claims['iss'] === 'play-api@example-project.iam.gserviceaccount.com'
            && $claims['scope'] === 'https://www.googleapis.com/auth/androidpublisher'
            && $claims['aud'] === 'https://oauth2.googleapis.com/token';
    });
});

it('answers 503 and stays retryable when the Play API is down', function (): void {
    GoogleApi::subscription(SUB_TOKEN, ['error' => 'backend'], 500);

    $this->postJson('/mobile-entitlements/google', Fixtures::googlePush('SUBSCRIPTION_PURCHASED', 'message-1'), ['Authorization' => 'Bearer '.GoogleKeys::pushToken()])
        ->assertStatus(503);

    $stored = StoreTransaction::sole();
    expect($stored->processed_at)->toBeNull()->and($stored->error)->toContain('500');

    pushGoogle('SUBSCRIPTION_PURCHASED', 'subscription_purchased', 'message-1')->assertOk();

    expect(StoreTransaction::sole()->processed_at)->not->toBeNull()
        ->and(googleEntitlement('pro_monthly')->is_active)->toBeTrue();
});

it('acknowledges and stops retrying tokens Google no longer knows', function (): void {
    GoogleApi::subscription(SUB_TOKEN, ['error' => 'gone'], 410);

    $this->postJson('/mobile-entitlements/google', Fixtures::googlePush('SUBSCRIPTION_EXPIRED'), ['Authorization' => 'Bearer '.GoogleKeys::pushToken()])
        ->assertOk()
        ->assertJson(['processed' => false]);

    expect(StoreTransaction::sole()->processed_at)->not->toBeNull()
        ->and(Entitlement::count())->toBe(0);
});

it('rejects pushes that fail OIDC verification', function (?string $token, array $config = []): void {
    config()->set($config);
    GoogleApi::subscription(SUB_TOKEN, Fixtures::play('subscription_purchased'));

    $headers = $token === null ? [] : ['Authorization' => 'Bearer '.$token];

    $this->postJson('/mobile-entitlements/google', Fixtures::googlePush('SUBSCRIPTION_PURCHASED'), $headers)->assertUnauthorized();

    expect(StoreTransaction::count())->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'androidpublisher'));
})->with([
    'missing token' => [null],
    'signed by another key' => [fn () => GoogleKeys::pushToken([], GoogleKeys::otherPrivateKey())],
    'unknown kid' => [fn () => GoogleKeys::pushToken([], GoogleKeys::otherPrivateKey(), 'rotated-kid')],
    'wrong audience' => [fn () => GoogleKeys::pushToken(['aud' => 'https://attacker.test'])],
    'wrong issuer' => [fn () => GoogleKeys::pushToken(['iss' => 'https://attacker.test'])],
    'wrong service account' => [fn () => GoogleKeys::pushToken(['email' => 'someone@example.com'])],
    'unverified email' => [fn () => GoogleKeys::pushToken(['email_verified' => false])],
    'expired' => [fn () => GoogleKeys::pushToken(['exp' => time() - 3600, 'iat' => time() - 7200])],
    'no expiry' => [fn () => GoogleKeys::pushToken(['exp' => null])],
    'garbage' => ['not.a.jwt'],
    'audience not configured' => [fn () => GoogleKeys::pushToken(), ['mobile-entitlements.google.push_audience' => null]],
]);

it('skips the email check when no push service account is configured', function (): void {
    config()->set('mobile-entitlements.google.push_service_account_email', null);

    pushGoogle('SUBSCRIPTION_PURCHASED', 'subscription_purchased', null, GoogleKeys::pushToken(['email' => 'other@example.com']))->assertOk();
});

it('rejects notifications for another package', function (): void {
    $push = Fixtures::googlePush('SUBSCRIPTION_PURCHASED');
    $notification = json_decode(base64_decode($push['message']['data']), true);
    $notification['packageName'] = 'com.attacker.app';
    $push['message']['data'] = base64_encode(json_encode($notification));

    $this->postJson('/mobile-entitlements/google', $push, ['Authorization' => 'Bearer '.GoogleKeys::pushToken()])->assertUnauthorized();
});

it('rejects malformed Pub/Sub bodies', function (array $body): void {
    $this->postJson('/mobile-entitlements/google', $body, ['Authorization' => 'Bearer '.GoogleKeys::pushToken()])->assertUnauthorized();
})->with([
    'empty' => [[]],
    'data is not base64 JSON' => [['message' => ['data' => '!!!', 'messageId' => '1']]],
    'no messageId' => [['message' => ['data' => base64_encode('{"packageName":"com.example.app"}')]]],
]);

it('caches Google OIDC certificates', function (): void {
    pushGoogle('TEST', null, 'm-1')->assertOk();
    pushGoogle('TEST', null, 'm-2')->assertOk();

    Http::assertSentCount(1);
});

it('supersedes the old purchase token on upgrade (linkedPurchaseToken)', function (): void {
    pushGoogle('SUBSCRIPTION_PURCHASED', 'subscription_purchased', 'm-1')->assertOk();

    $newToken = 'gp-sub-token-2.AO-J1OyUpgradedToken';
    $upgraded = Fixtures::play('subscription_active');
    $upgraded['linkedPurchaseToken'] = SUB_TOKEN;
    $upgraded['lineItems'][0]['productId'] = 'pro_yearly';
    GoogleApi::subscription($newToken, $upgraded);

    $push = Fixtures::googlePush('SUBSCRIPTION_PURCHASED', 'm-2');
    $notification = json_decode(base64_decode($push['message']['data']), true);
    $notification['subscriptionNotification']['purchaseToken'] = $newToken;
    $push['message']['data'] = base64_encode(json_encode($notification));

    $this->postJson('/mobile-entitlements/google', $push, ['Authorization' => 'Bearer '.GoogleKeys::pushToken()])->assertOk();

    expect(googleEntitlement('pro_monthly')->is_active)->toBeFalse()
        ->and(googleEntitlement('pro_yearly')->is_active)->toBeTrue();
});

it('stores the purchase token encrypted with a hash for lookups', function (): void {
    pushGoogle('SUBSCRIPTION_PURCHASED', 'subscription_purchased')->assertOk();

    $stored = StoreTransaction::sole();
    $raw = DB::table('store_transactions')->value('purchase_token');

    expect($stored->purchase_token)->toBe(SUB_TOKEN)
        ->and($raw)->not->toBe(SUB_TOKEN)
        ->and($stored->purchase_token_hash)->toBe(hash('sha256', SUB_TOKEN));
});

describe('test purchases', function (): void {
    beforeEach(function (): void {
        $sandbox = Fixtures::play('subscription_purchased');
        $sandbox['testPurchase'] = [];
        GoogleApi::subscription(SUB_TOKEN, $sandbox);
    });

    it('records but does not grant test purchases by default', function (): void {
        Event::fake(GOOGLE_EVENTS);

        pushGoogle('SUBSCRIPTION_PURCHASED')->assertOk()->assertJson(['processed' => true]);

        $stored = StoreTransaction::sole();

        expect(Entitlement::count())->toBe(0)
            ->and($stored->processed_at)->not->toBeNull()
            ->and($stored->environment)->toBe('sandbox')
            ->and($stored->error)->toContain('accept_test_purchases');

        Event::assertNotDispatched(EntitlementGranted::class);
    });

    it('grants test purchases when google.accept_test_purchases is true', function (): void {
        config()->set('mobile-entitlements.google.accept_test_purchases', true);

        pushGoogle('SUBSCRIPTION_PURCHASED')->assertOk();

        expect(googleEntitlement('pro_monthly'))
            ->is_active->toBeTrue()
            ->environment->toBe('sandbox');
        expect(StoreTransaction::sole()->error)->toBeNull();
    });
});

it('does not download Google certificates again for an unknown key within 60 seconds', function (): void {
    pushGoogle('TEST', null, 'm-1')->assertOk();

    foreach (['kid-a', 'kid-b', 'kid-a'] as $kid) {
        pushGoogle('TEST', null, 'm-'.$kid, GoogleKeys::pushToken([], GoogleKeys::otherPrivateKey(), $kid))->assertUnauthorized();
    }

    // One initial download plus a single refetch for the first unknown kid.
    Http::assertSentCount(2);
});

it('rate limits the webhooks per IP', function (): void {
    config()->set('mobile-entitlements.webhook_rate_limit', 2);

    $this->postJson('/mobile-entitlements/apple', [])->assertUnauthorized();
    $this->postJson('/mobile-entitlements/google', [])->assertUnauthorized();
    $this->postJson('/mobile-entitlements/apple', [])->assertTooManyRequests();
    $this->postJson('/mobile-entitlements/google', [])->assertTooManyRequests();
});

it('allows 120 webhook requests per minute by default', function (): void {
    expect(config('mobile-entitlements.webhook_rate_limit'))->toBe(120);
});
