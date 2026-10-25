<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Support;

use SilverStripe\ORM\DataObject;

/**
 * Calls a method that may only exist when an optional sibling module is installed (an extension-provided
 * accessor such as IsPickup() from click-and-collect, HasPickupOnlyItems() from shippability, or
 * getProductStatusBadge() from product-status). The dynamic method name keeps the call analysable whether or
 * not that module is present — the guard is {@see DataObject::hasMethod()}.
 */
final class OptionalCall
{
    public static function invoke(DataObject $owner, string $method, mixed $default = null): mixed
    {
        return $owner->hasMethod($method) ? $owner->{$method}() : $default;
    }
}
