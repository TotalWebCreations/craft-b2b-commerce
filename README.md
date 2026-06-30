# B2B Commerce

A B2B suite for Craft Commerce. B2B Commerce turns a standard Craft Commerce store
into a wholesale/business storefront with company accounts, roles and a registration
approval workflow. It is built around five pillars, delivered across multiple phases.

## What it does

B2B Commerce is organised around five pillars:

1. **Company accounts** — *available now.* Companies are first-class elements with
   their own control panel section, statuses (pending, approved, blocked), roles
   (admin, purchaser, approver) and permissions. Businesses register from the
   frontend and are approved by a store manager in the control panel. Company admins
   manage their own team and a shared address book from the frontend, completed orders
   are linked to their company, and the control panel offers per-company member and
   order overviews plus a configurable custom-field layout.
2. **Quotes** — *available now.* Request-for-quote flow with a full status lifecycle
   (requested → sent → accepted / declined / expired). An approved buyer turns a cart
   into a quote request; a merchant prices it in the control panel and sends it with an
   optional validity date that freezes the order's prices; the buyer accepts from an
   emailed link and checks out against the frozen prices — pay on account included.
3. **Order approvals** — *on the roadmap.* Spending thresholds with an approve/decline
   flow for purchasers and approvers.
4. **Pay on account** — *available now.* Offline "pay on account" gateway that lets
   approved companies with pay-on-account enabled check out on invoice, with credit
   limits enforced on the storefront and outstanding-balance overviews in both the
   control panel and the storefront.
5. **Quick order** — *available now.* Fast repeat purchasing for approved buyers:
   paste SKUs (one per line, Excel-style), upload a CSV, re-order a past order (your
   own or a colleague's), and keep shared, company-wide order lists to drop into the
   cart in one go.

This release delivers pillars 1, 2, 4 and 5. It ships:

- A **Company** element with control panel management, statuses and a
  `Manage companies` permission.
- Company **roles** (admin, purchaser, approver) linking users to a company.
- A **frontend registration** flow that creates a pending company plus its admin user
  and notifies a store manager by email.
- An **approval flow** using element actions (Approve / Block) in the control panel.
  Approving a company activates its members and sends them the
  `B2B: company approved` system message.
- **Frontend team management** for company admins: invite colleagues, change roles and
  remove members, guarded so a company always keeps at least one admin. Invited people
  receive the `B2B: added to a company` (existing users) or an activation email (new
  users).
- A **shared company address book**: native Craft `Address` elements owned by the
  company, so the whole team sees and reuses the same addresses.
- **Order–company linking**: a completed order is linked to the buyer's company, and a
  checkout backstop refuses completion for guests and unapproved/blocked accounts when
  price hiding is on.
- **Control panel company pages**: per-company member and order overviews.
- A **configurable custom-field layout** for companies, editable from the plugin
  settings.
- **Price visibility**: optionally hide prices and block add-to-cart for guests and
  unapproved accounts.
- **Quick order**: a SKU textarea (Excel-style paste), CSV upload, a re-order button
  (own and colleague orders) and shared, company-scoped **order lists**, all guarded by
  the same purchase check as the storefront.
- **Pay on account**: an offline "pay on account" gateway for approved companies, with a
  per-company **credit limit** enforced on the storefront (and re-checked under a lock at
  order completion), a **payment term** that drives a per-order payment due date, and
  outstanding-balance / available-credit overviews in the control panel and on the
  storefront.
- **Quotes**: approved buyers turn a cart into a quote request; a merchant works it in a
  control-panel workbench (`Manage quotes` permission), edits line-item prices in the
  Commerce order editor, and either **sends** it with an optional validity date — which
  freezes the prices — or **declines** it with a reason. Sent quotes email the buyer accept
  and decline links; accepting adopts the frozen order as the buyer's cart to check out
  (pay on account included). Overdue quotes lapse to `expired` via a console command or on
  the first accept/decline touch. Open quote carts are protected against buyer-side
  edits.
- **Console commands**: `b2b-commerce/seed` to bootstrap demo data,
  `b2b-commerce/team/assign-role` to recover a company that lost its admin, and
  `b2b-commerce/quotes/expire` to lapse overdue quotes.
- **Dutch translations** for all control panel and frontend strings.

## Requirements

- **Craft CMS 5, Pro edition** — required, because business accounts rely on multiple
  users. The Solo edition only supports a single user and cannot run the B2B flows.
- **Craft Commerce 5**
- **PHP 8.2** or newer

## Installation

Install with Composer and then enable the plugin through Craft:

```bash
composer require totalwebcreations/craft-b2b-commerce
php craft plugin/install b2b-commerce
```

Alternatively install it from the control panel under **Settings → Plugins**.

## Quick start

### 1. Copy the example templates

Example frontend templates live in `examples/templates/b2b/`. Copy them into your
project's `templates/` directory as a starting point:

```bash
cp -R vendor/totalwebcreations/craft-b2b-commerce/examples/templates/b2b templates/b2b
```

This gives you:

- `b2b/register.twig` — the company registration form.
- `b2b/product-price.twig` — a price/add-to-cart partial that respects price
  visibility.
- `b2b/team/index.twig` — a team management page for company admins (invite, change
  role, remove).
- `b2b/addresses/index.twig` — a shared address book with an add/edit/delete form.
- `b2b/quick-order/index.twig` — a quick order page with a SKU textarea and CSV upload.
- `b2b/orders/_reorder-button.twig` — a re-order button partial for an order row.
- `b2b/order-lists/index.twig` and `b2b/order-lists/_detail.twig` — shared order lists
  with create/rename/delete and an item editor.
- `b2b/quotes/_request-button.twig` — a "request a quote" button for the cart page,
  `b2b/quotes/index.twig` — the company's quote overview, and `b2b/quotes/accept.twig` —
  the accept/decline page the sent-quote email links to.

### 2. Configure the settings

Open **Settings → Plugins → B2B Commerce** and configure:

- Toggle the pillars you want enabled.
- Enable **Hide prices for guests** if prices and ordering should be restricted to
  approved business accounts.
- Set an **Admin notification email** so a store manager is notified when a new
  company registers. When left empty, the system "from" address is used.

### 3. Registration flow

A visitor submits the registration form (`b2b/register.twig`), which posts to the
`b2b-commerce/registration/register` action. This creates:

- a **pending** Company element, and
- a **pending** user, added to the company with the `admin` role.

The store manager receives a notification email with a link to review the company in
the control panel.

### 4. Approve via the control panel

Go to **B2B → Companies** in the control panel, select the pending company and run the
**Approve** action. This approves the company, activates its members and sends each
member the `B2B: company approved` email so they can set a password and sign in. Use
**Block** to revoke access.

### 5. Ordering

Once a member is approved and signed in, they can add products to the cart. When
**Hide prices for guests** is enabled, guests and unapproved accounts see a sign-in /
register prompt instead of prices and cannot add products to the cart.

### 6. Pay on account (optional)

To let approved companies check out on invoice, create the gateway in the control
panel under **Commerce → System Settings → Gateways → New gateway** and pick the
**Pay on account** type. Add one gateway per store. It behaves like Commerce's Manual
gateway (authorize-only; the order completes unpaid so you can capture and invoice out
of band) and shows up at checkout only when:

1. the **Pay on account** setting (`enableInvoicing`) is on;
2. the customer belongs to a company that is **approved** and has **Allow pay on
   account** enabled on its company record.

Credit limits are enforced on the storefront. The gateway is only offered at checkout
while a new order fits inside the company's remaining credit, and completion is checked
again — under a per-company lock — so two orders completing at once cannot both slip past
the limit.

A company's **credit limit** is the total it may owe on unpaid pay-on-account orders at
once. An **empty** credit limit means **no credit room at all**, not unlimited credit: a
company with no limit set can never pay on account, and the gateway is never offered to
it. Give a company a positive limit to let it order on invoice up to that amount. Each
completed invoice order draws down the remaining room until it is paid off (see
[Marking an invoice as paid](#marking-an-invoice-as-paid)).

Credit limits are **single-currency**: a limit is a plain amount with no currency of its
own, so it is compared against — and every credit figure is formatted in — your primary
store's currency. Running credit across multiple store currencies is not supported.

Enforcement is deliberately scoped to storefront (site) requests. Completing an
over-limit order from the control panel is treated as a business override: an admin doing
so is making an informed, merchant-initiated decision, so console and CP completions are
never refused (and never throw at the merchant). Only the customer-facing checkout path is
hard-enforced.

## Settings reference

| Setting | Key | Default | Description |
| --- | --- | --- | --- |
| Companies | `enableCompanies` | `true` | No effect yet — reserved. Company accounts are always active in this release. |
| Quotes | `enableQuotes` | `true` | Enables quote requests from the cart and the quote workflow. When off, the request-quote endpoint returns a clean "feature not enabled" failure. |
| Order approvals | `enableApprovals` | `true` | No effect yet — reserved for the order approvals pillar (roadmap). |
| Pay on account | `enableInvoicing` | `true` | Governs whether the pay-on-account (invoice) gateway is offered at checkout. When off, the gateway is never available regardless of company settings. See [Pay on account](#6-pay-on-account-optional). |
| Quick order | `enableQuickOrder` | `true` | Enables quick order, order lists and reorder for approved buyers. When off, those front-end endpoints return a clean "feature not enabled" failure and `craft.b2b` exposes no order-list data. |
| Hide prices for guests | `hidePricesForGuests` | `false` | Hide prices and disable add-to-cart for visitors without an approved company account. |
| Admin notification email | `adminNotificationEmail` | `''` | Receives a notification when a new company registers. Falls back to the system "from" address when empty. |
| Honeypot field name | `honeypotFieldName` | `'b2b_website'` | Name of the hidden anti-spam field on the registration form. See [Security notes](#security-notes). |

### Security notes

- **Honeypot** — the registration form includes a hidden field (default name
  `b2b_website`) that real visitors never fill. When a submission arrives with that
  field filled, the controller returns the normal success response but creates
  nothing, so bots learn nothing. Rename the field via the `honeypotFieldName`
  setting if it clashes with a real field, and keep your registration template in
  sync (the example template reads the setting automatically).
- **Before-register event** — the registration service fires a cancelable
  `RegisterEvent` before doing anything else, so you can plug in extra checks
  (rate limiting, disposable-email blocking, CAPTCHA verification). Set
  `$event->isValid = false` to cancel; the service then throws with a generic
  message and creates nothing:

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
- **Email enumeration** — registration reports "An account with this email address
  already exists." when the email is taken. This leaks whether an email is
  registered, an accepted tradeoff for a B2B flow where clear feedback to genuine
  business users outweighs the low enumeration risk of a manually reviewed,
  invite-style signup.

## Templating

The plugin registers a `craft.b2b` variable for use in your frontend templates. The
signed-in user's company is available through `craft.b2b.company`, which returns the
`Company` element (or `null` when the visitor is not linked to a company):

```twig
{% set company = craft.b2b.company %}

{% if company %}
    <p>{{ 'Ordering on behalf of'|t }} {{ company.title }}</p>
{% endif %}
```

Use `company.title` as the canonical accessor for the company name — it is the element's
title attribute and is always populated. Price visibility helpers are exposed the same
way: `craft.b2b.canViewPrices` and `craft.b2b.canPurchase`.

### Team management

`craft.b2b.teamMembers` returns the current user's colleagues as an array of rows, each a
`{ user, role }` pair (empty when the visitor has no company). Company admins manage the
team through the `b2b-commerce/team/invite`, `b2b-commerce/team/change-role` and
`b2b-commerce/team/remove` actions. The service guards role changes and removals so a
company always keeps at least one admin. See `examples/templates/b2b/team/index.twig` for
a complete member list with invite, role-change and remove forms:

```twig
<ul>
    {% for member in craft.b2b.teamMembers %}
        <li>{{ member.user.fullName }} — {{ member.role }}</li>
    {% endfor %}
</ul>
```

### Orders

A completed order is linked to the buyer's company. Read the company back from any order
with `order.b2bCompany`, which returns the `Company` element (or `null` for guest orders):

```twig
{% if order.b2bCompany %}
    <p>{{ 'Ordered by'|t }} {{ order.b2bCompany.title }}</p>
{% endif %}
```

Each completed order also exposes its **payment due date** — the order date plus the
company's payment term — through `order.b2bPaymentDueDate`. It returns a `DateTime` (or
`null` when the order is not completed, is a guest order, or the company has no payment
term configured). This is ideal for an order-confirmation email:

```twig
{% if order.b2bPaymentDueDate %}
    <p>{{ 'Payment due'|t('b2b-commerce') }}: {{ order.b2bPaymentDueDate|date('short') }}</p>
{% endif %}
```

### Credit balance

`craft.b2b.creditSummary` returns the signed-in user's company credit position as a
`{ outstanding, creditLimit, available }` array (or `null` when the visitor has no
company). `outstanding` is the unpaid balance across the company's completed
pay-on-account orders, `creditLimit` is the configured limit (or `null` when none is set),
and `available` is the room left under the limit — never below zero — (or `null` when
there is no limit). See `examples/templates/b2b/account/credit.twig` for a complete
summary page:

```twig
{% set summary = craft.b2b.creditSummary %}

{% if summary %}
    <p>{{ 'Outstanding balance'|t('b2b-commerce') }}: {{ summary.outstanding|currency }}</p>
    {% if summary.available is not null %}
        <p>{{ 'Available credit'|t('b2b-commerce') }}: {{ summary.available|currency }}</p>
    {% endif %}
{% endif %}
```

The same figures are shown to merchants in the control panel on a company's **Orders**
page, alongside a per-order payment status (paid / partially paid / unpaid).

#### Marking an invoice as paid

The plugin ships **no** custom "mark as paid" button. Because pay-on-account uses an
offline gateway, you record a payment the standard Commerce way: open the order in the
control panel order editor and add a transaction under **Payments** (or use **Update
order status** / the payment actions). The company's outstanding balance and available
credit are derived live from those transactions, so recording an offline payment there
immediately lowers the outstanding balance everywhere it is shown.

**Refunds.** The outstanding balance is measured as each order's live outstanding balance
(total minus paid). A refund lowers the paid amount, so a **fully refunded** invoice order
counts as outstanding again — a deliberate fail-safe that overstates rather than understates
what a company owes. To **write off** an order today, either adjust the company's credit
limit or record a payment covering the balance; excluding an order by its order status is on
the roadmap.

### Address book

Companies own a shared address book. Every stored address is a native Craft `Address`
element owned by the `Company`, so the whole team sees the same list. Read it with
`craft.b2b.companyAddresses` (an array of `Address` elements, empty when the visitor has no
company). Company admins manage the list through the
`b2b-commerce/addresses/save` and `b2b-commerce/addresses/delete` actions — see
`examples/templates/b2b/addresses/index.twig` for a full add/edit/delete form.

To use a stored address in checkout, copy its fields onto the cart. Orders keep their own
address copies, so you post the individual fields to `commerce/cart/update-cart` rather than
referencing the shared address by id:

```twig
{% set address = craft.b2b.companyAddresses|first %}

<form method="post">
    {{ csrfInput() }}
    {{ actionInput('commerce/cart/update-cart') }}

    {# Copy the shared address onto the order's own shipping address. #}
    {{ hiddenInput('shippingAddress[fullName]', address.fullName) }}
    {{ hiddenInput('shippingAddress[addressLine1]', address.addressLine1) }}
    {{ hiddenInput('shippingAddress[addressLine2]', address.addressLine2) }}
    {{ hiddenInput('shippingAddress[postalCode]', address.postalCode) }}
    {{ hiddenInput('shippingAddress[locality]', address.locality) }}
    {{ hiddenInput('shippingAddress[administrativeArea]', address.administrativeArea) }}
    {{ hiddenInput('shippingAddress[countryCode]', address.countryCode) }}

    <button type="submit">{{ 'Use this address'|t }}</button>
</form>
```

### Quick order

Approved buyers can add many products to the cart at once by pasting SKUs. The
`b2b/quick-order/index.twig` example ships a textarea that posts to
`b2b-commerce/quick-order/add`. Enter **one SKU per line**, optionally followed by a
quantity. The quantity separator is auto-detected per line, in this order: **tab**,
**comma**, **semicolon**, then any run of **whitespace** (a space). A bare SKU with no
quantity defaults to `1`:

```
WIDGET-01	5      ← tab-separated
WIDGET-02,3        ← comma
WIDGET-03;2        ← semicolon
WIDGET-04 10       ← space
WIDGET-05          ← bare SKU, quantity defaults to 1
```

SKU matching is **case-insensitive**, and duplicate SKUs (in any casing) are merged —
their quantities are summed onto the first occurrence. Blank lines are skipped. The
action returns JSON `{ added, errors }`, where `errors` is keyed by the **original
1-based line number** so you can report problems against the exact line the buyer
typed. Unknown SKUs, unavailable products, invalid quantities and cart vetoes each
surface as a per-line error while the valid lines are still added.

#### CSV upload

The same page also posts a file to `b2b-commerce/quick-order/upload-csv` (a
`multipart/form-data` form with a `csvFile` input). The upload is capped at 1 MB, must
be a text/CSV MIME type, and its contents are fed through the **exact same parser** as
the textarea — so the format, error reporting and casing/duplicate rules above apply
identically. A UTF-8 byte-order mark is stripped automatically.

#### Re-order

`b2b/orders/_reorder-button.twig` posts a completed order's id to
`b2b-commerce/quick-order/reorder`, which copies that order's still-available line items
into the current cart. A buyer may re-order an order they placed **or** any order that
belongs to their own company — so colleagues can repeat each other's orders. Only
completed orders can be re-ordered; unavailable line items surface as per-position
errors.

### Order lists

Order lists are named, **company-scoped** collections of products a team keeps around to
drop into the cart in one go. They are shared work material, not team administration: by
policy **any** company member — regardless of role (admin, purchaser or approver) — may
view, create, rename and delete lists, edit their items and add a list to the cart.
Every list is scoped to the buyer's company and each action re-checks that ownership, so
one company can never touch another's lists.

Read a company's lists with `craft.b2b.orderLists`, which returns an array of
`{ id, name, createdByUserId, itemCount }` rows, and read a single list's items with
`craft.b2b.getOrderListItems(listId)` (usable as `craft.b2b.orderListItems(listId)` in
Twig), which returns `{ purchasableId, qty, sku, description }` rows guarded by the
current user's company:

```twig
{% for list in craft.b2b.orderLists %}
    <p>{{ list.name }} — {{ list.itemCount }} {{ 'items'|t('b2b-commerce') }}</p>

    {% for item in craft.b2b.orderListItems(list.id) %}
        <span>{{ item.sku }} × {{ item.qty }}</span>
    {% endfor %}
{% endfor %}
```

Lists are managed through the `b2b-commerce/order-lists/create`, `.../rename`,
`.../delete`, `.../set-item` and `.../add-to-cart` actions. Setting an item's quantity to
`0` removes it from the list. Adding a list to the cart reuses the quick-order add-path,
so line-item vetoes are honoured and missing or unavailable products surface as
per-position errors. See `examples/templates/b2b/order-lists/index.twig` and
`_detail.twig` for a complete overview and item editor.

### Quotes

An approved buyer turns their cart into a quote request from the cart (see the
*Request a quote* example template). The request survives as a non-completed order; the
buyer keeps a fresh session cart. A merchant then works the quote in the control panel and
either **sends** it (with an optional validity date) or **declines** it.

The quote lifecycle is `requested → sent → accepted | declined | expired`. `accepted`,
`declined` and `expired` are terminal.

#### Merchant flow (control panel)

1. **Workbench.** A new **B2B → Quotes** section lists every quote newest-first, filterable
   by status, showing the company, requester, validity date and a link to the underlying
   order. It is gated by the **`Manage quotes`** permission, so grant that to the user
   groups who should handle quotes.
2. **Price the quote.** The quote is a normal, non-completed Commerce order. Open it in the
   standard **Commerce → Orders** editor and adjust line-item prices, add or remove lines,
   apply discounts — whatever the deal needs. Merchant edits in the control panel are never
   blocked (the buyer-mutation guard below is scoped to storefront requests only).
3. **Mark sent.** From the workbench, **Mark sent** with an optional **valid-until** date
   (which must be in the future). Sending freezes the order's prices (see *Frozen prices*)
   and emails the requester accept and decline links. From this point the buyer pays exactly
   what you left on the order.
4. **Merchant override.** Because the buyer-mutation guard stands down for control-panel
   requests, you can still re-open and re-edit a sent quote in the Commerce order editor if a
   price needs correcting before the buyer accepts — a deliberate merchant override. Re-run
   **Mark sent** afterwards is not required; the freeze persists on the order.
5. **Decline.** **Decline** records an optional reason (stored on the quote and shown in the
   workbench) and emails the requester that their request was declined, including the reason.

#### Customer flow (storefront)

1. **Request.** From the cart, the buyer submits *Request a quote* (optionally with notes).
   The store admin is notified by email; the buyer keeps a fresh, empty cart.
2. **Receive.** When the merchant sends the quote, the buyer gets the `B2B: quote sent` email
   with an **Accept** and a **Decline** link (both carrying the quote token).
3. **Accept → checkout.** Accepting adopts the frozen quote order as the buyer's active cart,
   so they proceed straight to checkout against the frozen prices. Pay on account is offered
   as usual (the credit check still runs at completion). Declining records a reason and
   notifies the store admin.
4. **Overview.** `craft.b2b.quotes` (see the *Your quotes* example template) lists the
   company's quotes with status and totals, and rebuilds the accept link for a still-sent
   quote so the buyer can act on it from their account too.

**Frozen prices.** When a quote is sent, the plugin pins the order's prices by setting
Commerce's `recalculationMode` to `none` and saving. That mode is a persisted column on
the order and is restored on load *before* the element defaults it, so the freeze survives
reloads and every later save short-circuits recalculation — whatever the merchant left on
each line item is exactly what the buyer will pay. Merchant line-item price overrides made
under this mode are preserved verbatim.

**Accept / decline the quote.** The sent-quote email links to a **site** route with the
quote's token in the query string:

```
{{ siteUrl('quotes/accept', { token: '…' }) }}
{{ siteUrl('quotes/decline', { token: '…' }) }}
```

The display page is **yours to route** — point a site route at the shipped example template
(`examples/templates/b2b/quotes/accept.twig`) so the token resolves there, for example in
`config/routes.php`:

```php
'quotes/accept' => ['template' => 'b2b/quotes/accept'],
```

That page reads the quote with `craft.b2b.quoteByToken(token)` — read-only data (status,
validity, notes, order reference and totals), guarded by the signed-in user's company so it
returns `null` for another company's token. It then posts to the two plugin actions, which
require a logged-in company member and the `enableQuotes` feature:

```
b2b-commerce/quotes/accept    (POST: token[, redirect])
b2b-commerce/quotes/decline   (POST: token, reason)
```

The **same token** authorizes both. **Accept** flips the quote to `accepted` and adopts the
quote order as the buyer's active session cart, so they check out directly against the frozen
prices — pay on account included, with the credit check still applied at checkout. **Decline**
records the reason and notifies the store admin. Token lookup is a single indexed unique
match (no timing-sensitive compare); an unknown token and another company's token return the
**same** generic "This quote is not available." message, so a guessed token cannot be probed.
A quote past its `validUntil` is expired lazily on the first accept/decline touch. On accept
the order customer is left unchanged (the requester): Commerce checkout authorizes on the
session cart, and the invoice gateway checks the customer's company, which is the acceptor's
company for any member of the same company.

**Cart-mutation guard.** An open quote order (`requested` or `sent`) must not be edited
through the storefront cart endpoints. Because `commerce/cart/load-cart` can reactivate any
non-completed order by number as the session cart, the plugin guards the order at save time:
on a site request it vetoes the save whenever an open quote's line items diverge from
what is stored — **quantity edits, option edits, line-item additions and removals are all
blocked** (line-item notes are not compared and stay editable, as they are financially and
delivery-wise inert). (This matters because under the `recalculationMode = none` freeze the charged
total still moves with quantity — a quantity change against a frozen absolute discount can
be driven to zero or negative — so freezing the per-unit price alone is not enough.) The
new-item add event is still vetoed too, as defence in depth. Merchant edits in the control
panel remain possible: the guard only applies to front-end site requests, and the plugin's
own saves (such as setting the freeze when a quote is sent) explicitly stand down. Once the
quote reaches a terminal status (e.g. accepted), the guard no longer applies and the order
resumes its normal lifecycle.

## Known limitations

- **Company field layout is stored in the database, not project config.** The custom-field
  layout you configure under **Settings → Plugins → B2B Commerce** is saved directly to the
  database and does **not** deploy through project config. Reconfigure it per environment
  after deploying. Project-config storage for the layout is on the roadmap.

## Console commands

The plugin ships these Craft console commands:

- `php craft b2b-commerce/seed` — bootstraps a demo, pre-approved company (*Acme
  Wholesale Ltd*) with an admin user (`buyer@acme.test`) for local development. It is
  idempotent: if the demo user already exists it does nothing.
- `php craft b2b-commerce/team/assign-role <companyId> <email> <role>` — assigns (or
  re-assigns) a company role to a user, looked up by email. Roles are `admin`,
  `purchaser` and `approver`. This is the **recovery path** for a company that lost its
  last admin: unlike the frontend team flows it deliberately bypasses the last-admin
  guard, so an operator can reinstate an admin from the command line.
- `php craft b2b-commerce/quotes/expire` — flips every still-open quote (`requested` or
  `sent`) whose `validUntil` has passed to `expired`, in a single update, and prints the
  count. Quotes without a `validUntil` never expire. **Run it on a cron** so quotes lapse
  on their own, for example hourly:

  ```cron
  0 * * * * cd /path/to/project && php craft b2b-commerce/quotes/expire >> /dev/null 2>&1
  ```

## Uninstalling

Uninstalling the plugin intentionally leaves its database tables (`b2b_companies`,
`b2b_company_users`, `b2b_order_company`, `b2b_order_lists`, `b2b_order_list_items` and
`b2b_quotes`) and
their data behind. The install migration is
idempotent: if the tables already exist it skips creation and keeps your data, so a later
reinstall picks up exactly where you left off — no manual SQL required.

## Roadmap

The remaining pillars are planned for future phases:

- **Order approvals** — spending thresholds with an approve/decline flow and emails.
- **Tax ID / VIES validation** and Plugin Store polish.

## License

Proprietary. © TotalWebCreations.
