<?php

namespace Vipertecpro\MobileEntitlements\Events;

use Vipertecpro\MobileEntitlements\Enums\Store;

/**
 * Fired for every verified, first-seen store notification, before processing. For app-level logging.
 */
class StoreNotificationReceived
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public Store $store, public string $type, public array $payload) {}
}
