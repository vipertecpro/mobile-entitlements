<?php

namespace Vipertecpro\MobileEntitlements\Support;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Vipertecpro\MobileEntitlements\Enums\Store;
use Vipertecpro\MobileEntitlements\Events\StoreNotificationReceived;
use Vipertecpro\MobileEntitlements\Models\StoreTransaction;

/**
 * Idempotent bookkeeping for store notifications, keyed on (store, notification_id).
 */
class NotificationRecorder
{
    public function __construct(private ConnectionInterface $db, private Dispatcher $events) {}

    public function alreadyProcessed(Store $store, string $notificationId): bool
    {
        return StoreTransaction::query()
            ->where('store', $store->value)
            ->where('notification_id', $notificationId)
            ->whereNotNull('processed_at')
            ->exists();
    }

    /**
     * Store the notification and run $process inside one transaction with the notification row
     * locked. $process returns true when the notification was fully handled, false when it was
     * stored but not understood (it stays unprocessed), or a string to mark it processed with that
     * note in `error` (for example a test purchase that was not applied). A notification that was already processed
     * is skipped and fires nothing; otherwise StoreNotificationReceived fires after commit.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $payload
     * @param  Closure(StoreTransaction): (bool|string)  $process
     */
    public function record(Store $store, string $notificationId, string $type, array $attributes, array $payload, Closure $process): StoreTransaction
    {
        $this->ensureRowExists($store, $notificationId, $type, $attributes, $payload);

        return $this->db->transaction(function () use ($store, $notificationId, $type, $payload, $process): StoreTransaction {
            /** @var StoreTransaction $transaction */
            $transaction = StoreTransaction::query()
                ->where('store', $store->value)
                ->where('notification_id', $notificationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transaction->processed_at !== null) {
                return $transaction;
            }

            $events = $this->events;
            $this->db->afterCommit(static fn () => $events->dispatch(new StoreNotificationReceived($store, $type, $payload)));

            $result = $process($transaction);

            $transaction->forceFill([
                'processed_at' => $result !== false ? now() : null,
                'error' => is_string($result) ? $result : null,
            ])->save();

            return $transaction;
        });
    }

    /**
     * Record a failure so it is visible. A final failure is marked processed so it is not retried.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $payload
     */
    public function fail(Store $store, string $notificationId, string $type, array $attributes, array $payload, string $error, bool $final = false): void
    {
        $this->ensureRowExists($store, $notificationId, $type, $attributes, $payload);

        StoreTransaction::query()
            ->where('store', $store->value)
            ->where('notification_id', $notificationId)
            ->whereNull('processed_at')
            ->update(['error' => $error, 'processed_at' => $final ? now() : null, 'updated_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $payload
     */
    private function ensureRowExists(Store $store, string $notificationId, string $type, array $attributes, array $payload): bool
    {
        $exists = StoreTransaction::query()
            ->where('store', $store->value)
            ->where('notification_id', $notificationId)
            ->exists();

        if ($exists) {
            return false;
        }

        try {
            StoreTransaction::query()->create(array_merge($attributes, [
                'store' => $store,
                'notification_id' => $notificationId,
                'type' => $type,
                'payload' => $payload,
                'received_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery created it first; the lock in record() serialises processing.
            return false;
        }

        return true;
    }
}
