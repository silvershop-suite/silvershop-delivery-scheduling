<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Tests\Checkout;

use SilverShop\DeliveryScheduling\Checkout\CheckoutSlotPicker;
use SilverShop\DeliveryScheduling\Forms\SchedulePickerField;
use SilverShop\DeliveryScheduling\Model\DeliverySlot;
use SilverShop\DeliveryScheduling\Tests\Stub\TestPickupMethod;
use SilverShop\DeliveryScheduling\Tests\UsesScheduleSettings;
use SilverShop\Model\Order;
use SilverShop\Shipping\Model\ShippingMethod;
use SilverShop\StoreLocations\Forms\WeeklyHoursField;
use SilverShop\StoreLocations\Model\OpeningException;
use SilverShop\StoreLocations\Model\StoreLocation;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * The checkout field builder + the submit-value persistence, both exercised without a live shipping checkout.
 */
class CheckoutSlotPickerTest extends SapphireTest
{
    use UsesScheduleSettings;

    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        TestPickupMethod::class,
    ];

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
        $this->scheduleSettings(['DeliveryScheduleAsap' => false]);
        // No slots created at all.

        $fields = CheckoutSlotPicker::fieldsFor(Order::create());

        $this->assertNull($fields->dataFieldByName(CheckoutSlotPicker::FIELD));
        $this->assertNotNull($fields->fieldByName(CheckoutSlotPicker::FIELD . '_None'));
    }

    public function testSchedulingDisabledReturnsNoFields(): void
    {
        $this->scheduleSettings(['DeliveryScheduleEnabled' => false]);
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

    public function testApplyRefusesAWindowThatIsNotBookable(): void
    {
        // Audit M3: a crafted POST must not store a disabled, past or full window.
        $slot = $this->mondaySlot();
        $disabled = DeliverySlot::create(['Weekday' => 2, 'StartTime' => '10:00:00', 'Enabled' => 0]);
        $disabled->write();
        $full = DeliverySlot::create(['Weekday' => 3, 'StartTime' => '10:00:00', 'Enabled' => 1, 'Capacity' => 1]);
        $full->write();
        Order::create(['Status' => 'Paid', 'DeliverySlotID' => $full->ID, 'RequestedDate' => '2026-01-07'])->write();

        foreach ([$disabled->ID . '|2026-01-06', $slot->ID . '|2025-12-29', $full->ID . '|2026-01-07', $slot->ID . '|2026-01-06'] as $token) {
            $order = Order::create();
            $this->assertFalse(CheckoutSlotPicker::applyToOrder($order, [CheckoutSlotPicker::FIELD => $token]), $token);
            $this->assertSame(0, (int) $order->DeliverySlotID, $token);
            $this->assertEmpty($order->RequestedDate, $token);
        }
    }

    public function testApplyStoresADateOnlyChoice(): void
    {
        $this->scheduleSettings(['DeliveryScheduleMode' => 'date']);
        $order = Order::create();

        $this->assertTrue(CheckoutSlotPicker::applyToOrder($order, [CheckoutSlotPicker::FIELD => '0|2026-01-06']));
        $this->assertSame(0, (int) $order->DeliverySlotID);
        $this->assertSame('2026-01-06', $order->RequestedDate);
        $this->assertTrue($order->HasSchedule());

        $weekend = Order::create();
        $this->assertFalse(CheckoutSlotPicker::applyToOrder($weekend, [CheckoutSlotPicker::FIELD => '0|2026-01-10']), 'Saturday');
    }

    public function testDateOnlyFieldShowsDatesWithoutTimes(): void
    {
        $this->scheduleSettings(['DeliveryScheduleMode' => 'date', 'DeliveryScheduleAsap' => false]);

        $field = CheckoutSlotPicker::fieldsFor(Order::create())->dataFieldByName(CheckoutSlotPicker::FIELD);

        $this->assertInstanceOf(SchedulePickerField::class, $field);
        $this->assertArrayHasKey('0|2026-01-05', $field->getSource());
        $this->assertArrayNotHasKey('0|2026-01-10', $field->getSource(), 'Saturday is not a working day');
        $this->assertSame('0|2026-01-05', $field->getValue(), 'the earliest date is preselected');

        $keys = array_map('strval', array_keys($field->getSource()));
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys, 'dates are listed in date order');
    }

    public function testTheSinglePageComponentChecksTheValueInsteadOfTheField(): void
    {
        $this->scheduleSettings(['DeliveryScheduleMode' => 'date']);
        $component = \SilverShop\DeliveryScheduling\Checkout\DeliveryScheduleCheckoutComponent::create();
        $order = Order::create();

        $field = $component->getFormFields($order)->dataFieldByName(CheckoutSlotPicker::FIELD);
        $field->setValue('0|2026-01-10');
        $this->assertTrue($field->validate()->isValid() === false, 'a value outside the list is still refused');

        $this->assertTrue($component->validateData($order, [CheckoutSlotPicker::FIELD => '0|2026-01-06']));
        $this->expectException(\SilverStripe\Core\Validation\ValidationException::class);
        $component->validateData($order, [CheckoutSlotPicker::FIELD => '0|2026-01-10']);
    }

    public function testFieldChecksTheValueAgainstTheMethodChosenOnTheSameForm(): void
    {
        $field = SchedulePickerField::create(CheckoutSlotPicker::FIELD, 'When', [
            '0|2026-01-06' => 'Tue',
            '0|2026-01-07' => 'Wed',
        ])
            ->setOptionContexts(['0|2026-01-06' => ['pickup-1'], '0|2026-01-07' => ['pickup-2']])
            ->setMethodContexts([10 => 'pickup-1', 20 => 'pickup-2'])
            ->setDefaultContext('pickup-1');
        $form = Form::create(null, 'Form', FieldList::create(
            OptionsetField::create('ShippingMethodID', 'Method', [10 => 'High Street', 20 => 'Riverside']),
            $field
        ), FieldList::create());

        $form->loadDataFrom(['ShippingMethodID' => 20, CheckoutSlotPicker::FIELD => '0|2026-01-06']);
        $this->assertFalse($field->validate()->isValid(), "another store's date is refused");

        $form->loadDataFrom(['ShippingMethodID' => 20, CheckoutSlotPicker::FIELD => '0|2026-01-07']);
        $this->assertTrue($field->validate()->isValid());

        $form->loadDataFrom(['ShippingMethodID' => 10, CheckoutSlotPicker::FIELD => '0|2099-01-01']);
        $this->assertFalse($field->validate()->isValid(), 'a value outside the list is refused');
    }

    public function testChoiceIsCheckedAgainstTheChosenPickupStore(): void
    {
        if (!class_exists(ShippingMethod::class) || !class_exists(StoreLocation::class)) {
            $this->markTestSkipped('needs silvershop/shipping and silvershop-suite/store-locations');
        }
        Config::modify()->set(CheckoutSlotPicker::class, 'pickup_method_classes', [TestPickupMethod::class]);
        $this->scheduleSettings(['DeliveryScheduleMode' => 'date']);

        $hours = WeeklyHoursField::encode([1 => '09:00-18:00', 2 => '09:00-18:00', 3 => '09:00-18:00']);
        $highStreet = StoreLocation::create(['Title' => 'High Street', 'Timezone' => 'Europe/Amsterdam', 'RegularHours' => $hours]);
        $highStreet->write();
        $riverside = StoreLocation::create(['Title' => 'Riverside', 'Timezone' => 'Europe/Amsterdam', 'RegularHours' => $hours]);
        $riverside->write();
        OpeningException::create(['StoreLocationID' => $riverside->ID, 'DateFrom' => '2026-01-06', 'Type' => 'Closed'])->write();

        $atHighStreet = TestPickupMethod::create(['Name' => 'Collect at High Street', 'TestStoreID' => $highStreet->ID]);
        $atHighStreet->write();
        $atRiverside = TestPickupMethod::create(['Name' => 'Collect at Riverside', 'TestStoreID' => $riverside->ID]);
        $atRiverside->write();

        $context = CheckoutSlotPicker::contextForMethod($atRiverside);
        $this->assertSame('pickup-' . $riverside->ID, $context['key']);

        $order = Order::create();
        $this->assertTrue(CheckoutSlotPicker::isChoiceValid($order, '0|2026-01-06', (int) $atHighStreet->ID));
        $this->assertFalse(CheckoutSlotPicker::isChoiceValid($order, '0|2026-01-06', (int) $atRiverside->ID), 'Riverside is closed that day');
    }
}
