<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Tests;

use SilverStripe\SiteConfig\SiteConfig;

/**
 * Sets the Settings → Shop delivery-date fields for a test.
 */
trait UsesScheduleSettings
{
    /**
     * @param array<string, mixed> $fields e.g. ['DeliveryScheduleMode' => 'date', 'DeliveryScheduleLeadDays' => 3]
     */
    protected function scheduleSettings(array $fields): void
    {
        $config = SiteConfig::current_site_config();
        foreach ($fields as $name => $value) {
            $config->setField($name, $value);
        }
        $config->write();
    }
}
