# B2B Commerce

B2B Commerce turns a standard Craft Commerce store into a wholesale/business storefront.
Businesses register and are approved by a store manager, their team orders on behalf of
the company, and merchants sell the way B2B works: negotiated quotes, spending approvals,
invoicing on account and fast repeat ordering — all on top of native Craft Commerce.

## What it does

B2B Commerce is organised around five pillars, all live in this release:

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
3. **Order approvals** — *available now.* Spending thresholds with an approve/decline
   flow for purchasers and approvers, plus a read-only monitoring overview in the control
   panel.
4. **Pay on account** — *available now.* Offline "pay on account" gateway that lets
   approved companies with pay-on-account enabled check out on invoice, with credit
   limits enforced on the storefront and outstanding-balance overviews in both the
   control panel and the storefront.
5. **Quick order** — *available now.* Fast repeat purchasing for approved buyers:
   paste SKUs (one per line, Excel-style), upload a CSV, re-order a past order (your
   own or a colleague's), and keep shared, company-wide order lists to drop into the
   cart in one go.

It also validates company **EU VAT IDs** against VIES and applies the intra-EU reverse
charge automatically at checkout, ships example storefront templates for every flow, adds
a `craft.b2b` template variable, and includes **Dutch translations** for all control-panel
and frontend strings. Full feature detail is in the sections below and in the
[changelog](CHANGELOG.md).

### Control panel overview

The **B2B** section opens on an **Overview** landing page (Companies, Quotes and Approvals
sit alongside it in the subnav). The overview shows the headline figures at a glance —
companies by status, the pending-registration queue, open quotes, pending approvals, the
distinct member count and the total outstanding on account — each linking through to its
list. The same figures are available as a **B2B overview** widget on Craft's dashboard.
Both are gated behind the *Manage companies* permission.

## Requirements

- **Craft CMS 5, Pro edition** — required, because business accounts rely on multiple
  users. The Solo edition only supports a single user and cannot run the B2B flows.
- **Craft Commerce 5** (`^5.0`). The **EU VAT ID validation & reverse charge** feature is
  built on Commerce's native VAT support, which arrived in **Commerce 5.3**; on Commerce
  5.0–5.2 every other feature works, but leave **Validate VAT IDs** off. All other pillars
  have no minimum beyond Commerce 5.0.
- **PHP 8.2** or newer.
- **MySQL or PostgreSQL** — both are supported and verified against clean installs. The
  plugin uses only Craft's query builder, so it runs on either of Craft's supported
  databases.

## Installation

Install with Composer and then enable the plugin through Craft:

```bash
composer require totalwebcreations/craft-b2b-commerce
php craft plugin/install b2b-commerce
```

Alternatively install it from the control panel under **Settings → Plugins**.

## Quick start

### 1. Copy the example templates

Example frontend templates live in `examples/templates/b2b/`. They are a cohesive,
copy-pasteable starter set — a shared `_layout.twig` with a B2B account navigation
that every page extends — but they are deliberately **unstyled**: no CSS framework,
minimal inline structure, meant to be restyled to match your storefront. Copy the
whole directory into your project's `templates/` directory as a starting point:

```bash
cp -R vendor/totalwebcreations/craft-b2b-commerce/examples/templates/b2b templates/b2b
```

See [`examples/README.md`](examples/README.md) for the full walkthrough, including the
site routes you must register (the `quotes/accept` / `quotes/decline` email links and
the `b2b/order-lists/<listId>` detail route) and how to include the button partials.

This gives you:

- `b2b/_layout.twig` — the shared, unstyled base layout with the B2B account nav.
- `b2b/account/index.twig` — the account landing page (company + status).
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
- `b2b/approvals/_submit-button.twig` — a "submit for approval" button for the cart page,
  and `b2b/approvals/index.twig` — the storefront approver queue plus the buyer's own
  requests with a resume-checkout button.
- `b2b/account/credit.twig` — a credit summary page for the current user's company.
- `b2b/account/budget.twig` — a spending-budget page for the current user.

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
| Order approvals | `enableApprovals` | `true` | Lets purchasers submit orders above their company approval threshold for an approver to approve. When off, the submit-for-approval endpoint returns a clean "feature not enabled" failure and the completion backstop is not enforced. |
| Pay on account | `enableInvoicing` | `true` | Governs whether the pay-on-account (invoice) gateway is offered at checkout. When off, the gateway is never available regardless of company settings. See [Pay on account](#6-pay-on-account-optional). |
| Settled order statuses | `excludedOrderStatusHandles` | `cancelled, refunded` | Comma-separated Commerce order-status handles whose orders are treated as settled and never count towards a company's outstanding balance. See [Pay on account](#6-pay-on-account-optional). |
| Quick order | `enableQuickOrder` | `true` | Enables quick order, order lists and reorder for approved buyers. When off, those front-end endpoints return a clean "feature not enabled" failure and `craft.b2b` exposes no order-list data. |
| Hide prices for guests | `hidePricesForGuests` | `false` | Hide prices and disable add-to-cart for visitors without an approved company account. |
| Admin notification email | `adminNotificationEmail` | `''` | Receives a notification when a new company registers. Falls back to the system "from" address when empty. |
| Honeypot field name | `honeypotFieldName` | `'b2b_website'` | Name of the hidden anti-spam field on the registration form. See [Security notes](#security-notes). |
| Validate VAT IDs | `validateTaxIds` | `false` | Validate company VAT IDs against VIES when a company is registered or saved. See [VAT ID validation & reverse charge](#vat-id-validation--reverse-charge). |
| VIES outage policy | `taxIdValidationPolicy` | `'lenient'` | What to do when VIES is unreachable during validation: `lenient` accepts and logs a warning, `strict` refuses the save. A definitively invalid VAT ID is refused under both. |
| Quote PDF template | `quotePdfTemplate` | `''` | Site template path used to render the quote PDF. Leave blank to use the bundled example (`b2b/pdf/quote.twig`). See [PDF documents](#pdf-documents). |
| Invoice PDF template | `invoicePdfTemplate` | `''` | Site template path used to render the order/invoice PDF. Leave blank to use the bundled example (`b2b/pdf/invoice.twig`). See [PDF documents](#pdf-documents). |

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

**Settled orders (cancelled / refunded).** The outstanding balance is measured as each
order's live outstanding balance (total minus paid), so a refund on its own lowers the paid
amount and a **fully refunded** order would otherwise count as outstanding again. To stop
that phantom debt, orders whose **Commerce order status** is one of the *settled* handles are
dropped from the balance entirely. The default settled handles are `cancelled` and `refunded`;
change them with the **Settled order statuses** setting (`excludedOrderStatusHandles`, a
comma-separated list of order-status handles). Give a cancelled or refunded order that status
and its receivable no longer eats into the company's credit room. To write off any other
order, adjust the company's credit limit or record a payment covering the balance.

### Spending budgets

A **spending budget** caps how much a single team member may spend for their company within a
period. It is separate from — and layers on top of — the company credit limit: a member can be
under budget while their company is over its credit limit, and vice versa, so both are checked
independently at checkout.

Budgets are set per member in the control panel on a company's **Members** page (**B2B →
Companies →** a company **→ Members**). For each member you set an **amount** and a **period**,
or remove the budget entirely. The page also shows the member's spend this period and the room
remaining. Setting a budget requires the `manageCompanies` permission.

The **period** decides the window spend is counted over, and therefore when it resets:

- **Monthly / Quarterly / Yearly** — spend is counted from the start of the current calendar
  period (measured in the site timezone) and resets when the next one begins.
- **None** — an all-time cap that never resets (a lifetime ceiling).
- **No budget at all** — the member has unlimited spending. This is distinct from a *None*
  budget, which still caps.

Spend is the sum of the member's completed orders for that company in the current period, minus
settled statuses (the same cancelled/refunded exclusion the credit balance uses). It counts the
order's full total on **every** gateway, not just pay-on-account — a budget caps what a member
spends, not what the company owes on account.

Enforcement runs at checkout, storefront only (control-panel and console completions are the
deliberate merchant override): the payment step refuses an order that would push the member over
budget, and order completion is checked again as a backstop.

`craft.b2b.memberBudget` exposes the signed-in user's own budget as a
`{ amount, period, spent, remaining }` array, or `null` when they have no budget (unlimited) or
no company. `remaining` is the room left this period, never below zero. See
`examples/templates/b2b/account/budget.twig` for a complete page:

```twig
{% set budget = craft.b2b.memberBudget %}

{% if budget %}
    <p>{{ 'Spent'|t('b2b-commerce') }}: {{ budget.spent|currency }} / {{ budget.amount|currency }}</p>
    <p>{{ 'Remaining'|t('b2b-commerce') }}: {{ budget.remaining|currency }}</p>
{% endif %}
```

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
`declined` and `expired` are terminal. An accepted quote that the buyer never checks out
stays completable at its frozen prices until the merchant cancels the underlying order —
there is no accept deadline. Quote orders (including terminal quote history) are excluded
from Commerce's inactive-cart purge, so a long-lived sent quote or a finished quote's
records are never silently deleted after the purge window.

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

#### Merchant-initiated quotes (send proactively)

Besides handling customer requests, a merchant can start a quote from scratch. Build a cart in
Commerce's native order editor (**Commerce → Orders → New**), add the customer, set your prices,
then click **Send as B2B quote** (a button on the order-edit screen). The plugin links the
customer's company (auto from their membership, or pick from the approved companies they belong
to), freezes the prices exactly like a customer-initiated quote (`recalculationMode = none`),
marks it sent, and emails the customer the accept/decline links and the quote PDF. The customer
accepts with the same token flow and checks out at the frozen prices. The quote's **origin**
(customer or merchant) is recorded for reporting, and a merchant quote obeys the same
buyer-mutation veto and accept-adopts-cart rules as any other quote.

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

**Cart-mutation guard.** A line-item-frozen quote order — one whose quote is `requested`,
`sent` or `accepted` and whose order is not yet completed — must not have its line items
edited through the storefront cart endpoints. Because `commerce/cart/load-cart` can reactivate
any non-completed order by number as the session cart, the plugin guards the order at save
time: on a site request it vetoes the save whenever such a quote's line items diverge from
what is stored — **quantity edits, option edits, line-item additions and removals are all
blocked** (line-item notes are not compared and stay editable, as they are financially and
delivery-wise inert). (This matters because under the `recalculationMode = none` freeze the charged
total still moves with quantity — a quantity change against a frozen absolute discount can
be driven to zero or negative — so freezing the per-unit price alone is not enough.) The
new-item add event is still vetoed too, as defence in depth. Merchant edits in the control
panel remain possible: the guard only applies to front-end site requests, and the plugin's
own saves (such as setting the freeze when a quote is sent) explicitly stand down.

An accepted quote is the deal as negotiated: its line items stay locked right through
checkout, because the frozen `recalculationMode = none` leaves tax and shipping adjustments
unrecomputed — a post-accept line-item addition would otherwise persist at resolve-time
prices while under-collecting tax. Address, gateway and completion saves never change the
line-item set, so they proceed freely. Only once the order **completes** (or the quote
lands in a `declined` / `expired` terminal status) does the line-item guard stand down. A
separate **completion veto** blocks any attempt to complete a quote order that reactivated
as a cart while its quote is not `accepted` (still requested at catalog prices, or sent,
declined or expired) with *"This order is part of a quote that has not been accepted."*

### PDF documents

Quote and order/invoice PDFs are rendered through Commerce's **native** `Pdfs` service
(dompdf) — the same renderer Commerce itself uses, so there is no extra PDF library
dependency and no new database table.

**Templates.** Two overridable Twig templates ship as examples:

- `examples/templates/b2b/pdf/quote.twig` — company block, PO number, frozen quote line
  items and totals, VAT/reverse-charge note, validity date.
- `examples/templates/b2b/pdf/invoice.twig` — the same, plus the payment term and due date.

Copy either into your own site templates folder to restyle it (they are plain Twig/HTML,
styled with inline CSS as dompdf requires), then point the matching setting at the copy:

| Setting | Key | Description |
| --- | --- | --- |
| Quote PDF template | `quotePdfTemplate` | Site template path for the quote PDF. Blank uses the bundled example. |
| Invoice PDF template | `invoicePdfTemplate` | Site template path for the order/invoice PDF. Blank uses the bundled example. |

Both templates read the due date and PO number straight off the order — `order.b2bPaymentDueDate`
and `order.b2bPoNumber` — so a custom template gets them for free.

**Control panel downloads.**

- **Quote edit screen** (**B2B → Quotes**) has a **Download PDF** link, gated by the
  **`Manage quotes`** permission.
- The **company order overview** (`_orders.twig`) has a **Download invoice PDF** link per
  order, gated by the **`Manage companies`** permission — a deliberate split, since the two
  links live on pages with different permissions.

**Storefront downloads.**

- The quote **accept page** (`examples/templates/b2b/quotes/accept.twig`) shows a
  **Download PDF** link once the quote is `sent` or `accepted`, authorized by the same
  token the accept/decline links carry — no separate login-gated permission check beyond
  the token's own company scoping.
- A member-guarded **Download invoice PDF** snippet
  (`examples/templates/b2b/orders/_invoice-pdf-button.twig`) is available for a completed,
  pay-on-account order: include it with `{% include 'b2b/orders/_invoice-pdf-button' with { order: order } %}`
  from your order-history/detail template. The download action re-checks server-side that
  the order is a completed invoice order belonging to the caller's own company, so the link
  itself cannot be used to leak another company's invoice.

### Order approvals

Each company sets an `approvalThreshold`. A **purchaser** whose order total clears that
threshold cannot place the order directly: it is held for a company **approver** (or admin)
to approve or decline. Approvers and admins always order directly; a company with a `null`
threshold runs no approval gate, and an order exactly at the threshold is placed without
approval (the comparison is strictly greater-than). The threshold in force at submit time is
snapshotted onto the approval row, so a later threshold change never rewrites why an order was
held. A hard completion backstop enforces the gate even if the storefront submit step is
bypassed, and it applies equally to accepted-quote orders that clear the threshold.

Approve, decline and resume-checkout are the company's **own internal process**, driven from
the storefront by its approvers — a merchant does **not** approve on the company's behalf. The
four-eyes principle holds: an approver may never approve their own submission. See
`examples/templates/b2b/approvals/_submit-button.twig` for the cart submit button and
`examples/templates/b2b/approvals/index.twig` for the storefront approver queue and the buyer's
own requests.

A purchaser who accepts an over-threshold **quote** is not exempt: the accepted-quote order is
still held by the backstop until it also carries an approved approval, so the purchaser submits
that accepted-quote order for approval as normal (the submit step refuses only *open* quotes, not
accepted ones). Both guards — quote-accepted and approval-approved — must be satisfied before it
completes.

When the `enableApprovals` setting is off the whole gate is disarmed: the completion backstop
stands down and any cart left awaiting approval from when the feature was on becomes editable
again.

#### Two-layer enforcement: payment-time and completion-time

The approval and credit gates are enforced in **two coexisting layers**, so a gated purchaser is
never charged for an order that cannot be placed:

1. **Payment-time (the charge is refused up front).** On `Payments::EVENT_BEFORE_PROCESS_PAYMENT`
   — before Commerce creates a transaction or asks the gateway to authorize or capture — a gated
   purchaser with no approved approval (or a pay-on-account order over the company credit limit) is
   refused. Because this fires *before* the charge, a purchaser paying by card is **never charged**;
   Commerce returns a clean storefront failure carrying the reason, with no transaction created.

2. **Completion-time (the defence-in-depth net).** The same gates are re-checked on
   `Order::EVENT_BEFORE_COMPLETE_ORDER` (`Approvals::enforceApprovalBeforeCompletion`,
   `CreditEnforcer::enforceCreditLimit`). This net catches the paths that never run a payment call
   at all: a zero-payment or free order that completes without a charge, an approver placing an
   approved invoice order directly, and any other completion that does not go through the payment
   service.

Both layers share the same decision logic, so the two can never disagree: the payment-time gate
only re-times the refusal to *before* the charge. Console and control-panel payments and
completions bypass both layers by design — that is the merchant override for placing a held order
by hand.

#### Control-panel monitoring (`Manage approvals` permission)

**B2B → Approvals** is a native Craft element index: a status-source sidebar (all, pending,
approved, declined) with colored status dots, keyword search, sortable columns and export. Each
approval is backed by a Craft element whose `orderId` stays the business key every enforcement
guard reads; the element only adds identity around the row. Each row shows the company, the order
total (linking to the order editor), the requester, resolver, the snapshotted threshold and the
request date.

**This overview is deliberately read-only.** Approval decisions belong to the customer's own
approvers, not the store operator, so the control panel monitors the queue but never approves
or declines from here. A merchant who genuinely needs to place a held order overrides the gate
the same way every other completion guard is overridden — by completing the order from the
control-panel order editor (console and control-panel completions bypass the storefront
backstop by design).

## Company-specific pricing

Give each company its own wholesale prices without a custom pricing engine — the plugin wires
companies onto Craft user groups and lets **native Commerce Catalog Pricing** do the rest.

A company points at a Craft **user group** (its "pricing group"). The plugin keeps the company's
approved members in that group, and you create Commerce **catalog pricing rules** whose *customer
condition* targets the group. Members then see the group's catalog prices everywhere Commerce shows
a price — product pages, cart, checkout — because it is Commerce's own pricing, not an overlay.

### Setup

1. **Create a user group** for the pricing tier, e.g. *Settings → Users → User Groups →
   "Wholesale — Acme"*. See the security caveat below on which group to use.
2. **Assign it to the company.** Open the company (*B2B → Companies*) and pick the group in the
   **Pricing group** field. Leave it on *No pricing group* to give the company no special prices.
3. **Build a Commerce catalog pricing rule** (*Commerce → Store settings → Catalog pricing*). Set
   the discount (for example, a flat price or a percentage off), and under **Customer condition**
   add a **User Group** rule matching the group from step 1. Save — Commerce recalculates the
   catalog prices for that group's members.

That's it. From then on the plugin keeps membership in sync automatically:

- Inviting, registering, or adding a member (control panel, storefront, or console) places them in
  the company's pricing group.
- Removing a member removes them from it.
- Changing a company's pricing group moves all its members from the old group to the new one.

### Approved companies only

Only members of an **approved** company are placed in the pricing group. A pending company's members
stay out of it, and blocking an approved company removes its members again — so an unapproved or
suspended account can never resolve wholesale prices. Approving a company syncs its members in;
blocking syncs them out.

### What the plugin does and does not touch

The plugin only ever adds or removes the configured **pricing groups** (the set of groups any company
points at). Every other group a user belongs to — permission groups, roles, unrelated memberships —
is left completely untouched. A member is only ever in their own company's pricing group among the
managed set.

### Security caveat

> [!IMPORTANT]
> The assigned group is a **pricing group only**. Because every approved member of the company is
> placed in it automatically, it must **not** be a group that grants control-panel access or admin
> permissions. Use a permission-free group dedicated to pricing; grant any real permissions through
> separate groups the plugin never manages.

## VAT ID validation & reverse charge

B2B Commerce builds directly on Craft Commerce's native EU VAT support (Commerce 5.3+):
Commerce ships a VIES-backed `EU VAT ID` validator, an `organizationTaxId` field on every
address and a *"Remove the included VAT if a valid VAT ID is present"* (`removeVatIncluded`)
option on tax rates. The plugin adds the B2B glue: validating the **company** VAT ID at
registration/save time, and carrying that company VAT ID onto the order at checkout so
Commerce's tax engine can apply the reverse charge automatically.

### Validating company VAT IDs

Turn on **Validate VAT IDs** in the plugin settings. From then on, every company save with a
non-empty Tax ID — frontend registration and control-panel edits alike — is validated:

1. **Format check** (offline, via Commerce's country-prefix patterns): a malformed VAT ID such
   as `FOO123` is refused immediately with *"This VAT ID is invalid."*
2. **Existence check** against VIES (the EU's VAT number register, the same REST endpoint
   Commerce uses). A VAT ID VIES reports as non-existent is refused with the same message.
3. **VIES outage**: when VIES cannot be reached the outcome is undecidable, and the **VIES
   outage policy** decides: `lenient` (default) accepts the save and logs a warning so
   registration is never blocked by a third-party outage — revalidate later with the console
   command below; `strict` refuses the save with *"This VAT ID could not be validated."*

Valid results are cached under Commerce's own cache key (Craft's default cache duration,
24 hours by default), so repeated saves don't re-query VIES and Commerce's checkout tax
adjuster finds the VAT ID already known-valid. Invalid or undecidable results are never cached.

### Automatic reverse charge at checkout (the full chain)

For intra-EU B2B sales where the VAT shifts to the buyer (*btw verlegd*), configure it once and
the whole chain is automatic:

1. **Commerce** — create your VAT tax rate as usual (**Store Management → Tax → Tax Rates**),
   with *Included in price?* on. Then enable **"Remove the included VAT if a valid VAT ID is
   present"** (`removeVatIncluded`) on the rate and select the **EU VAT ID** validator.
2. **B2B Commerce** — give the company a VAT ID (validated as above).
3. **Checkout** — on every storefront cart save the plugin copies the customer's company VAT ID
   into the order's shipping/billing address `organizationTaxId`, *only if the customer left
   that field empty* (a VAT ID typed at checkout always wins). This happens before Commerce
   recalculates the cart, so the very same save prices correctly.
4. **Commerce's tax adjuster** — sees a matching zone plus a valid `organizationTaxId` on the
   tax address and removes the included VAT from the order: the customer pays the ex-VAT price
   and the order shows the removed-VAT adjustment. No custom tax logic in the plugin.

The passthrough applies to storefront requests only (console and control-panel order edits are
never touched) and stops once an order is completed. Note that Commerce validates the address
VAT ID with its own VIES call at tax-calculation time when it is not in cache — with the plugin
validating company VAT IDs beforehand, the cache is typically already warm.

### Revalidating VAT IDs

VAT registrations lapse, so revalidate periodically:

```
php craft b2b-commerce/tax-id/revalidate
```

It re-checks every company VAT ID against VIES (bypassing the known-valid cache), reports
`valid` / `invalid` per company with a summary count, and skips VAT IDs it cannot check because
VIES is unreachable. Exit code `0` when every VAT ID got a verdict; `75` (`TEMPFAIL`) when one
or more were skipped, so a cron scheduler can flag or retry. Follow up on `invalid` results
manually — the command reports, it never blocks a company.

## GraphQL API

The plugin exposes its B2B data through GraphQL for headless / decoupled storefronts. The schema is
**read-only**: every write still goes through the existing action controllers (registration, team
management, quotes, approvals, order lists, checkout) — there are no GraphQL mutations. GraphQL only
lets a storefront *render* B2B context.

Nothing is queryable until you opt in per schema. Under **GraphQL → Schemas** (or the public schema)
in the control panel, enable the **B2B Commerce** scopes:

- **View companies** (`b2bCompanies.all`) — enables the `companies`, `company` and `companyCount`
  queries for the `Company` element type. This scope exposes only non-sensitive company **identity**:
  `id`, `name`, `registrationNumber`, `status` and any custom fields on the company field layout. The
  sensitive financial fields (`taxId`, `creditLimit`, `paymentTermDays`, `allowInvoicePayment`,
  `approvalThreshold`) resolve to `null` under this scope alone.
- **View company financial fields** (`b2bCompanies.financials`) — a separate, opt-in add-on that
  unlocks the financial fields above across **all** companies in the `companies`/`company` queries.
  Off by default. Do not enable it on a public schema.
- **View the current user's B2B context** (`b2bContext.self`) — enables the top-level `b2bContext`
  query.

```graphql
query {
    b2bContext {
        role
        company { id name creditLimit }
        memberBudget { amount period spent remaining }
        creditSummary { outstanding creditLimit available }
        members { role user { id email fullName } }
        quotes { status total currency validUntil }
        pendingApprovals { orderId reference total requesterName }
        myApprovalRequests { orderId status reason }
        orderLists { id name itemCount }
    }
}
```

**Security.** `b2bContext` takes no arguments: it always resolves from the authenticated user and is
scoped to *their own* company, so one company can never read another's members, quotes, approvals,
budgets or order lists — there is no id by which to cross company boundaries. `pendingApprovals` is
returned only to approvers (admins and approvers); everyone else gets an empty list. For a request
with no signed-in user (for example a public token), `b2bContext` resolves to `null` rather than an
error.

The `Company` element type behaves like any other Craft element type: once the **View companies**
scope is enabled on a schema, that schema can read **all** companies — but only their identity
(`name`, `registrationNumber`, `status`, `id`), not their financials. Each sensitive financial field
carries a per-field resolver that returns `null` unless **either** the active schema also has the
dedicated **View company financial fields** (`b2bCompanies.financials`) scope, **or** the field
belongs to the signed-in user's *own* company (a caller reading their own financials is always
permitted). So enabling **View companies** on a public token can never dump every company's tax IDs,
credit limits or approval thresholds to competitors; that requires the explicit financials scope.

A signed-in user's own per-company sensitive data (their company's financials, plus budgets, credit,
members, quotes, approvals and order lists) is always available — with no extra scope — through the
user-scoped `b2bContext`, which resolves solely from the authenticated user and cannot be pointed at
another company. Aggregate data (members, quotes, approvals, order lists, budgets) is never reachable
through the element type at all; it lives only under `b2bContext`.

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
- `php craft b2b-commerce/tax-id/revalidate` — revalidates every company VAT ID against VIES,
  bypassing the known-valid cache, and reports per company plus a summary. Skips (with a
  warning) VAT IDs it cannot check because VIES is unreachable and then exits `75` (`TEMPFAIL`)
  so schedulers can retry; exits `0` otherwise. Cron-able, for example weekly. See
  [VAT ID validation & reverse charge](#vat-id-validation--reverse-charge).

## Uninstalling

Uninstalling the plugin intentionally leaves its eight database tables
(`b2b_companies`, `b2b_company_users`, `b2b_order_company`, `b2b_quotes`,
`b2b_order_lists`, `b2b_order_list_items`, `b2b_approvals` and `b2b_member_budgets`)
and their data behind. The install migration is
idempotent: if the tables already exist it skips creation and keeps your data, so a later
reinstall picks up exactly where you left off — no manual SQL required.

## Roadmap

The five pillars, per-member budgets, the two-layer payment/completion enforcement and
the read-only GraphQL API all ship in this release. Genuinely planned for future
releases:

- **Project-config storage** for the company field layout (see [Known limitations](#known-limitations)).
- **Configurable roles** beyond the built-in admin / purchaser / approver set.
- **Departments** — sub-groups within a company, with **department-level budgets**
  layered above the per-member budgets shipping now.
- **PunchOut and accounting integrations** (cXML/OCI procurement punchout, and
  export to accounting packages).
- **Per-store credit** — credit limits and outstanding balances tracked per Commerce
  store rather than the single primary-store currency used today.
- **Lite / Pro editions** — a tiered edition split once the feature set settles.

## License

This is commercial software. Once it is available on the Craft Plugin Store, a licence
must be purchased through the store for each production install. See [LICENSE.md](LICENSE.md)
for the full licence terms.

© TotalWebCreations.
