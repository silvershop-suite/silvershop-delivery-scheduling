<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Extension;

use SilverShop\DeliveryScheduling\Email\DeliveryNotificationEmail;
use SilverShop\DeliveryScheduling\Model\DeliverySlot;
use SilverShop\Model\Order;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * The order-level fulfilment window: the requested date, the chosen recurring {@link DeliverySlot}, and the
 * ASAP flag — one fact about the whole order (you get one delivery / one collection), never per line.
 *
 * It is the shared storage for BOTH self-delivery and click & collect pickup: whichever fulfilment method the
 * buyer picks, the chosen window lives in these same fields. It also carries a light dispatch layer
 * (OutForDelivery datetime + the one-shot email), mirroring click-and-collect's ready-for-pickup transition.
 *
 * @property ?string $RequestedDate
 * @property bool $IsAsap
 * @property ?string $OutForDelivery
 * @property int $DeliverySlotID
 * @method DeliverySlot DeliverySlot()
 * @extends Extension<Order>
 */
class OrderSchedulingExtension extends Extension
{
    private static array $db = [
        'RequestedDate' => 'Date',
        'IsAsap' => 'Boolean',
        'OutForDelivery' => 'Datetime',
    ];

    private static array $has_one = [
        'DeliverySlot' => DeliverySlot::class,
    ];

    /**
     * Pending out-for-delivery sends keyed by {@see spl_object_id()} of the owning order, captured in
     * onBeforeWrite and consumed in onAfterWrite. Extension instances are reused across owners, so this is
     * keyed, never a bare scalar (the pattern click-and-collect uses for the ready-for-pickup mail).
     *
     * @var array<int, bool>
     */
    private array $pendingOutForDelivery = [];

    /**
     * Whether the order carries a chosen fulfilment window (a scheduled slot, a requested date, or ASAP).
     */
    public function HasSchedule(): bool
    {
        $owner = $this->getOwner();

        return (bool) $owner->getField('IsAsap')
            || (int) $owner->getField('DeliverySlotID') > 0
            || (string) $owner->getField('RequestedDate') !== '';
    }

    public function IsAsapDelivery(): bool
    {
        return (bool) $this->getOwner()->getField('IsAsap');
    }

    /**
     * The chosen recurring window, or null for an ASAP / unscheduled order.
     */
    public function ScheduledSlot(): ?DeliverySlot
    {
        $id = (int) $this->getOwner()->getField('DeliverySlotID');
        if ($id <= 0) {
            return null;
        }

        $slot = DeliverySlot::get()->byID($id);

        return $slot instanceof DeliverySlot ? $slot : null;
    }

    /**
     * Human-readable chosen window for emails, the order admin and the confirmation page, or an empty string.
     */
    public function SelectedWindowSummary(): string
    {
        if ($this->IsAsapDelivery()) {
            return _t(self::class . '.Asap', 'As soon as possible');
        }

        $owner = $this->getOwner();
        $date = (string) $owner->getField('RequestedDate');
        $slot = $this->ScheduledSlot();
        if ($date === '' && $slot === null) {
            return '';
        }

        $parts = [];
        if ($date !== '') {
            $ts = strtotime($date);
            $parts[] = $ts === false ? $date : date('D j M Y', $ts);
        }
        if ($slot instanceof DeliverySlot) {
            $range = $slot->TimeRange();
            if ($range !== '') {
                $parts[] = $range;
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * On placement, confirm the chosen window to the buyer (best-effort). Fires only for a scheduled order.
     */
    public function onPlaceOrder(): void
    {
        if (!$this->HasSchedule()) {
            return;
        }

        DeliveryNotificationEmail::send_scheduled($this->getOwner());
    }

    /**
     * Stamp the order out for delivery. Idempotent; the empty->set transition emails the buyer once.
     */
    public function markOutForDelivery(): bool
    {
        $owner = $this->getOwner();
        if ($owner->getField('OutForDelivery')) {
            return false;
        }

        $owner->setField('OutForDelivery', DBDatetime::now()->Rfc2822());
        $owner->write();

        return true;
    }

    public function IsOutForDelivery(): bool
    {
        return (bool) $this->getOwner()->getField('OutForDelivery');
    }

    /**
     * Capture an empty->set OutForDelivery transition so onAfterWrite can fire the email exactly once (whether
     * set via {@link markOutForDelivery()} or by editing the CMS field directly).
     */
    public function onBeforeWrite(): void
    {
        $owner = $this->getOwner();
        $changed = $owner->getChangedFields(['OutForDelivery'], DataObject::CHANGE_VALUE);
        if (!isset($changed['OutForDelivery'])) {
            return;
        }

        $before = $changed['OutForDelivery']['before'] ?? null;
        $after = $changed['OutForDelivery']['after'] ?? null;
        if (empty($before) && !empty($after)) {
            $this->pendingOutForDelivery[spl_object_id($owner)] = true;
        }
    }

    public function onAfterWrite(): void
    {
        $owner = $this->getOwner();
        $key = spl_object_id($owner);
        if (empty($this->pendingOutForDelivery[$key])) {
            return;
        }

        unset($this->pendingOutForDelivery[$key]);
        DeliveryNotificationEmail::send_out_for_delivery($owner);
    }

    public function updateCMSFields(FieldList $fields): void
    {
        $owner = $this->getOwner();
        if (!$owner->isInDB()) {
            return;
        }

        $fields->findOrMakeTab('Root.Scheduling', _t(self::class . '.Tab', 'Delivery scheduling'));

        $summary = $this->SelectedWindowSummary();
        if ($summary !== '') {
            $fields->addFieldToTab('Root.Scheduling', LiteralField::create(
                'ScheduledWindow',
                '<div class="field"><label class="left">'
                . Convert::raw2xml(_t(self::class . '.Window', 'Requested window'))
                . '</label><div class="middleColumn"><p class="form__field-description">'
                . Convert::raw2xml($summary)
                . '</p></div></div>'
            ));
        }

        $fields->addFieldToTab(
            'Root.Scheduling',
            DatetimeField::create('OutForDelivery', _t(self::class . '.OutForDelivery', 'Out for delivery'))
                ->setDescription(_t(
                    self::class . '.OutForDeliveryDesc',
                    'Set this to notify the customer their order is on its way. Saving emails them once.'
                ))
        );
    }
}
