<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Tests\Extension;

use SilverShop\DeliveryScheduling\Model\DeliverySlot;
use SilverShop\Model\Order;
use SilverStripe\Control\Email\Email;
use SilverStripe\Dev\SapphireTest;

/**
 * The order-level window storage, the chosen-window summary, the scheduled / out-for-delivery mails and the
 * admin Scheduling tab.
 */
class OrderSchedulingExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        Email::config()->set('admin_email', 'shop@example.com');
    }

    private function slot(): DeliverySlot
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

    public function testHasScheduleAndSlotAccessors(): void
    {
        $slot = $this->slot();

        $order = Order::create();
        $this->assertFalse($order->HasSchedule(), 'A fresh order has no schedule');

        $order->RequestedDate = '2026-01-05';
        $order->DeliverySlotID = $slot->ID;
        $order->write();

        $this->assertTrue($order->HasSchedule());
        $this->assertFalse($order->IsAsapDelivery());
        $this->assertInstanceOf(DeliverySlot::class, $order->ScheduledSlot());
        $this->assertSame($slot->ID, $order->ScheduledSlot()->ID);

        $summary = $order->SelectedWindowSummary();
        $this->assertStringContainsString('17:00–18:00', $summary);
        $this->assertStringContainsString('2026', $summary);
    }

    public function testAsapSummary(): void
    {
        $order = Order::create(['IsAsap' => 1]);
        $order->write();

        $this->assertTrue($order->HasSchedule());
        $this->assertTrue($order->IsAsapDelivery());
        $this->assertNull($order->ScheduledSlot());
        $this->assertSame('As soon as possible', $order->SelectedWindowSummary());
    }

    public function testOnPlaceOrderSendsScheduledEmail(): void
    {
        $order = Order::create(['Email' => 'buyer@example.com', 'IsAsap' => 1]);
        $order->write();

        $order->extend('onPlaceOrder');

        $this->assertEmailSent('buyer@example.com');
    }

    public function testOnPlaceOrderIsANoopWithoutSchedule(): void
    {
        $order = Order::create(['Email' => 'buyer@example.com']);
        $order->write();

        // No schedule on the order, so onPlaceOrder short-circuits before sending anything.
        $this->assertFalse($order->HasSchedule());
        $order->extend('onPlaceOrder');
        $this->assertFalse($order->HasSchedule());
    }

    public function testMarkOutForDeliveryTransitionAndEmail(): void
    {
        $order = Order::create(['Email' => 'buyer@example.com', 'IsAsap' => 1]);
        $order->write();

        $this->assertFalse($order->IsOutForDelivery());
        $this->assertTrue($order->markOutForDelivery());
        $this->assertTrue($order->IsOutForDelivery());
        $this->assertEmailSent('buyer@example.com');

        // Idempotent — a second call is a no-op.
        $this->assertFalse($order->markOutForDelivery());
    }

    public function testSchedulingTabAddedToCMSFields(): void
    {
        $order = Order::create(['Email' => 'buyer@example.com', 'IsAsap' => 1]);
        $order->write();

        $fields = $order->getCMSFields();

        $this->assertNotNull($fields->fieldByName('Root.Scheduling'), 'A Scheduling tab is added to the order');
        $this->assertNotNull($fields->dataFieldByName('OutForDelivery'));
    }
}
