<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Tests\Stub;

use SilverShop\Shipping\Model\ShippingMethod;
use SilverShop\StoreLocations\Model\StoreLocation;
use SilverStripe\Dev\TestOnly;

/**
 * Stands in for click-and-collect's PickupShippingMethod (with its store link) so these tests don't depend on it.
 *
 * @property int $TestStoreID
 */
class TestPickupMethod extends ShippingMethod implements TestOnly
{
    private static string $table_name = 'DeliveryScheduling_TestPickupMethod';

    private static array $db = [
        'TestStoreID' => 'Int',
    ];

    public function PickupStoreLocation(): ?StoreLocation
    {
        $store = StoreLocation::get()->byID((int) $this->TestStoreID);

        return $store instanceof StoreLocation ? $store : null;
    }
}
