<?php

namespace Vipertecpro\MobileEntitlements\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Vipertecpro\MobileEntitlements\Enums\Store;
use Vipertecpro\MobileEntitlements\Exceptions\EntitlementOwnedByAnotherUser;
use Vipertecpro\MobileEntitlements\Exceptions\StoreApiException;
use Vipertecpro\MobileEntitlements\Exceptions\VerificationException;
use Vipertecpro\MobileEntitlements\MobileEntitlementsManager;
use Vipertecpro\MobileEntitlements\Models\Entitlement;

/**
 * POST {prefix}/sync — the app reports a purchase; the server verifies it with the store and links it
 * to the signed-in user. Only the store's answer is trusted, never the client's product id.
 */
class SyncController
{
    public function __invoke(Request $request, MobileEntitlementsManager $entitlements): JsonResponse
    {
        $validated = $request->validate([
            'store' => ['required', 'string', Rule::enum(Store::class)],
            'token' => ['required', 'string', 'max:16384'],
            'productId' => ['nullable', 'string', 'max:255'],
            'appAccountToken' => ['nullable', 'uuid'],
            'platform' => ['nullable', 'string', 'max:32'],
        ]);

        $user = $request->user();
        abort_if($user === null, 401);

        try {
            $granted = $entitlements->grantFromToken(
                $user,
                Store::from($validated['store']),
                $validated['token'],
                $validated['productId'] ?? null,
            );
        } catch (VerificationException) {
            return response()->json(['message' => 'The purchase could not be verified.'], 422);
        } catch (EntitlementOwnedByAnotherUser $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (StoreApiException $exception) {
            return $exception->isNotFound()
                ? response()->json(['message' => 'The purchase could not be verified.'], 422)
                : response()->json(['message' => 'Store temporarily unavailable.'], 503);
        }

        return response()->json([
            'granted' => $entitlements->grantedKeys($user),
            'entitlements' => $granted->map(fn (Entitlement $entitlement): array => [
                'id' => $entitlement->id,
                'store' => $entitlement->store->value,
                'product_id' => $entitlement->product_id,
                'type' => $entitlement->type->value,
                'keys' => $entitlement->entitlementKeys(),
                'is_active' => $entitlement->grantsAccessNow(),
                'expires_at' => $entitlement->expires_at?->toIso8601String(),
                'will_renew' => $entitlement->will_renew,
                'in_grace_period' => $entitlement->in_grace_period,
                'is_trial' => $entitlement->is_trial,
                'environment' => $entitlement->environment,
            ])->values(),
        ]);
    }
}
