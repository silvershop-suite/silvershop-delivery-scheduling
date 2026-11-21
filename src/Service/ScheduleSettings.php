<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Service;

use SilverShop\DeliveryScheduling\Extension\DeliveryScheduleSiteConfigExtension;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * The delivery / pickup date settings as plain values, read from Settings → Shop
 * ({@see DeliveryScheduleSiteConfigExtension}). The defaults apply when that extension is not applied.
 */
final class ScheduleSettings
{
    public const MODE_SLOTS = 'slots';

    public const MODE_DATE = 'date';

    /** Monday to Friday. */
    public const DEFAULT_WORKING_DAYS = [1, 2, 3, 4, 5];

    /**
     * @param int[] $workingDays ISO weekdays, 1 = Monday ... 7 = Sunday
     */
    public function __construct(
        public readonly bool $enabled = true,
        public readonly string $mode = self::MODE_SLOTS,
        public readonly bool $asap = true,
        public readonly int $leadDays = 0,
        public readonly string $cutoff = '',
        public readonly int $horizonDays = 14,
        public readonly array $workingDays = self::DEFAULT_WORKING_DAYS,
        public readonly int $prepMinutes = 0,
    ) {
    }

    public static function current(): self
    {
        $config = SiteConfig::current_site_config();
        if (!$config->hasExtension(DeliveryScheduleSiteConfigExtension::class)) {
            return new self();
        }

        return new self(
            enabled: (bool) $config->getField('DeliveryScheduleEnabled'),
            mode: (string) $config->getField('DeliveryScheduleMode') === self::MODE_DATE
                ? self::MODE_DATE
                : self::MODE_SLOTS,
            asap: (bool) $config->getField('DeliveryScheduleAsap'),
            leadDays: max(0, (int) $config->getField('DeliveryScheduleLeadDays')),
            cutoff: self::normaliseTime((string) $config->getField('DeliveryScheduleCutoff')),
            horizonDays: max(0, (int) $config->getField('DeliveryScheduleHorizonDays')),
            workingDays: self::parseWeekdays((string) $config->getField('DeliveryScheduleWorkingDays')),
            prepMinutes: max(0, (int) $config->getField('DeliverySchedulePrepMinutes')),
        );
    }

    public function isDateOnly(): bool
    {
        return $this->mode === self::MODE_DATE;
    }

    public function isWorkingWeekday(int $isoWeekday): bool
    {
        return in_array($isoWeekday, $this->workingDays, true);
    }

    /**
     * Weekdays from the checkbox set's JSON list (or a plain "1,2,3" list). Empty or invalid = Monday to Friday.
     *
     * @return int[]
     */
    public static function parseWeekdays(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return self::DEFAULT_WORKING_DAYS;
        }

        $decoded = json_decode($value, true);
        $items = is_array($decoded) ? $decoded : explode(',', $value);

        $days = [];
        foreach ($items as $item) {
            $day = (int) $item;
            if ($day >= 1 && $day <= 7) {
                $days[$day] = $day;
            }
        }
        ksort($days);

        return $days === [] ? self::DEFAULT_WORKING_DAYS : array_values($days);
    }

    /**
     * "16:00" or "16:00:00" as HH:MM:SS; anything else (or empty) is no cutoff.
     */
    public static function normaliseTime(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m) !== 1) {
            return '';
        }

        return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
    }
}
