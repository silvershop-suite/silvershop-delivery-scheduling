<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Service;

use SilverShop\DeliveryScheduling\Model\DeliverySlot;
use SilverShop\DeliveryScheduling\Support\OptionalCall;
use SilverShop\Model\Order;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBDatetime;
use Throwable;

/**
 * Turns the recurring {@link DeliverySlot} templates into concrete, bookable (date, slot) windows for the
 * checkout picker and the headless slot endpoint.
 *
 * The rules, composed:
 *  - EARLIEST FEASIBLE: a window is only offered if its start is at least `now + prep` away, where prep is the
 *    per-slot override or the global default (minutes).
 *  - LEAD-TIME FLOOR: the cart's longest made-to-order expected date (from silvershop/product-status, soft) floors
 *    the earliest selectable date — a window before it is dropped.
 *  - SAME-DAY CUTOFF: once the day's cutoff time has passed, today is no longer offered.
 *  - CAPACITY: a window full of placed orders for that date is hidden (0 capacity = unlimited).
 *  - ASAP: the single earliest feasible window.
 *
 * Pure and deterministic — `now` is injectable, so every rule is unit-testable without the clock.
 */
class SlotAvailabilityService
{
    use Injectable;
    use Configurable;

    /**
     * How many days ahead (inclusive of today) to offer windows for.
     *
     * @config
     */
    private static int $horizon_days = 14;

    /**
     * Same-day cutoff as HH:MM:SS. Empty disables the cutoff (today stays offered until its last feasible window).
     *
     * @config
     */
    private static string $cutoff_time = '';

    /**
     * Global prep / lead time in minutes, applied to a window that has no per-slot override.
     *
     * @config
     */
    private static int $global_prep_minutes = 0;

    /**
     * Whether an "as soon as possible" choice is offered alongside the scheduled windows.
     *
     * @config
     */
    private static bool $asap_enabled = true;

    /**
     * Master switch for the whole scheduling layer (the checkout picker is suppressed when off).
     *
     * @config
     */
    private static bool $scheduling_enabled = true;

    public function __construct(private ?Order $order = null)
    {
    }

    public function isSchedulingEnabled(): bool
    {
        return (bool) static::config()->get('scheduling_enabled');
    }

    public function isAsapEnabled(): bool
    {
        return (bool) static::config()->get('asap_enabled');
    }

    /**
     * The feasible (date, slot) windows for a fulfilment mode, as template-friendly rows.
     *
     * Each row is an ArrayData{SlotID, Slot, DateYmd, Date, CapacityLeft (-1 = unlimited), Value, Label},
     * where Value is the "slotID|Y-m-d" token the checkout picker stores.
     *
     * @return ArrayList<ArrayData>
     */
    public function windows(string $mode, ?DBDatetime $now = null): ArrayList
    {
        $out = ArrayList::create();

        $nowTs = $this->nowTimestamp($now);
        $horizon = max(0, (int) static::config()->get('horizon_days'));
        $globalPrep = max(0, (int) static::config()->get('global_prep_minutes'));
        $cutoff = (string) static::config()->get('cutoff_time');
        $leadFloor = $this->leadFloorYmd();

        /** @var array<int, DeliverySlot> $slots */
        $slots = DeliverySlot::get()->filter('Enabled', true)->toArray();
        if ($slots === []) {
            return $out;
        }

        for ($dayOffset = 0; $dayOffset <= $horizon; $dayOffset++) {
            $dayTs = strtotime(sprintf('+%d day', $dayOffset), $nowTs);
            if ($dayTs === false) {
                continue;
            }

            $ymd = date('Y-m-d', $dayTs);
            $iso = (int) date('N', $dayTs);

            if ($leadFloor !== null && $ymd < $leadFloor) {
                continue;
            }

            foreach ($slots as $slot) {
                if ((int) $slot->Weekday !== $iso || !$slot->modeMatches($mode)) {
                    continue;
                }

                $slotStartTs = strtotime($ymd . ' ' . $this->timeValue($slot->StartTime));
                if ($slotStartTs === false) {
                    continue;
                }

                // Earliest feasible: enough prep/lead time before the window opens.
                if ($slotStartTs < $nowTs + $slot->prepMinutes($globalPrep) * 60) {
                    continue;
                }

                // Same-day cutoff: once today's cutoff has passed, drop the rest of today.
                if ($dayOffset === 0 && $cutoff !== '') {
                    $cutoffTs = strtotime($ymd . ' ' . $cutoff);
                    if ($cutoffTs !== false && $nowTs >= $cutoffTs) {
                        continue;
                    }
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
     * The single earliest feasible window for ASAP, or null when nothing is bookable in the horizon.
     */
    public function asapWindow(string $mode, ?DBDatetime $now = null): ?ArrayData
    {
        return $this->windows($mode, $now)->first();
    }

    /**
     * The earliest selectable date (Y-m-d) floored by the cart's longest made-to-order expected date, or null
     * when silvershop/product-status is absent or no cart line carries an expected date.
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

    private function row(DeliverySlot $slot, string $ymd, int $left): ArrayData
    {
        $date = DBDate::create('RequestedDate');
        $date->setValue($ymd);

        return ArrayData::create([
            'SlotID' => (int) $slot->ID,
            'Slot' => $slot,
            'DateYmd' => $ymd,
            'Date' => $date,
            'CapacityLeft' => $left,
            'Value' => ((int) $slot->ID) . '|' . $ymd,
            'Label' => $this->label($slot, $ymd),
        ]);
    }

    private function label(DeliverySlot $slot, string $ymd): string
    {
        $ts = strtotime($ymd);
        $datePart = $ts === false ? $ymd : date('D j M', $ts);
        $range = $slot->TimeRange();

        return $range !== '' ? $datePart . ' · ' . $range : $datePart;
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
