<?php

declare(strict_types=1);

namespace SilverShop\DeliveryScheduling\Forms;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Model\ArrayData;
use SilverStripe\View\Requirements;

/**
 * The date / time choice of the checkout. One radio list that holds the choices of every fulfilment context on the
 * form (delivery, pickup, pickup at each store), because the shipping method is chosen on the same form: each option
 * carries the contexts it is valid for, and a small script shows only the options of the selected shipping method.
 *
 * On submit the field checks the value against the context of the submitted shipping method, using choices that
 * are computed again for that request: a crafted value, another store's date or a date that has become unavailable
 * is refused with a message.
 */
class SchedulePickerField extends OptionsetField
{
    /** @var array<string, string[]> option value => context keys it is valid for */
    private array $optionContexts = [];

    /** @var array<int, string> shipping method ID => context key */
    private array $methodContexts = [];

    private string $defaultContext = '';

    private bool $checksContext = true;

    /**
     * @param array<string, string[]> $optionContexts
     */
    public function setOptionContexts(array $optionContexts): static
    {
        $this->optionContexts = $optionContexts;

        return $this;
    }

    /**
     * @param array<int, string> $methodContexts
     */
    public function setMethodContexts(array $methodContexts): static
    {
        $this->methodContexts = $methodContexts;

        return $this;
    }

    public function setDefaultContext(string $context): static
    {
        $this->defaultContext = $context;

        return $this;
    }

    /**
     * Turn off the field's own context check when something else checks the value against the shipping method (the
     * single-page checkout component), so the customer doesn't get the same message twice.
     */
    public function setChecksContext(bool $checks): static
    {
        $this->checksContext = $checks;

        return $this;
    }

    public function getDefaultContext(): string
    {
        return $this->defaultContext;
    }

    /**
     * The option values valid for a context.
     *
     * @return string[]
     */
    public function valuesForContext(string $context): array
    {
        $values = [];
        foreach ($this->optionContexts as $value => $contexts) {
            if (in_array($context, $contexts, true)) {
                $values[] = (string) $value;
            }
        }

        return $values;
    }

    /**
     * The context of the shipping method submitted on the same form, else the default context.
     */
    public function submittedContext(): string
    {
        $form = $this->getForm();
        if ($form !== null) {
            foreach ($form->Fields()->dataFields() as $field) {
                if (str_ends_with((string) $field->getName(), 'ShippingMethodID')) {
                    $id = (int) $field->dataValue();
                    if (isset($this->methodContexts[$id])) {
                        return $this->methodContexts[$id];
                    }
                }
            }
        }

        return $this->defaultContext;
    }

    public function getAttributes()
    {
        return array_merge(parent::getAttributes(), [
            'data-schedule-picker' => '1',
            'data-schedule-methods' => (string) json_encode((object) $this->methodContexts),
            'data-schedule-default' => $this->defaultContext,
        ]);
    }

    public function Field($properties = [])
    {
        Requirements::customScript($this->script(), 'silvershop-delivery-schedule-picker');
        Requirements::customCSS(
            '[data-schedule-picker] li[hidden]{display:none}',
            'silvershop-delivery-schedule-picker'
        );

        return parent::Field($properties);
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!$result->isValid() || !$this->checksContext) {
            return $result;
        }

        $value = (string) $this->getValue();
        $valid = $this->valuesForContext($this->submittedContext());

        if ($value === '') {
            if ($valid !== []) {
                $result->addFieldError($this->getName(), _t(self::class . '.Required', 'Please choose a date.'));
            }
        } elseif (!in_array($value, $valid, true)) {
            $result->addFieldError($this->getName(), _t(
                self::class . '.Unavailable',
                'This date is not available (any more) for the chosen delivery or pickup. Please choose another one.'
            ));
        }

        return $result;
    }

    protected function getFieldOption($value, $title, $odd)
    {
        $option = parent::getFieldOption($value, $title, $odd);
        if ($option instanceof ArrayData) {
            $option->setField('Contexts', implode(' ', $this->optionContexts[(string) $value] ?? []));
        }

        return $option;
    }

    /**
     * Shows only the options of the selected shipping method's context, and keeps a valid option selected.
     */
    private function script(): string
    {
        return <<<'JS'
(function () {
    function sync(list) {
        var form = list.closest('form');
        var methods = {};
        try { methods = JSON.parse(list.getAttribute('data-schedule-methods') || '{}'); } catch (e) { methods = {}; }
        var context = list.getAttribute('data-schedule-default') || '';
        if (form) {
            var picked = form.querySelector('input[type="radio"][name$="ShippingMethodID"]:checked')
                || form.querySelector('select[name$="ShippingMethodID"]');
            if (picked && methods[picked.value]) { context = methods[picked.value]; }
        }
        var first = null;
        var checkedVisible = false;
        list.querySelectorAll('li').forEach(function (li) {
            var input = li.querySelector('input');
            var contexts = (li.getAttribute('data-contexts') || '').split(' ');
            var show = contexts.indexOf(context) !== -1;
            li.hidden = !show;
            if (input) {
                input.disabled = !show;
                if (show && !first) { first = input; }
                if (show && input.checked) { checkedVisible = true; }
            }
        });
        if (!checkedVisible && first) { first.checked = true; }
        var empty = list.parentNode ? list.parentNode.querySelector('[data-schedule-empty]') : null;
        if (empty) { empty.hidden = !!first; }
    }
    function init() {
        document.querySelectorAll('[data-schedule-picker]').forEach(function (list) {
            sync(list);
            var form = list.closest('form');
            if (form) {
                form.addEventListener('change', function (e) {
                    var name = (e.target && e.target.name) || '';
                    if (name.slice(-16) === 'ShippingMethodID') { sync(list); }
                });
            }
        });
    }
    if (document.readyState !== 'loading') { init(); } else { document.addEventListener('DOMContentLoaded', init); }
})();
JS;
    }
}
