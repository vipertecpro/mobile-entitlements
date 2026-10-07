<?php

namespace Vipertecpro\MobileEntitlements\Facades;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Vipertecpro\MobileEntitlements\Enums\Store;
use Vipertecpro\MobileEntitlements\MobileEntitlementsManager;
use Vipertecpro\MobileEntitlements\Models\Entitlement;
use Vipertecpro\MobileEntitlements\Support\VerifiedPurchase;
use Vipertecpro\MobileEntitlements\Testing\MobileEntitlementsFake;

/**
 * @method static VerifiedPurchase verify(Store $store, string $token, ?string $productId = null)
 * @method static Collection<int, Entitlement> grantFromToken(Authenticatable $user, Store $store, string $token, ?string $productId = null)
 * @method static Collection<int, Entitlement> reconcile(Authenticatable $user)
 * @method static Entitlement reconcileEntitlement(Entitlement $entitlement)
 * @method static bool hasEntitlement(Authenticatable $user, string $key)
 * @method static list<string> grantedKeys(Authenticatable $user)
 *
 * @see MobileEntitlementsManager
 */
class MobileEntitlements extends Facade
{
    /**
     * Replace store verification with canned purchases keyed by token.
     *
     * @param  array<string, VerifiedPurchase>  $purchases
     */
    public static function fake(array $purchases = []): MobileEntitlementsFake
    {
        /** @var MobileEntitlementsFake $fake */
        $fake = static::getFacadeApplication()->make(MobileEntitlementsFake::class);
        $fake->withPurchases($purchases);

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return MobileEntitlementsManager::class;
    }
}
