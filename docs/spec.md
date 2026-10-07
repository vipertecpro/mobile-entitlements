# vipertecpro/mobile-entitlements: frozen spec (v1.0.0)

Free, MIT, public on Packagist. A Laravel package that receives Apple App Store Server Notifications V2 and Google Play Real-Time Developer Notifications, verifies them, and maintains a per-user entitlement table. Companion to the paid plugin `vipertecpro/subscriptions` (see `~/Herd/subscriptions/docs/spec.md`, section "Server sync"), but useful to any Laravel backend with a mobile client (Flutter, React Native, Swift, Kotlin). Laravel ^12|^13, PHP ^8.3. No dependency on NativePHP.

Conventions: follow `~/Herd/vipertecpro.com/AGENTS.md` PHP rules (curly braces, return types, constructor promotion, PHPDoc array shapes), Pest tests, Pint. Package skeleton: `src/`, `config/mobile-entitlements.php`, `database/migrations/`, `routes/mobile-entitlements.php`, `tests/`, `README.md` (links allowed here, it is a public package), `CHANGELOG.md`, `LICENSE` (MIT, present).

## 1. Public API

```php
use Vipertecpro\MobileEntitlements\Concerns\HasEntitlements;   // on the User model
$user->hasEntitlement('pro');                 // bool, via config mapping entitlement => product ids
$user->entitlement('pro');                    // ?Entitlement model (the active one with the latest expiry)
$user->entitlements();                        // HasMany Entitlement
$user->activeEntitlements();                  // query scope: active now
Route::middleware('entitled:pro')->group(...); // 402 JSON or configurable redirect when missing

Vipertecpro\MobileEntitlements\Facades\MobileEntitlements::verify(Store $store, string $token, ?string $productId = null): VerifiedPurchase;
MobileEntitlements::grantFromToken(User|Authenticatable $user, Store $store, string $token, ?string $productId = null): Collection<Entitlement>;
MobileEntitlements::reconcile(User $user): Collection<Entitlement>;   // re-query the store for every known transaction
```

Events: `EntitlementGranted(Entitlement)`, `EntitlementChanged(Entitlement, string $cause)`, `EntitlementRevoked(Entitlement, string $cause)`, `StoreNotificationReceived(Store $store, string $type, array $payload)` for app-level logging.

Enums: `Store {AppStore = 'appStore', GooglePlay = 'googlePlay'}`, `ProductType {Subscription, NonConsumable, Consumable}`.

## 2. Database

Migration `create_mobile_entitlements_tables`:

- `store_transactions`: id, store (string 16), notification_id (string, unique with store; Apple `notificationUUID`, Google Pub/Sub `messageId`), type (string: notification type), subtype (nullable), original_transaction_id (string, index), transaction_id (nullable, index), purchase_token (nullable text, hashed index column `purchase_token_hash` sha256), product_id, environment (sandbox|production), payload (json), signed_payload (longText nullable), received_at, processed_at (nullable), error (nullable text). Idempotency key = (store, notification_id).
- `entitlements`: id, user_id (nullable foreign, index; nullable because a notification may arrive before the app has linked the purchase to a user), store, product_id, type, original_transaction_id (index), latest_transaction_id, purchase_token_hash (nullable, index), app_account_token (nullable uuid, index; Apple `appAccountToken` / Google `obfuscatedExternalAccountId`), is_active (bool), purchased_at, expires_at (nullable), will_renew (bool), in_grace_period (bool), in_billing_retry (bool), is_trial (bool), revoked_at (nullable), revocation_reason (nullable), environment, quantity (int default 1), last_notification_type (nullable), raw (json nullable), timestamps. Unique (store, original_transaction_id, product_id).

Linking a user: when the app posts to `/sync` (authenticated), the entitlement is created or attached to `auth()->user()`. When a webhook arrives first, the row is stored with `user_id` null and later attached by `original_transaction_id` on the next `/sync`, or immediately when `app_account_token` matches `users.<config column>` (configurable, default off).

## 3. HTTP endpoints (`routes/mobile-entitlements.php`, prefix configurable, default `mobile-entitlements`)

- `POST /apple` — body `{signedPayload}`. Verify the JWS: decode header `x5c` chain, validate the chain to Apple Root CA G3 (ship the root certificate PEM in `resources/certs/AppleRootCA-G3.pem`, with the SHA-256 fingerprint checked in a test), check the leaf's OID `1.2.840.113635.100.6.11.1` (App Store Server Notifications), verify the ES256 signature, check `bundleId` equals config, environment matches config (`sandbox` accepted when `config('mobile-entitlements.apple.accept_sandbox')` is true), then decode `data.signedTransactionInfo` and `data.signedRenewalInfo` the same way. Reject with 401 on any failure; 200 on success even if the notification type is unknown (store it, mark unprocessed). Idempotent on `notificationUUID`.
- `POST /google` — Pub/Sub push body `{message: {data (base64 JSON), messageId, publishTime}, subscription}`. Verify the Pub/Sub push JWT in `Authorization: Bearer` against Google's OIDC certs (`https://www.googleapis.com/oauth2/v3/certs`, cached), audience = `config('mobile-entitlements.google.push_audience')`, email = configured service account (optional). Decode `data` → `{version, packageName, eventTimeMillis, subscriptionNotification|oneTimeProductNotification|voidedPurchaseNotification|testNotification}`. Check `packageName`. Then fetch truth from the Play Developer API: `purchases.subscriptionsv2.get(packageName, token)` or `purchases.products.get(packageName, productId, token)` using a service-account JWT (RS256) → access token (cache it). Idempotent on `messageId`.
- `POST /sync` — `auth:sanctum` by default (configurable guard). Body `{store, token, productId?, appAccountToken?, platform?}`. Apple: verify the client-provided signed transaction JWS exactly like a notification (same chain rules) and read `transactionId`, `originalTransactionId`, `productId`, `expiresDate`, `type`, `revocationDate`; if `config('mobile-entitlements.apple.verify_with_server_api')` is true also call App Store Server API `GET /inApps/v1/transactions/{transactionId}` with an ES256 JWT (issuer id, key id, private key, bundle id, `aud: appstoreconnect-v1`) and `GET /inApps/v1/subscriptions/{transactionId}` for status, grace and billing retry. Google: Play Developer API as above. Never trust `productId` from the client; use the store's. Response `{granted: string[] (entitlement keys), entitlements: [...]}`.

Rate-limit `/sync` (60/min per user). All endpoints return JSON. Webhook routes are excluded from CSRF.

## 4. Processing rules

Apple notification types to handle (fixtures required for each): `SUBSCRIBED (INITIAL_BUY, RESUBSCRIBE)`, `DID_RENEW`, `DID_CHANGE_RENEWAL_STATUS (AUTO_RENEW_ENABLED/DISABLED)`, `DID_CHANGE_RENEWAL_PREF (UPGRADE/DOWNGRADE)`, `DID_FAIL_TO_RENEW (GRACE_PERIOD)`, `GRACE_PERIOD_EXPIRED`, `EXPIRED (VOLUNTARY, BILLING_RETRY, PRICE_INCREASE, PRODUCT_NOT_FOR_SALE)`, `REFUND`, `REVOKE`, `OFFER_REDEEMED`, `ONE_TIME_CHARGE`, `TEST`. Google RTDN subscription types `1 RECOVERED, 2 RENEWED, 3 CANCELED, 4 PURCHASED, 5 ON_HOLD, 6 IN_GRACE_PERIOD, 7 RESTARTED, 10 PAUSED, 12 REVOKED, 13 EXPIRED` and one-time `ONE_TIME_PRODUCT_PURCHASED (1) / CANCELED (2)`, plus voided purchases.

Mapping to the row: `is_active` = now < expires_at (or non-consumable not revoked) and not revoked; `in_grace_period` from GRACE_PERIOD subtype / Google state `IN_GRACE_PERIOD`; `in_billing_retry` from `EXPIRED/BILLING_RETRY` or Google `ON_HOLD`; `will_renew` from `autoRenewStatus` / `autoRenewEnabled`; consumables create a row per transaction with `quantity`. Fire the events. Everything inside a DB transaction with a row lock on the entitlement.

`php artisan mobile-entitlements:reconcile {--user=} {--stale=24h}` re-queries the store for entitlements whose `expires_at` passed or that have not been seen recently, and fixes drift (webhooks get lost). Schedulable.

## 5. Config (`config/mobile-entitlements.php`)

`entitlements` (map key => product ids), `user_model`, `route_prefix`, `middleware.sync` (default `['api','auth:sanctum']`), `apple` (`bundle_id`, `environment` production|sandbox, `accept_sandbox` bool, `issuer_id`, `key_id`, `private_key` (path or PEM via env), `verify_with_server_api` bool default false), `google` (`package_name`, `service_account_json` path, `push_audience`, `push_service_account_email` nullable), `reconcile.stale`, `app_account_token_column` nullable.

## 6. Tests (Pest, Orchestra Testbench)

- `AppleSignatureTest`: generate a test CA + intermediate + leaf with the OID in PHP OpenSSL during the test (or ship PEM fixtures under `tests/fixtures/apple/`), sign payloads with ES256, assert acceptance; assert rejection for wrong chain, wrong OID, bad signature, wrong bundle id, wrong environment, expired leaf, `x5c` missing. The production root is Apple's real G3 PEM; tests swap the trusted root via config `apple.root_certificates` (list of PEM paths).
- `AppleNotificationsTest`: one fixture per notification type above → expected row state and events; idempotency (same UUID twice → one row, one event).
- `GoogleRtdnTest`: Pub/Sub JWT verified with a test JWKS served via `Http::fake()`; one fixture per type; Play Developer API responses faked; idempotency on `messageId`.
- `SyncEndpointTest`: client JWS verified; product id taken from the store; attaches to the user; links earlier anonymous rows; 401 when unauthenticated; rate limit.
- `HasEntitlementsTest`, `MiddlewareTest` (402 JSON), `ReconcileCommandTest`.
- `composer test` green. Pint clean.

## 7. README (public, links allowed)

Title "Mobile Entitlements for Laravel". Sections: Why, Install (`composer require vipertecpro/mobile-entitlements`, publish config + migrations, route registration), Apple setup (App Store Connect → App Store Server Notifications V2 URL, sandbox URL, server API key), Google setup (service account, Pub/Sub topic + push subscription with OIDC token, Play Console RTDN topic), Linking purchases to users (`/sync` and the NativePHP plugin "Subscriptions for NativePHP" on vipertecpro.com as the client that does this in one call; also show a plain curl for other clients), Checking access, Events, Reconciling, Testing with fakes (`MobileEntitlements::fake()`), Security notes (never trust client product ids, rotate keys, sandbox), Licence MIT. Independence notice (Apple, Google, NativePHP trademarks; not affiliated). Mention that the paid plugin is sold on vipertecpro.com; no other marketing.

## 8. Report format

Under 300 words: files, `composer test` tail, open questions, deviations.
