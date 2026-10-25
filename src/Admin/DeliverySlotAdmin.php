<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Admin;

use SilverShop\DeliveryScheduling\Model\DeliverySlot;
use SilverStripe\Admin\ModelAdmin;

/**
 * CMS admin for the recurring delivery / pickup windows. Any {@link DeliverySlot} created here is picked up
 * by {@link \SilverShop\DeliveryScheduling\Service\SlotAvailabilityService} at checkout — no extra wiring.
 */
class DeliverySlotAdmin extends ModelAdmin
{
    private static string $url_segment = 'delivery-scheduling';

    private static string $menu_title = 'Delivery scheduling';

    private static string $menu_icon_class = 'font-icon-clock';

    private static array $managed_models = [
        DeliverySlot::class,
    ];
}
