<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Entitlements
    |--------------------------------------------------------------------------
    |
    | Map each entitlement key your app checks (hasEntitlement('pro'),
    | entitled:pro) to the store product ids that unlock it. Product ids
    | from both stores may be listed under the same key.
    |
    */

    'entitlements' => [
        // 'pro' => ['com.example.pro.monthly', 'com.example.pro.yearly', 'pro_monthly'],
    ],

    /*
    | Product ids that are consumables. Apple reports the type itself;
    | Google Play does not, so list Google consumable product ids here.
    */

    'consumables' => [],

    'user_model' => env('MOBILE_ENTITLEMENTS_USER_MODEL', 'App\\Models\\User'),

    /*
    | Column on the users table that holds the UUID you pass to the store as
    | Apple appAccountToken / Google obfuscatedExternalAccountId. When set,
    | webhook rows are attached to the matching user immediately. Null = off.
    */

    'app_account_token_column' => env('MOBILE_ENTITLEMENTS_APP_ACCOUNT_TOKEN_COLUMN'),

    'register_routes' => true,

    'route_prefix' => env('MOBILE_ENTITLEMENTS_ROUTE_PREFIX', 'mobile-entitlements'),

    'middleware' => [
        'webhooks' => ['api'],
        'sync' => ['api', 'auth:sanctum'],

        /*
        | GET /summary. Define the viewMobileEntitlementsSummary Gate in your app;
        | without it every request is denied.
        */
        'summary' => ['api', 'auth:sanctum', 'can:viewMobileEntitlementsSummary'],

        /*
        | Where the `entitled` middleware sends browser (non-JSON) requests
        | when the entitlement is missing. Null returns 402 JSON always.
        */
        'redirect_to' => null,
    ],

    /*
    | Requests per minute per user on POST /sync.
    */

    'sync_rate_limit' => 60,

    /*
    | Requests per minute per IP on the /apple and /google webhooks.
    */

    'webhook_rate_limit' => 120,

    'apple' => [
        'bundle_id' => env('MOBILE_ENTITLEMENTS_APPLE_BUNDLE_ID'),

        // production | sandbox
        'environment' => env('MOBILE_ENTITLEMENTS_APPLE_ENVIRONMENT', 'production'),

        'accept_sandbox' => (bool) env('MOBILE_ENTITLEMENTS_APPLE_ACCEPT_SANDBOX', false),

        // App Store Server API key (App Store Connect > Users and Access > Integrations > In-App Purchase).
        'issuer_id' => env('MOBILE_ENTITLEMENTS_APPLE_ISSUER_ID'),
        'key_id' => env('MOBILE_ENTITLEMENTS_APPLE_KEY_ID'),
        'private_key' => env('MOBILE_ENTITLEMENTS_APPLE_PRIVATE_KEY'), // path to .p8 or the PEM itself

        'verify_with_server_api' => (bool) env('MOBILE_ENTITLEMENTS_APPLE_VERIFY_WITH_SERVER_API', false),

        /*
        | POST /promo-signature: StoreKit 2 promotional offer signatures (JWS). Off by default.
        | Apple expects an In-App Purchase key (App Store Connect > Users and Access >
        | Integrations > In-App Purchase). When these are null, key_id and private_key above
        | are used. The issuer id is always issuer_id above.
        */
        'promo_offers' => (bool) env('MOBILE_ENTITLEMENTS_APPLE_PROMO_OFFERS', false),
        'promo_key_id' => env('MOBILE_ENTITLEMENTS_APPLE_PROMO_KEY_ID'),
        'promo_private_key' => env('MOBILE_ENTITLEMENTS_APPLE_PROMO_PRIVATE_KEY'), // path to .p8 or the PEM itself

        /*
        | Trusted root certificates (PEM paths) for the x5c chain. Defaults to
        | the bundled Apple Root CA - G3. Only override in tests.
        */
        'root_certificates' => [
            dirname(__DIR__).'/resources/certs/AppleRootCA-G3.pem',
        ],
    ],

    'google' => [
        'package_name' => env('MOBILE_ENTITLEMENTS_GOOGLE_PACKAGE_NAME'),

        // Path to the service account JSON key with access to the Play Developer API.
        'service_account_json' => env('MOBILE_ENTITLEMENTS_GOOGLE_SERVICE_ACCOUNT_JSON'),

        // Audience configured on the Pub/Sub push subscription (usually the push URL).
        'push_audience' => env('MOBILE_ENTITLEMENTS_GOOGLE_PUSH_AUDIENCE'),

        // Service account the push subscription authenticates as. Null skips the check.
        'push_service_account_email' => env('MOBILE_ENTITLEMENTS_GOOGLE_PUSH_SERVICE_ACCOUNT_EMAIL'),

        // Licence-tester purchases (testPurchase / purchaseType 0) grant nothing unless this is true.
        // They are still recorded in store_transactions with a note.
        'accept_test_purchases' => (bool) env('MOBILE_ENTITLEMENTS_GOOGLE_ACCEPT_TEST_PURCHASES', false),
    ],

    'reconcile' => [
        'stale' => '24h',
    ],

    /*
    |--------------------------------------------------------------------------
    | Prices (optional)
    |--------------------------------------------------------------------------
    |
    | Monthly amount per subscription product id, all in one currency, used only
    | for estimated_mrr in mobile-entitlements:report and GET /summary. For a
    | yearly product enter the yearly price divided by 12. Leave empty and the
    | report shows counts only.
    |
    */

    'prices' => [
        // 'com.example.pro.monthly' => 9.99,
        // 'com.example.pro.yearly' => 99.99 / 12,
    ],

];
