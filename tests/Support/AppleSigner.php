<?php

namespace Vipertecpro\MobileEntitlements\Tests\Support;

use Firebase\JWT\JWT;

/**
 * Signs App Store style JWS values with the test-only chain in tests/fixtures/apple/certs.
 */
final class AppleSigner
{
    public static function certPath(string $name): string
    {
        return dirname(__DIR__).'/fixtures/apple/certs/'.$name;
    }

    public static function der(string $certificate): string
    {
        $pem = (string) file_get_contents(self::certPath($certificate.'.pem'));

        return preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem) ?? '';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{leaf?: string, intermediate?: string, root?: string, key?: string, x5c?: list<string>|null, alg?: string}  $options
     */
    public static function sign(array $payload, array $options = []): string
    {
        $leaf = $options['leaf'] ?? 'leaf';
        $intermediate = $options['intermediate'] ?? 'intermediate';
        $root = $options['root'] ?? 'root';
        $key = (string) file_get_contents(self::certPath(($options['key'] ?? 'leaf').'.key'));

        $header = [];

        if (! array_key_exists('x5c', $options)) {
            $header['x5c'] = [self::der($leaf), self::der($intermediate), self::der($root)];
        } elseif ($options['x5c'] !== null) {
            $header['x5c'] = $options['x5c'];
        }

        return JWT::encode($payload, $key, $options['alg'] ?? 'ES256', null, $header);
    }

    /**
     * A signed transaction (JWSTransactionDecodedPayload) with defaults for an active monthly subscription.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function transaction(array $overrides = [], array $options = []): string
    {
        return self::sign(self::transactionPayload($overrides), $options);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function transactionPayload(array $overrides = []): array
    {
        $now = (int) floor(microtime(true) * 1000);

        return array_merge([
            'transactionId' => '2000000000000001',
            'originalTransactionId' => '2000000000000001',
            'webOrderLineItemId' => '2000000000000101',
            'bundleId' => 'com.example.app',
            'productId' => 'com.example.pro.monthly',
            'subscriptionGroupIdentifier' => '21000001',
            'purchaseDate' => $now - 60_000,
            'originalPurchaseDate' => $now - 60_000,
            'expiresDate' => $now + 30 * 86_400_000,
            'quantity' => 1,
            'type' => 'Auto-Renewable Subscription',
            'inAppOwnershipType' => 'PURCHASED',
            'signedDate' => $now,
            'environment' => 'Production',
            'transactionReason' => 'PURCHASE',
            'storefront' => 'USA',
            'storefrontId' => '143441',
            'price' => 4990,
            'currency' => 'USD',
        ], $overrides);
    }
}
