<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Checkout;

use SilverShop\DeliveryScheduling\Forms\SchedulePickerField;
use SilverShop\DeliveryScheduling\Model\DeliverySlot;
use SilverShop\DeliveryScheduling\Service\SlotAvailabilityService;
use SilverShop\DeliveryScheduling\Support\OptionalCall;
use SilverShop\Model\Order;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;

/**
 * Builds the date (and slot) picker for the checkout and persists the chosen value onto the order. Framework-only
 * (no shipping dependency), so both halves are unit-testable without a live checkout: {@link fieldsFor()} builds the
 * field from an order, {@link applyToOrder()} checks and stores the submitted value.
 *
 * A "context" is what the choices depend on: delivery, pickup, or pickup at one store
 * (silvershop-suite/click-and-collect + store-locations). The shipping method is chosen on the same form, so the
 * field carries the choices of every available method's context and shows the selected method's ones.
 */
class CheckoutSlotPicker
{
    use Configurable;

    /** The single checkout field name holding the chosen token (or the ASAP sentinel). */
    public const FIELD = 'DeliveryScheduleSlot';

    /** Sentinel option value meaning "as soon as possible" (no specific date). */
    public const ASAP = 'asap';

    /** Context key when nothing narrows the choice (no shipping method known). */
    public const CONTEXT_ANY = 'any';

    public const CONTEXT_DELIVERY = 'delivery';

    public const CONTEXT_PICKUP = 'pickup';

    /**
     * Shipping method classes that mean "collect in store". A pickup method may link a store through a
     * PickupStoreLocation() accessor (click-and-collect with store-locations).
     *
     * @var string[]
     */
    private static array $pickup_method_classes = [
        'SilverShop\ClickAndCollect\Model\PickupShippingMethod',
    ];

    private const SHIPPING_METHOD_CLASS = 'SilverShop\Shipping\Model\ShippingMethod';

    /**
     * The fulfilment mode the order itself implies. Pickup wins when the order is a click-and-collect pickup, or when
     * silvershop-suite/shippability reports pickup-only lines; otherwise both delivery and pickup choices apply.
     */
    public static function modeFor(Order $order): string
    {
        if ((bool) OptionalCall::invoke($order, 'IsPickup', false)) {
            return DeliverySlot::MODE_PICKUP;
        }

        if ((bool) OptionalCall::invoke($order, 'HasPickupOnlyItems', false)) {
            return DeliverySlot::MODE_PICKUP;
        }

        return DeliverySlot::MODE_BOTH;
    }

    /**
     * The context of a shipping method: pickup (at its store, when linked) or delivery.
     *
     * @return array{key: string, mode: string, store: ?DataObject}
     */
    public static function contextForMethod(DataObject $method, ?Order $order = null): array
    {
        $pickupOnly = $order !== null && (bool) OptionalCall::invoke($order, 'HasPickupOnlyItems', false);
        if (!self::isPickupMethod($method)) {
            return $pickupOnly
                ? ['key' => self::CONTEXT_PICKUP, 'mode' => DeliverySlot::MODE_PICKUP, 'store' => null]
                : ['key' => self::CONTEXT_DELIVERY, 'mode' => DeliverySlot::MODE_DELIVERY, 'store' => null];
        }

        $store = OptionalCall::invoke($method, 'PickupStoreLocation');
        if ($store instanceof DataObject && $store->exists()) {
            return self::pickupContext($store);
        }

        return ['key' => self::CONTEXT_PICKUP, 'mode' => DeliverySlot::MODE_PICKUP, 'store' => null];
    }

    /**
     * The context of the order as it stands: its shipping method's context, else what the order implies.
     *
     * @return array{key: string, mode: string, store: ?DataObject}
     */
    public static function orderContext(Order $order): array
    {
        $method = self::shippingMethod((int) $order->getField('ShippingMethodID'));
        if ($method !== null) {
            return self::contextForMethod($method, $order);
        }

        if (self::modeFor($order) === DeliverySlot::MODE_PICKUP) {
            $store = SlotAvailabilityService::create($order)->pickupStore();

            return $store !== null
                ? self::pickupContext($store)
                : ['key' => self::CONTEXT_PICKUP, 'mode' => DeliverySlot::MODE_PICKUP, 'store' => null];
        }

        return ['key' => self::CONTEXT_ANY, 'mode' => DeliverySlot::MODE_BOTH, 'store' => null];
    }

    /**
     * Every context the form can end up in: one per available shipping method, plus the order's own.
     *
     * @return array{
     *     contexts: array<string, array{key: string, mode: string, store: ?DataObject}>,
     *     methods: array<int, string>,
     *     default: string
     * }
     */
    public static function contextsFor(Order $order): array
    {
        $contexts = [];
        $methods = [];

        foreach (self::availableMethods($order) as $method) {
            $context = self::contextForMethod($method, $order);
            $contexts[$context['key']] = $context;
            $methods[(int) $method->ID] = $context['key'];
        }

        // The default is the order's own context; a shipping-method field on the same form overrides it (in the
        // browser and on submit), so a form without one still gets the choices that match the order.
        $own = self::orderContext($order);
        $contexts[$own['key']] = $own;

        return ['contexts' => $contexts, 'methods' => $methods, 'default' => $own['key']];
    }

    /**
     * The picker field for an order, a notice when nothing is bookable, or nothing when scheduling is switched off.
     * Pass $fieldChecksContext = false when the caller checks the submitted value itself.
     */
    public static function fieldsFor(Order $order, bool $fieldChecksContext = true): FieldList
    {
        $fields = FieldList::create();

        $service = SlotAvailabilityService::create($order);
        if (!$service->isSchedulingEnabled()) {
            return $fields;
        }

        $setup = self::contextsFor($order);
        $labels = [];
        $optionContexts = [];
        $sortKeys = [];

        if ($service->isAsapEnabled()) {
            $labels[self::ASAP] = _t(self::class . '.Asap', 'As soon as possible');
            $optionContexts[self::ASAP] = array_keys($setup['contexts']);
        }

        foreach ($setup['contexts'] as $key => $context) {
            foreach ($service->windowsFor($context['mode'], $context['store']) as $window) {
                $value = (string) $window->Value;
                if (!isset($labels[$value])) {
                    $slot = $window->Slot;
                    $time = $slot instanceof DeliverySlot ? (string) $slot->StartTime : '';
                    $sortKeys[$value] = $window->DateYmd . ' ' . $time;
                    $label = (string) $window->Label;
                    $left = (int) $window->CapacityLeft;
                    if ($left >= 0) {
                        $label .= ' (' . _t(self::class . '.Left', '{count} left', ['count' => $left]) . ')';
                    }
                    $labels[$value] = $label;
                }
                $optionContexts[$value][] = $key;
            }
        }

        // Choices of different contexts merge into one list: keep it in date (and slot time) order, ASAP first.
        uksort($labels, static function (string|int $a, string|int $b) use ($sortKeys): int {
            return ($sortKeys[(string) $a] ?? '') <=> ($sortKeys[(string) $b] ?? '');
        });

        if ($labels === []) {
            // Nothing bookable and no ASAP: never block checkout. Explain and let the order through.
            $fields->push(LiteralField::create(
                self::FIELD . '_None',
                '<p class="message warning">' . Convert::raw2xml(_t(
                    self::class . '.NoWindows',
                    'No delivery or pickup windows are currently available; we will contact you to arrange timing.'
                )) . '</p>'
            ));

            return $fields;
        }

        $legend = $service->isDateOnly()
            ? _t(self::class . '.LegendDate', 'Choose a delivery / pickup date')
            : _t(self::class . '.Legend', 'Choose a delivery / pickup time');

        $default = null;
        foreach ($optionContexts as $value => $contexts) {
            if (in_array($setup['default'], $contexts, true)) {
                $default = (string) $value;
                break;
            }
        }

        $field = SchedulePickerField::create(self::FIELD, $legend, $labels, $default)
            ->setOptionContexts($optionContexts)
            ->setMethodContexts($setup['methods'])
            ->setDefaultContext($setup['default'])
            ->setChecksContext($fieldChecksContext);

        $fields->push($field);

        return $fields;
    }

    /**
     * Whether a submitted choice is bookable now, for the given shipping method or else the order's own context.
     */
    public static function isChoiceValid(Order $order, string $choice, ?int $shippingMethodId = null): bool
    {
        $method = $shippingMethodId ? self::shippingMethod($shippingMethodId) : null;
        $context = $method !== null ? self::contextForMethod($method, $order) : self::orderContext($order);

        return SlotAvailabilityService::create($order)->isFeasible($choice, $context['mode'], $context['store']);
    }

    /**
     * Check the submitted choice against what is bookable right now and store it on the order (the caller writes the
     * order). A value that is malformed, no longer available, or for another store is not stored. Returns whether it
     * was stored.
     *
     * @param array<string, mixed> $data
     * @param int|null $shippingMethodId the shipping method submitted with the value (the order may not have it yet)
     */
    public static function applyToOrder(Order $order, array $data, ?int $shippingMethodId = null): bool
    {
        if (!array_key_exists(self::FIELD, $data)) {
            return false;
        }

        $choice = (string) $data[self::FIELD];
        $slotId = 0;
        $date = '';
        if ($choice !== self::ASAP) {
            if (preg_match('/^(\d+)\|(\d{4}-\d{2}-\d{2})$/', $choice, $matches) !== 1) {
                return false;
            }
            $slotId = (int) $matches[1];
            $date = $matches[2];
        }
        if (!self::isChoiceValid($order, $choice, $shippingMethodId)) {
            return false;
        }

        $isAsap = $choice === self::ASAP;
        $order->setField('IsAsap', $isAsap);
        $order->setField('DeliverySlotID', $slotId);
        $order->setField('RequestedDate', $isAsap ? self::earliestAsapDate($order, $shippingMethodId) : $date);

        return true;
    }

    private static function earliestAsapDate(Order $order, ?int $shippingMethodId = null): string
    {
        $method = $shippingMethodId ? self::shippingMethod($shippingMethodId) : null;
        $context = $method !== null ? self::contextForMethod($method, $order) : self::orderContext($order);
        $window = SlotAvailabilityService::create($order)->windowsFor($context['mode'], $context['store'])->first();

        return $window !== null ? (string) $window->DateYmd : date('Y-m-d');
    }

    /**
     * @return array{key: string, mode: string, store: ?DataObject}
     */
    private static function pickupContext(DataObject $store): array
    {
        return [
            'key' => self::CONTEXT_PICKUP . '-' . $store->ID,
            'mode' => DeliverySlot::MODE_PICKUP,
            'store' => $store,
        ];
    }

    private static function isPickupMethod(DataObject $method): bool
    {
        foreach ((array) self::config()->get('pickup_method_classes') as $class) {
            if (is_string($class) && $class !== '' && is_a($method, $class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The shipping methods offered to the order (silvershop/shipping), or none.
     *
     * @return DataObject[]
     */
    private static function availableMethods(Order $order): array
    {
        try {
            $estimates = OptionalCall::invoke($order, 'getShippingEstimates');
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        if (is_iterable($estimates)) {
            foreach ($estimates as $method) {
                if ($method instanceof DataObject && $method->ID) {
                    $out[] = $method;
                }
            }
        }

        return $out;
    }

    private static function shippingMethod(int $id): ?DataObject
    {
        if ($id <= 0 || !class_exists(self::SHIPPING_METHOD_CLASS)) {
            return null;
        }
        $method = DataObject::get_by_id(self::SHIPPING_METHOD_CLASS, $id);

        return $method instanceof DataObject ? $method : null;
    }
}
