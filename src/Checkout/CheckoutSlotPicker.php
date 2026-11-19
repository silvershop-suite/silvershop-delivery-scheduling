<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Checkout;

use SilverShop\DeliveryScheduling\Model\DeliverySlot;
use SilverShop\DeliveryScheduling\Service\SlotAvailabilityService;
use SilverShop\DeliveryScheduling\Support\OptionalCall;
use SilverShop\Model\Order;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\OptionsetField;

/**
 * Builds the date + slot picker for the checkout shipping-method step and persists the chosen window onto the
 * order. Kept as a pure, framework-only helper (no shipping dependency) so both halves are unit-testable
 * without a live checkout: {@link fieldsFor()} builds the fields from an order, {@link applyToOrder()} reads
 * the submitted value back.
 *
 * v1 renders one JS-free OptionsetField whose options are the feasible (date, slot) windows (full / blackout
 * windows are simply absent), optionally preceded by an "as soon as possible" choice. A dynamic calendar-style
 * date picker that filters slots per day is a v2 storefront (Fable) enhancement.
 */
class CheckoutSlotPicker
{
    /** The single checkout field name holding the chosen window token (or the ASAP sentinel). */
    public const FIELD = 'DeliveryScheduleSlot';

    /** Sentinel option value meaning "as soon as possible" (no specific numbered slot). */
    public const ASAP = 'asap';

    /**
     * The fulfilment mode to offer windows for. Pickup wins when the order is a click-and-collect pickup, or
     * when silvershop-suite/shippability reports pickup-only lines; otherwise both delivery and pickup windows show
     * (the method itself is chosen on the same form). The optional sibling accessors are reached through
     * {@see OptionalCall} so they resolve whether or not those modules are installed.
     */
    public static function modeFor(Order $order): string
    {
        // A click-and-collect pickup order, or a cart with shippability pickup-only lines, forces pickup.
        if ((bool) OptionalCall::invoke($order, 'IsPickup', false)) {
            return DeliverySlot::MODE_PICKUP;
        }

        if ((bool) OptionalCall::invoke($order, 'HasPickupOnlyItems', false)) {
            return DeliverySlot::MODE_PICKUP;
        }

        return DeliverySlot::MODE_BOTH;
    }

    /**
     * The picker fields for an order, or an empty list when scheduling is switched off.
     */
    public static function fieldsFor(Order $order): FieldList
    {
        $fields = FieldList::create();

        $service = SlotAvailabilityService::create($order);
        if (!$service->isSchedulingEnabled()) {
            return $fields;
        }

        $mode = self::modeFor($order);

        $source = [];
        if ($service->isAsapEnabled()) {
            $source[self::ASAP] = _t(self::class . '.Asap', 'As soon as possible');
        }

        foreach ($service->windows($mode) as $window) {
            $label = (string) $window->Label;
            $left = (int) $window->CapacityLeft;
            if ($left >= 0) {
                $label .= ' (' . _t(self::class . '.Left', '{count} left', ['count' => $left]) . ')';
            }
            $source[(string) $window->Value] = $label;
        }

        if ($source === []) {
            // Nothing bookable and no ASAP: never block checkout — explain and let the order through.
            $fields->push(LiteralField::create(
                self::FIELD . '_None',
                '<p class="message warning">' . Convert::raw2xml(_t(
                    self::class . '.NoWindows',
                    'No delivery or pickup windows are currently available; we will contact you to arrange timing.'
                )) . '</p>'
            ));

            return $fields;
        }

        $field = OptionsetField::create(
            self::FIELD,
            _t(self::class . '.Legend', 'Choose a delivery / pickup time'),
            $source,
            array_key_first($source)
        );

        $fields->push($field);

        return $fields;
    }

    /**
     * Persist the submitted choice onto the order's scheduling fields. The caller writes the order afterwards.
     * Only the enumerated (feasible) options can reach here; an unrecognised value leaves scheduling untouched.
     *
     * @param array<string, mixed> $data
     */
    public static function applyToOrder(Order $order, array $data): void
    {
        if (!array_key_exists(self::FIELD, $data)) {
            return;
        }

        $choice = (string) $data[self::FIELD];

        if ($choice === self::ASAP) {
            $order->setField('IsAsap', true);
            $order->setField('DeliverySlotID', 0);
            $order->setField('RequestedDate', self::earliestAsapDate($order));

            return;
        }

        if (preg_match('/^(\d+)\|(\d{4}-\d{2}-\d{2})$/', $choice, $matches) === 1) {
            $order->setField('IsAsap', false);
            $order->setField('DeliverySlotID', (int) $matches[1]);
            $order->setField('RequestedDate', $matches[2]);
        }
    }

    private static function earliestAsapDate(Order $order): string
    {
        $window = SlotAvailabilityService::create($order)->asapWindow(self::modeFor($order));

        return $window !== null ? (string) $window->DateYmd : date('Y-m-d');
    }
}
