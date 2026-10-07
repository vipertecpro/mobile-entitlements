<?php

use Illuminate\Support\Facades\Route;
use Vipertecpro\MobileEntitlements\Http\Controllers\AppleNotificationController;
use Vipertecpro\MobileEntitlements\Http\Controllers\GoogleNotificationController;
use Vipertecpro\MobileEntitlements\Http\Controllers\PromotionalOfferSignatureController;
use Vipertecpro\MobileEntitlements\Http\Controllers\SummaryController;
use Vipertecpro\MobileEntitlements\Http\Controllers\SyncController;

Route::prefix((string) config('mobile-entitlements.route_prefix', 'mobile-entitlements'))
    ->name('mobile-entitlements.')
    ->group(function (): void {
        Route::middleware(array_merge(
            (array) config('mobile-entitlements.middleware.webhooks', ['api']),
            ['throttle:mobile-entitlements-webhooks'],
        ))->group(function (): void {
            Route::post('apple', AppleNotificationController::class)->name('apple');
            Route::post('google', GoogleNotificationController::class)->name('google');
        });

        Route::post('sync', SyncController::class)
            ->middleware(array_merge(
                (array) config('mobile-entitlements.middleware.sync', ['api', 'auth:sanctum']),
                ['throttle:mobile-entitlements-sync'],
            ))
            ->name('sync');

        Route::post('promo-signature', PromotionalOfferSignatureController::class)
            ->middleware(array_merge(
                (array) config('mobile-entitlements.middleware.sync', ['api', 'auth:sanctum']),
                ['throttle:mobile-entitlements-sync'],
            ))
            ->name('promo-signature');

        Route::get('summary', SummaryController::class)
            ->middleware((array) config('mobile-entitlements.middleware.summary', ['api', 'auth:sanctum', 'can:viewMobileEntitlementsSummary']))
            ->name('summary');
    });
