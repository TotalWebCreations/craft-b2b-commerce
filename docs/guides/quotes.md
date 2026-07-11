# Quotes

Request-for-quote flow with a full status lifecycle: `requested → sent → accepted | declined |
expired`. An approved buyer turns a cart into a quote request; a merchant prices it in the
control panel and sends it with an optional validity date that freezes the order's prices; the
buyer accepts from an emailed link and checks out against the frozen prices — pay on account
included.

`accepted`, `declined` and `expired` are terminal. An accepted quote that the buyer never checks
out stays completable at its frozen prices until the merchant cancels the underlying order —
there is no accept deadline. Quote orders (including terminal quote history) are excluded from
Commerce's inactive-cart purge, so a long-lived sent quote or a finished quote's records are
never silently deleted after the purge window.

Gated by the **Quotes** setting (`enableQuotes`); when off, the request-quote endpoint returns a
clean "feature not enabled" failure.

## Customer-initiated flow (storefront)

1. **Request.** From the cart, the buyer submits *Request a quote* (optionally with notes) — see
   `examples/templates/b2b/quotes/_request-button.twig`. The request survives as a
   non-completed order; the buyer keeps a fresh session cart. The store admin is notified by
   email.
2. **Receive.** When the merchant sends the quote, the buyer gets the `B2B: quote sent` email
   with an **Accept** and a **Decline** link, both carrying the quote token.
3. **Accept → checkout.** Accepting adopts the frozen quote order as the buyer's active cart, so
   they proceed straight to checkout against the frozen prices. Pay on account is offered as
   usual (the credit check still runs at completion). Declining records a reason and notifies
   the store admin.
4. **Overview.** `craft.b2b.quotes` lists the company's quotes with status and totals, and
   rebuilds the accept link for a still-sent quote so the buyer can act on it from their account
   too.

### Accept / decline routes

The sent-quote email links to a **site** route with the quote's token in the query string:

```twig
{{ siteUrl('quotes/accept', { token: '…' }) }}
{{ siteUrl('quotes/decline', { token: '…' }) }}
```

Route both at the shipped example template (`examples/templates/b2b/quotes/accept.twig`), for
example in `config/routes.php`:

```php
'quotes/accept' => ['template' => 'b2b/quotes/accept'],
'quotes/decline' => ['template' => 'b2b/quotes/accept'],
```

That page reads the quote with `craft.b2b.quoteByToken(token)` — read-only data (status,
validity, notes, order reference and totals), guarded by the signed-in user's company so it
returns `null` for another company's token. It then posts to two plugin actions, which require a
logged-in company member and the `enableQuotes` feature:

```
b2b-commerce/quotes/accept    (POST: token[, redirect])
b2b-commerce/quotes/decline   (POST: token, reason)
```

The **same token** authorizes both. Token lookup is a single indexed unique match (no
timing-sensitive compare); an unknown token and another company's token return the **same**
generic "This quote is not available." message, so a guessed token cannot be probed. A quote past
its `validUntil` is expired lazily on the first accept/decline touch (or by the
[`quotes/expire` console command](/reference/console-commands) on a cron).

## Merchant flow (control panel)

1. **Workbench.** **B2B → Quotes** lists every quote newest-first, filterable by status, showing
   the company, requester, validity date and a link to the underlying order. Gated by the
   `Manage quotes` permission.
2. **Price the quote.** The quote is a normal, non-completed Commerce order. Open it in the
   standard **Commerce → Orders** editor and adjust line-item prices, add or remove lines, apply
   discounts. Merchant edits in the control panel are never blocked.
3. **Mark sent.** From the workbench, **Mark sent** with an optional **valid-until** date (which
   must be in the future). Sending freezes the order's prices (see *Frozen prices* below) and
   emails the requester accept and decline links.
4. **Merchant override.** Because the buyer-mutation guard stands down for control-panel
   requests, you can still re-open and re-edit a sent quote in the Commerce order editor if a
   price needs correcting before the buyer accepts. Re-running **Mark sent** afterwards is not
   required; the freeze persists on the order.
5. **Decline.** **Decline** records an optional reason and emails the requester that their
   request was declined, including the reason.

## Merchant-initiated quotes (send proactively)

Besides handling customer requests, a merchant can start a quote from scratch. Build a cart in
Commerce's native order editor (**Commerce → Orders → New**), add the customer, set your prices,
then click **Send as B2B quote** (a button injected onto the order-edit screen, gated by
`Manage quotes`). The plugin links the customer's company (auto from their membership, or pick
from the approved companies they belong to), freezes the prices exactly like a
customer-initiated quote, marks it sent, and emails the customer the accept/decline links and
the quote PDF. The quote's **origin** (customer or merchant) is recorded for reporting.

## Frozen prices

When a quote is sent, the plugin pins the order's prices by setting Commerce's
`recalculationMode` to `none` and saving. That mode is a persisted column on the order and is
restored on load *before* the element defaults it, so the freeze survives reloads and every
later save short-circuits recalculation — whatever the merchant left on each line item is
exactly what the buyer will pay.

## Cart-mutation guard

A line-item-frozen quote order — one whose quote is `requested`, `sent` or `accepted` and whose
order is not yet completed — must not have its line items edited through the storefront cart
endpoints. Because `commerce/cart/load-cart` can reactivate any non-completed order by number as
the session cart, the plugin vetoes the save whenever such a quote's line items diverge from
what is stored: **quantity edits, option edits, line-item additions and removals are all
blocked** (line-item notes stay editable). Merchant edits in the control panel remain possible —
the guard only applies to front-end site requests.

Only once the order **completes** (or the quote lands in a `declined`/`expired` terminal status)
does the line-item guard stand down. A separate **completion veto** blocks any attempt to
complete a quote order that reactivated as a cart while its quote is not `accepted`.

## Interaction with order approvals

A purchaser who accepts an over-threshold **quote** is not exempt from approval: the
accepted-quote order is still held by the completion backstop until it also carries an approved
approval, so the purchaser submits that accepted-quote order for approval as normal. See
[Order approvals](/guides/approvals) for the full two-guard interaction.

## PDF downloads

Quote PDFs render through the same PDF service as invoices and statements — see
[PDF documents](/guides/pdf-documents).
