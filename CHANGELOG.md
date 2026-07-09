# Release Notes for B2B Commerce

## Unreleased

### Added
- **Order on behalf of (sales reps).** A sales rep can act as a member of a company they are
  assigned to and place orders in that member's name, without gaining any of their own elevated
  rights: the active identity becomes the member, so every existing storefront guard (budget,
  credit limit, approval, PO number, account status) is enforced against the member — a rep can
  never place an order the member could not place themselves. Built on Craft's native user
  impersonation, so a rep needs **both** the B2B `Order on behalf of a company`
  (`b2b-commerce:orderOnBehalf`) permission **and** Craft's native `Impersonate users`
  permission; the plugin's own assignment scope decides *which* companies a rep may act for,
  independently of the impersonate permission. Assign or remove reps and review a per-company
  impersonation audit log on the company's **Sales reps** page in the control panel. Completed
  on-behalf orders are stamped with the placing rep and logged. New `b2b_rep_companies` and
  `b2b_impersonation_log` tables plus a `placedByRepId` column (schema 1.1.4).
- **Merchant-initiated quotes.** A merchant or sales-rep can build a cart in Commerce's native
  order editor and click **Send as B2B quote** to send a price-frozen quote to a customer —
  linking the customer's company automatically (or via a picker), emailing the accept/decline
  links plus the quote PDF, and recording the quote's origin. The customer accepts through the
  existing token flow and checks out at the frozen prices; the same buyer-mutation veto applies.
- Quote and order/invoice PDF documents, rendered via Commerce's native dompdf service, with
  overridable templates, permission-gated CP downloads and token/member-guarded storefront
  downloads. No schema change.

## 1.0.0-beta.3 - 2026-07-09

### Added
- **Company-specific pricing.** Assign a company to a Craft user group; the plugin keeps every
  approved member of that company in the group (and never touches their other group
  memberships). Build a Craft Commerce **Catalog Pricing Rule** with a customer condition
  matching that group and each company automatically sees its own wholesale prices — no custom
  pricing engine, all native Commerce. Members of pending or blocked companies are not placed in
  the group, so unapproved accounts never get wholesale pricing.
- **Per-member spending budgets.** A budget caps how much a single team member may spend
  for their company within a period, independently of (and on top of) the company credit
  limit. Set each member's **amount** and **period** (monthly, quarterly, yearly, or a
  never-resetting *None*) on the company's **Members** page in the control panel — or
  leave a member with no budget for unlimited spending. Spend is the member's completed
  orders for that company in the current period (on every gateway, minus settled
  cancelled/refunded orders). The member's own budget is exposed on the storefront as
  `craft.b2b.memberBudget` (`{ amount, period, spent, remaining }`), with an example
  `b2b/account/budget.twig` page.
- **Payment-time enforcement — the approval and credit gates now refuse the charge up
  front.** A gated purchaser with no approved approval, an over-budget member, or a
  pay-on-account order over the company credit limit is now refused on
  `Payments::EVENT_BEFORE_PROCESS_PAYMENT`, *before* Commerce creates a transaction or the
  gateway authorizes — so a buyer paying by card is never charged for an order that cannot
  be placed. This layers on top of (and shares its decision logic with) the existing
  completion-time backstop, which still catches zero-payment and free-order completions.
  Console and control-panel payments and completions bypass both layers by design (the
  merchant override).
- Read-only GraphQL API for headless storefronts. Exposes the `Company` element type
  (`companies` / `company` / `companyCount` queries, including custom fields) and a top-level
  `b2bContext` query returning the authenticated user's company, role, spending budget, company
  credit summary, members, quotes, approvals and order lists. Both are gated behind opt-in
  **B2B Commerce** schema scopes; `b2bContext` is always scoped to the caller's own company and
  never exposes another company's data. No mutations — writes stay on the action controllers.
  The **View companies** (`b2bCompanies.all`) scope exposes only company *identity* (name,
  registration number, status); sensitive financial fields (tax ID, credit limit, payment term,
  pay-on-account, approval threshold) resolve to `null` unless the caller reads their own company
  or the separate, opt-in **View company financial fields** (`b2bCompanies.financials`) scope is
  enabled — so enabling **View companies** on a public token can no longer leak every company's
  financials.
- Manage a company's contact persons (members) directly from the control panel: add or invite
  a member with a role, change a member's role, and remove a member — from the company's
  Members page. Reuses the same guards as the front-end team management (approved company,
  duplicate-membership check, last-admin protection).
- A "New company" button on the control-panel Companies index, so companies can be created
  from the CP.

### Changed
- The company edit screen now renders the core fields (name, registration number, VAT ID,
  credit limit, payment term, pay-on-account, approval threshold) in the main content area
  instead of only the sidebar, so the screen is never empty and the field-layout designer
  still manages any custom fields you add.
- **Quotes and Approvals are now Craft elements.** Their control-panel lists (B2B → Quotes,
  B2B → Approvals) are native element indexes — status-source sidebar with colored dots,
  keyword search, sortable columns and export. The element identity sits around the existing
  business record: `orderId` stays the business key every enforcement guard reads, so all
  quote and approval enforcement is unchanged. The Approvals index stays deliberately read-only
  monitoring — approval decisions belong to the company's own approvers, never the store operator.

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
