<?php

namespace Vipertecpro\MobileEntitlements\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Vipertecpro\MobileEntitlements\Enums\ProductType;
use Vipertecpro\MobileEntitlements\Enums\Store;

/**
 * @property int $id
 * @property int|string|null $user_id
 * @property Store $store
 * @property string $product_id
 * @property ProductType $type
 * @property string $original_transaction_id
 * @property string|null $latest_transaction_id
 * @property string|null $purchase_token_hash
 * @property string|null $app_account_token
 * @property bool $is_active
 * @property Carbon|null $purchased_at
 * @property Carbon|null $expires_at
 * @property bool $will_renew
 * @property bool $in_grace_period
 * @property bool $in_billing_retry
 * @property bool $is_trial
 * @property Carbon|null $revoked_at
 * @property string|null $revocation_reason
 * @property string|null $environment
 * @property int $quantity
 * @property string|null $last_notification_type
 * @property array<string, mixed>|null $raw
 */
class Entitlement extends Model
{
    protected $table = 'entitlements';

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['raw', 'purchase_token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'store' => Store::class,
            'type' => ProductType::class,
            'is_active' => 'boolean',
            'will_renew' => 'boolean',
            'in_grace_period' => 'boolean',
            'in_billing_retry' => 'boolean',
            'is_trial' => 'boolean',
            'purchased_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'quantity' => 'integer',
            'raw' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('mobile-entitlements.user_model');

        return $this->belongsTo($userModel, 'user_id');
    }

    /**
     * Entitlements that grant access right now.
     *
     * @param  Builder<Entitlement>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)
            ->whereNull('revoked_at')
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now())
                    ->orWhere('in_grace_period', true);
            });
    }

    /**
     * @param  Builder<Entitlement>  $query
     * @param  list<string>  $productIds
     */
    public function scopeForProducts(Builder $query, array $productIds): void
    {
        $query->whereIn('product_id', $productIds);
    }

    public function grantsAccessNow(): bool
    {
        if (! $this->is_active || $this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture() || $this->in_grace_period;
    }

    /**
     * Entitlement keys from config that this product unlocks.
     *
     * @return list<string>
     */
    public function entitlementKeys(): array
    {
        $keys = [];

        foreach ((array) config('mobile-entitlements.entitlements', []) as $key => $productIds) {
            if (in_array($this->product_id, (array) $productIds, true)) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }
}
