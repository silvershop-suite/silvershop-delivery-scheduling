<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Tests\Model;

use SilverShop\DeliveryScheduling\Model\DeliverySlot;
use SilverStripe\Dev\SapphireTest;

/**
 * The slot template's small pure contracts: mode matching, the prep-override fallback, the window label and
 * the capacity label.
 */
class DeliverySlotTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testModeMatching(): void
    {
        $both = DeliverySlot::create(['Mode' => DeliverySlot::MODE_BOTH]);
        $delivery = DeliverySlot::create(['Mode' => DeliverySlot::MODE_DELIVERY]);
        $pickup = DeliverySlot::create(['Mode' => DeliverySlot::MODE_PICKUP]);

        // A "both" window matches any request.
        $this->assertTrue($both->modeMatches(DeliverySlot::MODE_DELIVERY));
        $this->assertTrue($both->modeMatches(DeliverySlot::MODE_PICKUP));

        // A specific window matches only its own mode (and the "both" request).
        $this->assertTrue($delivery->modeMatches(DeliverySlot::MODE_DELIVERY));
        $this->assertFalse($delivery->modeMatches(DeliverySlot::MODE_PICKUP));
        $this->assertTrue($delivery->modeMatches(DeliverySlot::MODE_BOTH));

        $this->assertTrue($pickup->modeMatches(DeliverySlot::MODE_PICKUP));
        $this->assertFalse($pickup->modeMatches(DeliverySlot::MODE_DELIVERY));
    }

    public function testPrepMinutesFallsBackToGlobal(): void
    {
        $noOwn = DeliverySlot::create(['PrepMinutes' => 0]);
        $withOwn = DeliverySlot::create(['PrepMinutes' => 45]);

        $this->assertSame(30, $noOwn->prepMinutes(30), 'No per-slot prep uses the supplied global default');
        $this->assertSame(45, $withOwn->prepMinutes(30), 'A per-slot prep override wins over the global default');
        $this->assertSame(0, $noOwn->prepMinutes(0));
    }

    public function testTimeRange(): void
    {
        $slot = DeliverySlot::create(['StartTime' => '17:00:00', 'EndTime' => '18:30:00']);
        $this->assertSame('17:00–18:30', $slot->TimeRange());

        $empty = DeliverySlot::create();
        $this->assertSame('', $empty->TimeRange());
    }

    public function testWeekdayLabel(): void
    {
        $this->assertSame('Monday', DeliverySlot::create(['Weekday' => 1])->WeekdayLabel());
        $this->assertSame('Sunday', DeliverySlot::create(['Weekday' => 7])->WeekdayLabel());
        $this->assertSame('', DeliverySlot::create(['Weekday' => 0])->WeekdayLabel());
    }

    public function testCapacityLabel(): void
    {
        $this->assertSame('5', DeliverySlot::create(['Capacity' => 5])->CapacityLabel());
        $this->assertSame('Unlimited', DeliverySlot::create(['Capacity' => 0])->CapacityLabel());
    }

    public function testGetTitleCombinesLabelAndWindow(): void
    {
        $named = DeliverySlot::create([
            'Title' => 'Evening',
            'Weekday' => 2,
            'StartTime' => '17:00:00',
            'EndTime' => '18:00:00',
        ]);
        $this->assertSame('Evening (Tuesday 17:00–18:00)', $named->getTitle());

        $unnamed = DeliverySlot::create(['Weekday' => 2, 'StartTime' => '17:00:00', 'EndTime' => '18:00:00']);
        $this->assertSame('Tuesday 17:00–18:00', $unnamed->getTitle());
    }

    public function testGetCMSFieldsReplacesWeekdayWithDropdown(): void
    {
        $fields = DeliverySlot::create()->getCMSFields();
        $weekday = $fields->dataFieldByName('Weekday');

        $this->assertNotNull($weekday);
        $this->assertInstanceOf(\SilverStripe\Forms\DropdownField::class, $weekday);
    }
}
