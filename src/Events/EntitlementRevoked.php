<?php

namespace Vipertecpro\MobileEntitlements\Events;

use Vipertecpro\MobileEntitlements\Models\Entitlement;

class EntitlementRevoked
{
    public function __construct(public Entitlement $entitlement, public string $cause) {}
}
