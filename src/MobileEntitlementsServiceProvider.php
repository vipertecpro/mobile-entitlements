<?php

namespace Vipertecpro\MobileEntitlements;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Vipertecpro\MobileEntitlements\Apple\AppleJwsVerifier;
use Vipertecpro\MobileEntitlements\Apple\AppStoreServerApiClient;
use Vipertecpro\MobileEntitlements\Apple\PromotionalOfferSigner;
use Vipertecpro\MobileEntitlements\Console\ReconcileCommand;
use Vipertecpro\MobileEntitlements\Console\ReportCommand;
use Vipertecpro\MobileEntitlements\Google\GooglePurchaseMapper;
use Vipertecpro\MobileEntitlements\Google\PlayDeveloperApiClient;
use Vipertecpro\MobileEntitlements\Google\PubSubTokenVerifier;
use Vipertecpro\MobileEntitlements\Http\Middleware\EnsureEntitled;
use Vipertecpro\MobileEntitlements\Support\RevenueReport;

class MobileEntitlementsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mobile-entitlements.php', 'mobile-entitlements');

        $this->app->bind(AppleJwsVerifier::class, fn (Application $app): AppleJwsVerifier => new AppleJwsVerifier(
            array_values(array_map('strval', (array) $app['config']->get('mobile-entitlements.apple.root_certificates', []))),
        ));

        $this->app->bind(AppStoreServerApiClient::class, fn (Application $app): AppStoreServerApiClient => new AppStoreServerApiClient(
            $app->make(HttpFactory::class),
            $app['config']->get('mobile-entitlements.apple.issuer_id'),
            $app['config']->get('mobile-entitlements.apple.key_id'),
            $app['config']->get('mobile-entitlements.apple.private_key'),
            $app['config']->get('mobile-entitlements.apple.bundle_id'),
        ));

        $this->app->bind(PromotionalOfferSigner::class, fn (Application $app): PromotionalOfferSigner => new PromotionalOfferSigner(
            $app['config']->get('mobile-entitlements.apple.issuer_id'),
            $app['config']->get('mobile-entitlements.apple.promo_key_id') ?: $app['config']->get('mobile-entitlements.apple.key_id'),
            $app['config']->get('mobile-entitlements.apple.promo_private_key') ?: $app['config']->get('mobile-entitlements.apple.private_key'),
            $app['config']->get('mobile-entitlements.apple.bundle_id'),
        ));

        $this->app->bind(RevenueReport::class, fn (Application $app): RevenueReport => new RevenueReport(
            (array) $app['config']->get('mobile-entitlements.prices', []),
        ));

        $this->app->bind(PubSubTokenVerifier::class, fn (Application $app): PubSubTokenVerifier => new PubSubTokenVerifier(
            $app->make(HttpFactory::class),
            $app['cache.store'],
            $app['config']->get('mobile-entitlements.google.push_audience'),
            $app['config']->get('mobile-entitlements.google.push_service_account_email'),
        ));

        $this->app->bind(PlayDeveloperApiClient::class, fn (Application $app): PlayDeveloperApiClient => new PlayDeveloperApiClient(
            $app->make(HttpFactory::class),
            $app['cache.store'],
            $app['config']->get('mobile-entitlements.google.service_account_json'),
        ));

        $this->app->bind(GooglePurchaseMapper::class, fn (Application $app): GooglePurchaseMapper => new GooglePurchaseMapper(
            array_values(array_map('strval', (array) $app['config']->get('mobile-entitlements.consumables', []))),
        ));

        $this->app->scoped(MobileEntitlementsManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/mobile-entitlements.php' => config_path('mobile-entitlements.php'),
            ], 'mobile-entitlements-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'mobile-entitlements-migrations');

            $this->commands([ReconcileCommand::class, ReportCommand::class]);
        }

        RateLimiter::for('mobile-entitlements-sync', function (Request $request): Limit {
            $perMinute = (int) config('mobile-entitlements.sync_rate_limit', 60);

            return Limit::perMinute($perMinute)->by('mobile-entitlements-sync:'.($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });

        RateLimiter::for('mobile-entitlements-webhooks', function (Request $request): Limit {
            $perMinute = (int) config('mobile-entitlements.webhook_rate_limit', 120);

            return Limit::perMinute($perMinute)->by('mobile-entitlements-webhooks:'.$request->ip());
        });

        $this->app->make(Router::class)->aliasMiddleware('entitled', EnsureEntitled::class);

        if (config('mobile-entitlements.register_routes', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/mobile-entitlements.php');
        }
    }
}
