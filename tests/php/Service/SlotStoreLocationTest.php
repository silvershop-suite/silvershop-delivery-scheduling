<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Tests\Service;

use SilverShop\DeliveryScheduling\Model\DeliverySlot;
use SilverShop\DeliveryScheduling\Service\SlotAvailabilityService;
use SilverShop\Model\Order;
use SilverShop\StoreLocations\Forms\WeeklyHoursField;
use SilverShop\StoreLocations\Hours\OpeningCalendar;
use SilverShop\StoreLocations\Model\OpeningException;
use SilverShop\StoreLocations\Model\StoreLocation;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * The store-locations link: store-bound slots skip the store's closed days, and a pickup order only sees its own
 * store's slots, on that store's open days.
 */
class SlotStoreLocationTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $required_extensions = [
        Order::class => [SlotStoreLocationTestOrderExtension::class],
    ];

    private const MONDAY_9AM = '2026-01-05 09:00:00';

    private const THIS_MONDAY = '2026-01-05';

    private const NEXT_MONDAY = '2026-01-12';

    private string $previousTimezone = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(StoreLocation::class)) {
            $this->markTestSkipped('silvershop-suite/store-locations is not installed.');
        }
        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Amsterdam');
        SlotStoreLocationTestOrderExtension::$store = null;
        OpeningCalendar::singleton()->reset();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimezone);
        SlotStoreLocationTestOrderExtension::$store = null;
        parent::tearDown();
    }

    private function now(): DBDatetime
    {
        $now = DBDatetime::create();
        $now->setValue(self::MONDAY_9AM);

        return $now;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function store(array $data = []): StoreLocation
    {
        $store = StoreLocation::create(array_merge([
            'Title' => 'High Street',
            'Timezone' => 'Europe/Amsterdam',
            'RegularHours' => WeeklyHoursField::encode([1 => '09:00-18:00', 2 => '09:00-18:00']),
        ], $data));
        $store->write();

        return $store;
    }

    private function closedOn(StoreLocation $store, string $date): void
    {
        OpeningException::create(['StoreLocationID' => $store->ID, 'DateFrom' => $date, 'Type' => 'Closed'])->write();
    }

    private function mondaySlot(?StoreLocation $store = null): DeliverySlot
    {
        $slot = DeliverySlot::create([
            'Weekday' => 1,
            'StartTime' => '17:00:00',
            'EndTime' => '18:00:00',
            'Capacity' => 0,
            'Mode' => DeliverySlot::MODE_BOTH,
            'Enabled' => 1,
            'StoreLocationID' => $store?->ID ?? 0,
        ]);
        $slot->write();

        return $slot;
    }

    /**
     * @return string[]
     */
    private function dates(string $mode, ?Order $order = null): array
    {
        $out = [];
        foreach (SlotAvailabilityService::create($order)->windows($mode, $this->now()) as $row) {
            $out[] = (int) $row->SlotID . '|' . $row->DateYmd;
        }

        return $out;
    }

    public function testStoreSlotSkipsTheStoresClosedDays(): void
    {
        $store = $this->store();
        $this->closedOn($store, self::THIS_MONDAY);
        $slot = $this->mondaySlot($store);

        $dates = $this->dates(DeliverySlot::MODE_DELIVERY);
        $this->assertNotContains($slot->ID . '|' . self::THIS_MONDAY, $dates);
        $this->assertContains($slot->ID . '|' . self::NEXT_MONDAY, $dates);
    }

    public function testSlotWithoutStoreIsUnaffectedForDelivery(): void
    {
        $store = $this->store();
        $this->closedOn($store, self::THIS_MONDAY);
        $slot = $this->mondaySlot();

        $this->assertContains($slot->ID . '|' . self::THIS_MONDAY, $this->dates(DeliverySlot::MODE_DELIVERY));
    }

    public function testPickupOrderOnlySeesItsStoreOnItsOpenDays(): void
    {
        $delft = $this->store();
        $riverside = $this->store(['Title' => 'Riverside']);
        $this->closedOn($delft, self::NEXT_MONDAY);

        $anyStore = $this->mondaySlot();
        $delftSlot = $this->mondaySlot($delft);
        $riversideSlot = $this->mondaySlot($riverside);

        SlotStoreLocationTestOrderExtension::$store = $delft;
        $order = Order::create();
        $order->write();

        $dates = $this->dates(DeliverySlot::MODE_PICKUP, $order);

        $this->assertContains($anyStore->ID . '|' . self::THIS_MONDAY, $dates);
        $this->assertContains($delftSlot->ID . '|' . self::THIS_MONDAY, $dates);
        $this->assertNotContains($anyStore->ID . '|' . self::NEXT_MONDAY, $dates, 'Delft is closed that day');
        $this->assertNotContains($delftSlot->ID . '|' . self::NEXT_MONDAY, $dates, 'Delft is closed that day');
        foreach ($dates as $value) {
            $this->assertStringStartsNotWith($riversideSlot->ID . '|', $value, 'another store\'s slot');
        }
        $this->assertSame($delft->ID, SlotAvailabilityService::create($order)->pickupStore()?->ID);
    }

    public function testTemporarilyClosedStoreSkipsItsSlotsUntilItReopens(): void
    {
        $store = $this->store(['Status' => StoreLocation::STATUS_TEMPORARILY_CLOSED, 'StatusUntil' => '2026-01-10']);
        $slot = $this->mondaySlot($store);

        $dates = $this->dates(DeliverySlot::MODE_BOTH);
        $this->assertNotContains($slot->ID . '|' . self::THIS_MONDAY, $dates);
        $this->assertContains($slot->ID . '|' . self::NEXT_MONDAY, $dates);
    }
}

/**
 * Stands in for click-and-collect's Order::PickupStoreLocation() so this module's tests don't depend on it.
 *
 * @extends Extension<Order>
 */
class SlotStoreLocationTestOrderExtension extends Extension implements TestOnly
{
    public static ?StoreLocation $store = null;

    public function PickupStoreLocation(): ?StoreLocation
    {
        return self::$store;
    }
}
