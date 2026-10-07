<?php

namespace Vipertecpro\MobileEntitlements\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vipertecpro\MobileEntitlements\MobileEntitlementsManager;

/**
 * Route middleware `entitled:pro` (several keys = any of them).
 */
class EnsureEntitled
{
    public function __construct(private MobileEntitlementsManager $entitlements) {}

    public function handle(Request $request, Closure $next, string ...$keys): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        foreach ($keys as $key) {
            if ($this->entitlements->hasEntitlement($user, $key)) {
                return $next($request);
            }
        }

        $redirectTo = config('mobile-entitlements.middleware.redirect_to');

        if (filled($redirectTo) && ! $request->expectsJson()) {
            return redirect()->to((string) $redirectTo);
        }

        return response()->json([
            'message' => 'An active entitlement is required.',
            'entitlements' => array_values($keys),
        ], 402);
    }
}
