<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Checkout;

use SilverShop\Checkout\Component\CheckoutComponent;
use SilverShop\Model\Order;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;

/**
 * The date (and slot) picker in the single-page checkout. Added to core's SinglePageCheckoutComponentConfig through
 * the Injector (see _config/delivery-scheduling.yml); the stepped checkout uses {@see ShippingMethodStepExtension}.
 *
 * The field checks itself against the shipping method chosen on the same form; {@see validateData()} checks the
 * value again against the order, and {@see setData()} only stores a value that is still bookable.
 */
class DeliveryScheduleCheckoutComponent extends CheckoutComponent
{
    public function getFormFields(Order $order): FieldList
    {
        // validateData() checks the value against the submitted shipping method, so the field doesn't repeat it.
        return CheckoutSlotPicker::fieldsFor($order, false);
    }

    public function getRequiredFields(Order $order): array
    {
        return [];
    }

    /**
     * Checks the value against the shipping method submitted on the same form (another component's field, read from
     * the request), else against the order as it stands.
     */
    public function validateData(Order $order, array $data): bool
    {
        $choice = (string) ($data[CheckoutSlotPicker::FIELD] ?? '');
        if ($choice === '' || CheckoutSlotPicker::isChoiceValid($order, $choice, $this->submittedMethodId())) {
            return true;
        }

        $result = ValidationResult::create();
        $result->addFieldError(CheckoutSlotPicker::FIELD, _t(
            self::class . '.Unavailable',
            'This date is not available (any more). Please choose another one.'
        ));
        throw ValidationException::create($result);
    }

    public function getData(Order $order): array
    {
        if ((bool) $order->getField('IsAsap')) {
            return [CheckoutSlotPicker::FIELD => CheckoutSlotPicker::ASAP];
        }

        $date = (string) $order->getField('RequestedDate');
        if ($date === '') {
            return [];
        }

        return [CheckoutSlotPicker::FIELD => (int) $order->getField('DeliverySlotID') . '|' . substr($date, 0, 10)];
    }

    public function setData(Order $order, array $data): Order
    {
        CheckoutSlotPicker::applyToOrder($order, $data, $this->submittedMethodId());

        return $order;
    }

    /**
     * The shipping method chosen on the same single-page form (silvershop/shipping's component field, whose name
     * ends in "ShippingMethodID"), or null when the form has none.
     */
    protected function submittedMethodId(): ?int
    {
        $controller = Controller::curr();
        if ($controller === null) {
            return null;
        }
        foreach ((array) $controller->getRequest()->postVars() as $name => $value) {
            if (is_string($name) && str_ends_with($name, 'ShippingMethodID') && is_scalar($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }
}
