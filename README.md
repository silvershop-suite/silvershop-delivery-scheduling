# SilverShop Delivery Scheduling

Delivery **and** pickup time-slot scheduling for [SilverShop](https://github.com/silvershop/silvershop-core)
(CMS 6). Let a buyer pick **when** their order arrives — a recurring weekly window with per-slot capacity,
lead/prep time, a same-day cutoff, and an **ASAP or scheduled** choice — at checkout, with the chosen window
stored on the **order** (one window per order, never per line).

This is the **v1** slice. It is the shared timing primitive for both the shop's own local delivery and
`silvershop-suite/click-and-collect` pickup: pickup windows come from here, not a second slot engine.

## How it works

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
- **Checkout picker** — on the stepped-checkout shipping-method step, `ShippingMethodStepExtension` injects a
  date + slot `OptionsetField` (full windows absent) plus an **ASAP** choice via the core
  `updateShippingMethodForm` / `onSetShippingMethod` hooks, and persists the choice onto the order. No core or
  theme edit.

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
| `silvershop/shipping` | The stepped-checkout shipping-method step the picker injects into. |
| `silvershop-suite/click-and-collect` | The pickup fulfilment choice; a pickup order reuses these same windows (pickup mode). |
| `silvershop-suite/shippability` | A cart with pickup-only lines forces pickup, so only pickup windows are offered. |
| `silvershop-suite/product-status` | The cart's longest made-to-order expected date floors the earliest selectable window. |
| `silvershop-suite/delivery-zones` | Per-zone availability + fee + minimum order (companion module); v2 can restrict a window to matched zones. |
| `silvershop-suite/store-locations` | Slots per physical store: a slot linked to a store is only offered on days that store is open (its exceptions, holidays and temporary closures apply). With click-and-collect, a pickup order only sees its own store's slots, on that store's open days. |
| `silvershop-suite/store-profile` | Shop name + address shown on the emails. |
| `silvershop-suite/notification-log` | Logs the scheduling emails from the stamped header (fulfilment audit). |
| `silvershop-suite/order-admin` | Surfaces the window + an out-for-delivery action in the modern order screen (a standalone Scheduling tab otherwise). |

## Configuration

```yaml
SilverShop\DeliveryScheduling\Service\SlotAvailabilityService:
  scheduling_enabled: true     # master switch for the checkout picker
  asap_enabled: true           # offer an "as soon as possible" choice
  horizon_days: 14             # how many days ahead to offer windows
  global_prep_minutes: 0       # default prep/lead time when a slot has no override
  cutoff_time: ''              # e.g. '15:00:00' — same-day orders after this are no longer offered today
```

## Roadmap (v2)

Deliberately **not** in v1 (closed days and per-store slots come from `silvershop-suite/store-locations`, see
above): a date-only mode without time windows; lead time in working days; zone-restricted windows
(`delivery-zones`); a driver
/ route manifest export; an `.ics` attachment on the confirmation; a hard atomic capacity reservation under
concurrency (v1 hides full windows and re-reads capacity at each decision point, mirroring
`silvershop/stock`'s check-based discipline); the single-page-checkout `ShippingCheckoutComponent` picker; and
the fuller storefront (Fable) calendar UX.

## Licence

BSD-3-Clause.

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
