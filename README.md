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

The package registers these routes under the `route_prefix` (default `mobile-entitlements`):

| Method | URI | Middleware | Purpose |
| --- | --- | --- | --- |
| POST | `/mobile-entitlements/apple` | `middleware.webhooks` (default `api`) | App Store Server Notifications V2 |
| POST | `/mobile-entitlements/google` | `middleware.webhooks` (default `api`) | Pub/Sub push for Play RTDN |
| POST | `/mobile-entitlements/sync` | `middleware.sync` (default `api`, `auth:sanctum`) + 60 requests/minute per user | Link a purchase to the signed-in user |
| POST | `/mobile-entitlements/promo-signature` | same as `/sync` | Sign an App Store promotional offer (off by default) |
| GET | `/mobile-entitlements/summary` | `middleware.summary` (default `api`, `auth:sanctum`, `can:viewMobileEntitlementsSummary`) | Subscriber and sales counts as JSON |

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

[Paywalls & Purchases for NativePHP](https://vipertecpro.com/products/purchases) does this in one call:
`Purchases::syncWithServer()` posts the latest purchase to `/sync` and caches the granted keys.
Point it at this package:

```dotenv
PURCHASES_SERVER_URL=https://your-app.com/mobile-entitlements/sync
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

## Revenue report

Counts from the `entitlements` table, by store:

```sh
php artisan mobile-entitlements:report            # last 30 days, as a table
php artisan mobile-entitlements:report --days=7 --json
```

| Metric | Meaning |
| --- | --- |
| `active_subscribers` | Subscriptions that grant access now (grace period included) |
| `in_trial` | Active subscriptions in a free trial |
| `new_subscriptions` | Subscriptions first seen in the period |
| `churned` | Subscriptions without access now that expired or were revoked in the period |
| `refunds` | Apple refunds and Google voided purchases in the period, any product type |
| `one_time_unlocks_sold` | Non-consumables first seen in the period |
| `consumables_sold` | Total quantity of consumables first seen in the period |

Sandbox rows are left out. "First seen" is when the package created the row, from a webhook or
`/sync`, so purchases made before you installed the package are not counted as new.

The report never invents amounts. To add `estimated_mrr`, list a monthly amount per subscription
product id, all in one currency:

```php
'prices' => [
    'com.example.pro.monthly' => 9.99,
    'com.example.pro.yearly' => 99.99 / 12,
    'pro_monthly' => 9.99,
],
```

`estimated_mrr` is the sum of those amounts over active subscribers who are not in a trial. It is
before store commission and tax, and ignores price changes and introductory prices. Active
subscribers whose product has no price are counted in `unpriced_subscribers`.

### Summary endpoint

`GET /mobile-entitlements/summary?days=30` returns the same JSON, for a dashboard:

```json
{
    "period": {"days": 30, "from": "2026-09-08T10:00:00+00:00", "to": "2026-10-08T10:00:00+00:00"},
    "stores": {
        "appStore": {"active_subscribers": 120, "in_trial": 14, "new_subscriptions": 31, "churned": 9, "refunds": 1, "one_time_unlocks_sold": 4, "consumables_sold": 0},
        "googlePlay": {"active_subscribers": 75, "in_trial": 6, "new_subscriptions": 18, "churned": 5, "refunds": 0, "one_time_unlocks_sold": 2, "consumables_sold": 0}
    },
    "total": {"active_subscribers": 195, "in_trial": 20, "new_subscriptions": 49, "churned": 14, "refunds": 1, "one_time_unlocks_sold": 6, "consumables_sold": 0}
}
```

It is guarded by the `viewMobileEntitlementsSummary` Gate. Define it in your app; until you do,
every request gets 403:

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('viewMobileEntitlementsSummary', fn ($user): bool => $user->is_admin);
}
```

Change `middleware.summary` to use another guard or your own middleware.

## Promotional offer signatures

StoreKit 2 promotional offers need a JWS signed on your server. The package signs it as described
in Apple's [Generating JWS to sign App Store requests](https://developer.apple.com/documentation/storekit/generating-jws-to-sign-app-store-requests).

1. In App Store Connect open **Users and Access → Integrations → In-App Purchase** and generate a
   key. Apple expects an In-App Purchase key here, not an App Store Connect API key.
2. Configure it:

```env
MOBILE_ENTITLEMENTS_APPLE_PROMO_OFFERS=true
MOBILE_ENTITLEMENTS_APPLE_ISSUER_ID=57246542-96fe-1a63-e053-0824d011072a
MOBILE_ENTITLEMENTS_APPLE_PROMO_KEY_ID=2X9R4HXF34
MOBILE_ENTITLEMENTS_APPLE_PROMO_PRIVATE_KEY=/path/to/SubscriptionKey_2X9R4HXF34.p8
```

If `promo_key_id` and `promo_private_key` are empty, the App Store Server API key (`key_id`,
`private_key`) is used. That key is also an In-App Purchase key, so one key can do both.

The app asks for a signature (same authentication as `/sync`):

```sh
curl -X POST https://your-app.com/mobile-entitlements/promo-signature \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -d productId=com.example.pro.monthly -d offerId=winback_50 -d transactionId=2000000000000001
```

```json
{"signature": "eyJ0eXAiOiJKV1QiLCJhbGciOiJFUzI1NiIsImtpZCI6IjJYOVI0SFhGMzQifQ..."}
```

and passes it to `Product.PurchaseOption.promotionalOffer(offerID, compactJWS: signature)`.

The JWS header is `{"alg": "ES256", "kid": "<key id>", "typ": "JWT"}` and the claims are `iss`,
`iat`, `aud: "promotional-offer"`, `bid`, a fresh `nonce` (UUID), `productId`, `offerIdentifier`
and, when sent, `transactionId` (any transaction of the customer, or their `appTransactionID`;
Apple recommends it). There is no `exp`: Apple rejects tokens that carry one and enforces the
expiry from `iat`, so request the signature right before the purchase. Apple's claim set has no
`appAccountToken`; set it on the purchase with `.appAccountToken(...)` in the app instead.

Without further checks any signed-in user can get any offer signed. To decide who gets which
offer, define this Gate; when it exists, denied requests get 403:

```php
Gate::define('redeemMobileEntitlementsPromoOffer', function ($user, string $productId, string $offerId): bool {
    return $offerId !== 'winback_50' || $user->entitlements()->where('product_id', $productId)->exists();
});
```

With `promo_offers` off (the default) the endpoint answers 403. Without a key it answers 503.

## What you can build

Mobile Entitlements is a building block: verifying store notifications and keeping an
entitlement table your API can trust is done, and the product around it is yours. Sell
digital features inside your app through the App Store's and Google Play's own in-app
purchases, as their rules require; this package is the server side of those purchases.

**Subscriptions and content**

- **Premium articles, podcasts and video.** Put your paid content API behind
  `entitled:pro`, so only verified subscribers get it, whatever the app sends.
- **Fitness and coaching apps.** Gate workout plans and programmes on the server. Billing
  grace periods keep access open while the store retries a failed payment, so paying
  customers are not locked out by a card problem.
- **Language and course apps.** Offer a monthly plan and a lifetime unlock side by side,
  and check either key with `entitled:pro,lifetime`.

**Games and consumables**

- **Coins, gems and credits.** Listen for `EntitlementGranted` on a consumable and add to
  the player's balance in your own table. Each purchase is stored once, so a retried
  webhook or a repeated `/sync` never pays out twice.

**SaaS and multi-platform products**

- **One account on iPhone, Android and the web.** The entitlement belongs to the user, so
  a subscription bought on one phone unlocks the same features in your other app and
  your Laravel web app.
- **Mobile companions for a SaaS product.** Check `hasEntitlement()` in the same policies
  and middleware the web app already uses.

**Operations and retention**

- **Founder dashboards.** Feed the `/summary` endpoint or `mobile-entitlements:report`
  into an admin page with active subscribers, trials, churn and refunds per store.
- **Support tools.** When a customer says they paid but have no access, look up their
  entitlements and run `mobile-entitlements:reconcile --user=<id>` to ask the stores
  again.
- **Win-back offers.** When `EntitlementRevoked` fires for an expired subscription, show
  an App Store promotional offer in the app, signed by `/promo-signature`. Email only
  people who agreed to hear from you.

The package stores purchases and grants access; it does not set prices, show a paywall or
send emails. Those stay in your app, and the stores' rules for in-app purchases and
subscription offers apply to them.

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

MIT. The LICENSE file is included with the package.

Apple, App Store and StoreKit are trademarks of Apple Inc. Google Play and Google Cloud Pub/Sub are
trademarks of Google LLC. NativePHP is a trademark of its respective owner. This package is an
independent project and is not affiliated with, endorsed or sponsored by Apple, Google or NativePHP.

The companion NativePHP plugin, Paywalls & Purchases for NativePHP, is sold on
[vipertecpro.com](https://vipertecpro.com).
