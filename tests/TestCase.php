<?php

namespace Vipertecpro\MobileEntitlements\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Vipertecpro\MobileEntitlements\MobileEntitlementsServiceProvider;
use Vipertecpro\MobileEntitlements\Tests\Support\GoogleKeys;
use Vipertecpro\MobileEntitlements\Tests\Support\User;

abstract class TestCase extends Orchestra
{
    public const BUNDLE_ID = 'com.example.app';

    public const PACKAGE_NAME = 'com.example.app';

    public const PUSH_AUDIENCE = 'https://example.test/mobile-entitlements/google';

    public const PUSH_EMAIL = 'pubsub-push@example-project.iam.gserviceaccount.com';

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [MobileEntitlementsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');

        $app['config']->set('mobile-entitlements.user_model', User::class);
        $app['config']->set('mobile-entitlements.middleware.sync', ['api', 'auth']);
        $app['config']->set('mobile-entitlements.entitlements', [
            'pro' => ['com.example.pro.monthly', 'com.example.pro.yearly', 'pro_monthly', 'pro_yearly'],
            'lifetime' => ['com.example.lifetime', 'lifetime_unlock'],
            'coins' => ['com.example.coins.100', 'coins_100'],
        ]);
        $app['config']->set('mobile-entitlements.consumables', ['coins_100']);

        $app['config']->set('mobile-entitlements.apple.bundle_id', self::BUNDLE_ID);
        $app['config']->set('mobile-entitlements.apple.environment', 'production');
        $app['config']->set('mobile-entitlements.apple.accept_sandbox', false);
        $app['config']->set('mobile-entitlements.apple.root_certificates', [__DIR__.'/fixtures/apple/certs/root.pem']);

        $app['config']->set('mobile-entitlements.google.package_name', self::PACKAGE_NAME);
        $app['config']->set('mobile-entitlements.google.push_audience', self::PUSH_AUDIENCE);
        $app['config']->set('mobile-entitlements.google.push_service_account_email', self::PUSH_EMAIL);
        $app['config']->set('mobile-entitlements.google.service_account_json', GoogleKeys::serviceAccountJson());
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->uuid('app_account_token')->nullable();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
