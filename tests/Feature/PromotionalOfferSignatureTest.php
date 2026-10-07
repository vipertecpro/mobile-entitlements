<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Vipertecpro\MobileEntitlements\Tests\Support\User;
use Vipertecpro\MobileEntitlements\Tests\TestCase;

/**
 * @return array{private: string, public: string}
 */
function promoKeyPair(): array
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($key, $private);

    return ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
}

/**
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function decodePromoJws(string $jws, string $publicKey): array
{
    [$header] = explode('.', $jws);

    return [
        json_decode(JWT::urlsafeB64Decode($header), true),
        (array) JWT::decode($jws, new Key($publicKey, 'ES256')),
    ];
}

beforeEach(function (): void {
    $this->keys = promoKeyPair();

    config()->set([
        'mobile-entitlements.apple.promo_offers' => true,
        'mobile-entitlements.apple.issuer_id' => '57246542-96fe-1a63-e053-0824d011072a',
        'mobile-entitlements.apple.promo_key_id' => 'PROMOKEY01',
        'mobile-entitlements.apple.promo_private_key' => $this->keys['private'],
    ]);

    $this->user = User::newUser();
});

it('signs a promotional offer as an ES256 compact JWS with Apple\'s header and claims', function (): void {
    $this->freezeSecond();

    $signature = $this->actingAs($this->user)
        ->postJson('/mobile-entitlements/promo-signature', [
            'productId' => 'com.example.pro.monthly',
            'offerId' => 'winback_50',
            'transactionId' => '2000000000000001',
        ])
        ->assertOk()
        ->json('signature');

    expect(explode('.', $signature))->toHaveCount(3);

    [$header, $claims] = decodePromoJws($signature, $this->keys['public']);

    expect($header)->toBe(['typ' => 'JWT', 'alg' => 'ES256', 'kid' => 'PROMOKEY01'])
        ->and($claims)->toMatchArray([
            'iss' => '57246542-96fe-1a63-e053-0824d011072a',
            'iat' => now()->getTimestamp(),
            'aud' => 'promotional-offer',
            'bid' => TestCase::BUNDLE_ID,
            'productId' => 'com.example.pro.monthly',
            'offerIdentifier' => 'winback_50',
            'transactionId' => '2000000000000001',
        ])
        ->and(Str::isUuid($claims['nonce']))->toBeTrue();
});

it('leaves out exp, because Apple derives the expiry from iat and rejects tokens with exp', function (): void {
    $signature = $this->actingAs($this->user)
        ->postJson('/mobile-entitlements/promo-signature', ['productId' => 'com.example.pro.monthly', 'offerId' => 'winback_50'])
        ->assertOk()
        ->json('signature');

    [, $claims] = decodePromoJws($signature, $this->keys['public']);

    expect($claims)->not->toHaveKey('exp')
        ->and($claims)->not->toHaveKey('transactionId')
        ->and($claims['iat'])->toBeGreaterThanOrEqual(time() - 5)->toBeLessThanOrEqual(time());
});

it('uses a fresh nonce for every signature', function (): void {
    $nonces = collect(range(1, 2))->map(function (): string {
        $signature = $this->actingAs($this->user)
            ->postJson('/mobile-entitlements/promo-signature', ['productId' => 'com.example.pro.monthly', 'offerId' => 'winback_50'])
            ->json('signature');

        return decodePromoJws($signature, $this->keys['public'])[1]['nonce'];
    });

    expect($nonces->unique())->toHaveCount(2);
});

it('answers 403 when promotional offers are disabled', function (): void {
    config()->set('mobile-entitlements.apple.promo_offers', false);

    $this->actingAs($this->user)
        ->postJson('/mobile-entitlements/promo-signature', ['productId' => 'com.example.pro.monthly', 'offerId' => 'winback_50'])
        ->assertForbidden()
        ->assertJsonMissingPath('signature');
});

it('is disabled by default', function (): void {
    expect(require dirname(__DIR__, 2).'/config/mobile-entitlements.php')
        ->apple->promo_offers->toBeFalse();
});

it('falls back to the App Store Server API key when no promo key is set', function (): void {
    config()->set([
        'mobile-entitlements.apple.promo_key_id' => null,
        'mobile-entitlements.apple.promo_private_key' => null,
        'mobile-entitlements.apple.key_id' => 'SERVERKEY1',
        'mobile-entitlements.apple.private_key' => $this->keys['private'],
    ]);

    $signature = $this->actingAs($this->user)
        ->postJson('/mobile-entitlements/promo-signature', ['productId' => 'com.example.pro.monthly', 'offerId' => 'winback_50'])
        ->assertOk()
        ->json('signature');

    expect(decodePromoJws($signature, $this->keys['public'])[0]['kid'])->toBe('SERVERKEY1');
});

it('answers 503 when no signing key is configured', function (): void {
    config()->set('mobile-entitlements.apple.promo_private_key', null);

    $this->actingAs($this->user)
        ->postJson('/mobile-entitlements/promo-signature', ['productId' => 'com.example.pro.monthly', 'offerId' => 'winback_50'])
        ->assertStatus(503);
});

it('lets the app decide eligibility through the redeemMobileEntitlementsPromoOffer Gate', function (): void {
    Gate::define('redeemMobileEntitlementsPromoOffer', fn (User $user, string $productId, string $offerId): bool => $offerId === 'winback_50');

    $this->actingAs($this->user)
        ->postJson('/mobile-entitlements/promo-signature', ['productId' => 'com.example.pro.monthly', 'offerId' => 'winback_50'])
        ->assertOk();

    $this->actingAs($this->user)
        ->postJson('/mobile-entitlements/promo-signature', ['productId' => 'com.example.pro.monthly', 'offerId' => 'staff_90'])
        ->assertForbidden();
});

it('validates the request', function (): void {
    $this->actingAs($this->user)
        ->postJson('/mobile-entitlements/promo-signature', ['productId' => 'com.example.pro.monthly'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('offerId');
});

it('requires authentication', function (): void {
    $this->postJson('/mobile-entitlements/promo-signature', ['productId' => 'com.example.pro.monthly', 'offerId' => 'winback_50'])
        ->assertUnauthorized();
});
