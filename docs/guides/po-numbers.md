# PO numbers

A signed-in buyer can set (or clear) a purchase order / reference number on their current cart.
The PO is stored separately from Commerce's own auto-generated `reference` — it lives in a
dedicated `b2b_order_references` table keyed by order id, so the row is available from the
moment a cart exists.

## Setting a PO number

`examples/templates/b2b/checkout/po-number.twig` posts to
`b2b-commerce/checkout/set-reference`, which requires a signed-in company member (`requireLogin`
plus a company-membership check — a signed-in shopper with no company cannot set one).

The PO is available on any order as `order.b2bPoNumber` — in Twig, in PHP, and inside Commerce
order-email templates, because the plugin exposes it via an `Order` behavior attached to every
order. Merchants add the Twig expression below to their Commerce order email templates:

```twig
{{ order.b2bPoNumber }}
```

It is already read by the plugin's own quote/invoice PDF templates
(`src/templates/pdf/quote.twig`, `src/templates/pdf/invoice.twig`) — see
[PDF documents](/guides/pdf-documents).

## Requiring a PO number

A company can be configured with **Require purchase order number** (`requirePoNumber`), a
per-company field on the company edit screen. When set, checkout is refused — a completion
backstop on `Order::EVENT_BEFORE_COMPLETE_ORDER` — until a PO is set on the cart. Like the other
completion guards, this is storefront-scoped: console and control-panel completions are the
merchant override.

## GraphQL

The `setPoNumber` write mutation sets the PO number on the caller's active cart — see the
[GraphQL reference](/reference/graphql#write-mutations). The PO number is also surfaced as
`poNumber` on `quotes`, `myApprovalRequests` and `pendingApprovals` under `b2bContext`, since
Commerce 5 ships no GraphQL `Order` type or top-level `order` query.
