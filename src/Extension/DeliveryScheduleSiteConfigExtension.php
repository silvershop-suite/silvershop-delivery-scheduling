<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Extension;

use SilverShop\DeliveryScheduling\Model\DeliverySlot;
use SilverShop\DeliveryScheduling\Service\ScheduleSettings;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Forms\TimeField;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * The delivery / pickup date settings, under Settings → Shop. Read through {@see ScheduleSettings}.
 *
 * @property bool $DeliveryScheduleEnabled
 * @property string $DeliveryScheduleMode         slots | date
 * @property bool $DeliveryScheduleAsap
 * @property int $DeliveryScheduleLeadDays        working days before the earliest date
 * @property ?string $DeliveryScheduleCutoff      HH:MM:SS; after it the order counts from the next day
 * @property int $DeliveryScheduleHorizonDays     how many days ahead to offer
 * @property ?string $DeliveryScheduleWorkingDays JSON list of ISO weekdays (1 = Monday), as the checkbox set stores it;
 *                                                empty = Monday to Friday
 * @property int $DeliverySchedulePrepMinutes     default prep time of a time slot
 * @extends Extension<SiteConfig>
 */
class DeliveryScheduleSiteConfigExtension extends Extension
{
    private static array $db = [
        'DeliveryScheduleEnabled' => 'Boolean(1)',
        'DeliveryScheduleMode' => "Enum('slots,date','slots')",
        'DeliveryScheduleAsap' => 'Boolean(1)',
        'DeliveryScheduleLeadDays' => 'Int',
        'DeliveryScheduleCutoff' => 'Time',
        'DeliveryScheduleHorizonDays' => 'Int(14)',
        'DeliveryScheduleWorkingDays' => 'Varchar(64)',
        'DeliverySchedulePrepMinutes' => 'Int',
    ];

    /** For a new SiteConfig; existing ones get the same values from the column defaults on dev/build. */
    private static array $defaults = [
        'DeliveryScheduleEnabled' => true,
        'DeliveryScheduleMode' => 'slots',
        'DeliveryScheduleAsap' => true,
        'DeliveryScheduleHorizonDays' => 14,
    ];

    public function updateCMSFields(FieldList $fields): void
    {
        $prefix = self::class . '.';
        $workingDays = CheckboxSetField::create(
            'DeliveryScheduleWorkingDays',
            _t($prefix . 'WorkingDays', 'Working days'),
            DeliverySlot::weekday_options()
        )->setDescription(_t(
            $prefix . 'WorkingDaysDesc',
            'Days on which orders are prepared and delivered. The preparation time counts these days. Pickup at a '
            . 'store follows that store\'s opening hours and closing days instead. Nothing ticked = Monday to Friday.'
        ));
        if ((string) $this->getOwner()->getField('DeliveryScheduleWorkingDays') === '') {
            $workingDays->setValue(ScheduleSettings::DEFAULT_WORKING_DAYS);
        }

        $fields->addFieldsToTab('Root.Shop', [
            HeaderField::create('DeliveryScheduleHeader', _t($prefix . 'Header', 'Delivery and pickup dates')),
            CheckboxField::create('DeliveryScheduleEnabled', _t($prefix . 'Enabled', 'Let customers choose a date'))
                ->setDescription(_t(
                    $prefix . 'EnabledDesc',
                    'Shows a delivery / pickup date choice in the checkout.'
                )),
            OptionsetField::create('DeliveryScheduleMode', _t($prefix . 'Mode', 'What customers choose'), [
                ScheduleSettings::MODE_SLOTS => _t($prefix . 'ModeSlots', 'A date and a time slot'),
                ScheduleSettings::MODE_DATE => _t($prefix . 'ModeDate', 'A date only'),
            ])->setDescription(_t(
                $prefix . 'ModeDesc',
                'Time slots are managed under Delivery scheduling. With "a date only" no slots are needed.'
            )),
            NumericField::create(
                'DeliveryScheduleLeadDays',
                _t($prefix . 'LeadDays', 'Preparation time (working days)')
            )
                ->setScale(0)
                ->setDescription(_t(
                    $prefix . 'LeadDaysDesc',
                    'The earliest date is this many working days after the order. 0 = the same day.'
                )),
            TimeField::create('DeliveryScheduleCutoff', _t($prefix . 'Cutoff', 'Order cutoff time'))
                ->setDescription(_t(
                    $prefix . 'CutoffDesc',
                    'Orders placed after this time count from the next day. Leave empty for no cutoff.'
                )),
            NumericField::create('DeliveryScheduleHorizonDays', _t($prefix . 'HorizonDays', 'Days ahead'))
                ->setScale(0)
                ->setDescription(_t($prefix . 'HorizonDaysDesc', 'How many days ahead customers can choose.')),
            $workingDays,
            CheckboxField::create('DeliveryScheduleAsap', _t($prefix . 'Asap', 'Offer "as soon as possible"'))
                ->setDescription(_t(
                    $prefix . 'AsapDesc',
                    'An extra choice for the earliest possible date.'
                )),
            NumericField::create(
                'DeliverySchedulePrepMinutes',
                _t($prefix . 'PrepMinutes', 'Default slot preparation time (minutes)')
            )
                ->setScale(0)
                ->setDescription(_t(
                    $prefix . 'PrepMinutesDesc',
                    'Time slots only: minutes needed before a slot can start, unless the slot sets its own.'
                )),
        ]);
    }
}
