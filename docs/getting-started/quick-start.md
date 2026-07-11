# Quick start

## 1. Copy the example templates

Example frontend templates live in `examples/templates/b2b/`. They are a cohesive,
copy-pasteable starter set — a shared `_layout.twig` with a B2B account navigation that every
page extends — but they are deliberately **unstyled**: no CSS framework, minimal inline
structure, meant to be restyled to match your storefront.

Copy the whole directory into your project's `templates/` directory as a starting point:

```bash
cp -R vendor/totalwebcreations/craft-b2b-commerce/examples/templates/b2b templates/b2b
```

This gives you a working page (or partial) for every flow: account overview, registration, team
management, address book, quick order, order lists, quotes, approvals, credit summary, spending
budget, PO number entry, and the quote/invoice PDF customization starting points.

Two routes must be registered by hand in `config/routes.php` — the order-list detail partial and
the sent-quote email's accept/decline links:

```php
return [
    'b2b/order-lists/<listId:\d+>' => ['template' => 'b2b/order-lists/_detail'],

    'quotes/accept' => ['template' => 'b2b/quotes/accept'],
    'quotes/decline' => ['template' => 'b2b/quotes/accept'],
];
```

See [`examples/README.md`](https://github.com/TotalWebCreations/craft-b2b-commerce/blob/main/examples/README.md)
in the repository for the full template walkthrough, including how to wire up the partials
(`product-price`, the cart "request a quote" / "submit for approval" buttons, and the order-row
reorder button).

## 2. Configure the settings

Open **Settings → Plugins → B2B Commerce** and configure:

- Toggle the pillars you want enabled (companies, quotes, approvals, pay on account, quick
  order).
- Enable **Hide prices for guests** if prices and ordering should be restricted to approved
  business accounts.
- Set an **Admin notification email** so a store manager is notified when a new company
  registers. When left empty, the system "from" address is used.

The full list of settings, with defaults and behaviour, is in the
[settings reference](/reference/settings).

## 3. Registration flow

A visitor submits the registration form (`b2b/register.twig`), which posts to the
`b2b-commerce/registration/register` action. This creates:

- a **pending** Company element, and
- a **pending** user, added to the company with the `admin` role.

The store manager receives a notification email with a link to review the company in the
control panel.

## 4. Approve via the control panel

Go to **B2B → Companies** in the control panel, select the pending company and run the
**Approve** action. This approves the company, activates its members and sends each member the
`B2B: company approved` email so they can set a password and sign in. Use **Block** to revoke
access.

## 5. Ordering

Once a member is approved and signed in, they can add products to the cart. When **Hide prices
for guests** is enabled, guests and unapproved accounts see a sign-in / register prompt instead
of prices and cannot add products to the cart.

## 6. Try the demo data (optional)

For local development, `php craft b2b-commerce/seed` bootstraps a demo, pre-approved company
(*Acme Wholesale Ltd*) with an admin user (`buyer@acme.test`) so you have something to sign in
as immediately. It is idempotent — running it again is a no-op once the demo data exists. See
the [console commands reference](/reference/console-commands).

## Where next

- [Companies & teams](/guides/companies-teams) — roles, team management, the address book.
- [Pay on account & credit](/guides/pay-on-account) — set up the invoice gateway.
- [Quotes](/guides/quotes) and [Order approvals](/guides/approvals) — the negotiation and
  spending-control flows.
- [Reference → Settings](/reference/settings) — every setting the plugin exposes.
