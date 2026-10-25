<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Model;

use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\NumericField;
use SilverStripe\ORM\DataObject;

/**
 * A recurring weekly delivery / pickup window template.
 *
 * A slot is NOT a single dated event (that is the courses model) — it is a repeating weekly window
 * (e.g. "every Tuesday 17:00–18:00") from which {@link \SilverShop\DeliveryScheduling\Service\SlotAvailabilityService}
 * materialises concrete (date, slot) windows for the next N days, honouring capacity, lead/prep time and the
 * same-day cutoff. The window the buyer picks is captured at order level by {@link OrderSchedulingExtension}.
 *
 * Capacity is counted as *orders per (slot, date)* (operational throughput), not seats; a full window is hidden
 * from the picker. Capacity 0 means unlimited.
 *
 * @property string $Title
 * @property int $Weekday         ISO-8601 day of week, 1 = Monday ... 7 = Sunday
 * @property string $StartTime    HH:MM:SS
 * @property string $EndTime      HH:MM:SS
 * @property int $Capacity        orders per (slot, date); 0 = unlimited
 * @property string $Mode         delivery | pickup | both
 * @property int $PrepMinutes     per-slot prep override in minutes; 0 = use the global default
 * @property bool $Enabled
 * @property int $Sort
 */
class DeliverySlot extends DataObject
{
    public const MODE_DELIVERY = 'delivery';

    public const MODE_PICKUP = 'pickup';

    public const MODE_BOTH = 'both';

    private static string $table_name = 'SilverShop_DeliverySlot';

    private static string $singular_name = 'Delivery / pickup slot';

    private static string $plural_name = 'Delivery / pickup slots';

    private static string $default_sort = '"Sort" ASC, "Weekday" ASC, "StartTime" ASC';

    private static array $db = [
        'Title' => 'Varchar(100)',
        'Weekday' => 'Int',
        'StartTime' => 'Time',
        'EndTime' => 'Time',
        'Capacity' => 'Int',
        'Mode' => "Enum('delivery,pickup,both','both')",
        'PrepMinutes' => 'Int',
        'Enabled' => 'Boolean(1)',
        'Sort' => 'Int',
    ];

    private static array $summary_fields = [
        'WeekdayLabel' => 'Weekday',
        'TimeRange' => 'Window',
        'Mode' => 'Mode',
        'CapacityLabel' => 'Capacity',
        'Enabled.Nice' => 'Enabled',
    ];

    private static array $searchable_fields = [
        'Weekday',
        'Mode',
        'Enabled',
    ];

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $fields->replaceField(
            'Weekday',
            DropdownField::create('Weekday', $this->fieldLabel('Weekday'), self::weekday_options())
        );

        $fields->dataFieldByName('Capacity')?->setDescription(_t(
            self::class . '.CapacityDesc',
            'How many orders this window can take on a single date. 0 = unlimited. A full window is hidden from checkout.'
        ));

        $fields->dataFieldByName('PrepMinutes')?->setDescription(_t(
            self::class . '.PrepMinutesDesc',
            'Minimum minutes needed before this window can start (kitchen / picking time). 0 uses the global default.'
        ));

        $fields->dataFieldByName('Mode')?->setDescription(_t(
            self::class . '.ModeDesc',
            'Whether this window is offered for delivery, for in-store pickup, or for both.'
        ));

        $capacity = $fields->dataFieldByName('Capacity');
        if ($capacity instanceof NumericField) {
            $capacity->setScale(0);
        }

        return $fields;
    }

    /**
     * Whether this window is offered for the requested fulfilment mode.
     * A "both" window matches any request; a request for "both" matches any window.
     */
    public function modeMatches(string $requested): bool
    {
        $mode = (string) $this->Mode;
        if ($mode === self::MODE_BOTH || $requested === self::MODE_BOTH) {
            return true;
        }

        return $mode === $requested;
    }

    /**
     * Effective prep/lead time for this window: the per-slot override when set, else the supplied global default.
     */
    public function prepMinutes(int $globalDefault = 0): int
    {
        $own = (int) $this->PrepMinutes;

        return $own > 0 ? $own : max(0, $globalDefault);
    }

    /**
     * "17:00–18:00" (or an empty string if unset) for summaries and the picker label.
     */
    public function TimeRange(): string
    {
        $start = substr((string) $this->StartTime, 0, 5);
        $end = substr((string) $this->EndTime, 0, 5);
        if ($start === '' && $end === '') {
            return '';
        }

        return trim($start . '–' . $end, '–');
    }

    public function WeekdayLabel(): string
    {
        $options = self::weekday_options();

        return (string) ($options[(int) $this->Weekday] ?? '');
    }

    public function CapacityLabel(): string
    {
        return (int) $this->Capacity > 0
            ? (string) (int) $this->Capacity
            : _t(self::class . '.Unlimited', 'Unlimited');
    }

    public function getTitle(): string
    {
        // Read the raw field, not $this->Title, which would recurse into this getter.
        $label = trim((string) $this->getField('Title'));
        $window = trim($this->WeekdayLabel() . ' ' . $this->TimeRange());

        return $label !== '' ? $label . ' (' . $window . ')' : $window;
    }

    /**
     * ISO-8601 weekday map (1 = Monday ... 7 = Sunday), matching PHP's date('N').
     *
     * @return array<int, string>
     */
    public static function weekday_options(): array
    {
        return [
            1 => _t(self::class . '.Mon', 'Monday'),
            2 => _t(self::class . '.Tue', 'Tuesday'),
            3 => _t(self::class . '.Wed', 'Wednesday'),
            4 => _t(self::class . '.Thu', 'Thursday'),
            5 => _t(self::class . '.Fri', 'Friday'),
            6 => _t(self::class . '.Sat', 'Saturday'),
            7 => _t(self::class . '.Sun', 'Sunday'),
        ];
    }
}
