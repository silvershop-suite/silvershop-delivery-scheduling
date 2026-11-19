<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Email;

use Psr\Log\LoggerInterface;
use SilverShop\DeliveryScheduling\Support\OptionalCall;
use SilverShop\Extension\ShopConfigExtension;
use SilverShop\Model\Order;
use SilverStripe\Control\Director;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\SiteConfig\SiteConfig;
use Throwable;

/**
 * Builds + sends the two scheduling notifications:
 *   - delivery.scheduled        — confirms the chosen window when the order is placed.
 *   - delivery.out_for_delivery — tells the buyer their order is on its way.
 *
 * Self-contained styling (its own inline-safe `<style>`), like click-and-collect's pickup email — it does not
 * depend on the core 6.2 email shell, so the module stays robust across core 6.1/6.2.
 *
 * Each message stamps two headers an optional silvershop-suite/notification-log records with zero coupling (the log
 * only READS headers):
 *   X-SilverShop-Order-ID: {OrderID}
 *   X-SilverShop-Notification-Key: delivery.scheduled | delivery.out_for_delivery
 */
class DeliveryNotificationEmail
{
    use Configurable;

    public const KEY_SCHEDULED = 'delivery.scheduled';

    public const KEY_OUT_FOR_DELIVERY = 'delivery.out_for_delivery';

    private static string $scheduled_template = 'SilverShop/DeliveryScheduling/Email/DeliveryScheduledEmail';

    private static string $out_for_delivery_template = 'SilverShop/DeliveryScheduling/Email/OutForDeliveryEmail';

    public static function send_scheduled(Order $order): bool
    {
        return self::send_kind($order, self::KEY_SCHEDULED);
    }

    public static function send_out_for_delivery(Order $order): bool
    {
        return self::send_kind($order, self::KEY_OUT_FOR_DELIVERY);
    }

    /**
     * Build the message for a notification kind, with the window summary + ASAP flag pulled from the order.
     */
    public static function create_for(Order $order, string $kind): Email
    {
        $isOut = $kind === self::KEY_OUT_FOR_DELIVERY;

        $template = (string) self::config()->get($isOut ? 'out_for_delivery_template' : 'scheduled_template');

        $from = ShopConfigExtension::config()->get('email_from')
            ?: Email::config()->get('admin_email')
            ?: ('no-reply@' . self::senderHost());
        $to = (string) $order->getLatestEmail();

        $reference = $order->exists() ? (string) $order->Reference : '';
        $subject = $isOut
            ? _t(self::class . '.SubjectOut', 'Your order #{OrderNo} is on its way', '', ['OrderNo' => $reference])
            : _t(self::class . '.SubjectScheduled', 'Your order #{OrderNo} is scheduled', '', ['OrderNo' => $reference]);

        $config = SiteConfig::current_site_config();
        $storeName = $config->hasMethod('StoreProfileName')
            ? (string) $config->StoreProfileName()
            : (string) $config->Title;
        $storeAddress = $config->hasMethod('StoreFormattedAddress')
            ? (string) $config->StoreFormattedAddress(true)
            : '';

        $window = (string) OptionalCall::invoke($order, 'SelectedWindowSummary', '');
        $isAsap = (bool) OptionalCall::invoke($order, 'IsAsapDelivery', false);

        $email = Email::create()
            ->setHTMLTemplate($template)
            ->setPlainTemplate($template . 'Plain')
            ->setFrom($from)
            ->setTo($to)
            ->setSubject($subject);

        $email->setData([
            'Order' => $order,
            'StoreName' => $storeName,
            'StoreAddress' => $storeAddress,
            'WindowSummary' => $window,
            'IsAsap' => $isAsap,
            'BaseURL' => Director::absoluteBaseURL(),
        ]);

        $headers = $email->getHeaders();
        $headers->addTextHeader('X-SilverShop-Order-ID', (string) (int) $order->ID);
        $headers->addTextHeader('X-SilverShop-Notification-Key', $kind);

        return $email;
    }

    /**
     * Best-effort send: a missing recipient is skipped (false); a transport failure is logged, never fatal — a
     * failed notification must not break the order write that triggered it.
     */
    private static function send_kind(Order $order, string $kind): bool
    {
        if (!$order->exists() || trim((string) $order->getLatestEmail()) === '') {
            return false;
        }

        try {
            self::create_for($order, $kind)->send();
        } catch (Throwable $throwable) {
            Injector::inst()->get(LoggerInterface::class)->error(
                'DeliveryNotificationEmail: failed to send ' . $kind . ' for order '
                . (int) $order->ID . ': ' . $throwable->getMessage()
            );

            return false;
        }

        return true;
    }

    /**
     * Host for the synthetic no-reply fallback sender, used only when neither ShopConfig.email_from nor
     * Email.admin_email is set (so setFrom never receives an empty address).
     */
    private static function senderHost(): string
    {
        $host = parse_url((string) Director::absoluteBaseURL(), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'localhost';
    }
}
