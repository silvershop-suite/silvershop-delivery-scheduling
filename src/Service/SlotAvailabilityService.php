<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Service;

use DateTimeImmutable;
use SilverShop\DeliveryScheduling\Model\DeliverySlot;
use SilverShop\DeliveryScheduling\Support\OptionalCall;
use SilverShop\Model\Order;
use SilverShop\StoreLocations\Hours\DateRules;
use SilverShop\StoreLocations\Hours\OpeningCalendar;
use SilverShop\StoreLocations\Model\OpeningException;
use SilverShop\StoreLocations\Model\StoreLocation;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBDatetime;
use Throwable;

/**
 * Turns the settings and the recurring {@link DeliverySlot} templates into the concrete choices the checkout offers:
 * dates (date-only mode) or (date, slot) windows (time-slot mode).
 *
 * The rules, composed:
 *  - EARLIEST DATE: today, or tomorrow once today's cutoff has passed, plus the preparation time in working days.
 *    Working days are the pickup store's open days (silvershop-suite/store-locations: its hours, exceptions and
 *    closures), otherwise the configured weekdays minus the global closing days. With no preparation time the
 *    earliest date is just today (or tomorrow after the cutoff).
 *  - LEAD-TIME FLOOR: the cart's longest made-to-order expected date (silvershop-suite/product-status, soft)
 *    floors the earliest date too.
 *  - DAYS AHEAD: nothing later than today + the configured number of days.
 *  - DATE-ONLY MODE: every working / open day from the earliest date on.
 *  - TIME-SLOT MODE: the slots of each day, each needing its own prep time before it starts; a full slot (capacity)
 *    is hidden; a slot tied to a store only on that store's open days; a pickup order only sees its own store's
 *    slots (plus slots without a store); a slot without a store skips the global closing days.
 *  - ASAP: the single earliest choice.
 *
 * Pure and deterministic: `now` is injectable, so every rule is unit-testable without the clock.
 */
class SlotAvailabilityService
{
    use Injectable;

    private ?ScheduleSettings $settings = null;

    /** @var array<string, bool> */
    private array $openMemo = [];

    /** @var array<int, array{string, string, bool}>|null */
    private ?array $globalClosures = null;

    public function __construct(private ?Order $order = null)
    {
    }

    public function settings(): ScheduleSettings
    {
        return $this->settings ??= ScheduleSettings::current();
    }

    public function withSettings(ScheduleSettings $settings): static
    {
        $this->settings = $settings;

        return $this;
    }

    public function isSchedulingEnabled(): bool
    {
        return $this->settings()->enabled;
    }

    public function isAsapEnabled(): bool
    {
        return $this->settings()->asap;
    }

    public function isDateOnly(): bool
    {
        return $this->settings()->isDateOnly();
    }

    /**
     * The bookable choices for a fulfilment mode, for the order's own pickup store when the mode is pickup.
     *
     * @return ArrayList<ArrayData>
     */
    public function windows(string $mode, ?DBDatetime $now = null): ArrayList
    {
        $store = $mode === DeliverySlot::MODE_PICKUP ? $this->pickupStore() : null;

        return $this->windowsFor($mode, $store, $now);
    }

    /**
     * The bookable choices for a fulfilment mode and (pickup) store, as template-friendly rows:
     * ArrayData{SlotID (0 in date-only mode), Slot (null in date-only mode), DateYmd, Date, CapacityLeft
     * (-1 = unlimited), Value, Label}. Value is the "slotID|Y-m-d" token the checkout stores.
     *
     * @return ArrayList<ArrayData>
     */
    public function windowsFor(string $mode, ?DataObject $store, ?DBDatetime $now = null): ArrayList
    {
        $nowTs = $this->nowTimestamp($now);
        $earliest = $this->earliestYmd($store, $nowTs);
        $last = date('Y-m-d', (int) strtotime(sprintf('+%d day', $this->settings()->horizonDays), $nowTs));

        return $this->isDateOnly()
            ? $this->dateWindows($store, $earliest, $last)
            : $this->slotWindows($mode, $store, $nowTs, $earliest, $last);
    }

    /**
     * Whether a submitted choice ("slotID|Y-m-d" or the ASAP sentinel) is bookable right now for this mode and store.
     * The server-side check behind the checkout: a crafted, stale or other-store value is refused.
     */
    public function isFeasible(string $choice, string $mode, ?DataObject $store, ?DBDatetime $now = null): bool
    {
        if ($choice === 'asap') {
            return $this->isAsapEnabled();
        }

        foreach ($this->windowsFor($mode, $store, $now) as $window) {
            if ((string) $window->Value === $choice) {
                return true;
            }
        }

        return false;
    }

    /**
     * The earliest date (Y-m-d) that can be offered: today, or tomorrow after the cutoff, plus the preparation time
     * in working days, floored by the cart's made-to-order expected date.
     */
    public function earliestYmd(?DataObject $store = null, ?int $nowTs = null): string
    {
        $nowTs ??= $this->nowTimestamp(null);
        $settings = $this->settings();

        $start = date('Y-m-d', $nowTs);
        if ($settings->cutoff !== '') {
            $cutoffTs = strtotime($start . ' ' . $settings->cutoff);
            if ($cutoffTs !== false && $nowTs >= $cutoffTs) {
                $start = $this->nextDay($start);
            }
        }

        $earliest = $settings->leadDays > 0 ? $this->addWorkingDays($store, $start, $settings->leadDays) : $start;

        $floor = $this->leadFloorYmd();

        return $floor !== null && $floor > $earliest ? $floor : $earliest;
    }

    /**
     * Whether a date can be chosen at all: an open day of the store, or (without a store) a working weekday that is
     * not a global closing day.
     */
    public function isOfferDay(?DataObject $store, string $ymd): bool
    {
        if ($store !== null) {
            return $this->storeOpenOn($store, $ymd);
        }

        $ts = strtotime($ymd);

        return $ts !== false
            && $this->settings()->isWorkingWeekday((int) date('N', $ts))
            && !$this->isGlobalClosingDay($ymd);
    }

    /**
     * The date $days working days after $startYmd (the start itself does not count).
     */
    public function addWorkingDays(?DataObject $store, string $startYmd, int $days): string
    {
        if ($store instanceof StoreLocation && class_exists(OpeningCalendar::class)) {
            $date = new DateTimeImmutable($startYmd . ' 12:00:00', $store->getTimezoneObject());
            $result = OpeningCalendar::singleton()->workingDaysAfter($store, $date, $days);
            if ($result !== null) {
                return $result->format('Y-m-d');
            }
        }

        $current = $startYmd;
        $counted = 0;
        // A year is enough to find any number of working days a shop would configure.
        for ($i = 0; $i < 366 && $counted < $days; $i++) {
            $current = $this->nextDay($current);
            if ($this->isOfferDay($store, $current)) {
                $counted++;
            }
        }

        return $current;
    }

    /**
     * Remaining capacity for a window on a given date: its capacity minus the placed orders already holding it.
     * A window with no capacity set is unlimited and returns PHP_INT_MAX.
     */
    public function capacityLeft(DeliverySlot $slot, string $ymd): int
    {
        $capacity = (int) $slot->Capacity;
        if ($capacity <= 0) {
            return PHP_INT_MAX;
        }

        // Count live placed orders holding this (slot, date); a cancelled order frees its window.
        $holding = array_values(array_diff(
            (array) Order::config()->get('placed_status'),
            ['AdminCancelled', 'MemberCancelled']
        ));

        $used = Order::get()->filter([
            'DeliverySlotID' => (int) $slot->ID,
            'RequestedDate' => $ymd,
            'Status' => $holding,
        ])->count();

        return max(0, $capacity - $used);
    }

    /**
     * The single earliest bookable choice for ASAP, or null when nothing is bookable in the horizon.
     */
    public function asapWindow(string $mode, ?DBDatetime $now = null): ?ArrayData
    {
        return $this->windows($mode, $now)->first();
    }

    /**
     * The earliest selectable date (Y-m-d) floored by the cart's longest made-to-order expected date, or null
     * when silvershop-suite/product-status is absent or no cart line carries an expected date.
     *
     * Only the machine-readable ExpectedDate is used as a hard floor; the free-text lead-time sentence stays
     * display-only.
     */
    public function leadFloorYmd(): ?string
    {
        if (!$this->order instanceof Order) {
            return null;
        }

        try {
            $items = $this->order->Items();
        } catch (Throwable) {
            return null;
        }

        $max = null;
        foreach ($items as $item) {
            $date = $this->itemExpectedDate($item);
            if ($date !== null && ($max === null || $date > $max)) {
                $max = $date;
            }
        }

        return $max;
    }

    /**
     * The store a pickup order is collected from (click-and-collect's link to store-locations), or null.
     */
    public function pickupStore(): ?DataObject
    {
        if (!$this->order instanceof Order) {
            return null;
        }
        $store = OptionalCall::invoke($this->order, 'PickupStoreLocation');

        return $store instanceof DataObject && $store->exists() ? $store : null;
    }

    /**
     * @return ArrayList<ArrayData>
     */
    private function dateWindows(?DataObject $store, string $earliest, string $last): ArrayList
    {
        $out = ArrayList::create();
        for ($ymd = $earliest; $ymd <= $last; $ymd = $this->nextDay($ymd)) {
            if ($this->isOfferDay($store, $ymd)) {
                $out->push($this->row(null, $ymd, -1));
            }
        }

        return $out;
    }

    /**
     * @return ArrayList<ArrayData>
     */
    private function slotWindows(
        string $mode,
        ?DataObject $store,
        int $nowTs,
        string $earliest,
        string $last
    ): ArrayList {
        $out = ArrayList::create();

        /** @var array<int, DeliverySlot> $slots */
        $slots = DeliverySlot::get()->filter('Enabled', true)->toArray();
        if ($slots === []) {
            return $out;
        }

        $globalPrep = $this->settings()->prepMinutes;
        // A pickup store limits the choice to its own slots (plus slots without a store), on its open days.
        $pickupStore = $mode === DeliverySlot::MODE_PICKUP ? $store : null;

        for ($ymd = $earliest; $ymd <= $last; $ymd = $this->nextDay($ymd)) {
            $dayTs = strtotime($ymd);
            if ($dayTs === false) {
                continue;
            }
            $iso = (int) date('N', $dayTs);

            foreach ($slots as $slot) {
                if ((int) $slot->Weekday !== $iso || !$slot->modeMatches($mode)) {
                    continue;
                }

                $slotStore = $this->slotStore($slot);
                if ($slotStore !== null && $pickupStore !== null && (int) $slotStore->ID !== (int) $pickupStore->ID) {
                    continue;
                }
                $dayStore = $slotStore ?? $pickupStore;
                if ($dayStore !== null ? !$this->storeOpenOn($dayStore, $ymd) : $this->isGlobalClosingDay($ymd)) {
                    continue;
                }

                $slotStartTs = strtotime($ymd . ' ' . $this->timeValue($slot->StartTime));
                if ($slotStartTs === false || $slotStartTs < $nowTs + $slot->prepMinutes($globalPrep) * 60) {
                    continue;
                }

                $capacity = (int) $slot->Capacity;
                $left = $capacity > 0 ? $this->capacityLeft($slot, $ymd) : -1;
                if ($capacity > 0 && $left <= 0) {
                    continue;
                }

                $out->push($this->row($slot, $ymd, $left));
            }
        }

        return $out;
    }

    /**
     * The expected-availability date (Y-m-d) frozen / live on a cart line via product-status, or null.
     * The optional accessor is reached through {@see OptionalCall} so it resolves whether or not that module
     * is installed.
     */
    private function itemExpectedDate(DataObject $item): ?string
    {
        try {
            $badge = OptionalCall::invoke($item, 'getProductStatusBadge');
        } catch (Throwable) {
            return null;
        }

        if (!$badge instanceof ArrayData) {
            return null;
        }

        $expected = $badge->getField('ExpectedDate');
        if ($expected instanceof DBDate && $expected->getValue()) {
            $ts = strtotime((string) $expected->getValue());

            return $ts === false ? null : date('Y-m-d', $ts);
        }

        return null;
    }

    /**
     * The store a slot belongs to (silvershop-suite/store-locations), or null.
     */
    private function slotStore(DeliverySlot $slot): ?DataObject
    {
        $store = OptionalCall::invoke($slot, 'SlotStoreLocation');

        return $store instanceof DataObject && $store->exists() ? $store : null;
    }

    /**
     * Whether a store has any opening hours on a date (its regular week, seasons, exceptions, holidays and status
     * applied), asked of store-locations' OpeningCalendar. Memoised per store and date.
     */
    private function storeOpenOn(DataObject $store, string $ymd): bool
    {
        $key = $store->ID . '|' . $ymd;
        if (!array_key_exists($key, $this->openMemo)) {
            $this->openMemo[$key] = true;
            if (class_exists(OpeningCalendar::class) && $store instanceof StoreLocation) {
                $date = new DateTimeImmutable($ymd . ' 12:00:00', $store->getTimezoneObject());
                $this->openMemo[$key] = OpeningCalendar::singleton()->isOpenOn($store, $date);
            }
        }

        return $this->openMemo[$key];
    }

    /**
     * A closing day for all locations, from store-locations (an exception without a store). False without that
     * module.
     */
    private function isGlobalClosingDay(string $ymd): bool
    {
        if ($this->globalClosures === null) {
            $this->globalClosures = [];
            if (class_exists(OpeningException::class)) {
                $closures = OpeningException::get()->filter([
                    'StoreLocationID' => 0,
                    'Type' => OpeningException::TYPE_CLOSED,
                ]);
                foreach ($closures as $closure) {
                    $this->globalClosures[] = [
                        (string) $closure->DateFrom,
                        (string) $closure->DateTo,
                        (bool) $closure->RecurringYearly,
                    ];
                }
            }
        }

        foreach ($this->globalClosures as [$from, $to, $yearly]) {
            if (DateRules::matches($ymd, $from, $to, $yearly)) {
                return true;
            }
        }

        return false;
    }

    private function row(?DeliverySlot $slot, string $ymd, int $left): ArrayData
    {
        $date = DBDate::create('RequestedDate');
        $date->setValue($ymd);
        $slotId = $slot instanceof DeliverySlot ? (int) $slot->ID : 0;

        return ArrayData::create([
            'SlotID' => $slotId,
            'Slot' => $slot,
            'DateYmd' => $ymd,
            'Date' => $date,
            'CapacityLeft' => $left,
            'Value' => $slotId . '|' . $ymd,
            'Label' => $this->label($slot, $date),
        ]);
    }

    private function label(?DeliverySlot $slot, DBDate $date): string
    {
        // Localised day name and month ("Mon 24 Nov", "ma 24 nov.").
        $datePart = (string) $date->Format('EEE d MMM');
        $range = $slot instanceof DeliverySlot ? $slot->TimeRange() : '';

        return $range !== '' ? $datePart . ' · ' . $range : $datePart;
    }

    private function nextDay(string $ymd): string
    {
        return date('Y-m-d', (int) strtotime($ymd . ' +1 day'));
    }

    private function nowTimestamp(?DBDatetime $now): int
    {
        $now ??= DBDatetime::now();
        $ts = strtotime((string) $now->getValue());

        return $ts === false ? time() : $ts;
    }

    private function timeValue(?string $time): string
    {
        $time = trim((string) $time);

        return $time === '' ? '00:00:00' : $time;
    }
}
