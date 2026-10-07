<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Vipertecpro\MobileEntitlements\Apple\AppStoreServerApiClient;
use Vipertecpro\MobileEntitlements\Enums\ProductType;
use Vipertecpro\MobileEntitlements\Enums\Store;
use Vipertecpro\MobileEntitlements\Events\EntitlementChanged;
use Vipertecpro\MobileEntitlements\Events\EntitlementGranted;
use Vipertecpro\MobileEntitlements\Facades\MobileEntitlements;
use Vipertecpro\MobileEntitlements\Models\Entitlement;
use Vipertecpro\MobileEntitlements\Models\StoreTransaction;
use Vipertecpro\MobileEntitlements\Support\VerifiedPurchase;
use Vipertecpro\MobileEntitlements\Tests\Support\AppleSigner;
use Vipertecpro\MobileEntitlements\Tests\Support\Fixtures;
use Vipertecpro\MobileEntitlements\Tests\Support\GoogleApi;
use Vipertecpro\MobileEntitlements\Tests\Support\GoogleKeys;
use Vipertecpro\MobileEntitlements\Tests\Support\User;

beforeEach(function (): void {
    GoogleApi::fake();
    $this->user = User::newUser();
});

describe('App Store', function (): void {
    it('verifies the signed transaction and grants the entitlement to the signed-in user', function (): void {
        Event::fake([EntitlementGranted::class]);

        $this->actingAs($this->user)
            ->postJson('/mobile-entitlements/sync', ['store' => 'appStore', 'token' => AppleSigner::transaction()])
            ->assertOk()
            ->assertJson([
                'granted' => ['pro'],
                'entitlements' => [['store' => 'appStore', 'product_id' => 'com.example.pro.monthly', 'keys' => ['pro'], 'is_active' => true]],
            ]);

        $entitlement = Entitlement::sole();

        expect($entitlement->user_id)->toBe($this->user->id)
            ->and($entitlement->original_transaction_id)->toBe('2000000000000001')
            ->and($this->user->hasEntitlement('pro'))->toBeTrue();

        expect(StoreTransaction::sole())
            ->type->toBe('SYNC')
            ->processed_at->not->toBeNull();

        Event::assertDispatchedTimes(EntitlementGranted::class, 1);
    });

    it('takes the product id from the store, never from the client', function (): void {
        $this->actingAs($this->user)
            ->postJson('/mobile-entitlements/sync', [
                'store' => 'appStore',
                'token' => AppleSigner::transaction(['productId' => 'com.example.basic.monthly']),
                'productId' => 'com.example.lifetime',
            ])
            ->assertOk()
            ->assertJson(['granted' => []]);

        expect(Entitlement::sole()->product_id)->toBe('com.example.basic.monthly')
            ->and($this->user->hasEntitlement('lifetime'))->toBeFalse()
            ->and($this->user->hasEntitlement('pro'))->toBeFalse();
    });

    it('links a row that a webhook created before the app synced', function (): void {
        $this->postJson('/mobile-entitlements/apple', ['signedPayload' => Fixtures::appleNotification('SUBSCRIBED_INITIAL_BUY')])->assertOk();
        expect(Entitlement::sole()->user_id)->toBeNull();

        Event::fake([EntitlementGranted::class, EntitlementChanged::class]);

        $this->actingAs($this->user)
            ->postJson('/mobile-entitlements/sync', [
                'store' => 'appStore',
                'token' => AppleSigner::transaction(['purchaseDate' => now()->addSecond()->getTimestampMs()]),
            ])
            ->assertOk()
            ->assertJson(['granted' => ['pro']]);

        expect(Entitlement::sole()->user_id)->toBe($this->user->id);
        Event::assertNotDispatched(EntitlementGranted::class);
        Event::assertDispatched(EntitlementChanged::class, fn (EntitlementChanged $event): bool => $event->cause === 'sync');
    });

    it('links anonymous rows of the same subscription for other products', function (): void {
        $this->postJson('/mobile-entitlements/apple', ['signedPayload' => Fixtures::appleNotification('EXPIRED_VOLUNTARY')])->assertOk();

        $this->actingAs($this->user)
            ->postJson('/mobile-entitlements/sync', ['store' => 'appStore', 'token' => AppleSigner::transaction([
                'transactionId' => '2000000000000009',
                'productId' => 'com.example.pro.yearly',
            ])])
            ->assertOk();

        expect(Entitlement::query()->whereNull('user_id')->count())->toBe(0)
            ->and(Entitlement::count())->toBe(2);
    });

    it('refuses a purchase that belongs to another user', function (): void {
        $owner = User::newUser();
        $this->actingAs($owner)->postJson('/mobile-entitlements/sync', ['store' => 'appStore', 'token' => AppleSigner::transaction()])->assertOk();

        $this->actingAs($this->user)
            ->postJson('/mobile-entitlements/sync', ['store' => 'appStore', 'token' => AppleSigner::transaction()])
            ->assertStatus(409);

        expect(Entitlement::sole()->user_id)->toBe($owner->id)
            ->and($this->user->hasEntitlement('pro'))->toBeFalse();
    });

    it('refuses a purchase stamped with another user\'s appAccountToken', function (): void {
        config()->set('mobile-entitlements.app_account_token_column', 'app_account_token');
        $this->user->forceFill(['app_account_token' => 'c0ffee00-0000-4000-8000-000000000003'])->save();

        $this->actingAs($this->user)
            ->postJson('/mobile-entitlements/sync', ['store' => 'appStore', 'token' => AppleSigner::transaction(['appAccountToken' => 'a1b2c3d4-0000-4000-8000-000000000001'])])
            ->assertStatus(409);

        $this->actingAs($this->user)
            ->postJson('/mobile-entitlements/sync', ['store' => 'appStore', 'token' => AppleSigner::transaction(['appAccountToken' => 'C0FFEE00-0000-4000-8000-000000000003'])])
            ->assertOk();
    });

    it('rejects tokens that fail JWS verification', function (array $options, array $overrides = []): void {
        $this->actingAs($this->user)
            ->postJson('/mobile-entitlements/sync', ['store' => 'appStore', 'token' => AppleSigner::transaction($overrides, $options)])
            ->assertStatus(422);

        expect(Entitlement::count())->toBe(0);
    })->with([
        'untrusted chain' => [['leaf' => 'other-leaf', 'intermediate' => 'other-intermediate', 'root' => 'other-root', 'key' => 'other-leaf']],
        'bad signature' => [['key' => 'other-leaf']],
        'wrong OID' => [['leaf' => 'leaf-no-oid']],
        'wrong bundle id' => [[], ['bundleId' => 'com.attacker.app']],
        'sandbox transaction' => [[], ['environment' => 'Sandbox']],
    ]);

    it('rejects a token that is not a JWS', function (): void {
        $this->actingAs($this->user)
            ->postJson('/mobile-entitlements/sync', ['store' => 'appStore', 'token' => 'hello'])
            ->assertStatus(422);
    });

    it('asks the App Store Server API for status when verify_with_server_api is on', function (): void {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);

        config()->set([
            'mobile-entitlements.apple.verify_with_server_api' => true,
            'mobile-entitlements.apple.issuer_id' => '57246542-96fe-1a63-e053-0824d011072a',
            'mobile-entitlements.apple.key_id' => '2X9R4HXF34',
            'mobile-entitlements.apple.private_key' => $pem,
        ]);

        $graceUntil = now()->addDays(10)->getTimestampMs();
        $serverTransaction = AppleSigner::transaction(['expiresDate' => now()->subHour()->getTimestampMs(), 'transactionId' => '2000000000000004']);

        Http::fake([
            AppStoreServerApiClient::PRODUCTION_URL.'/inApps/v1/transactions/2000000000000001' => Http::response(['signedTransactionInfo' => AppleSigner::transaction()]),
            AppStoreServerApiClient::PRODUCTION_URL.'/inApps/v1/subscriptions/2000000000000001' => Http::response([
                'environment' => 'Production',
                'bundleId' => 'com.example.app',
                'appAppleId' => 1234567890,
                'data' => [[
                    'subscriptionGroupIdentifier' => '21000001',
                    'lastTransactions' => [[
                        'originalTransactionId' => '2000000000000001',
                        'status' => 4,
                        'signedTransactionInfo' => $serverTransaction,
                        'signedRenewalInfo' => AppleSigner::sign([
                            'originalTransactionId' => '2000000000000001',
                            'productId' => 'com.example.pro.monthly',
                            'autoRenewProductId' => 'com.example.pro.monthly',
                            'autoRenewStatus' => 1,
                            'isInBillingRetryPeriod' => true,
                            'gracePeriodExpiresDate' => $graceUntil,
                            'environment' => 'Production',
                            'signedDate' => now()->getTimestampMs(),
                        ]),
                    ]],
                ]],
            ]),
        ]);

        $this->actingAs($this->user)
            ->postJson('/mobile-entitlements/sync', ['store' => 'appStore', 'token' => AppleSigner::transaction()])
            ->assertOk()
            ->assertJson(['granted' => ['pro']]);

        $entitlement = Entitlement::sole();

        expect($entitlement->in_grace_period)->toBeTrue()
            ->and($entitlement->in_billing_retry)->toBeTrue()
            ->and($entitlement->latest_transaction_id)->toBe('2000000000000004')
            ->and($entitlement->expires_at->getTimestamp())->toBe(intdiv($graceUntil, 1000));

        Http::assertSent(function (Request $request): bool {
            [$header, $claims] = array_map(
                fn (string $part): array => json_decode(base64_decode(strtr($part, '-_', '+/')), true),
                array_slice(explode('.', substr($request->header('Authorization')[0], 7)), 0, 2),
            );

            return $header['alg'] === 'ES256' && $header['kid'] === '2X9R4HXF34'
                && $claims['iss'] === '57246542-96fe-1a63-e053-0824d011072a'
                && $claims['aud'] === 'appstoreconnect-v1'
                && $claims['bid'] === 'com.example.app'
                && $claims['exp'] - $claims['iat'] <= 3600;
        });
    });
});

describe('Google Play', function (): void {
    it('verifies a subscription token with the Play Developer API', function (): void {
        GoogleApi::subscription('client-sub-token', Fixtures::play('subscription_active'));

        $this->actingAs($this->user)
            ->postJson('/mobile-entitlements/sync', ['store' => 'googlePlay', 'token' => 'client-sub-token', 'productId' => 'lifetime_unlock'])
            ->assertOk()
            ->assertJson(['granted' => ['pro'], 'entitlements' => [['product_id' => 'pro_monthly']]]);

        expect(Entitlement::sole())
            ->user_id->toBe($this->user->id)
            ->purchase_token_hash->toBe(hash('sha256', 'client-sub-token'));
    });

    it('verifies a one-time product using the client product id only as a lookup key', function (): void {
        GoogleApi::product('lifetime_unlock', 'client-otp-token', Fixtures::play('product_purchased'));

        $this->actingAs($this->user)
            ->postJson('/mobile-entitlements/sync', ['store' => 'googlePlay', 'token' => 'client-otp-token', 'productId' => 'lifetime_unlock'])
            ->assertOk()
            ->assertJson(['granted' => ['lifetime']]);

        expect(Entitlement::sole()->type)->toBe(ProductType::NonConsumable);
    });

    it('rejects a one-time token sent with a product id it was not bought for', function (): void {
        GoogleApi::product('lifetime_unlock', 'client-otp-token', Fixtures::play('product_purchased'));

        $this->actingAs($this->user)
            ->postJson('/mobile-entitlements/sync', ['store' => 'googlePlay', 'token' => 'client-otp-token', 'productId' => 'coins_100'])
            ->assertStatus(422);

        expect(Entitlement::count())->toBe(0);
    });

    it('rejects Google test purchases unless accept_test_purchases is on', function (): void {
        $sandbox = Fixtures::play('subscription_active');
        $sandbox['testPurchase'] = [];
        GoogleApi::subscription('tester-token', $sandbox);

        $this->actingAs($this->user)
            ->postJson('/mobile-entitlements/sync', ['store' => 'googlePlay', 'token' => 'tester-token'])
            ->assertStatus(422);
        expect(Entitlement::count())->toBe(0);

        config()->set('mobile-entitlements.google.accept_test_purchases', true);

        $this->actingAs($this->user)
            ->postJson('/mobile-entitlements/sync', ['store' => 'googlePlay', 'token' => 'tester-token'])
            ->assertOk()
            ->assertJson(['granted' => ['pro']]);
    });

    it('rejects unknown tokens', function (): void {
        $this->actingAs($this->user)
            ->postJson('/mobile-entitlements/sync', ['store' => 'googlePlay', 'token' => 'made-up-token'])
            ->assertStatus(422);
    });

    it('answers 503 when Google is unavailable', function (): void {
        GoogleApi::subscription('client-sub-token', [], 503);

        $this->actingAs($this->user)
            ->postJson('/mobile-entitlements/sync', ['store' => 'googlePlay', 'token' => 'client-sub-token'])
            ->assertStatus(503);
    });

    it('links a row created by an earlier RTDN', function (): void {
        GoogleApi::subscription('gp-sub-token-1.AO-J1OyTestSubscriptionToken', Fixtures::play('subscription_active'));
        $this->postJson('/mobile-entitlements/google', Fixtures::googlePush('SUBSCRIPTION_PURCHASED'), ['Authorization' => 'Bearer '.GoogleKeys::pushToken()])->assertOk();

        $this->actingAs($this->user)
            ->postJson('/mobile-entitlements/sync', ['store' => 'googlePlay', 'token' => 'gp-sub-token-1.AO-J1OyTestSubscriptionToken'])
            ->assertOk();

        expect(Entitlement::sole()->user_id)->toBe($this->user->id);
    });
});

it('requires authentication', function (): void {
    $this->postJson('/mobile-entitlements/sync', ['store' => 'appStore', 'token' => AppleSigner::transaction()])
        ->assertUnauthorized();

    expect(Entitlement::count())->toBe(0);
});

it('validates the request body', function (array $body): void {
    $this->actingAs($this->user)->postJson('/mobile-entitlements/sync', $body)->assertUnprocessable();
})->with([
    'missing store' => [['token' => 'x']],
    'unknown store' => [['store' => 'amazon', 'token' => 'x']],
    'missing token' => [['store' => 'appStore']],
    'appAccountToken not a uuid' => [['store' => 'appStore', 'token' => 'x', 'appAccountToken' => 'nope']],
]);

it('rate limits /sync per user', function (): void {
    config()->set('mobile-entitlements.sync_rate_limit', 3);
    $token = AppleSigner::transaction();

    foreach (range(1, 3) as $attempt) {
        $this->actingAs($this->user)->postJson('/mobile-entitlements/sync', ['store' => 'appStore', 'token' => $token])->assertOk();
    }

    $this->actingAs($this->user)->postJson('/mobile-entitlements/sync', ['store' => 'appStore', 'token' => $token])->assertTooManyRequests();
    $this->actingAs(User::newUser())->postJson('/mobile-entitlements/sync', ['store' => 'appStore', 'token' => 'other'])->assertStatus(422);
});

it('allows 60 requests per minute by default', function (): void {
    expect(config('mobile-entitlements.sync_rate_limit'))->toBe(60);
});

it('works with MobileEntitlements::fake()', function (): void {
    $fake = MobileEntitlements::fake([
        'fake-token' => VerifiedPurchase::fake(['store' => Store::GooglePlay, 'productId' => 'pro_yearly']),
    ]);

    $this->actingAs($this->user)
        ->postJson('/mobile-entitlements/sync', ['store' => 'googlePlay', 'token' => 'fake-token'])
        ->assertOk()
        ->assertJson(['granted' => ['pro']]);

    $this->actingAs($this->user)
        ->postJson('/mobile-entitlements/sync', ['store' => 'googlePlay', 'token' => 'unknown'])
        ->assertStatus(422);

    $fake->assertVerified('fake-token', Store::GooglePlay);
    expect($this->user->hasEntitlement('pro'))->toBeTrue();
    Http::assertNothingSent();
});
