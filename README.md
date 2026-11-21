# SilverShop Delivery Scheduling

Delivery **and** pickup date scheduling for [SilverShop](https://github.com/silvershop/silvershop-core) (CMS 6).
Let a buyer pick **when** their order arrives or can be collected: **a date only**, or **a date and a time slot**
(recurring weekly windows with capacity), with a preparation time in **working days**, an order cutoff time and an
optional **as soon as possible** choice. The choice is stored on the **order** (one per order, never per line) and
checked again on the server when it is submitted.

It is the shared timing primitive for both the shop's own delivery and `silvershop-suite/click-and-collect` pickup:
pickup dates come from here, not a second engine. With `silvershop-suite/store-locations`, pickup dates follow each
store's own opening days and closing days.

## How it works

- **Settings → Shop → Delivery and pickup dates** (CMS): on/off; what customers choose (**a date only** or **a date
  and a time slot**); preparation time in working days; order cutoff time; days ahead; working days; "as soon as
  possible"; default slot preparation time.
- **Earliest date** = today, or the next day once the cutoff time has passed, plus the preparation time in working
  days. Example: 3 working days with a 16:00 cutoff gives Monday 10:00 → Thursday and Monday 17:00 → Friday.
  Working days are the configured weekdays minus the closing days for all stores (store-locations); for pickup at a
  store they are that store's open days (its hours, exceptions, holidays and temporary closures).
- **Date only**: every working day (or open day of the pickup store) from the earliest date up to the days ahead.
  No slots needed.
- **`DeliverySlot`** — a recurring weekly window template: a weekday, a start/end time, a `Capacity`
  (orders per window; 0 = unlimited), a `Mode` (delivery / pickup / both) and an optional per-slot
  `PrepMinutes`. Managed in a **Delivery scheduling** CMS admin. No dated-event catalogue — windows roll
  forward automatically.
- **`SlotAvailabilityService`** — turns the templates into concrete bookable `(date, slot)` windows for the
  next N days, composing the rules:
  - **earliest feasible** = the window starts at least `now + prep` away (per-slot prep, else the global default);
  - **lead-time floor** = the cart's longest made-to-order expected date (from `silvershop-suite/product-status`,
    soft) floors the earliest selectable date;
  - **same-day cutoff** = once the day's cutoff time passes, today is no longer offered;
  - **capacity** = a window full of placed orders for that date is **hidden**;
  - **ASAP** = the single earliest feasible window.
- **`OrderSchedulingExtension`** — stores the chosen window on the order (`RequestedDate`, a `DeliverySlot`
  relation, and an `IsAsap` flag), exposes `SelectedWindowSummary()`, adds a read-only **Scheduling** tab to
  the order admin, and carries a light dispatch layer (`OutForDelivery` datetime + a one-shot email).
- **Checkout picker** — in the stepped checkout, `ShippingMethodStepExtension` adds the picker to the
  shipping-method step (core `updateShippingMethodForm` / `onSetShippingMethod` hooks); in the single-page checkout,
  `DeliveryScheduleCheckoutComponent` is added to core's component list. No core or theme edit.
  The shipping method is chosen on the same form, so the picker holds the dates of every method (delivery, pickup,
  pickup at each store) and a small script shows only those of the selected method.
- **Server-side check** — the submitted value is checked again on submit against the chosen method, with dates
  computed for that request: a crafted value, another store's date, a full slot or a date that is no longer available
  is refused with a message, and never stored on the order.

Two emails are sent, each self-contained (its own inline-safe `<style>`, like the pickup email) and stamped
with the headers `silvershop-suite/notification-log` reads with zero coupling:

```
X-SilverShop-Order-ID: {OrderID}
X-SilverShop-Notification-Key: delivery.scheduled        (on placement — confirms the window)
X-SilverShop-Notification-Key: delivery.out_for_delivery (when marked out for delivery)
```

## Installation

```bash
composer require silvershop-suite/delivery-scheduling
```

Run `dev/build?flush=all`. In **Delivery scheduling** (CMS) add the recurring windows (weekday, time,
capacity, mode). The picker then appears on the checkout shipping-method step.

Requires `silvershop/core ^6.2` (framework + cms ^6). Everything below is optional and auto-detected:

| Module | What it adds |
|---|---|
| `silvershop/shipping` | The shipping methods (delivery / pickup per store) the dates depend on, and the stepped-checkout shipping-method step the picker is added to. |
| `silvershop-suite/click-and-collect` | The pickup fulfilment choice; a pickup order reuses these same windows (pickup mode). |
| `silvershop-suite/shippability` | A cart with pickup-only lines forces pickup, so only pickup windows are offered. |
| `silvershop-suite/product-status` | The cart's longest made-to-order expected date floors the earliest selectable window. |
| `silvershop-suite/delivery-zones` | Per-zone availability + fee + minimum order (companion module); v2 can restrict a window to matched zones. |
| `silvershop-suite/store-locations` | Pickup at a store follows that store's open days and closing days (for the dates and for counting working days); closing days for all stores apply to delivery too. A slot linked to a store is only offered on days that store is open, and a pickup order only sees its own store's slots. |
| `silvershop-suite/store-profile` | Shop name + address shown on the emails. |
| `silvershop-suite/notification-log` | Logs the scheduling emails from the stamped header (fulfilment audit). |
| `silvershop-suite/order-admin` | Surfaces the window + an out-for-delivery action in the modern order screen (a standalone Scheduling tab otherwise). |

## Configuration

Everything is set in the CMS under **Settings → Shop → Delivery and pickup dates** (defaults: on, time slots, "as soon
as possible" on, no preparation time, no cutoff, 14 days ahead, Monday to Friday).

Which shipping methods count as pickup (default: click-and-collect's `PickupShippingMethod`; a pickup method's store
comes from its `PickupStoreLocation()`):

```yaml
SilverShop\DeliveryScheduling\Checkout\CheckoutSlotPicker:
  pickup_method_classes:
    - 'SilverShop\ClickAndCollect\Model\PickupShippingMethod'
```

## Roadmap

Not yet: capacity per date in date-only mode; a production / pickup list per store per day; the chosen date as a
column and filter in the order list (`silvershop-suite/order-admin`); zone-restricted windows (`delivery-zones`); a
driver / route manifest export; an `.ics` attachment on the confirmation; a hard atomic capacity reservation under
concurrency (full windows are hidden and capacity is re-read at each decision point, mirroring `silvershop/stock`'s
check-based discipline); and a calendar-style picker in the storefront.

## Licence

BSD-3-Clause.

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
