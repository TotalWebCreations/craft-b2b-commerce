# FAQ

### Does this work with Craft Solo?

No. Business accounts rely on multiple users per company (admin, purchaser, approver roles), so
**Craft CMS 5, Pro edition** is required. The Solo edition only supports a single user and
cannot run the B2B flows.

### Do I need Craft Commerce 5.3 specifically?

Only for **EU VAT ID validation & reverse charge**, which is built on Commerce's native VAT
support introduced in Commerce 5.3. Every other pillar (companies, quotes, approvals, pay on
account, quick order, departments, sales reps, catalog/pricing, statements, dunning) works on
Commerce 5.0 and up — just leave **Validate VAT IDs** off if you're on an earlier 5.x.

### An empty credit limit means unlimited credit, right?

No — the opposite. An **empty** credit limit means **no credit room at all**: a company with no
limit set can never pay on account, and the invoice gateway is never offered to it. Give a
company a positive limit to let it order on invoice up to that amount. See
[Pay on account & credit](/guides/pay-on-account).

### How do I mark an invoice as paid?

The plugin ships no custom "mark as paid" button. Record the payment the standard Commerce way —
open the order in the control panel and add a transaction under **Payments** (or use **Update
order status**). The company's outstanding balance and available credit are derived live from
those transactions. See
[Marking an invoice as paid](/guides/pay-on-account#marking-an-invoice-as-paid).

### Why did my over-limit / over-budget / over-threshold order still complete from the control panel?

By design. Console and control-panel completions are a deliberate **merchant override** for
every enforcement guard in the plugin (approval, credit limit, per-member budget, per-department
budget, required PO number, account status). Only the customer-facing storefront checkout path
is hard-enforced. An admin completing an order from the control panel is treated as an informed,
merchant-initiated decision.

### Can a sales rep place an order the member themselves couldn't?

No. Acting on behalf of a member switches the active session identity to that member (via
Craft's native impersonation), so every storefront guard is enforced **against the member**,
exactly as if they had signed in themselves. See [Sales reps](/guides/sales-reps).

### Does `craft.b2b.catalogCriteria` actually stop a restricted product from being ordered?

No — it is convenience filtering only, for hiding products from a listing. The actual security
boundary is the server-side add-to-cart veto, which runs across every add path (add-to-cart,
quick order, reorder, order lists, quote-accept, order-on-behalf). See
[Company-specific pricing & catalog](/guides/company-catalog).

### My company field-layout customizations disappeared after deploying to a new environment.

That's expected today — the company field layout is stored in the database, not project config.
See the [known limitation](/guides/companies-teams#known-limitation); project-config storage for
it is on the roadmap.

### Why isn't dunning sending any reminders even though I turned it on?

`enableDunning` only lets the `b2b-commerce/dunning/run` console command send reminders — the
setting alone does nothing without a scheduled cron calling that command. See
[Statements & dunning](/guides/statements-dunning).

### Where do I report a bug or request a feature?

Open an issue on [GitHub](https://github.com/TotalWebCreations/craft-b2b-commerce/issues).
