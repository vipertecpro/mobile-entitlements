<?php

namespace Vipertecpro\MobileEntitlements\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Vipertecpro\MobileEntitlements\Apple\AppleNotificationHandler;
use Vipertecpro\MobileEntitlements\Exceptions\VerificationException;

/**
 * POST {prefix}/apple — App Store Server Notifications V2.
 */
class AppleNotificationController
{
    public function __invoke(Request $request, AppleNotificationHandler $handler): JsonResponse
    {
        $signedPayload = $request->input('signedPayload');

        if (! is_string($signedPayload) || $signedPayload === '') {
            return response()->json(['message' => 'Invalid notification.'], 401);
        }

        try {
            $transaction = $handler->handle($signedPayload);
        } catch (VerificationException $exception) {
            Log::warning('mobile-entitlements: rejected App Store notification', ['reason' => $exception->getMessage()]);

            return response()->json(['message' => 'Invalid notification.'], 401);
        }

        return response()->json([
            'received' => true,
            'processed' => $transaction->processed_at !== null,
        ]);
    }
}
