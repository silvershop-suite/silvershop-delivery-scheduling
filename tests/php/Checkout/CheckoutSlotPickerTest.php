<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Tests\Checkout;

use SilverShop\DeliveryScheduling\Checkout\CheckoutSlotPicker;
use SilverShop\DeliveryScheduling\Model\DeliverySlot;
use SilverShop\DeliveryScheduling\Service\SlotAvailabilityService;
use SilverShop\Model\Order;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * The checkout field builder + the submit-value persistence, both exercised without a live shipping checkout.
 */
class CheckoutSlotPickerTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        // Monday 2026-01-05 09:00, so the default DBDatetime::now() inside the picker is deterministic.
        DBDatetime::set_mock_now('2026-01-05 09:00:00');
    }

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    private function mondaySlot(): DeliverySlot
    {
        $slot = DeliverySlot::create([
            'Weekday' => 1,
            'StartTime' => '17:00:00',
            'EndTime' => '18:00:00',
            'Mode' => DeliverySlot::MODE_BOTH,
            'Enabled' => 1,
        ]);
        $slot->write();

        return $slot;
    }

    public function testFieldsForBuildsOptionsetWithAsapAndWindows(): void
    {
        $slot = $this->mondaySlot();

        $fields = CheckoutSlotPicker::fieldsFor(Order::create());
        $field = $fields->dataFieldByName(CheckoutSlotPicker::FIELD);

        $this->assertInstanceOf(OptionsetField::class, $field);
        $source = $field->getSource();
        $this->assertArrayHasKey(CheckoutSlotPicker::ASAP, $source);
        $this->assertArrayHasKey($slot->ID . '|2026-01-05', $source);
    }

    public function testNoWindowsAndNoAsapShowsNoticeNotOptionset(): void
    {
        Config::modify()->set(SlotAvailabilityService::class, 'asap_enabled', false);
        // No slots created at all.

        $fields = CheckoutSlotPicker::fieldsFor(Order::create());

        $this->assertNull($fields->dataFieldByName(CheckoutSlotPicker::FIELD));
        $this->assertNotNull($fields->fieldByName(CheckoutSlotPicker::FIELD . '_None'));
    }

    public function testSchedulingDisabledReturnsNoFields(): void
    {
        Config::modify()->set(SlotAvailabilityService::class, 'scheduling_enabled', false);
        $this->mondaySlot();

        $this->assertSame(0, CheckoutSlotPicker::fieldsFor(Order::create())->count());
    }

    public function testApplyAsap(): void
    {
        $this->mondaySlot();
        $order = Order::create();

        CheckoutSlotPicker::applyToOrder($order, [CheckoutSlotPicker::FIELD => CheckoutSlotPicker::ASAP]);

        $this->assertTrue((bool) $order->IsAsap);
        $this->assertSame(0, (int) $order->DeliverySlotID);
        $this->assertNotEmpty($order->RequestedDate);
    }

    public function testApplySpecificSlot(): void
    {
        $slot = $this->mondaySlot();
        $order = Order::create();

        CheckoutSlotPicker::applyToOrder($order, [CheckoutSlotPicker::FIELD => $slot->ID . '|2026-01-05']);

        $this->assertFalse((bool) $order->IsAsap);
        $this->assertSame($slot->ID, (int) $order->DeliverySlotID);
        $this->assertSame('2026-01-05', $order->RequestedDate);
    }

    public function testApplyUnrecognisedValueLeavesScheduleUntouched(): void
    {
        $order = Order::create();
        CheckoutSlotPicker::applyToOrder($order, [CheckoutSlotPicker::FIELD => 'not-a-valid-token']);

        $this->assertFalse((bool) $order->IsAsap);
        $this->assertSame(0, (int) $order->DeliverySlotID);
        $this->assertEmpty($order->RequestedDate);
    }

    public function testApplyWithNoFieldIsNoop(): void
    {
        $order = Order::create();
        CheckoutSlotPicker::applyToOrder($order, []);

        $this->assertFalse((bool) $order->IsAsap);
        $this->assertSame(0, (int) $order->DeliverySlotID);
    }

    public function testModeForDefaultsToBothWithoutSoftDeps(): void
    {
        $this->assertSame(DeliverySlot::MODE_BOTH, CheckoutSlotPicker::modeFor(Order::create()));
    }
}
