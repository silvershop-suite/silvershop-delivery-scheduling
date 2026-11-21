<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Extension;

use SilverShop\Cart\ShoppingCart;
use SilverShop\DeliveryScheduling\Checkout\CheckoutSlotPicker;
use SilverShop\Model\Order;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\Form;

/**
 * Injects the slot picker into the stepped-checkout shipping-method step and persists the chosen window.
 *
 * It attaches to {@link \SilverShop\Page\CheckoutPageController} — the object that silvershop/shipping's
 * CheckoutStepShippingMethod is an extension of, and therefore the one whose ShippingMethodForm() /
 * setShippingMethod() fire `updateShippingMethodForm` and `onSetShippingMethod`. Those hooks only fire when
 * the shipping step is configured, so this extension is inert (no-op) on a site without silvershop/shipping,
 * and no shipping class is referenced here.
 *
 * The single-page checkout gets the picker through
 * {@see \SilverShop\DeliveryScheduling\Checkout\DeliveryScheduleCheckoutComponent}.
 *
 * @extends Extension<\SilverShop\Page\CheckoutPageController>
 */
class ShippingMethodStepExtension extends Extension
{
    /**
     * Add the date + slot picker fields to the shipping-method form.
     *
     * This only appends fields — it deliberately does NOT call $form->setValidator(), which would REPLACE the
     * form validator. silvershop-suite/delivery-zones also hooks updateShippingMethodForm on this same controller and
     * installs its minimum-order validator via setValidator(); appending fields here leaves that intact, so the
     * two modules compose regardless of extension order. The picker validates itself: its own validate() checks the
     * submitted value against the shipping method chosen on the same form, with choices computed for this request.
     */
    public function updateShippingMethodForm(Form $form): void
    {
        $order = ShoppingCart::curr();
        if (!$order instanceof Order) {
            return;
        }

        foreach (CheckoutSlotPicker::fieldsFor($order) as $field) {
            $form->Fields()->push($field);
        }
    }

    /**
     * Persist the chosen window when the shipping method is submitted (the method is already set on the order, so
     * the value is checked once more against that method's context). The core step writes the order after this
     * hook returns.
     *
     * @param mixed $data form data (array)
     * @param mixed $form the submitting form
     */
    public function onSetShippingMethod(Order $order, $data = null, $form = null): void
    {
        CheckoutSlotPicker::applyToOrder($order, is_array($data) ? $data : []);
    }
}
