# Changelog

All notable changes to `vipertecpro/mobile-entitlements` are documented here.
This project follows [Semantic Versioning](https://semver.org/).

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
