<?php

namespace Vipertecpro\MobileEntitlements\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Throwable;
use Vipertecpro\MobileEntitlements\MobileEntitlementsManager;
use Vipertecpro\MobileEntitlements\Models\Entitlement;

/**
 * Webhooks get lost. Re-query the store for entitlements that have expired while still marked
 * active, or that have not been refreshed for longer than --stale.
 */
class ReconcileCommand extends Command
{
    protected $signature = 'mobile-entitlements:reconcile
        {--user= : Only reconcile this user id}
        {--stale= : Refresh rows not updated for this long, e.g. 24h, 30m, 7d (default: config reconcile.stale)}';

    protected $description = 'Re-check entitlements with the App Store and Google Play and fix drift';

    public function handle(MobileEntitlementsManager $entitlements): int
    {
        try {
            $staleBefore = CarbonImmutable::now()->subSeconds($this->parseDuration(
                (string) ($this->option('stale') ?: config('mobile-entitlements.reconcile.stale', '24h')),
            ));
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::INVALID;
        }

        $reconciled = 0;
        $failed = 0;

        Entitlement::query()
            ->when($this->option('user') !== null, fn (Builder $query) => $query->where('user_id', $this->option('user')))
            ->whereNull('revoked_at')
            ->where(function (Builder $query) use ($staleBefore): void {
                $query->where(fn (Builder $query) => $query->where('is_active', true)->where('expires_at', '<=', now()))
                    ->orWhere('updated_at', '<=', $staleBefore);
            })
            ->chunkById(100, function ($chunk) use ($entitlements, &$reconciled, &$failed): void {
                foreach ($chunk as $entitlement) {
                    try {
                        $entitlements->reconcileEntitlement($entitlement);
                        $reconciled++;
                    } catch (Throwable $exception) {
                        $failed++;
                        $this->components->warn("Entitlement #{$entitlement->id}: {$exception->getMessage()}");
                    }
                }
            });

        $this->components->info("Reconciled {$reconciled} entitlement(s), {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function parseDuration(string $value): int
    {
        if (preg_match('/^(\d+)\s*([smhd])$/i', trim($value), $matches) !== 1) {
            throw new InvalidArgumentException("Invalid --stale value [{$value}]. Use e.g. 30m, 24h or 7d.");
        }

        return (int) $matches[1] * match (strtolower($matches[2])) {
            's' => 1,
            'm' => 60,
            'h' => 3600,
            'd' => 86400,
        };
    }
}
