# B2B Commerce

A B2B suite for Craft Commerce. B2B Commerce turns a standard Craft Commerce store
into a wholesale/business storefront with company accounts, roles and a registration
approval workflow. It is built around five pillars, delivered across multiple phases.

## What it does

B2B Commerce is organised around five pillars:

1. **Company accounts** — *available now.* Companies are first-class elements with
   their own control panel section, statuses (pending, approved, blocked), roles
   (admin, purchaser, approver) and permissions. Businesses register from the
   frontend and are approved by a store manager in the control panel.
2. **Quotes** — *on the roadmap.* Request-for-quote flow with a status lifecycle and
   order adjuster.
3. **Order approvals** — *on the roadmap.* Spending thresholds with an approve/decline
   flow for purchasers and approvers.
4. **Pay on account** — *on the roadmap.* Offline "pay on account" gateway with credit
   limits and balance overviews.
5. **Quick order** — *on the roadmap.* SKU entry, CSV import, re-ordering and order
   lists for fast repeat purchasing.

This first release focuses on pillar 1. It ships:

- A **Company** element with control panel management, statuses and a
  `Manage companies` permission.
- Company **roles** (admin, purchaser, approver) linking users to a company.
- A **frontend registration** flow that creates a pending company plus its admin user
  and notifies a store manager by email.
- An **approval flow** using element actions (Approve / Block) in the control panel.
  Approving a company activates its members and sends them the
  `B2B: company approved` system message.
- **Price visibility**: optionally hide prices and block add-to-cart for guests and
  unapproved accounts.
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

## Settings reference

| Setting | Key | Default | Description |
| --- | --- | --- | --- |
| Companies | `enableCompanies` | `true` | Enable company accounts, roles and registration approval. |
| Quotes | `enableQuotes` | `true` | Reserved for the quotes pillar (roadmap). |
| Order approvals | `enableApprovals` | `true` | Reserved for the order approvals pillar (roadmap). |
| Pay on account | `enableInvoicing` | `true` | Reserved for the pay-on-account pillar (roadmap). |
| Quick order | `enableQuickOrder` | `true` | Reserved for the quick order pillar (roadmap). |
| Hide prices for guests | `hidePricesForGuests` | `false` | Hide prices and disable add-to-cart for visitors without an approved company account. |
| Admin notification email | `adminNotificationEmail` | `''` | Receives a notification when a new company registers. Falls back to the system "from" address when empty. |

## Uninstalling

Uninstalling the plugin does not drop its database tables (`b2b_companies`,
`b2b_company_users`). If you plan to reinstall, drop those tables first, otherwise the
install migration will fail because the tables already exist:

```sql
DROP TABLE IF EXISTS b2b_company_users, b2b_companies;
```

## Roadmap

The remaining pillars are planned for future phases:

- **Quick order** — SKU entry, CSV import, re-ordering and order lists.
- **Pay on account** — offline "pay on account" gateway, credit checks and balance
  overviews.
- **Quotes** — request-for-quote lifecycle, order adjuster and validity handling.
- **Order approvals** — spending thresholds with an approve/decline flow and emails.
- **Tax ID / VIES validation** and Plugin Store polish.

## License

Proprietary. © TotalWebCreations.
