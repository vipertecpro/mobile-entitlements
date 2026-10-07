<?php

namespace Vipertecpro\MobileEntitlements\Enums;

enum ProductType: string
{
    case Subscription = 'subscription';
    case NonConsumable = 'nonConsumable';
    case Consumable = 'consumable';
}
