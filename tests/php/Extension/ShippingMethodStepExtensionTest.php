<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Tests\Extension;

use SilverShop\Cart\ShoppingCart;
use SilverShop\DeliveryScheduling\Checkout\CheckoutSlotPicker;
use SilverShop\DeliveryScheduling\Extension\ShippingMethodStepExtension;
use SilverShop\DeliveryScheduling\Forms\SchedulePickerField;
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
 * The stepped checkout: silvershop/shipping's CheckoutStepShippingMethod builds its form (a "ShippingMethodID"
 * optionset), lets extensions add fields (updateShippingMethodForm), and on submit sets the chosen method on the
 * order BEFORE it calls onSetShippingMethod. These tests follow that order.
 *
 * Reference: Monday 2026-01-05 09:00.
 */
class ShippingMethodStepExtensionTest extends SapphireTest
{
    use UsesScheduleSettings;

    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        TestPickupMethod::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        DBDatetime::set_mock_now('2026-01-05 09:00:00');
        $this->scheduleSettings(['DeliveryScheduleMode' => 'date', 'DeliveryScheduleAsap' => false]);
    }

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    private function cart(): Order
    {
        $order = Order::create();
        $order->write();
        ShoppingCart::singleton()->setCurrent($order);

        return $order;
    }

    /**
     * The form as CheckoutStepShippingMethod::ShippingMethodForm() builds it, before extensions run.
     *
     * @param array<int, string> $methods
     */
    private function stepForm(array $methods = []): Form
    {
        return Form::create(null, 'ShippingMethodForm', FieldList::create(
            OptionsetField::create('ShippingMethodID', 'Shipping Options', $methods)
        ), FieldList::create());
    }

    /**
     * What CheckoutStepShippingMethod::setShippingMethod() does: set the method, then call the hook.
     */
    private function submit(Order $order, string $choice, ?ShippingMethod $method = null): void
    {
        if ($method !== null) {
            $order->setField('ShippingMethodID', $method->ID);
        }
        (new ShippingMethodStepExtension())->onSetShippingMethod($order, [CheckoutSlotPicker::FIELD => $choice]);
    }

    public function testTheStepFormGetsThePicker(): void
    {
        $this->cart();
        $form = $this->stepForm();

        (new ShippingMethodStepExtension())->updateShippingMethodForm($form);

        $field = $form->Fields()->dataFieldByName(CheckoutSlotPicker::FIELD);
        $this->assertInstanceOf(SchedulePickerField::class, $field);
        $this->assertArrayHasKey('0|2026-01-06', $field->getSource());
        $this->assertArrayNotHasKey('0|2026-01-10', $field->getSource(), 'no Saturday');

        $form->loadDataFrom([CheckoutSlotPicker::FIELD => '0|2026-01-10']);
        $this->assertFalse($field->validate()->isValid(), 'a crafted value is refused when the form validates');
        $form->loadDataFrom([CheckoutSlotPicker::FIELD => '0|2026-01-06']);
        $this->assertTrue($field->validate()->isValid());
    }

    public function testWithoutACartTheFormIsLeftAlone(): void
    {
        ShoppingCart::singleton()->clear(false);
        $form = $this->stepForm();

        (new ShippingMethodStepExtension())->updateShippingMethodForm($form);

        $this->assertNull($form->Fields()->dataFieldByName(CheckoutSlotPicker::FIELD));
    }

    public function testSubmittingStoresABookableDateOnly(): void
    {
        $order = $this->cart();

        $this->submit($order, '0|2026-01-10');
        $this->assertSame('', (string) $order->getField('RequestedDate'), 'Saturday is not stored');

        $this->submit($order, '0|2026-01-06');
        $this->assertStringStartsWith('2026-01-06', (string) $order->getField('RequestedDate'));
    }

    public function testSubmittingChecksTheDateAgainstTheChosenPickupStore(): void
    {
        if (!class_exists(StoreLocation::class)) {
            $this->markTestSkipped('needs silvershop-suite/store-locations');
        }
        Config::modify()->set(CheckoutSlotPicker::class, 'pickup_method_classes', [TestPickupMethod::class]);

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

        $order = $this->cart();

        $this->submit($order, '0|2026-01-06', $atRiverside);
        $this->assertSame('', (string) $order->getField('RequestedDate'), 'Riverside is closed that day');

        $this->submit($order, '0|2026-01-06', $atHighStreet);
        $this->assertStringStartsWith('2026-01-06', (string) $order->getField('RequestedDate'));
    }
}
