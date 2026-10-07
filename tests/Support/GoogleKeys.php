<?php

namespace Vipertecpro\MobileEntitlements\Tests\Support;

use Firebase\JWT\JWT;
use Vipertecpro\MobileEntitlements\Tests\TestCase;

/**
 * Test-only RSA keys standing in for Google's OIDC signing key and a Play service account.
 */
final class GoogleKeys
{
    public const KID = 'test-oidc-kid';

    private static ?string $privateKey = null;

    private static ?string $otherPrivateKey = null;

    public static function privateKey(): string
    {
        return self::$privateKey ??= self::generate();
    }

    public static function otherPrivateKey(): string
    {
        return self::$otherPrivateKey ??= self::generate();
    }

    /**
     * @return array{keys: list<array<string, string>>}
     */
    public static function jwks(): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private(self::privateKey()));

        return ['keys' => [[
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => self::KID,
            'n' => JWT::urlsafeB64Encode($details['rsa']['n']),
            'e' => JWT::urlsafeB64Encode($details['rsa']['e']),
        ]]];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function pushToken(array $overrides = [], ?string $key = null, string $kid = self::KID): string
    {
        $now = time();

        $claims = array_filter(array_merge([
            'aud' => TestCase::PUSH_AUDIENCE,
            'azp' => '100000000000000000000',
            'email' => TestCase::PUSH_EMAIL,
            'email_verified' => true,
            'exp' => $now + 3600,
            'iat' => $now,
            'iss' => 'https://accounts.google.com',
            'sub' => '100000000000000000000',
        ], $overrides), fn (mixed $value): bool => $value !== null);

        return JWT::encode($claims, $key ?? self::privateKey(), 'RS256', $kid);
    }

    public static function serviceAccountJson(): string
    {
        return (string) json_encode([
            'type' => 'service_account',
            'project_id' => 'example-project',
            'private_key_id' => 'test-service-account-key',
            'private_key' => self::privateKey(),
            'client_email' => 'play-api@example-project.iam.gserviceaccount.com',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]);
    }

    private static function generate(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        return $pem;
    }
}
