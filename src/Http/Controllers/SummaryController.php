<?php

namespace Vipertecpro\MobileEntitlements\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vipertecpro\MobileEntitlements\Support\RevenueReport;

/**
 * GET {prefix}/summary?days=30 — the mobile-entitlements:report data as JSON. Guarded by the
 * viewMobileEntitlementsSummary Gate through middleware.summary.
 */
class SummaryController
{
    public function __invoke(Request $request, RevenueReport $report): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        return response()->json($report->generate((int) ($validated['days'] ?? 30)));
    }
}
