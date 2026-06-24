# Release Notes for B2B Commerce

## 1.0.0-alpha.1 - Unreleased

### Added
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
