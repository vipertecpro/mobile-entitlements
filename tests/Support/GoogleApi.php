<?php

namespace Vipertecpro\MobileEntitlements\Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fakes Google's OIDC certs, OAuth token endpoint and the Play Developer API.
 * Responses are keyed by "subscription:{token}" and "product:{productId}:{token}".
 */
final class GoogleApi
{
    /** @var array<string, array{0: array<string, mixed>, 1: int}> */
    public static array $responses = [];

    public static int $tokenRequests = 0;

    public static function fake(): void
    {
        self::$responses = [];
        self::$tokenRequests = 0;

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_starts_with($url, 'https://www.googleapis.com/oauth2/v3/certs')) {
                return Http::response(GoogleKeys::jwks(), 200, ['Cache-Control' => 'public, max-age=21600']);
            }

            if (str_starts_with($url, 'https://oauth2.googleapis.com/token')) {
                self::$tokenRequests++;

                return Http::response(['access_token' => 'ya29.test-access-token', 'expires_in' => 3599, 'token_type' => 'Bearer']);
            }

            if (preg_match('#/purchases/subscriptionsv2/tokens/([^/?]+)#', $url, $m) === 1) {
                return self::respond('subscription:'.rawurldecode($m[1]));
            }

            if (preg_match('#/purchases/products/([^/]+)/tokens/([^/?]+)#', $url, $m) === 1) {
                return self::respond('product:'.rawurldecode($m[1]).':'.rawurldecode($m[2]));
            }

            return null; // Not Google: let other Http::fake() stubs answer.
        });
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function subscription(string $token, array $body, int $status = 200): void
    {
        self::$responses['subscription:'.$token] = [$body, $status];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function product(string $productId, string $token, array $body, int $status = 200): void
    {
        self::$responses['product:'.$productId.':'.$token] = [$body, $status];
    }

    private static function respond(string $key): mixed
    {
        if (! isset(self::$responses[$key])) {
            return Http::response(['error' => ['code' => 404, 'message' => 'Not found']], 404);
        }

        [$body, $status] = self::$responses[$key];

        return Http::response($body, $status);
    }
}
