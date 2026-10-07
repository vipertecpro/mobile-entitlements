<?php

use Firebase\JWT\JWT;
use Vipertecpro\MobileEntitlements\Apple\AppleJwsVerifier;
use Vipertecpro\MobileEntitlements\Exceptions\VerificationException;
use Vipertecpro\MobileEntitlements\Models\StoreTransaction;
use Vipertecpro\MobileEntitlements\Tests\Support\AppleSigner;
use Vipertecpro\MobileEntitlements\Tests\Support\Fixtures;

const APPLE_ROOT_CA_G3_SHA256 = '63343abfb89a6a03ebb57e9b3f5fa7be7c4f5c756f3017b3a8c488c3653e9179';

function appleVerifier(): AppleJwsVerifier
{
    return app(AppleJwsVerifier::class);
}

it('ships the genuine Apple Root CA - G3 certificate', function (): void {
    $path = dirname(__DIR__, 2).'/resources/certs/AppleRootCA-G3.pem';
    $pem = file_get_contents($path);
    $der = base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem));

    expect(hash('sha256', $der))->toBe(APPLE_ROOT_CA_G3_SHA256)
        ->and(openssl_x509_fingerprint($pem, 'sha256'))->toBe(APPLE_ROOT_CA_G3_SHA256)
        ->and(openssl_x509_parse($pem)['subject']['CN'])->toBe('Apple Root CA - G3');
});

it('trusts only the bundled Apple root by default', function (): void {
    $defaults = require dirname(__DIR__, 2).'/config/mobile-entitlements.php';

    expect($defaults['apple']['root_certificates'])->toHaveCount(1)
        ->and(realpath($defaults['apple']['root_certificates'][0]))->toBe(realpath(dirname(__DIR__, 2).'/resources/certs/AppleRootCA-G3.pem'));
});

it('rejects a test-signed payload when only the real Apple root is trusted', function (): void {
    config()->set('mobile-entitlements.apple.root_certificates', [dirname(__DIR__, 2).'/resources/certs/AppleRootCA-G3.pem']);

    appleVerifier()->verify(AppleSigner::transaction());
})->throws(VerificationException::class, 'trusted root');

it('accepts an ES256 payload whose x5c chains to the trusted root with the right OIDs', function (): void {
    $payload = appleVerifier()->verify(AppleSigner::transaction(['productId' => 'com.example.pro.yearly']));

    expect($payload['productId'])->toBe('com.example.pro.yearly')
        ->and($payload['bundleId'])->toBe('com.example.app');
});

it('rejects invalid signatures and chains', function (array $options, string $message): void {
    expect(fn () => appleVerifier()->verify(AppleSigner::transaction([], $options)))
        ->toThrow(VerificationException::class, $message);
})->with([
    'chain from an untrusted root' => [['leaf' => 'other-leaf', 'intermediate' => 'other-intermediate', 'root' => 'other-root', 'key' => 'other-leaf'], 'trusted root'],
    'leaf signed by an untrusted intermediate' => [['leaf' => 'other-leaf', 'key' => 'other-leaf'], 'not signed by the intermediate'],
    'leaf without the App Store OID' => [['leaf' => 'leaf-no-oid'], '1.2.840.113635.100.6.11.1'],
    'intermediate without the WWDR OID' => [['leaf' => 'leaf-under-no-oid-intermediate', 'intermediate' => 'intermediate-no-oid'], '1.2.840.113635.100.6.2.1'],
    'bad signature' => [['key' => 'other-leaf'], 'signature'],
    'expired leaf' => [['leaf' => 'leaf-expired'], 'not valid at this time'],
    'x5c missing' => [['x5c' => null], 'x5c'],
    'x5c with two certificates' => [['x5c' => [AppleSigner::der('leaf'), AppleSigner::der('intermediate')]], 'x5c'],
    'x5c with garbage' => [['x5c' => ['not-base64!', 'x', 'y']], 'x5c'],
    'root used as leaf' => [['leaf' => 'root', 'intermediate' => 'root', 'key' => 'root'], 'missing OID'],
]);

it('rejects algorithms other than ES256', function (): void {
    $token = JWT::encode(AppleSigner::transactionPayload(), str_repeat('s', 64), 'HS256', null, [
        'x5c' => [AppleSigner::der('leaf'), AppleSigner::der('intermediate'), AppleSigner::der('root')],
    ]);

    appleVerifier()->verify($token);
})->throws(VerificationException::class, 'ES256');

it('rejects an unsigned token', function (): void {
    $header = JWT::urlsafeB64Encode(json_encode(['alg' => 'none', 'x5c' => [AppleSigner::der('leaf'), AppleSigner::der('intermediate'), AppleSigner::der('root')]]));
    $body = JWT::urlsafeB64Encode(json_encode(AppleSigner::transactionPayload()));

    appleVerifier()->verify("{$header}.{$body}.");
})->throws(VerificationException::class);

it('rejects a payload that was altered after signing', function (): void {
    [$header, , $signature] = explode('.', AppleSigner::transaction());
    $tampered = JWT::urlsafeB64Encode(json_encode(AppleSigner::transactionPayload(['productId' => 'com.example.pro.yearly'])));

    appleVerifier()->verify("{$header}.{$tampered}.{$signature}");
})->throws(VerificationException::class, 'signature');

describe('POST /apple', function (): void {
    it('accepts a valid notification', function (): void {
        $this->postJson('/mobile-entitlements/apple', ['signedPayload' => Fixtures::appleNotification('SUBSCRIBED_INITIAL_BUY')])
            ->assertOk()
            ->assertJson(['received' => true, 'processed' => true]);
    });

    it('answers 401 and stores nothing for invalid notifications', function (array $signOptions, array $overrides, array $config = []): void {
        config()->set($config);

        $this->postJson('/mobile-entitlements/apple', [
            'signedPayload' => Fixtures::appleNotification('SUBSCRIBED_INITIAL_BUY', $signOptions, $overrides),
        ])->assertUnauthorized();

        expect(StoreTransaction::count())->toBe(0);
    })->with([
        'wrong chain' => [['leaf' => 'other-leaf', 'intermediate' => 'other-intermediate', 'root' => 'other-root', 'key' => 'other-leaf'], []],
        'wrong OID' => [['leaf' => 'leaf-no-oid'], []],
        'bad signature' => [['key' => 'other-leaf'], []],
        'expired leaf' => [['leaf' => 'leaf-expired'], []],
        'x5c missing' => [['x5c' => null], []],
        'wrong bundle id' => [[], ['data' => ['bundleId' => 'com.attacker.app']]],
        'wrong bundle id inside the transaction' => [[], ['data' => ['transactionInfo' => ['bundleId' => 'com.attacker.app']]]],
        'sandbox not accepted' => [[], ['data' => ['environment' => 'Sandbox', 'transactionInfo' => ['environment' => 'Sandbox']]]],
        'production sent to a sandbox-only server' => [[], [], ['mobile-entitlements.apple.environment' => 'sandbox']],
        'bundle id not configured' => [[], [], ['mobile-entitlements.apple.bundle_id' => null]],
    ]);

    it('accepts sandbox notifications when accept_sandbox is on', function (): void {
        config()->set('mobile-entitlements.apple.accept_sandbox', true);

        $this->postJson('/mobile-entitlements/apple', [
            'signedPayload' => Fixtures::appleNotification('SUBSCRIBED_INITIAL_BUY', [], ['data' => ['environment' => 'Sandbox', 'transactionInfo' => ['environment' => 'Sandbox']]]),
        ])->assertOk();

        expect(StoreTransaction::sole()->environment)->toBe('sandbox');
    });

    it('answers 401 when signedPayload is missing or not a JWS', function (mixed $body): void {
        $this->postJson('/mobile-entitlements/apple', $body)->assertUnauthorized();
    })->with([
        'missing' => [[]],
        'not a string' => [['signedPayload' => ['a']]],
        'garbage' => [['signedPayload' => 'a.b.c']],
    ]);
});
