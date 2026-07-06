# B2B Commerce — example storefront templates

These are **plain, unstyled starting-point templates** for the B2B Commerce
storefront. They carry no CSS framework and only minimal inline structure on
purpose: copy them into your site, wire up the routes below, then restyle and
extend them to match your storefront. They are meant to be edited, not used
verbatim in production.

## What's here

Everything lives under `templates/b2b/`:

| Template | Purpose |
| --- | --- |
| `_layout.twig` | Shared base layout: doctype, the B2B account nav and a `content` block every page fills. All pages extend it. |
| `account/index.twig` | Account overview (company + status) — the account landing page. |
| `account/credit.twig` | Company credit summary (`craft.b2b.creditSummary`). |
| `account/budget.twig` | The member's own spending budget (`craft.b2b.memberBudget`). |
| `register.twig` | Public company registration form. |
| `team/index.twig` | Team management for admins (invite, change role, remove). |
| `addresses/index.twig` | Shared company address book (add / edit / delete). |
| `quick-order/index.twig` | Quick order: paste SKUs or upload a CSV. |
| `order-lists/index.twig` | Shared order lists overview. |
| `order-lists/_detail.twig` | A single order list's item editor. |
| `quotes/index.twig` | The company's quotes overview. |
| `quotes/accept.twig` | The accept / decline page reached from a sent-quote email. |
| `approvals/index.twig` | The approver queue plus the buyer's own requests. |
| `product-price.twig` | Price / add-to-cart partial that respects price visibility (`{% include %}` on your product page). |
| `orders/_reorder-button.twig` | Reorder button partial (`{% include %}` in an order row). |
| `quotes/_request-button.twig` | "Request a quote" button partial for the cart page. |
| `approvals/_submit-button.twig` | "Submit for approval" button partial for the cart page. |

Every full page starts with a header comment explaining what it does and which
controller action or `craft.b2b` variable it uses. Templates whose name starts
with an underscore (`_layout`, `_detail`, `_reorder-button`, …) are partials or
routed views and are never served directly by Craft.

## 1. Copy the templates

From your project root:

```bash
cp -R vendor/totalwebcreations/craft-b2b-commerce/examples/templates/b2b templates/b2b
```

## 2. Register the routes

Most pages (`b2b/register`, `b2b/team`, `b2b/addresses`, `b2b/quick-order`,
`b2b/order-lists`, `b2b/quotes`, `b2b/approvals`, `b2b/account`,
`b2b/account/credit`, `b2b/account/budget`) resolve automatically through Craft's
template routing, and the nav in `_layout.twig` links to exactly those paths.
Two routes you **must** register by hand in `config/routes.php`:

```php
return [
    // The order-list detail template is a partial (underscore prefix), and it
    // needs the list id captured from the URL.
    'b2b/order-lists/<listId:\d+>' => ['template' => 'b2b/order-lists/_detail'],

    // The sent-quote email links to `quotes/accept` and `quotes/decline` at the
    // site root, both carrying the quote token. Point both at the accept page —
    // it handles accepting and declining from the one token.
    'quotes/accept' => ['template' => 'b2b/quotes/accept'],
    'quotes/decline' => ['template' => 'b2b/quotes/accept'],
];
```

If you prefer explicit routing for everything (recommended for real sites), add
the landing pages too, for example:

```php
    'b2b/register' => ['template' => 'b2b/register'],
    'b2b/account' => ['template' => 'b2b/account/index'],
    'b2b/account/credit' => ['template' => 'b2b/account/credit'],
    'b2b/account/budget' => ['template' => 'b2b/account/budget'],
    'b2b/team' => ['template' => 'b2b/team/index'],
    'b2b/addresses' => ['template' => 'b2b/addresses/index'],
    'b2b/quick-order' => ['template' => 'b2b/quick-order/index'],
    'b2b/order-lists' => ['template' => 'b2b/order-lists/index'],
    'b2b/quotes' => ['template' => 'b2b/quotes/index'],
    'b2b/approvals' => ['template' => 'b2b/approvals/index'],
```

The **resume-checkout**, **approve**, **decline** and other write flows are POST
actions handled by the plugin's own controllers (for example
`b2b-commerce/approvals/resume`) — those need no site route; they are posted from
the pages above.

## 3. Wire up the partials

The four partials are not standalone pages. Include them where they belong:

```twig
{# On a product / variant page #}
{% include 'b2b/product-price' with { variant } %}

{# On the cart page #}
{% include 'b2b/quotes/_request-button' %}
{% include 'b2b/approvals/_submit-button' %}

{# In an order row or order detail #}
{% include 'b2b/orders/_reorder-button' with { order } %}
```

## 4. Restyle

There is intentionally no styling. Replace the bare tables, forms and the nav in
`_layout.twig` with your own markup and CSS. The template variables, action
paths and hidden inputs are the parts that matter — keep those, change the rest.
