# References

Payload shapes, enum values and endpoints were checked against these documents on 2026-10-07.
Apple's pages render client-side; their content was read through the JSON form of each page
(`https://developer.apple.com/tutorials/data/documentation/<path>.json`).

## Apple

- App Store Server Notifications V2 payload: https://developer.apple.com/documentation/appstoreservernotifications/responsebodyv2decodedpayload
- `data` object (bundleId, environment, signedTransactionInfo, signedRenewalInfo, status): https://developer.apple.com/documentation/appstoreservernotifications/data
- `notificationType` values: https://developer.apple.com/documentation/appstoreservernotifications/notificationtype
- `subtype` values: https://developer.apple.com/documentation/appstoreservernotifications/subtype
- JWSTransactionDecodedPayload: https://developer.apple.com/documentation/appstoreserverapi/jwstransactiondecodedpayload
- JWSRenewalInfoDecodedPayload: https://developer.apple.com/documentation/appstoreserverapi/jwsrenewalinfodecodedpayload
- Transaction `type` values: https://developer.apple.com/documentation/appstoreserverapi/type
- `environment` values (`Sandbox`, `Production`): https://developer.apple.com/documentation/appstoreserverapi/environment
- `offerDiscountType` values: https://developer.apple.com/documentation/appstoreserverapi/offerdiscounttype
- `autoRenewStatus` values: https://developer.apple.com/documentation/appstoreserverapi/autorenewstatus
- Get Transaction Info (`GET /inApps/v1/transactions/{transactionId}`): https://developer.apple.com/documentation/appstoreserverapi/get-transaction-info
- Get All Subscription Statuses (`GET /inApps/v1/subscriptions/{transactionId}`): https://developer.apple.com/documentation/appstoreserverapi/get-all-subscription-statuses
- Subscription `status` values (1 active, 2 expired, 3 billing retry, 4 grace period, 5 revoked): https://developer.apple.com/documentation/appstoreserverapi/status
- StatusResponse: https://developer.apple.com/documentation/appstoreserverapi/statusresponse
- API JWT (ES256, kid, iss, iat, exp up to 1 hour, aud `appstoreconnect-v1`, bid): https://developer.apple.com/documentation/appstoreserverapi/generating-json-web-tokens-for-api-requests
- Promotional offer JWS (checked 2026-10-08; header `alg`/`kid`/`typ`, claims `iss`, `iat`, `aud` `promotional-offer`, `bid`, `nonce`, `productId`, `offerIdentifier`, optional `transactionId`; no `exp`, which "makes the request fail"; use an In-App Purchase key): https://developer.apple.com/documentation/storekit/generating-jws-to-sign-app-store-requests
- Passing the signature in the app: https://developer.apple.com/documentation/storekit/product/purchaseoption/promotionaloffer(_:compactjws:)
- Promotional offers overview: https://developer.apple.com/documentation/storekit/implementing-promotional-offers-in-your-app
- Apple's own implementation (`PromotionalOfferV2SignatureCreator`, same claim set): https://github.com/apple/app-store-server-library-python/blob/main/appstoreserverlibrary/jws_signature_creator.py
- Chain rules (exactly three certificates, leaf OID `1.2.840.113635.100.6.11.1`, intermediate OID `1.2.840.113635.100.6.2.1`), from Apple's own App Store Server Library: https://github.com/apple/app-store-server-library-python/blob/main/appstoreserverlibrary/signed_data_verifier.py
- Apple PKI (root certificates): https://www.apple.com/certificateauthority/

## Google

- Real-time developer notifications reference (DeveloperNotification, notification type codes, voided purchases): https://developer.android.com/google/play/billing/rtdn-reference
- `purchases.subscriptionsv2` (SubscriptionPurchaseV2, SubscriptionState): https://developers.google.com/android-publisher/api-ref/rest/v3/purchases.subscriptionsv2
- `purchases.products` (ProductPurchase, purchaseState): https://developers.google.com/android-publisher/api-ref/rest/v3/purchases.products
- Endpoint paths confirmed in the API discovery document: https://androidpublisher.googleapis.com/$discovery/rest?version=v3
- Authenticating Pub/Sub push (issuer, audience, email, email_verified): https://docs.cloud.google.com/pubsub/docs/authenticate-push-subscriptions
- Google OIDC signing keys: https://www.googleapis.com/oauth2/v3/certs
- Service-account JWT bearer grant (RS256, iss, scope, aud `https://oauth2.googleapis.com/token`, 1 hour max): https://developers.google.com/identity/protocols/oauth2/service-account
