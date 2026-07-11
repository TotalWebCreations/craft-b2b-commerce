# Quick order & order lists

Gated by the **Quick order** setting (`enableQuickOrder`); when off, these front-end endpoints
return a clean "feature not enabled" failure and `craft.b2b` exposes no order-list data.

## Quick order (SKU paste)

Approved buyers can add many products to the cart at once by pasting SKUs. The
`b2b/quick-order/index.twig` example ships a textarea that posts to
`b2b-commerce/quick-order/add`. Enter **one SKU per line**, optionally followed by a quantity.
The quantity separator is auto-detected per line, in this order: **tab**, **comma**,
**semicolon**, then any run of **whitespace** (a space). A bare SKU with no quantity defaults to
`1`:

```
WIDGET-01	5      ← tab-separated
WIDGET-02,3        ← comma
WIDGET-03;2        ← semicolon
WIDGET-04 10       ← space
WIDGET-05          ← bare SKU, quantity defaults to 1
```

SKU matching is **case-insensitive**, and duplicate SKUs (in any casing) are merged — their
quantities are summed onto the first occurrence. Blank lines are skipped. The action returns
JSON `{ added, errors }`, where `errors` is keyed by the **original 1-based line number** so you
can report problems against the exact line the buyer typed. Unknown SKUs, unavailable products,
invalid quantities and cart vetoes each surface as a per-line error while the valid lines are
still added.

### CSV upload

The same page also posts a file to `b2b-commerce/quick-order/upload-csv` (a
`multipart/form-data` form with a `csvFile` input). The upload is capped at 1 MB, must be a
text/CSV MIME type, and its contents are fed through the **exact same parser** as the textarea —
so the format, error reporting and casing/duplicate rules above apply identically. A UTF-8
byte-order mark is stripped automatically.

### Re-order

`b2b/orders/_reorder-button.twig` posts a completed order's id to
`b2b-commerce/quick-order/reorder`, which copies that order's still-available line items into
the current cart. A buyer may re-order an order they placed **or** any order that belongs to
their own company — so colleagues can repeat each other's orders. Only completed orders can be
re-ordered; unavailable line items surface as per-position errors.

## Order lists

Order lists are named, **company-scoped** collections of products a team keeps around to drop
into the cart in one go. They are shared work material, not team administration: by policy
**any** company member — regardless of role (admin, purchaser or approver) — may view, create,
rename and delete lists, edit their items and add a list to the cart. Every list is scoped to the
buyer's company and each action re-checks that ownership, so one company can never touch
another's lists.

Read a company's lists with `craft.b2b.orderLists`, which returns an array of
`{ id, name, createdByUserId, itemCount }` rows, and read a single list's items with
`craft.b2b.getOrderListItems(listId)` (usable as `craft.b2b.orderListItems(listId)` in Twig),
which returns `{ purchasableId, qty, sku, description }` rows guarded by the current user's
company:

```twig
{% for list in craft.b2b.orderLists %}
    <p>{{ list.name }} — {{ list.itemCount }} {{ 'items'|t('b2b-commerce') }}</p>

    {% for item in craft.b2b.orderListItems(list.id) %}
        <span>{{ item.sku }} × {{ item.qty }}</span>
    {% endfor %}
{% endfor %}
```

Lists are managed through the `b2b-commerce/order-lists/create`, `.../rename`, `.../delete`,
`.../set-item` and `.../add-to-cart` actions. Setting an item's quantity to `0` removes it from
the list. Adding a list to the cart reuses the quick-order add-path, so line-item vetoes are
honoured and missing or unavailable products surface as per-position errors. See
`examples/templates/b2b/order-lists/index.twig` and `_detail.twig` for a complete overview and
item editor.
