<?php

namespace Vipertecpro\MobileEntitlements\Events;

use Vipertecpro\MobileEntitlements\Models\Entitlement;

class EntitlementGranted
{
    public function __construct(public Entitlement $entitlement) {}
}
