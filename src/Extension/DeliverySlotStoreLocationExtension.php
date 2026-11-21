<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Extension;

use SilverShop\DeliveryScheduling\Model\DeliverySlot;
use SilverShop\StoreLocations\Model\StoreLocation;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;

/**
 * Ties a slot to a physical store from silvershop-suite/store-locations: the slot is then only offered on days the
 * store is open (its exceptions, holidays and temporary closures apply), and in pickup mode only for orders
 * collected at that store. A slot without a store stays available everywhere.
 *
 * Applied only when store-locations is installed (see _config/delivery-scheduling.yml).
 *
 * @property int $StoreLocationID
 * @method StoreLocation StoreLocation()
 * @extends Extension<DeliverySlot>
 */
class DeliverySlotStoreLocationExtension extends Extension
{
    private static array $has_one = [
        'StoreLocation' => StoreLocation::class,
    ];

    /**
     * The store this slot belongs to, or null when it applies to every store.
     */
    public function SlotStoreLocation(): ?StoreLocation
    {
        $location = $this->getOwner()->StoreLocation();

        return $location->exists() ? $location : null;
    }

    public function updateCMSFields(FieldList $fields): void
    {
        $fields->dataFieldByName('StoreLocationID')?->setDescription(_t(
            self::class . '.StoreLocationDesc',
            'Optional. A slot for one store is only offered on days that store is open, and for pickup only at that '
            . 'store. Leave empty for a slot that applies everywhere.'
        ));
    }

    /**
     * @param array<string, string> $fields
     */
    public function updateSummaryFields(array &$fields): void
    {
        $fields['StoreLocation.Title'] = _t(DeliverySlot::class . '.has_one_StoreLocation', 'Store');
    }
}
