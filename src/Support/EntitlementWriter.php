<?php

namespace Vipertecpro\MobileEntitlements\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Vipertecpro\MobileEntitlements\Enums\ProductType;
use Vipertecpro\MobileEntitlements\Events\EntitlementChanged;
use Vipertecpro\MobileEntitlements\Events\EntitlementGranted;
use Vipertecpro\MobileEntitlements\Events\EntitlementRevoked;
use Vipertecpro\MobileEntitlements\Exceptions\EntitlementOwnedByAnotherUser;
use Vipertecpro\MobileEntitlements\Models\Entitlement;

/**
 * The only code that writes entitlement rows. Each write runs in a transaction with the row locked,
 * and events are dispatched after the outermost transaction commits.
 */
class EntitlementWriter
{
    /**
     * Columns whose change is reported through EntitlementChanged.
     */
    private const TRACKED = [
        'product_id', 'latest_transaction_id', 'is_active', 'expires_at', 'will_renew', 'in_grace_period',
        'in_billing_retry', 'is_trial', 'revoked_at', 'quantity', 'user_id', 'raw',
    ];

    public function __construct(private ConnectionInterface $db, private Dispatcher $events) {}

    /**
     * Create or update the entitlement for a verified purchase.
     *
     * @param  int|string|null  $userId  The authenticated owner (from /sync); null for webhooks.
     *
     * @throws EntitlementOwnedByAnotherUser
     */
    public function apply(VerifiedPurchase $purchase, string $cause, int|string|null $userId = null, ?string $linkedPurchaseToken = null): Entitlement
    {
        return $this->db->transaction(function () use ($purchase, $cause, $userId, $linkedPurchaseToken): Entitlement {
            $entitlement = $this->lockedRow($purchase);
            $isNew = ! $entitlement->exists;
            $wasActive = ! $isNew && $this->wasActive($entitlement);

            if ($userId !== null) {
                if ($entitlement->user_id !== null && (string) $entitlement->user_id !== (string) $userId) {
                    throw new EntitlementOwnedByAnotherUser('This purchase is linked to another account.');
                }

                $entitlement->user_id = $userId;
            }

            $isRevocation = $purchase->revokedAt !== null;
            $isStale = ! $isNew
                && ! $isRevocation
                && $purchase->purchasedAt !== null
                && $entitlement->purchased_at !== null
                && $purchase->purchasedAt->lessThan($entitlement->purchased_at);

            if (! $isStale) {
                $entitlement->fill($this->attributes($purchase));
            }

            $entitlement->last_notification_type = $cause;

            if ($entitlement->user_id === null) {
                $entitlement->user_id = $this->inferUserId($purchase, $linkedPurchaseToken);
            }

            $entitlement->save();

            $this->dispatchFor($entitlement, $isNew, $wasActive, $cause);

            if ($entitlement->user_id !== null) {
                $this->linkAnonymousSiblings($entitlement);
            }

            if ($entitlement->grantsAccessNow() && $purchase->productType === ProductType::Subscription) {
                $this->supersedeSiblings($entitlement, $linkedPurchaseToken);
            }

            return $entitlement;
        });
    }

    /**
     * Revoke access (Google voided purchase) for every row bought with the given token.
     *
     * @return list<Entitlement>
     */
    public function revokeByPurchaseToken(string $purchaseToken, CarbonImmutable $revokedAt, string $reason, string $cause): array
    {
        return $this->db->transaction(function () use ($purchaseToken, $revokedAt, $reason, $cause): array {
            $rows = Entitlement::query()
                ->where('purchase_token_hash', hash('sha256', $purchaseToken))
                ->lockForUpdate()
                ->get();

            foreach ($rows as $entitlement) {
                $wasActive = $this->wasActive($entitlement);
                $entitlement->forceFill([
                    'revoked_at' => $revokedAt,
                    'revocation_reason' => $reason,
                    'is_active' => false,
                    'will_renew' => false,
                    'last_notification_type' => $cause,
                ])->save();

                $this->dispatchFor($entitlement, false, $wasActive, $cause);
            }

            return $rows->all();
        });
    }

    /**
     * Note a change that does not alter access (for example a partial refund).
     */
    public function touch(Entitlement $entitlement, string $cause): Entitlement
    {
        $entitlement->forceFill(['last_notification_type' => $cause])->save();
        $this->afterCommit(new EntitlementChanged($entitlement, $cause));

        return $entitlement;
    }

    /**
     * Recompute access from the stored dates when the store cannot be asked (reconcile fallback).
     */
    public function refreshFromClock(Entitlement $entitlement, string $cause): Entitlement
    {
        return $this->db->transaction(function () use ($entitlement, $cause): Entitlement {
            /** @var Entitlement $locked */
            $locked = Entitlement::query()->whereKey($entitlement->getKey())->lockForUpdate()->firstOrFail();
            $wasActive = $this->wasActive($locked);

            if ($locked->expires_at !== null && $locked->expires_at->isPast()) {
                $locked->in_grace_period = false;
                $locked->is_active = false;
            }

            $locked->last_notification_type = $cause;
            $locked->save();
            $locked->touch();

            $this->dispatchFor($locked, false, $wasActive, $cause);

            return $locked;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(VerifiedPurchase $purchase): array
    {
        $appAccountToken = $purchase->appAccountToken !== null && Str::isUuid($purchase->appAccountToken)
            ? strtolower($purchase->appAccountToken)
            : null;

        return [
            'type' => $purchase->productType,
            'latest_transaction_id' => $purchase->transactionId,
            'purchase_token_hash' => $purchase->purchaseTokenHash(),
            'app_account_token' => $appAccountToken,
            'is_active' => $purchase->isActive(),
            'purchased_at' => $purchase->purchasedAt,
            'expires_at' => $purchase->expiresAt,
            'will_renew' => $purchase->willRenew,
            'in_grace_period' => $purchase->inGracePeriod,
            'in_billing_retry' => $purchase->inBillingRetry,
            'is_trial' => $purchase->isTrial,
            'revoked_at' => $purchase->revokedAt,
            'revocation_reason' => $purchase->revocationReason,
            'environment' => $purchase->environment,
            'quantity' => $purchase->quantity,
            'raw' => $purchase->raw,
        ];
    }

    private function lockedRow(VerifiedPurchase $purchase): Entitlement
    {
        $keys = [
            'store' => $purchase->store->value,
            'original_transaction_id' => $purchase->originalTransactionId,
            'product_id' => $purchase->productId,
        ];

        $existing = Entitlement::query()->where($keys)->lockForUpdate()->first();

        if ($existing instanceof Entitlement) {
            return $existing;
        }

        $entitlement = new Entitlement;
        $entitlement->forceFill($keys);

        return $entitlement;
    }

    private function inferUserId(VerifiedPurchase $purchase, ?string $linkedPurchaseToken): int|string|null
    {
        $sibling = Entitlement::query()
            ->where('store', $purchase->store->value)
            ->whereNotNull('user_id')
            ->where(function ($query) use ($purchase, $linkedPurchaseToken): void {
                $query->where('original_transaction_id', $purchase->originalTransactionId);

                if ($linkedPurchaseToken !== null) {
                    $query->orWhere('purchase_token_hash', hash('sha256', $linkedPurchaseToken));
                }
            })
            ->value('user_id');

        if ($sibling !== null) {
            return $sibling;
        }

        $column = config('mobile-entitlements.app_account_token_column');

        if (blank($column) || $purchase->appAccountToken === null || ! Str::isUuid($purchase->appAccountToken)) {
            return null;
        }

        /** @var class-string<Model> $userModel */
        $userModel = config('mobile-entitlements.user_model');

        $user = $userModel::query()->where($column, strtolower($purchase->appAccountToken))->first();

        return $user?->getKey();
    }

    private function linkAnonymousSiblings(Entitlement $entitlement): void
    {
        Entitlement::query()
            ->where('store', $entitlement->store->value)
            ->where('original_transaction_id', $entitlement->original_transaction_id)
            ->whereNull('user_id')
            ->whereKeyNot($entitlement->getKey())
            ->lockForUpdate()
            ->get()
            ->each(function (Entitlement $sibling) use ($entitlement): void {
                $sibling->forceFill(['user_id' => $entitlement->user_id])->save();
                $this->afterCommit(new EntitlementChanged($sibling, 'linked'));
            });
    }

    /**
     * An upgrade or crossgrade replaces the other products of the same subscription.
     */
    private function supersedeSiblings(Entitlement $entitlement, ?string $linkedPurchaseToken): void
    {
        Entitlement::query()
            ->where('store', $entitlement->store->value)
            ->where('is_active', true)
            ->whereKeyNot($entitlement->getKey())
            ->where(function ($query) use ($entitlement, $linkedPurchaseToken): void {
                $query->where('original_transaction_id', $entitlement->original_transaction_id);

                if ($linkedPurchaseToken !== null) {
                    $query->orWhere('purchase_token_hash', hash('sha256', $linkedPurchaseToken));
                }
            })
            ->lockForUpdate()
            ->get()
            ->each(function (Entitlement $sibling) use ($entitlement): void {
                $sibling->forceFill([
                    'is_active' => false,
                    'will_renew' => false,
                    'user_id' => $sibling->user_id ?? $entitlement->user_id,
                    'last_notification_type' => 'superseded',
                ])->save();
                $this->afterCommit(new EntitlementChanged($sibling, 'superseded'));
            });
    }

    /**
     * The stored state before this write. Events describe changes to stored state, so a row that
     * expired by the clock but is still flagged active is revoked (with an event) when it is next
     * written, for example by the reconcile command.
     */
    private function wasActive(Entitlement $entitlement): bool
    {
        return $entitlement->is_active && $entitlement->revoked_at === null;
    }

    private function dispatchFor(Entitlement $entitlement, bool $isNew, bool $wasActive, string $cause): void
    {
        $isActive = $entitlement->grantsAccessNow();

        if (! $wasActive && $isActive) {
            $this->afterCommit(new EntitlementGranted($entitlement));

            return;
        }

        if ($wasActive && ! $isActive) {
            $this->afterCommit(new EntitlementRevoked($entitlement, $cause));

            return;
        }

        if ($isNew || $entitlement->wasChanged(self::TRACKED)) {
            $this->afterCommit(new EntitlementChanged($entitlement, $cause));
        }
    }

    private function afterCommit(object $event): void
    {
        $events = $this->events;

        if (method_exists($this->db, 'afterCommit')) {
            $this->db->afterCommit(static fn () => $events->dispatch($event));

            return;
        }

        $events->dispatch($event);
    }
}
