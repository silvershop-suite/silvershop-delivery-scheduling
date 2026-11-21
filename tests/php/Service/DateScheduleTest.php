<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Tests\Service;

use SilverShop\DeliveryScheduling\Model\DeliverySlot;
use SilverShop\DeliveryScheduling\Service\SlotAvailabilityService;
use SilverShop\DeliveryScheduling\Tests\UsesScheduleSettings;
use SilverShop\StoreLocations\Forms\WeeklyHoursField;
use SilverShop\StoreLocations\Hours\OpeningCalendar;
use SilverShop\StoreLocations\Model\OpeningException;
use SilverShop\StoreLocations\Model\StoreLocation;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * v2 date rules: date-only mode, preparation time in working days, the cutoff, days ahead, closing days and a pickup
 * store's own open days, plus the server-side feasibility check.
 *
 * Reference: Monday 2026-01-05 09:00, Europe/Amsterdam.
 */
class DateScheduleTest extends SapphireTest
{
    use UsesScheduleSettings;

    protected $usesDatabase = true;

    private string $previousTimezone = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Amsterdam');
        if (class_exists(OpeningCalendar::class)) {
            OpeningCalendar::singleton()->reset();
        }
        $this->scheduleSettings([
            'DeliveryScheduleMode' => 'date',
            'DeliveryScheduleHorizonDays' => 7,
            'DeliveryScheduleLeadDays' => 0,
            'DeliveryScheduleCutoff' => null,
            'DeliveryScheduleWorkingDays' => '',
        ]);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimezone);
        parent::tearDown();
    }

    private function at(string $datetime): DBDatetime
    {
        $now = DBDatetime::create();
        $now->setValue($datetime);

        return $now;
    }

    /**
     * @return string[]
     */
    private function dates(string $now = '2026-01-05 09:00:00', ?StoreLocation $store = null): array
    {
        $mode = $store !== null ? DeliverySlot::MODE_PICKUP : DeliverySlot::MODE_DELIVERY;
        $out = [];
        foreach (SlotAvailabilityService::create()->windowsFor($mode, $store, $this->at($now)) as $row) {
            $this->assertSame(0, (int) $row->SlotID, 'date-only choices have no slot');
            $out[] = (string) $row->DateYmd;
        }

        return $out;
    }

    private function requireStoreLocations(): void
    {
        if (!class_exists(StoreLocation::class)) {
            $this->markTestSkipped('silvershop-suite/store-locations is not installed.');
        }
    }

    public function testDateOnlyOffersTheWorkingDaysWithinTheDaysAhead(): void
    {
        $this->assertSame(
            ['2026-01-05', '2026-01-06', '2026-01-07', '2026-01-08', '2026-01-09', '2026-01-12'],
            $this->dates(),
            'Monday to Friday, today + 7 days, no weekend'
        );
    }

    public function testDateOnlyNeedsNoSlots(): void
    {
        $this->assertSame(0, DeliverySlot::get()->count());
        $this->assertNotSame([], $this->dates());
    }

    public function testPreparationTimeCountsWorkingDays(): void
    {
        $this->scheduleSettings(['DeliveryScheduleLeadDays' => 3]);

        $this->assertSame('2026-01-08', $this->dates()[0], 'Monday + 3 working days = Thursday');
        $this->assertSame('2026-01-14', $this->dates('2026-01-09 10:00:00')[0], 'Friday + 3 skips the weekend: Wednesday');
    }

    public function testAfterTheCutoffTheOrderCountsFromTheNextDay(): void
    {
        $this->scheduleSettings(['DeliveryScheduleLeadDays' => 3, 'DeliveryScheduleCutoff' => '16:00:00']);

        $this->assertSame('2026-01-08', $this->dates('2026-01-05 15:59:00')[0], 'before 16:00: Thursday');
        $this->assertSame('2026-01-09', $this->dates('2026-01-05 16:00:00')[0], 'from 16:00: Friday (4 working days)');
    }

    public function testCutoffWithoutPreparationTimeDropsToday(): void
    {
        $this->scheduleSettings(['DeliveryScheduleCutoff' => '08:00:00']);

        $this->assertSame('2026-01-06', $this->dates()[0]);
    }

    public function testConfiguredWorkingDays(): void
    {
        $this->scheduleSettings([
            'DeliveryScheduleWorkingDays' => json_encode(['2', '4', '6']),
            'DeliveryScheduleLeadDays' => 1,
        ]);

        // Working days Tue/Thu/Sat: Monday + 1 working day = Tuesday; then only those weekdays, up to Monday 12th.
        $this->assertSame(['2026-01-06', '2026-01-08', '2026-01-10'], $this->dates());
    }

    public function testAGlobalClosingDayIsNotOfferedAndNotCounted(): void
    {
        $this->requireStoreLocations();
        OpeningException::create(['StoreLocationID' => 0, 'DateFrom' => '2026-01-06', 'Type' => 'Closed'])->write();

        $this->assertNotContains('2026-01-06', $this->dates());

        $this->scheduleSettings(['DeliveryScheduleLeadDays' => 3]);
        $this->assertSame('2026-01-09', $this->dates()[0], 'Tuesday closed: Wed, Thu, Fri');
    }

    public function testPickupFollowsTheStoresOpenDays(): void
    {
        $this->requireStoreLocations();
        $store = StoreLocation::create([
            'Title' => 'High Street',
            'Timezone' => 'Europe/Amsterdam',
            'RegularHours' => WeeklyHoursField::encode([
                1 => '09:00-18:00', 2 => '09:00-18:00', 3 => '09:00-18:00',
                4 => '09:00-18:00', 5 => '09:00-18:00', 6 => '09:00-17:00',
            ]),
        ]);
        $store->write();
        OpeningException::create(['StoreLocationID' => $store->ID, 'DateFrom' => '2026-01-08', 'Type' => 'Closed'])->write();

        // Open Saturday, closed Sunday and on its own closing day (Thursday).
        $this->assertSame(
            ['2026-01-05', '2026-01-06', '2026-01-07', '2026-01-09', '2026-01-10', '2026-01-12'],
            $this->dates('2026-01-05 09:00:00', $store)
        );

        // Working days are the store's open days: Monday + 3 = Tue, Wed, (Thu closed) Fri.
        $this->scheduleSettings(['DeliveryScheduleLeadDays' => 3]);
        OpeningCalendar::singleton()->reset();
        $this->assertSame('2026-01-09', $this->dates('2026-01-05 09:00:00', $store)[0]);
    }

    public function testFeasibilityRefusesWhatIsNotOffered(): void
    {
        $service = SlotAvailabilityService::create();
        $now = $this->at('2026-01-05 09:00:00');

        $this->assertTrue($service->isFeasible('0|2026-01-06', DeliverySlot::MODE_DELIVERY, null, $now));
        $this->assertFalse($service->isFeasible('0|2026-01-10', DeliverySlot::MODE_DELIVERY, null, $now), 'Saturday');
        $this->assertFalse($service->isFeasible('0|2026-01-02', DeliverySlot::MODE_DELIVERY, null, $now), 'in the past');
        $this->assertFalse($service->isFeasible('0|2026-02-02', DeliverySlot::MODE_DELIVERY, null, $now), 'too far ahead');
        $this->assertTrue($service->isFeasible('asap', DeliverySlot::MODE_DELIVERY, null, $now));
    }

    public function testSlotModeHonoursThePreparationTimeToo(): void
    {
        $this->scheduleSettings(['DeliveryScheduleMode' => 'slots', 'DeliveryScheduleLeadDays' => 2, 'DeliveryScheduleHorizonDays' => 14]);
        $monday = DeliverySlot::create(['Weekday' => 1, 'StartTime' => '17:00:00', 'EndTime' => '18:00:00', 'Enabled' => 1]);
        $monday->write();
        $wednesday = DeliverySlot::create(['Weekday' => 3, 'StartTime' => '17:00:00', 'EndTime' => '18:00:00', 'Enabled' => 1]);
        $wednesday->write();

        $values = [];
        foreach (SlotAvailabilityService::create()->windowsFor(DeliverySlot::MODE_DELIVERY, null, $this->at('2026-01-05 09:00:00')) as $row) {
            $values[] = (string) $row->Value;
        }

        $this->assertNotContains($monday->ID . '|2026-01-05', $values, 'today is within the 2 working days');
        $this->assertContains($wednesday->ID . '|2026-01-07', $values, 'Monday + 2 working days = Wednesday');
        $this->assertContains($monday->ID . '|2026-01-12', $values);
    }
}
