# Events

## `RegisterEvent`

`totalwebcreations\b2bcommerce\events\RegisterEvent`, fired by
`totalwebcreations\b2bcommerce\modules\companies\services\Registration::EVENT_BEFORE_REGISTER`.

This is the plugin's one custom, cancelable event. It fires before a frontend registration is
processed at all, so you can plug in extra checks — rate limiting, disposable-email blocking,
CAPTCHA verification — ahead of the honeypot and the rest of the registration flow (see
[Companies & teams](/guides/companies-teams#registration-flow)).

Set `$event->isValid = false` to cancel; the service then throws with a generic message and
creates nothing (no company, no user).

```php
use yii\base\Event;
use totalwebcreations\b2bcommerce\events\RegisterEvent;
use totalwebcreations\b2bcommerce\modules\companies\services\Registration;

Event::on(
    Registration::class,
    Registration::EVENT_BEFORE_REGISTER,
    function (RegisterEvent $event) {
        if (str_ends_with($event->email, '@blocked.example')) {
            $event->isValid = false;
        }
    }
);
```

The event carries the submitted registration data as public properties:

| Property | Type |
| --- | --- |
| `companyName` | `string` |
| `registrationNumber` | `?string` |
| `taxId` | `?string` |
| `firstName` | `string` |
| `lastName` | `string` |
| `email` | `string` |

## Native Craft & Commerce events

The plugin does not define any other custom events — everything else is built entirely on
**native** Craft and Commerce extension points, which remain available to your own project or
other plugins exactly as documented by Craft/Commerce:

- `craft\commerce\services\Payments::EVENT_BEFORE_PROCESS_PAYMENT` — the payment-time
  approval/budget/credit refusal (see [Order approvals](/guides/approvals#two-layer-enforcement-payment-time-and-completion-time)).
- `craft\commerce\elements\Order::EVENT_BEFORE_ADD_LINE_ITEM` — the price-visibility and
  company-catalog add-to-cart vetoes.
- `craft\commerce\elements\Order::EVENT_BEFORE_SAVE` — the quote/approval line-item freeze guard
  and the VAT-id checkout passthrough.
- `craft\commerce\elements\Order::EVENT_BEFORE_COMPLETE_ORDER` — the quote-completion veto, the
  approval backstop, the account-status backstop, the required-PO-number backstop, the
  per-member and per-department budget backstops, and the credit-limit backstop, in that
  registration order.
- `craft\commerce\elements\Order::EVENT_AFTER_COMPLETE_ORDER` — linking the order to its
  company, releasing the credit/budget locks, and reconciling any stale-pending approval.
  Registration order is load-bearing here (see the source comments in `src/Plugin.php` if you
  need to hook in between).
- `craft\commerce\elements\Order::EVENT_BEFORE_DELETE` and
  `craft\commerce\services\Carts::EVENT_BEFORE_PURGE_INACTIVE_CARTS` — orphan and purge
  protection for quote/approval elements and their business records.

If you need to observe or extend plugin behaviour beyond `RegisterEvent`, hook these native
Craft/Commerce events directly rather than looking for a B2B-specific one — none exists for
these paths.
