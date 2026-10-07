<?php

namespace Vipertecpro\MobileEntitlements\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Vipertecpro\MobileEntitlements\Models\Entitlement;

/**
 * Add to the User model.
 *
 * @mixin Model
 */
trait HasEntitlements
{
    /**
     * @return HasMany<Entitlement, $this>
     */
    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class, 'user_id');
    }

    /**
     * Entitlements that grant access right now.
     *
     * @return HasMany<Entitlement, $this>
     */
    public function activeEntitlements(): HasMany
    {
        return $this->entitlements()->active();
    }

    public function hasEntitlement(string $key): bool
    {
        $productIds = $this->entitlementProductIds($key);

        return $productIds !== [] && $this->activeEntitlements()->whereIn('product_id', $productIds)->exists();
    }

    /**
     * The active entitlement for $key with the latest expiry (lifetime purchases first).
     */
    public function entitlement(string $key): ?Entitlement
    {
        $productIds = $this->entitlementProductIds($key);

        if ($productIds === []) {
            return null;
        }

        return $this->activeEntitlements()
            ->whereIn('product_id', $productIds)
            ->orderByRaw('case when expires_at is null then 0 else 1 end')
            ->orderByDesc('expires_at')
            ->first();
    }

    /**
     * @return list<string>
     */
    protected function entitlementProductIds(string $key): array
    {
        return array_values(array_map('strval', (array) config("mobile-entitlements.entitlements.{$key}", [])));
    }
}
