# Mobile Entitlements for Laravel

Receive and verify App Store Server Notifications V2 and Google Play Real-time Developer
Notifications, and keep a per-user entitlement table your Laravel backend can trust. Works with any
mobile client: Flutter, React Native, Swift, Kotlin or NativePHP.

```php
if ($request->user()->hasEntitlement('pro')) {
    // ...
}
```

## Why

Your app knows what the user bought, but your API should not take the app's word for it. This
package lets the stores tell your server directly and checks every message:

- Apple notifications are JWS documents. The package checks the `x5c` certificate chain against the
  bundled Apple Root CA - G3, the Apple OIDs on the leaf and intermediate certificates, the ES256
  signature, your bundle id and the environment.
- Google notifications arrive through Pub/Sub push. The package checks Google's OIDC token
  (issuer, audience, service account), then reads the real state from the Play Developer API.
  The notification itself is only a hint.
- Your app links a purchase to a user through an authenticated `/sync` endpoint. The server
  verifies the token with the store and uses the store's product id, never the client's.
- Every notification is stored once (idempotent on Apple `notificationUUID` and Pub/Sub
  `messageId`), and every change fires an event.

## Install

```sh
composer require vipertecpro/mobile-entitlements
php artisan vendor:publish --tag=mobile-entitlements-config
php artisan vendor:publish --tag=mobile-entitlements-migrations
php artisan migrate
```

Requires PHP 8.3+ and Laravel 12 or 13.

**UUID or ULID user ids.** The `entitlements.user_id` column is an unsigned big integer, matching
Laravel's default `users.id`. If your users use UUIDs or ULIDs, edit the published migration before
running it and change that column to `$table->uuid('user_id')->nullable()->index();` or
`$table->ulid('user_id')->nullable()->index();`.

Add the trait to your user model:

```php
use Vipertecpro\MobileEntitlements\Concerns\HasEntitlements;

class User extends Authenticatable
{
    use HasEntitlements;
}
```

Map entitlement keys to store product ids in `config/mobile-entitlements.php`:

```php
'entitlements' => [
    'pro' => ['com.example.pro.monthly', 'com.example.pro.yearly', 'pro_monthly', 'pro_yearly'],
    'lifetime' => ['com.example.lifetime', 'lifetime_unlock'],
],

// Google Play does not say whether a one-time product is a consumable, so list them here.
'consumables' => ['coins_100'],
```

### Routes

The package registers three routes under the `route_prefix` (default `mobile-entitlements`):

| Method | URI | Middleware | Purpose |
| --- | --- | --- | --- |
| POST | `/mobile-entitlements/apple` | `middleware.webhooks` (default `api`) | App Store Server Notifications V2 |
| POST | `/mobile-entitlements/google` | `middleware.webhooks` (default `api`) | Pub/Sub push for Play RTDN |
| POST | `/mobile-entitlements/sync` | `middleware.sync` (default `api`, `auth:sanctum`) + 60 requests/minute per user | Link a purchase to the signed-in user |

The webhook routes are limited to `webhook_rate_limit` requests per minute per IP (default 120).
They use the `api` group, so they are not subject to CSRF checks. If you move them
into the `web` group, exclude them from CSRF verification. To register the routes yourself, set
`register_routes` to `false` and load `routes/mobile-entitlements.php` from the package.

`/sync` uses Laravel Sanctum by default. If you use another guard, change `middleware.sync`, for
example `['api', 'auth:api']`.

## Apple setup

1. In [App Store Connect](https://appstoreconnect.apple.com), open your app, then
   **App Information → App Store Server Notifications**.
2. Set the **Production Server URL** to `https://your-app.com/mobile-entitlements/apple` and choose
   **Version 2**.
3. Set the **Sandbox Server URL** to the same URL on a staging server (or the same server with
   `accept_sandbox` on while you test).
4. Configure:

   ```dotenv
   MOBILE_ENTITLEMENTS_APPLE_BUNDLE_ID=com.example.app
   MOBILE_ENTITLEMENTS_APPLE_ENVIRONMENT=production
   MOBILE_ENTITLEMENTS_APPLE_ACCEPT_SANDBOX=false
   ```

5. Optional, recommended: create an **In-App Purchase** key under
   **Users and Access → Integrations** to use the
   [App Store Server API](https://developer.apple.com/documentation/appstoreserverapi). With it,
   `/sync` and the reconcile command ask Apple for the current subscription status (grace period,
   billing retry, renewal) instead of relying on the transaction the app sent.

   ```dotenv
   MOBILE_ENTITLEMENTS_APPLE_VERIFY_WITH_SERVER_API=true
   MOBILE_ENTITLEMENTS_APPLE_ISSUER_ID=57246542-96fe-1a63-e053-0824d011072a
   MOBILE_ENTITLEMENTS_APPLE_KEY_ID=2X9R4HXF34
   MOBILE_ENTITLEMENTS_APPLE_PRIVATE_KEY=/path/to/SubscriptionKey_2X9R4HXF34.p8
   ```

   You can test delivery with Apple's
   [Request a Test Notification](https://developer.apple.com/documentation/appstoreserverapi/request-a-test-notification)
   endpoint; it arrives as a `TEST` notification.

## Google setup

1. In [Google Cloud](https://console.cloud.google.com), create a **service account** and a JSON key.
   In [Play Console](https://play.google.com/console), invite the service account under
   **Users and permissions** with permission to view financial data and manage orders and
   subscriptions.
2. Create a **Pub/Sub topic**, for example `play-rtdn`, and grant
   `google-play-developer-notifications@system.gserviceaccount.com` the **Pub/Sub Publisher** role
   on it.
3. Create a **push subscription** on the topic:
   - Endpoint URL: `https://your-app.com/mobile-entitlements/google`
   - **Enable authentication**, choose a service account for the push, and set the audience
     (use the endpoint URL).
4. In Play Console, open **Monetize with Play → Monetization setup**, enter the topic name
   (`projects/your-project/topics/play-rtdn`) under **Real-time developer notifications** and send a
   test notification.
5. Configure:

   ```dotenv
   MOBILE_ENTITLEMENTS_GOOGLE_PACKAGE_NAME=com.example.app
   MOBILE_ENTITLEMENTS_GOOGLE_SERVICE_ACCOUNT_JSON=/path/to/service-account.json
   MOBILE_ENTITLEMENTS_GOOGLE_PUSH_AUDIENCE=https://your-app.com/mobile-entitlements/google
   MOBILE_ENTITLEMENTS_GOOGLE_PUSH_SERVICE_ACCOUNT_EMAIL=pubsub-push@your-project.iam.gserviceaccount.com
   ```

Purchases by licence testers (Google reports them as test purchases) are recorded in
`store_transactions` with a note but grant nothing, and `/sync` rejects them, unless
`google.accept_test_purchases` (`MOBILE_ENTITLEMENTS_GOOGLE_ACCEPT_TEST_PURCHASES`) is `true`. Turn
it on only on servers used for testing.

If the Play Developer API is down, the endpoint answers `503` so Pub/Sub retries. Tokens Google no
longer knows are recorded and acknowledged so they are not retried forever.

## Linking purchases to users

A notification can arrive before your server knows which user made the purchase. Those rows are
stored with `user_id` null and attached later:

- **On `/sync`.** After a purchase or restore, the app posts the store token to `/sync` with the
  user's API token. The server verifies it with the store, attaches the entitlement to the user, and
  attaches any earlier anonymous rows of the same subscription.
- **By account token (optional).** If the app passes the user's UUID as Apple `appAccountToken` or
  Google `obfuscatedAccountId`, set `app_account_token_column` to the users column that holds it.
  Webhook rows are then attached immediately, and `/sync` refuses purchases stamped for another
  user.

A purchase that is already linked to another user is refused with `409`.

### With the NativePHP plugin

[Subscriptions for NativePHP](https://vipertecpro.com/products/subscriptions) does this in one call:
`Subscriptions::syncWithServer()` posts the latest purchase to `/sync` and caches the granted keys.
Point it at this package:

```dotenv
SUBSCRIPTIONS_SERVER_URL=https://your-app.com/mobile-entitlements/sync
```

### From any other client

Send the StoreKit 2 `jwsRepresentation` (Apple) or the purchase token (Google):

```sh
curl -X POST https://your-app.com/mobile-entitlements/sync \
  -H "Authorization: Bearer $USER_API_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"store": "appStore", "token": "eyJhbGciOiJFUzI1NiIsIng1YyI6WyJNSUlF..."}'
```

```json
{
  "granted": ["pro"],
  "entitlements": [
    {
      "id": 1,
      "store": "appStore",
      "product_id": "com.example.pro.monthly",
      "type": "subscription",
      "keys": ["pro"],
      "is_active": true,
      "expires_at": "2026-11-09T10:00:00+00:00",
      "will_renew": true,
      "in_grace_period": false,
      "is_trial": false,
      "environment": "production"
    }
  ]
}
```

Body fields: `store` (`appStore` or `googlePlay`), `token`, and optionally `productId`,
`appAccountToken`, `platform`. For Google one-time products, include `productId`: it is only used
to look the purchase up, and Google rejects a token sent with the wrong product. Responses:
`422` when the store does not confirm the purchase, `409` when it belongs to another user, `503`
when the store is unreachable, `429` above the rate limit.

## Checking access

```php
$user->hasEntitlement('pro');          // bool
$user->entitlement('pro');             // ?Entitlement, the active one with the latest expiry
$user->entitlements;                   // every row
$user->activeEntitlements()->get();    // rows that grant access right now

Route::middleware(['auth:sanctum', 'entitled:pro'])->group(function () {
    // 402 JSON when the user lacks the entitlement
});

Route::middleware('entitled:pro,lifetime')->get(...); // any of several keys
```

Set `middleware.redirect_to` to send browser requests to a pricing page instead of a `402`.

An entitlement grants access while it is active, not revoked, and either has no expiry
(non-consumables), has not expired, or is in a billing grace period. During Apple's grace period,
`expires_at` is the end of the grace period.

The facade offers the same checks plus verification:

```php
use Vipertecpro\MobileEntitlements\Enums\Store;
use Vipertecpro\MobileEntitlements\Facades\MobileEntitlements;

MobileEntitlements::verify(Store::AppStore, $jws);            // VerifiedPurchase, nothing stored
MobileEntitlements::grantFromToken($user, Store::GooglePlay, $token);
MobileEntitlements::reconcile($user);
```

## Events

| Event | When |
| --- | --- |
| `EntitlementGranted($entitlement)` | A row starts granting access (new purchase, resubscribe, recovery). |
| `EntitlementChanged($entitlement, $cause)` | Anything else changed (renewal, auto-renew toggled, linked to a user, superseded by an upgrade). |
| `EntitlementRevoked($entitlement, $cause)` | A row stops granting access (expiry, refund, revoke, account hold, pause). |
| `StoreNotificationReceived($store, $type, $payload)` | Every verified notification before it is applied, for logging. |

`$cause` is the lower-case notification type (`did_renew`, `refund`, `subscription_on_hold`, ...),
or `sync`, `reconcile`, `linked`, `superseded`, `voided_purchase`. Events are dispatched after the
database transaction commits.

## Reconciling

Webhooks get lost. Schedule the reconcile command to re-ask the stores about rows that have expired
while still marked active, or that have not been refreshed recently:

```php
// routes/console.php
Schedule::command('mobile-entitlements:reconcile')->hourly();
```

```sh
php artisan mobile-entitlements:reconcile --stale=24h
php artisan mobile-entitlements:reconcile --user=42
```

Google rows are refreshed from the Play Developer API. Apple rows are refreshed from the App Store
Server API when `verify_with_server_api` is on; otherwise their stored expiry is applied.

## Testing with fakes

`MobileEntitlements::fake()` replaces store verification with purchases you define. Everything
else (rows, linking, events, the `/sync` endpoint) runs for real:

```php
use Vipertecpro\MobileEntitlements\Enums\Store;
use Vipertecpro\MobileEntitlements\Facades\MobileEntitlements;
use Vipertecpro\MobileEntitlements\Support\VerifiedPurchase;

$fake = MobileEntitlements::fake([
    'test-token' => VerifiedPurchase::fake([
        'store' => Store::GooglePlay,
        'productId' => 'pro_monthly',
    ]),
]);

$this->actingAs($user)
    ->postJson('/mobile-entitlements/sync', ['store' => 'googlePlay', 'token' => 'test-token'])
    ->assertOk()
    ->assertJson(['granted' => ['pro']]);

$fake->assertVerified('test-token');
```

Unknown tokens fail verification, like a real store would.

## Security notes

- **Never trust client product ids.** `/sync` stores what the store reports. A client cannot
  upgrade itself by sending a different `productId`.
- **Keep the root certificate.** The package ships Apple Root CA - G3 and checks its fingerprint in
  its own test suite. `apple.root_certificates` exists for tests; do not add other roots in
  production.
- **Sandbox.** Keep `accept_sandbox` off in production. TestFlight and App Review purchases use the
  sandbox, so enable it on the server those builds talk to.
- **Rotate keys.** Rotate the App Store Connect key and the Google service-account key from time to
  time, and keep them out of version control. Purchase tokens are stored encrypted with your
  `APP_KEY`; keep old keys in `APP_PREVIOUS_KEYS` when rotating it.
- **Fail closed.** Without `apple.bundle_id`, `google.package_name` or `google.push_audience`
  configured, the matching webhook rejects every request.

## Licence

MIT. See [LICENSE](LICENSE).

Apple, App Store and StoreKit are trademarks of Apple Inc. Google Play and Google Cloud Pub/Sub are
trademarks of Google LLC. NativePHP is a trademark of its respective owner. This package is an
independent project and is not affiliated with, endorsed or sponsored by Apple, Google or NativePHP.

The companion NativePHP plugin, Subscriptions for NativePHP, is sold on
[vipertecpro.com](https://vipertecpro.com).
