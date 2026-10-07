<?php

namespace Vipertecpro\MobileEntitlements\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Vipertecpro\MobileEntitlements\Apple\PromotionalOfferSigner;
use Vipertecpro\MobileEntitlements\Exceptions\StoreApiException;

/**
 * POST {prefix}/promo-signature — returns a StoreKit 2 promotional offer JWS for the signed-in user.
 * Off unless apple.promo_offers is true. When the app defines the redeemMobileEntitlementsPromoOffer
 * Gate, it decides which users may receive which offers.
 */
class PromotionalOfferSignatureController
{
    public const GATE = 'redeemMobileEntitlementsPromoOffer';

    public function __invoke(Request $request, PromotionalOfferSigner $signer): JsonResponse
    {
        if (! config('mobile-entitlements.apple.promo_offers', false)) {
            return response()->json(['message' => 'Promotional offers are disabled.'], 403);
        }

        $validated = $request->validate([
            'productId' => ['required', 'string', 'max:255'],
            'offerId' => ['required', 'string', 'max:255'],
            'transactionId' => ['nullable', 'string', 'max:64'],
        ]);

        $user = $request->user();
        abort_if($user === null, 401);

        if (Gate::has(self::GATE) && Gate::forUser($user)->denies(self::GATE, [$validated['productId'], $validated['offerId']])) {
            return response()->json(['message' => 'This offer is not available.'], 403);
        }

        try {
            $signature = $signer->sign($validated['productId'], $validated['offerId'], $validated['transactionId'] ?? null);
        } catch (StoreApiException) {
            return response()->json(['message' => 'Promotional offer signing is not configured.'], 503);
        }

        return response()->json(['signature' => $signature]);
    }
}
