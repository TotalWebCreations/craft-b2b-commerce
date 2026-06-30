# Release Notes for B2B Commerce

## 1.0.0-alpha.1 - Unreleased

### Added
- Quotes: approved buyers turn a cart into a quote request; merchants send a quote with an optional validity date, which freezes the order's prices (Commerce `recalculationMode = none`, persisted) and emails the requester accept and decline links, or decline it (notifying the requester, or the store admin when the buyer declines)
- `b2b-commerce/quotes/expire` console command that lapses open, overdue quotes to `expired` in one update — run it on a cron
- Cart-mutation guard that vetoes buyer-side line-item changes (quantity, options, additions and removals) on an order that still carries an open quote, so a reactivated quote cart cannot be re-priced; merchant control-panel edits stay free
- Control-panel quote workbench (B2B → Quotes) listing every quote newest-first, filterable by status, with Mark sent (optional validity date) and Decline (with reason) actions, gated by a new `Manage quotes` permission
- Storefront quote overview exposed as `craft.b2b.quotes`, and read-only token data for the accept page as `craft.b2b.quoteByToken(token)`
- Token-authorized accept and decline: the sent-quote email links carry a quote token; accepting adopts the frozen order as the buyer's cart, declining records a reason and notifies the store admin. An unknown token and another company's token return the same generic message, so a guessed token cannot be probed
- Example templates for the quote request button, the company quote overview and the accept/decline page
- Pay on account: an offline "pay on account" gateway (Commerce → System Settings → Gateways → New gateway → Pay on account) that lets approved companies with pay-on-account enabled check out on invoice; the order completes unpaid so you can capture and invoice out of band
- Per-company credit limit enforced on the storefront: the gateway is only offered while a new order fits inside the company's remaining credit, and the limit is re-checked under a per-company lock at order completion so two orders completing at once cannot both slip past it. An empty credit limit means no credit room at all, not unlimited credit. Enforcement is scoped to storefront requests; control-panel completions are treated as a merchant override
- Credit balance overview: outstanding balance and available credit exposed as `craft.b2b.creditSummary` on the storefront and shown to merchants on a company's Orders page, alongside a per-order paid / partially paid / unpaid status
- `order.b2bPaymentDueDate`: a completed invoice order's payment due date, derived from the order date plus the company's payment term
- Quick order: paste SKUs (one per line, Excel-style) to add many products to the cart at once, with per-line error reporting keyed to the original line number
- Quick order CSV upload, fed through the same SKU parser as the textarea
- Re-order action that copies a completed order's still-available line items into the cart, for the buyer's own and colleague orders
- Shared, company-scoped order lists with a create/rename/delete flow, an item editor and add-to-cart, exposed as `craft.b2b.orderLists` and `craft.b2b.getOrderListItems`
- `b2b-commerce/team/assign-role` console command to recover a company that lost its last admin
- Example templates for quick order, the re-order button and order lists
- Company element with control panel management, statuses and permissions
- Company roles (admin, purchaser, approver)
- Frontend company registration with admin approval flow
- Registration honeypot anti-spam field and a cancelable before-register event
- Frontend team management for company admins (invite, change role, remove) with a last-admin guard
- Shared company address book stored as native Craft `Address` elements
- Order–company linking for completed orders, exposed as `order.b2bCompany`
- Checkout backstop that refuses order completion for guests and unapproved/blocked accounts
- Control panel per-company member and order overview pages
- Configurable custom-field layout for companies
- `craft.b2b.teamMembers` and `craft.b2b.companyAddresses` template variables
- Price visibility: hide prices and block ordering for guests and unapproved accounts
- Dutch translations

### Changed
- The `enableInvoicing` and `enableQuickOrder` settings are now functional: turning them off makes the pay-on-account gateway unavailable everywhere and returns a clean "feature not enabled" failure from the quick-order endpoints
- The `B2B: company approved` email is now accompanied by an activation email so new members can set a password

### Fixed
- Surface company field layout save failures instead of discarding them silently
- Exempt already-paid orders from the checkout purchase backstop, so a blocked company's paid order can still be completed
- Link orders to their company after completion is persisted, so the association survives the completion transaction
