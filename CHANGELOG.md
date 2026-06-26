# Release Notes for B2B Commerce

## 1.0.0-alpha.1 - Unreleased

### Added
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
- The `B2B: company approved` email is now accompanied by an activation email so new members can set a password

### Fixed
- Surface company field layout save failures instead of discarding them silently
- Exempt already-paid orders from the checkout purchase backstop, so a blocked company's paid order can still be completed
- Link orders to their company after completion is persisted, so the association survives the completion transaction
