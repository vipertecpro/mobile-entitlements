# Changelog

All notable changes to `vipertecpro/mobile-entitlements` are documented here.
This project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- `mobile-entitlements:report {--days=30} {--json}`: active subscribers, trials, new subscriptions,
  churn, refunds, one-time unlocks and consumables sold, by store. Sandbox rows are excluded.
- `GET /mobile-entitlements/summary?days=30`: the same report as JSON, behind
  `middleware.summary` (default `api`, `auth:sanctum`, `can:viewMobileEntitlementsSummary`).
- Optional `prices` config (monthly amount per product id) adds `estimated_mrr` and
  `unpriced_subscribers`. Without it the report shows counts only.
- `POST /mobile-entitlements/promo-signature`: StoreKit 2 promotional offer JWS signed with an
  In-App Purchase key (`apple.promo_key_id`, `apple.promo_private_key`, falling back to the server
  API key). Off by default (`apple.promo_offers`). Optional `redeemMobileEntitlementsPromoOffer` Gate.

## [1.0.0] - 2026-10-08

### Added

- `POST /mobile-entitlements/apple`: App Store Server Notifications V2 with full JWS verification
  (x5c chain to Apple Root CA - G3, leaf and intermediate OIDs, ES256, bundle id, environment).
- `POST /mobile-entitlements/google`: Google Play Real-time Developer Notifications over Pub/Sub
  push, with OIDC token verification and state read from the Play Developer API.
- `POST /mobile-entitlements/sync`: authenticated, rate-limited endpoint that verifies a purchase with
  the store and links it to the signed-in user. Product ids always come from the store.
- `entitlements` and `store_transactions` tables, idempotent on the store's notification id.
- `HasEntitlements` trait, `entitled` route middleware, `MobileEntitlements` facade.
- Events: `EntitlementGranted`, `EntitlementChanged`, `EntitlementRevoked`, `StoreNotificationReceived`.
- `mobile-entitlements:reconcile` command to repair drift from lost webhooks.
- `MobileEntitlements::fake()` for application tests.
- `google.accept_test_purchases` (default off) and `webhook_rate_limit` (default 120/min per IP).
