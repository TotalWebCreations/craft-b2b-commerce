# Release Notes for B2B Commerce

## 1.0.0-beta.1 - 2026-07-09

First public release. B2B Commerce turns a standard Craft Commerce store into a
wholesale/business storefront, built around five pillars: company accounts, quotes,
order approvals, pay on account and quick order.

### Added

#### Company accounts
- **Company element** with its own control-panel section, statuses (pending, approved,
  blocked), roles (admin, purchaser, approver) and a `Manage companies` permission.
- **Frontend registration**: businesses register from the storefront, creating a pending
  company plus its admin user and notifying a store manager by email. Includes a honeypot
  anti-spam field and a cancelable before-register event for custom checks.
- **Approval flow**: approve or block a company from the control panel with element
  actions. Approving activates its members and emails them the `B2B: company approved`
  message alongside an activation email so they can set a password.
- **Team management** for company admins from the storefront: invite colleagues, change
  roles and remove members, guarded so a company always keeps at least one admin.
- **Shared address book**: native Craft `Address` elements owned by the company, so the
  whole team sees and reuses the same addresses.
- **Order–company linking**: completed orders are linked to the buyer's company, exposed
  as `order.b2bCompany`, with a checkout backstop that refuses completion for guests and
  unapproved or blocked accounts.
- **Control-panel company pages** with per-company member and order overviews, plus a
  configurable custom-field layout.
- **Price visibility**: optionally hide prices and block ordering for guests and
  unapproved accounts.

#### Quotes
- **Request for quote**: an approved buyer turns a cart into a quote request; the request
  survives as a non-completed order while the buyer keeps a fresh cart.
- **Quote workbench** (B2B → Quotes, `Manage quotes` permission): every quote newest-first,
  filterable by status, with Mark sent (optional validity date) and Decline (with reason)
  actions.
- **Frozen prices**: sending a quote pins the order's prices (Commerce `recalculationMode
  = none`, persisted and restored on load) so the buyer pays exactly what the merchant
  left on each line item.
- **Token-authorized accept and decline** from an emailed link: accepting adopts the
  frozen order as the buyer's cart to check out against, declining records a reason. An
  unknown token and another company's token return the same generic message so a token
  cannot be probed.
- **Cart-mutation guard** that vetoes buyer-side line-item changes on an open or accepted
  quote order, so a reactivated quote cart cannot be re-priced; merchant edits stay free.
- Storefront `craft.b2b.quotes` overview and `craft.b2b.quoteByToken(token)` accept data.
- `b2b-commerce/quotes/expire` console command to lapse overdue quotes, plus lazy
  expiry on the first accept/decline touch.

#### Order approvals
- **Spending threshold**: a per-company `approvalThreshold` holds a purchaser's
  over-threshold order for a company approver. The threshold in force is snapshotted onto
  the request; a `null` threshold runs no gate and the comparison is strictly greater-than.
- **Submit / approve / decline** driven by the company's own approvers, enforcing the
  four-eyes rule (an approver can never approve their own submission). Approving an invoice
  order places it on account; approving other orders emails the requester a
  resume-checkout link; declining records a reason that reaches the requester by email.
- **Completion backstop** that enforces the gate server-side even when the storefront
  submit step is bypassed, applied equally to accepted-quote orders. Auto-approves a
  pending order that no longer needs approval at completion instead of blocking it.
- **Monitoring overview** (B2B → Approvals, `Manage approvals` permission): read-only,
  newest-first, filterable by status, showing company, requester, resolver, snapshotted
  threshold, order total and decline reason. Decisions belong to the customer's approvers.
- Storefront `craft.b2b.pendingApprovals` (approver queue) and
  `craft.b2b.myApprovalRequests` (a requester's own requests).

#### Pay on account
- **Offline "pay on account" gateway** that lets approved companies with pay-on-account
  enabled check out on invoice; the order completes unpaid so you can capture and invoice
  out of band.
- **Per-company credit limit** enforced on the storefront: the gateway is only offered
  while an order fits inside the remaining credit, and the limit is re-checked under a
  per-company lock at completion. An empty limit means no credit room, not unlimited.
- **Credit summary**: outstanding balance and available credit exposed as
  `craft.b2b.creditSummary` on the storefront and shown to merchants on a company's Orders
  page, alongside a per-order paid / partially paid / unpaid status.
- `order.b2bPaymentDueDate`: a completed invoice order's payment due date, derived from
  the order date plus the company's payment term.

#### Quick order
- **Paste SKUs** (one per line, Excel-style) to add many products to the cart at once,
  with per-line error reporting keyed to the original line number.
- **CSV upload** fed through the same SKU parser as the textarea.
- **Re-order** a completed order's still-available line items — the buyer's own or a
  colleague's.
- **Shared, company-scoped order lists** with a create/rename/delete flow and an item
  editor, exposed as `craft.b2b.orderLists` and `craft.b2b.getOrderListItems`.

#### VAT & platform
- **EU VAT ID validation and reverse charge** built on Commerce's native VAT support:
  validates the company VAT ID against VIES at registration/save time and carries it onto
  the order at checkout so Commerce applies the reverse charge automatically. Configurable
  VIES outage policy (lenient/strict) and a `b2b-commerce/tax-id/revalidate` console
  command.
- `b2b-commerce/team/assign-role` console command to recover a company that lost its last
  admin, and `b2b-commerce/seed` to bootstrap demo data.
- Example storefront templates for registration, price display, team, address book, quick
  order, re-order, order lists, quotes and approvals.
- `craft.b2b` template variables including `company`, `teamMembers`, `companyAddresses`,
  `canViewPrices` and `canPurchase`.
- Dutch translations for all control-panel and frontend strings.

### Changed
- The `enableInvoicing`, `enableQuotes`, `enableApprovals` and `enableQuickOrder` settings
  are functional: turning one off cleanly disables its endpoints and features (and disarms
  the approval completion backstop for `enableApprovals`).

### Fixed
- Surface company field-layout save failures instead of discarding them silently.
- Exempt already-paid orders from the checkout purchase backstop, so a blocked company's
  paid order can still be completed.
- Link orders to their company after completion is persisted, so the association survives
  the completion transaction.
- Stand down the line-item freeze during storefront completion saves. `markAsComplete()`
  salts the line-item options signature while the stored row still carries the cart
  signature, which the buyer-mutation veto read as a phantom edit and used to block the
  completion of every accepted quote and approved order (leaving a paid-but-incomplete
  order). Completion never changes the line-item set, so the guard now stands down for a
  completing or completed order.
- Default the pay-on-account (invoice) gateway to the authorize-only payment type it
  supports. It previously inherited Commerce's `purchase` default and refused every payment
  with "Gateway doesn't support purchase".
