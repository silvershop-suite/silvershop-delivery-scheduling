<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Tests\Service;

use SilverShop\DeliveryScheduling\Service\ScheduleSettings;
use SilverShop\DeliveryScheduling\Tests\UsesScheduleSettings;
use SilverStripe\Dev\SapphireTest;

class ScheduleSettingsTest extends SapphireTest
{
    use UsesScheduleSettings;

    protected $usesDatabase = true;

    public function testDefaultsOfANewSite(): void
    {
        $settings = ScheduleSettings::current();

        $this->assertTrue($settings->enabled);
        $this->assertSame(ScheduleSettings::MODE_SLOTS, $settings->mode);
        $this->assertTrue($settings->asap);
        $this->assertSame(0, $settings->leadDays);
        $this->assertSame('', $settings->cutoff);
        $this->assertSame(14, $settings->horizonDays);
        $this->assertSame([1, 2, 3, 4, 5], $settings->workingDays);
    }

    public function testReadsTheCmsValues(): void
    {
        $this->scheduleSettings([
            'DeliveryScheduleMode' => 'date',
            'DeliveryScheduleLeadDays' => 3,
            'DeliveryScheduleCutoff' => '16:00',
            'DeliveryScheduleHorizonDays' => 7,
            'DeliveryScheduleWorkingDays' => '["1","2","3","4","5","6"]',
        ]);

        $settings = ScheduleSettings::current();
        $this->assertTrue($settings->isDateOnly());
        $this->assertSame(3, $settings->leadDays);
        $this->assertSame('16:00:00', $settings->cutoff);
        $this->assertSame(7, $settings->horizonDays);
        $this->assertSame([1, 2, 3, 4, 5, 6], $settings->workingDays);
    }

    public function testWeekdayParsing(): void
    {
        $this->assertSame([2, 4], ScheduleSettings::parseWeekdays('["4","2"]'));
        $this->assertSame([1, 3], ScheduleSettings::parseWeekdays('1,3'));
        $this->assertSame([1, 2, 3, 4, 5], ScheduleSettings::parseWeekdays(''));
        $this->assertSame([1, 2, 3, 4, 5], ScheduleSettings::parseWeekdays('[9]'), 'nothing valid = default');
    }

    public function testTimeNormalising(): void
    {
        $this->assertSame('08:30:00', ScheduleSettings::normaliseTime('8:30'));
        $this->assertSame('16:00:00', ScheduleSettings::normaliseTime('16:00:00'));
        $this->assertSame('', ScheduleSettings::normaliseTime(''));
        $this->assertSame('', ScheduleSettings::normaliseTime('soon'));
    }
}
