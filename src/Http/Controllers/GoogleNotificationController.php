<?php

namespace Vipertecpro\MobileEntitlements\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Vipertecpro\MobileEntitlements\Exceptions\StoreApiException;
use Vipertecpro\MobileEntitlements\Exceptions\VerificationException;
use Vipertecpro\MobileEntitlements\Google\GoogleNotificationHandler;

/**
 * POST {prefix}/google — Pub/Sub push of Google Play Real-time Developer Notifications.
 */
class GoogleNotificationController
{
    public function __invoke(Request $request, GoogleNotificationHandler $handler): JsonResponse
    {
        try {
            $transaction = $handler->handle($request->json()->all(), $request->bearerToken());
        } catch (VerificationException $exception) {
            Log::warning('mobile-entitlements: rejected Pub/Sub push', ['reason' => $exception->getMessage()]);

            return response()->json(['message' => 'Invalid notification.'], 401);
        } catch (StoreApiException $exception) {
            Log::error('mobile-entitlements: Play Developer API failed, Pub/Sub will retry', ['reason' => $exception->getMessage()]);

            return response()->json(['message' => 'Store temporarily unavailable.'], 503);
        }

        return response()->json([
            'received' => true,
            'processed' => $transaction?->processed_at !== null,
        ]);
    }
}
