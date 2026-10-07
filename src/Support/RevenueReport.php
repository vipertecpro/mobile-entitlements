<?php

namespace Vipertecpro\MobileEntitlements\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Vipertecpro\MobileEntitlements\Enums\ProductType;
use Vipertecpro\MobileEntitlements\Enums\Store;
use Vipertecpro\MobileEntitlements\Models\Entitlement;

/**
 * Counts from the entitlements table for mobile-entitlements:report and GET {prefix}/summary.
 * No amounts are invented: estimated_mrr appears only when config('mobile-entitlements.prices')
 * maps product ids to monthly amounts. Sandbox rows are left out.
 */
class RevenueReport
{
    /** @var list<string> */
    public const METRICS = [
        'active_subscribers',
        'in_trial',
        'new_subscriptions',
        'churned',
        'refunds',
        'one_time_unlocks_sold',
        'consumables_sold',
    ];

    /**
     * @param  array<string, int|float|string>  $prices  product id => monthly amount
     */
    public function __construct(private array $prices = []) {}

    /**
     * @return array{
     *     period: array{days: int, from: string, to: string},
     *     stores: array<string, array<string, int|float>>,
     *     total: array<string, int|float>
     * }
     */
    public function generate(int $days): array
    {
        $now = CarbonImmutable::now();
        $since = $now->subDays($days);

        $metrics = [
            'active_subscribers' => $this->countByStore($this->activeSubscriptions($now)),
            'in_trial' => $this->countByStore($this->activeSubscriptions($now)->where('is_trial', true)),
            'new_subscriptions' => $this->countByStore($this->ofType(ProductType::Subscription)->whereBetween('created_at', [$since, $now])),
            'churned' => $this->countByStore($this->churned($since, $now)),
            'refunds' => $this->countByStore($this->refunded($since, $now)),
            'one_time_unlocks_sold' => $this->countByStore($this->ofType(ProductType::NonConsumable)->whereBetween('created_at', [$since, $now])),
            'consumables_sold' => $this->countByStore($this->ofType(ProductType::Consumable)->whereBetween('created_at', [$since, $now]), 'quantity'),
        ];

        $stores = [];
        $total = array_fill_keys(self::METRICS, 0);

        foreach (Store::cases() as $store) {
            foreach (self::METRICS as $metric) {
                $value = $metrics[$metric][$store->value] ?? 0;
                $stores[$store->value][$metric] = $value;
                $total[$metric] += $value;
            }
        }

        if ($this->prices !== []) {
            $mrr = $this->estimatedMrr($now);

            foreach (Store::cases() as $store) {
                $stores[$store->value]['estimated_mrr'] = round($mrr[$store->value]['estimated_mrr'] ?? 0, 2);
                $stores[$store->value]['unpriced_subscribers'] = $mrr[$store->value]['unpriced_subscribers'] ?? 0;
            }

            $total['estimated_mrr'] = round(array_sum(array_column($stores, 'estimated_mrr')), 2);
            $total['unpriced_subscribers'] = array_sum(array_column($stores, 'unpriced_subscribers'));
        }

        return [
            'period' => ['days' => $days, 'from' => $since->toIso8601String(), 'to' => $now->toIso8601String()],
            'stores' => $stores,
            'total' => $total,
        ];
    }

    /**
     * @return Builder<Entitlement>
     */
    private function query(): Builder
    {
        return Entitlement::query()->where(function (Builder $query): void {
            $query->whereNull('environment')->orWhere('environment', '!=', 'sandbox');
        });
    }

    /**
     * @return Builder<Entitlement>
     */
    private function ofType(ProductType $type): Builder
    {
        return $this->query()->where('type', $type->value);
    }

    /**
     * Subscriptions that grant access right now (grace period included).
     *
     * @return Builder<Entitlement>
     */
    private function activeSubscriptions(CarbonImmutable $now): Builder
    {
        return $this->ofType(ProductType::Subscription)
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $now)
                    ->orWhere('in_grace_period', true);
            });
    }

    /**
     * Subscriptions without access now that were revoked, or expired, inside the period.
     *
     * @return Builder<Entitlement>
     */
    private function churned(CarbonImmutable $since, CarbonImmutable $now): Builder
    {
        return $this->ofType(ProductType::Subscription)
            ->where(function (Builder $query) use ($now): void {
                $query->where('is_active', false)
                    ->orWhereNotNull('revoked_at')
                    ->orWhere(fn (Builder $query) => $query->where('expires_at', '<=', $now)->where('in_grace_period', false));
            })
            ->where(function (Builder $query) use ($since, $now): void {
                $query->whereBetween('revoked_at', [$since, $now])
                    ->orWhere(fn (Builder $query) => $query->whereNull('revoked_at')->whereBetween('expires_at', [$since, $now]));
            });
    }

    /**
     * Apple refunds (revocation_reason refund or refund:N) and Google voided purchases.
     *
     * @return Builder<Entitlement>
     */
    private function refunded(CarbonImmutable $since, CarbonImmutable $now): Builder
    {
        return $this->query()
            ->whereBetween('revoked_at', [$since, $now])
            ->where(function (Builder $query): void {
                $query->where('revocation_reason', 'refund')
                    ->orWhere('revocation_reason', 'like', 'refund:%')
                    ->orWhere('revocation_reason', 'voided');
            });
    }

    /**
     * @param  Builder<Entitlement>  $query
     * @return array<string, int>
     */
    private function countByStore(Builder $query, ?string $sumColumn = null): array
    {
        $aggregate = $sumColumn === null ? 'count(*)' : "coalesce(sum({$sumColumn}), 0)";

        return $query->toBase()
            ->selectRaw("store, {$aggregate} as aggregate")
            ->groupBy('store')
            ->pluck('aggregate', 'store')
            ->map(fn (mixed $value): int => (int) $value)
            ->all();
    }

    /**
     * Paying (non-trial) active subscribers times the configured monthly price of their product.
     *
     * @return array<string, array{estimated_mrr: float, unpriced_subscribers: int}>
     */
    private function estimatedMrr(CarbonImmutable $now): array
    {
        $rows = $this->activeSubscriptions($now)
            ->where('is_trial', false)
            ->toBase()
            ->selectRaw('store, product_id, count(*) as subscribers')
            ->groupBy('store', 'product_id')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $store = (string) $row->store;
            $result[$store] ??= ['estimated_mrr' => 0.0, 'unpriced_subscribers' => 0];

            if (array_key_exists((string) $row->product_id, $this->prices)) {
                $result[$store]['estimated_mrr'] += (float) $this->prices[(string) $row->product_id] * (int) $row->subscribers;
            } else {
                $result[$store]['unpriced_subscribers'] += (int) $row->subscribers;
            }
        }

        return $result;
    }
}
