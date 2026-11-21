<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Tests\Service;

use SilverShop\DeliveryScheduling\Model\DeliverySlot;
use SilverShop\DeliveryScheduling\Service\SlotAvailabilityService;
use SilverShop\DeliveryScheduling\Tests\UsesScheduleSettings;
use SilverShop\Model\Order;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * The scheduling brain: window generation, the prep / lead floor, the same-day cutoff, capacity hiding and
 * the mode filter — all driven off an injected "now" so every rule is deterministic.
 *
 * Fixed reference instant: Monday 2026-01-05 09:00 (ISO weekday 1).
 */
class SlotAvailabilityServiceTest extends SapphireTest
{
    use UsesScheduleSettings;

    protected $usesDatabase = true;

    private const MONDAY_9AM = '2026-01-05 09:00:00';

    private const THIS_MONDAY = '2026-01-05';

    private const NEXT_MONDAY = '2026-01-12';

    private function now(): DBDatetime
    {
        $now = DBDatetime::create();
        $now->setValue(self::MONDAY_9AM);

        return $now;
    }

    private function mondaySlot(array $overrides = []): DeliverySlot
    {
        $slot = DeliverySlot::create(array_merge([
            'Weekday' => 1,
            'StartTime' => '17:00:00',
            'EndTime' => '18:00:00',
            'Capacity' => 0,
            'Mode' => DeliverySlot::MODE_BOTH,
            'Enabled' => 1,
        ], $overrides));
        $slot->write();

        return $slot;
    }

    /** @return array<int, string> the Value tokens of the returned windows */
    private function values(string $mode): array
    {
        $out = [];
        foreach (SlotAvailabilityService::create()->windows($mode, $this->now()) as $row) {
            $out[] = (string) $row->Value;
        }

        return $out;
    }

    public function testGeneratesSameDayAndFutureWindows(): void
    {
        $slot = $this->mondaySlot();

        $values = $this->values(DeliverySlot::MODE_DELIVERY);

        // Same Monday 17:00 (9am + 0 prep, no cutoff) and next Monday within the 14-day horizon.
        $this->assertContains($slot->ID . '|' . self::THIS_MONDAY, $values);
        $this->assertContains($slot->ID . '|' . self::NEXT_MONDAY, $values);
    }

    public function testPrepTimePushesPastSameDay(): void
    {
        // 10h prep from 09:00 lands at 19:00, past the 17:00 window — so today is not offered.
        $slot = $this->mondaySlot(['PrepMinutes' => 600]);

        $values = $this->values(DeliverySlot::MODE_DELIVERY);

        $this->assertNotContains($slot->ID . '|' . self::THIS_MONDAY, $values, 'Prep must exclude today');
        $this->assertContains($slot->ID . '|' . self::NEXT_MONDAY, $values, 'Next week still fits the prep window');
    }

    public function testSameDayCutoffExcludesToday(): void
    {
        $this->scheduleSettings(['DeliveryScheduleCutoff' => '08:00:00']);
        $slot = $this->mondaySlot();

        $values = $this->values(DeliverySlot::MODE_DELIVERY);

        // 09:00 is past the 08:00 cutoff, so same-day drops; future days stay.
        $this->assertNotContains($slot->ID . '|' . self::THIS_MONDAY, $values);
        $this->assertContains($slot->ID . '|' . self::NEXT_MONDAY, $values);
    }

    public function testFullWindowIsHiddenForThatDateOnly(): void
    {
        $slot = $this->mondaySlot(['Capacity' => 1]);

        // One placed order already holds this (slot, this Monday) -> that date is full.
        $placed = Order::create([
            'Status' => 'Paid',
            'DeliverySlotID' => $slot->ID,
            'RequestedDate' => self::THIS_MONDAY,
        ]);
        $placed->write();

        $values = $this->values(DeliverySlot::MODE_DELIVERY);

        $this->assertNotContains($slot->ID . '|' . self::THIS_MONDAY, $values, 'Full date is hidden');
        $this->assertContains($slot->ID . '|' . self::NEXT_MONDAY, $values, 'A different date is unaffected');
    }

    public function testCapacityLeftCountsPlacedOrdersOnly(): void
    {
        $slot = $this->mondaySlot(['Capacity' => 3]);

        // A placed order consumes capacity; a cart (unplaced) order does not.
        Order::create(['Status' => 'Paid', 'DeliverySlotID' => $slot->ID, 'RequestedDate' => self::THIS_MONDAY])->write();
        Order::create(['Status' => 'Cart', 'DeliverySlotID' => $slot->ID, 'RequestedDate' => self::THIS_MONDAY])->write();

        $this->assertSame(2, SlotAvailabilityService::create()->capacityLeft($slot, self::THIS_MONDAY));
    }

    public function testCancelledOrderFreesCapacity(): void
    {
        $slot = $this->mondaySlot(['Capacity' => 1]);

        // A cancelled order must not hold the window.
        Order::create([
            'Status' => 'MemberCancelled',
            'DeliverySlotID' => $slot->ID,
            'RequestedDate' => self::THIS_MONDAY,
        ])->write();

        $this->assertSame(1, SlotAvailabilityService::create()->capacityLeft($slot, self::THIS_MONDAY));
        $this->assertContains($slot->ID . '|' . self::THIS_MONDAY, $this->values(DeliverySlot::MODE_DELIVERY));
    }

    public function testUnlimitedCapacityReturnsMax(): void
    {
        $slot = $this->mondaySlot(['Capacity' => 0]);
        $this->assertSame(PHP_INT_MAX, SlotAvailabilityService::create()->capacityLeft($slot, self::THIS_MONDAY));
    }

    public function testModeFilter(): void
    {
        $pickup = $this->mondaySlot(['Mode' => DeliverySlot::MODE_PICKUP]);

        $this->assertNotContains($pickup->ID . '|' . self::THIS_MONDAY, $this->values(DeliverySlot::MODE_DELIVERY));
        $this->assertContains($pickup->ID . '|' . self::THIS_MONDAY, $this->values(DeliverySlot::MODE_PICKUP));
        $this->assertContains($pickup->ID . '|' . self::THIS_MONDAY, $this->values(DeliverySlot::MODE_BOTH));
    }

    public function testDisabledSlotIsIgnored(): void
    {
        $slot = $this->mondaySlot(['Enabled' => 0]);
        $this->assertSame([], $this->values(DeliverySlot::MODE_BOTH));
    }

    public function testHorizonBoundsTheLookahead(): void
    {
        $this->scheduleSettings(['DeliveryScheduleHorizonDays' => 3]);
        $slot = $this->mondaySlot();

        $values = $this->values(DeliverySlot::MODE_DELIVERY);

        // Only this Monday is within 3 days; next Monday (7 days out) is beyond the horizon.
        $this->assertContains($slot->ID . '|' . self::THIS_MONDAY, $values);
        $this->assertNotContains($slot->ID . '|' . self::NEXT_MONDAY, $values);
    }

    public function testAsapWindowIsTheEarliestFeasible(): void
    {
        $slot = $this->mondaySlot();

        $asap = SlotAvailabilityService::create()->asapWindow(DeliverySlot::MODE_DELIVERY, $this->now());

        $this->assertNotNull($asap);
        $this->assertSame($slot->ID . '|' . self::THIS_MONDAY, (string) $asap->Value);
    }

    public function testLeadFloorIsNullWithoutOrder(): void
    {
        $this->assertNull(SlotAvailabilityService::create()->leadFloorYmd());
        $this->assertNull(SlotAvailabilityService::create(Order::create())->leadFloorYmd());
    }
}
