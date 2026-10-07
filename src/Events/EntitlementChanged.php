<?php

namespace Vipertecpro\MobileEntitlements\Events;

use Vipertecpro\MobileEntitlements\Models\Entitlement;

class EntitlementChanged
{
    public function __construct(public Entitlement $entitlement, public string $cause) {}
}
