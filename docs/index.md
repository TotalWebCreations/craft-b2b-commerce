---
layout: home

hero:
  name: B2B Commerce
  text: A wholesale storefront for Craft Commerce
  tagline: Company accounts, quotes, order approvals, pay on account and quick ordering — all on top of native Craft Commerce.
  actions:
    - theme: brand
      text: Get started
      link: /getting-started/installation
    - theme: alt
      text: Guides
      link: /guides/companies-teams
    - theme: alt
      text: Reference
      link: /reference/settings

features:
  - title: Company accounts
    details: Companies are first-class elements with statuses (pending, approved, blocked), roles (admin, purchaser, approver) and a shared team & address book. Businesses register from the frontend and are approved by a store manager in the control panel.
  - title: Quotes
    details: Request-for-quote flow with a full status lifecycle. An approved buyer turns a cart into a quote request, or a merchant sends one proactively — either way prices freeze and the buyer checks out at exactly what was quoted.
  - title: Order approvals
    details: Spending thresholds and amount-tiered, multi-level approval ladders with an approve/decline flow for purchasers and approvers, enforced at both payment time and completion time.
  - title: Pay on account
    details: An offline invoice gateway lets approved companies check out on account, with credit limits enforced on the storefront and outstanding-balance overviews everywhere.
  - title: Quick order
    details: Paste SKUs, upload a CSV, re-order a past order, or keep shared company-wide order lists to drop into the cart in one go.
  - title: Departments & budgets
    details: Per-member and per-department spending budgets layer on top of the company credit limit, with amount-tiered, department-scoped approval routing.
  - title: Sales reps
    details: A rep can act as a company's member and place orders on their behalf, built on Craft's native impersonation — with no elevated rights and a full audit log.
  - title: Company-specific pricing & catalog
    details: Assign a company to a Craft user group for native Commerce catalog pricing, and restrict which products a company may buy with a per-company product condition.
  - title: PDF documents & statements
    details: Quote, invoice and account-statement PDFs render through Commerce's own dompdf service, with overridable templates and an opt-in dunning command for overdue-invoice reminders.
---

## What it does

B2B Commerce turns a standard Craft Commerce store into a wholesale/business storefront.
Businesses register and are approved by a store manager, their team orders on behalf of the
company, and merchants sell the way B2B works: negotiated quotes, spending approvals, invoicing
on account and fast repeat ordering.

The plugin is organised around five pillars, all live in this release:

1. **[Company accounts](/guides/companies-teams)** — control-panel approval, roles, team
   management, a shared address book, [sales reps](/guides/sales-reps), and
   [company-specific pricing & catalog restriction](/guides/company-catalog).
2. **[Quotes](/guides/quotes)** — request-for-quote (customer-initiated) and merchant-initiated
   quotes, both with frozen prices and PDF documents.
3. **[Order approvals](/guides/approvals)** — single-threshold and amount-tiered, multi-level
   approval ladders, with [department budgets](/guides/departments-budgets) layered on top.
4. **[Pay on account](/guides/pay-on-account)** — an offline invoice gateway with enforced credit
   limits, [account statements and opt-in dunning](/guides/statements-dunning).
5. **[Quick order](/guides/quick-order)** — SKU paste, CSV upload, re-order and shared order
   lists.

It also validates company **EU VAT IDs** against VIES and applies the intra-EU reverse charge
automatically at checkout, ships example storefront templates for every flow, adds a
`craft.b2b` template variable and an opt-in [GraphQL API](/reference/graphql), and includes
Dutch translations for all control-panel and frontend strings.

## Requirements

- **Craft CMS 5, Pro edition** — required, because business accounts rely on multiple users.
  The Solo edition only supports a single user and cannot run the B2B flows.
- **Craft Commerce 5** (`^5.0`). The EU VAT ID validation & reverse charge feature needs
  Commerce's native VAT support, which arrived in **Commerce 5.3**; every other feature works
  on Commerce 5.0–5.2.
- **PHP 8.2** or newer.
- **MySQL or PostgreSQL** — both are supported, since the plugin uses only Craft's query
  builder.

See [Installation & requirements](/getting-started/installation) for the full details, or head
straight to the [quick start](/getting-started/quick-start).

## Where to get it

This is commercial software. Once it is available on the [Craft Plugin
Store](https://plugins.craftcms.com/), a licence must be purchased through the store for each
production install.
