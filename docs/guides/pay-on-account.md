# Pay on account & credit

## The invoice gateway

To let approved companies check out on invoice, create the gateway in the control panel under
**Commerce → System Settings → Gateways → New gateway** and pick the **Pay on account** type. Add
one gateway per store. It behaves like Commerce's Manual gateway (authorize-only; the order
completes unpaid so you can capture and invoice out of band) and shows up at checkout only when:

1. the **Pay on account** setting (`enableInvoicing`) is on;
2. the customer belongs to a company that is **approved** and has **Allow pay on account**
   enabled on its company record.

Credit limits are enforced on the storefront. The gateway is only offered at checkout while a
new order fits inside the company's remaining credit, and completion is checked again — under a
per-company lock — so two orders completing at once cannot both slip past the limit.

## Credit limits

A company's **credit limit** is the total it may owe on unpaid pay-on-account orders at once. An
**empty** credit limit means **no credit room at all**, not unlimited credit: a company with no
limit set can never pay on account, and the gateway is never offered to it. Give a company a
positive limit to let it order on invoice up to that amount. Each completed invoice order draws
down the remaining room until it is paid off.

Credit limits are **single-currency**: a limit is a plain amount with no currency of its own, so
it is compared against — and every credit figure is formatted in — your primary store's
currency. Running credit across multiple store currencies is not supported.

Enforcement is deliberately scoped to storefront (site) requests. Completing an over-limit order
from the control panel is treated as a business override: an admin doing so is making an
informed, merchant-initiated decision, so console and CP completions are never refused. Only the
customer-facing checkout path is hard-enforced.

## Reading the credit position

`craft.b2b.creditSummary` returns the signed-in user's company credit position as a
`{ outstanding, creditLimit, available }` array (or `null` when the visitor has no company).
`outstanding` is the unpaid balance across the company's completed pay-on-account orders,
`creditLimit` is the configured limit (or `null` when none is set), and `available` is the room
left under the limit — never below zero — (or `null` when there is no limit).

```twig
{% set summary = craft.b2b.creditSummary %}

{% if summary %}
    <p>{{ 'Outstanding balance'|t('b2b-commerce') }}: {{ summary.outstanding|currency }}</p>
    {% if summary.available is not null %}
        <p>{{ 'Available credit'|t('b2b-commerce') }}: {{ summary.available|currency }}</p>
    {% endif %}
{% endif %}
```

The same figures are shown to merchants in the control panel on a company's **Orders** page,
alongside a per-order payment status (paid / partially paid / unpaid).

## Marking an invoice as paid

The plugin ships **no** custom "mark as paid" button. Because pay-on-account uses an offline
gateway, you record a payment the standard Commerce way: open the order in the control panel
order editor and add a transaction under **Payments** (or use **Update order status** / the
payment actions). The company's outstanding balance and available credit are derived live from
those transactions, so recording an offline payment there immediately lowers the outstanding
balance everywhere it is shown.

### Settled orders (cancelled / refunded)

The outstanding balance is measured as each order's live outstanding balance (total minus paid),
so a refund on its own lowers the paid amount and a **fully refunded** order would otherwise
count as outstanding again. To stop that phantom debt, orders whose **Commerce order status** is
one of the *settled* handles are dropped from the balance entirely. The default settled handles
are `cancelled` and `refunded`; change them with the **Settled order statuses** setting
(`excludedOrderStatusHandles`, a comma-separated list of order-status handles). Give a cancelled
or refunded order that status and its receivable no longer eats into the company's credit room.
To write off any other order, adjust the company's credit limit or record a payment covering the
balance.

## Payment term & due date

Each completed order exposes its **payment due date** — the order date plus the company's
payment term (`paymentTermDays`) — through `order.b2bPaymentDueDate`. It returns a `DateTime`
(or `null` when the order is not completed, is a guest order, or the company has no payment term
configured). This feeds order-confirmation emails, invoice PDFs and the
[account statement / dunning](/guides/statements-dunning) flow.

## How the guards interact

Approval, per-member budget, department budget and credit are all independent gates that must
each pass — a member can be under budget while their company is over its credit limit, and vice
versa. See [Order approvals](/guides/approvals) for the shared two-layer (payment-time /
completion-time) enforcement pattern, which the credit limit follows identically.
