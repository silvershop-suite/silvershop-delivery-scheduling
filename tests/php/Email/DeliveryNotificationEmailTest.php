<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Tests\Email;

use SilverShop\DeliveryScheduling\Email\DeliveryNotificationEmail;
use SilverShop\Model\Order;
use SilverStripe\Control\Email\Email;
use SilverStripe\Dev\SapphireTest;

/**
 * The scheduling emails: recipient, the stamped notification-key / order-id headers the log reads, and the
 * best-effort send behaviour (mirrors click-and-collect's pickup email).
 */
class DeliveryNotificationEmailTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        Email::config()->set('admin_email', 'shop@example.com');
    }

    public function testScheduledStampsNotificationHeaders(): void
    {
        $order = Order::create(['Email' => 'buyer@example.com', 'IsAsap' => 1]);
        $order->write();

        $email = DeliveryNotificationEmail::create_for($order, DeliveryNotificationEmail::KEY_SCHEDULED);
        $headers = $email->getHeaders();

        $this->assertSame('delivery.scheduled', $headers->get('X-SilverShop-Notification-Key')->getBodyAsString());
        $this->assertSame((string) $order->ID, $headers->get('X-SilverShop-Order-ID')->getBodyAsString());
        $this->assertSame('buyer@example.com', $email->getTo()[0]->getAddress());
    }

    public function testOutForDeliveryStampsNotificationHeaders(): void
    {
        $order = Order::create(['Email' => 'buyer@example.com', 'IsAsap' => 1]);
        $order->write();

        $email = DeliveryNotificationEmail::create_for($order, DeliveryNotificationEmail::KEY_OUT_FOR_DELIVERY);
        $headers = $email->getHeaders();

        $this->assertSame(
            'delivery.out_for_delivery',
            $headers->get('X-SilverShop-Notification-Key')->getBodyAsString()
        );
    }

    public function testSendScheduledRecordsViaMailer(): void
    {
        $order = Order::create(['Email' => 'buyer@example.com', 'IsAsap' => 1]);
        $order->write();

        $this->assertTrue(DeliveryNotificationEmail::send_scheduled($order));
        $this->assertEmailSent('buyer@example.com');
    }

    public function testSendOutForDeliveryRecordsViaMailer(): void
    {
        $order = Order::create(['Email' => 'buyer@example.com', 'IsAsap' => 1]);
        $order->write();

        $this->assertTrue(DeliveryNotificationEmail::send_out_for_delivery($order));
        $this->assertEmailSent('buyer@example.com');
    }

    public function testSendWithNoRecipientReturnsFalse(): void
    {
        $order = Order::create(['IsAsap' => 1]);
        $order->write();

        $this->assertFalse(DeliveryNotificationEmail::send_scheduled($order), 'No recipient means nothing is sent');
    }

    public function testSendDoesNotThrowWithNoConfiguredSender(): void
    {
        Email::config()->set('admin_email', null);

        $order = Order::create(['Email' => 'buyer@example.com', 'IsAsap' => 1]);
        $order->write();

        $this->assertTrue(DeliveryNotificationEmail::send_scheduled($order));
        $this->assertEmailSent('buyer@example.com');
    }
}
