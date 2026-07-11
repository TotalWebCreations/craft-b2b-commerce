# Settings reference

All settings live under **Settings → Plugins → B2B Commerce**, backed by
`totalwebcreations\b2bcommerce\models\Settings`.

| Setting | Key | Default | Description |
| --- | --- | --- | --- |
| Companies | `enableCompanies` | `true` | No effect yet — reserved. Company accounts are always active in this release. |
| Quotes | `enableQuotes` | `true` | Enables quote requests from the cart and the quote workflow. When off, the request-quote endpoint returns a clean "feature not enabled" failure. |
| Order approvals | `enableApprovals` | `true` | Lets purchasers submit orders above their company approval threshold (or tier) for an approver to approve. When off, the submit-for-approval endpoint returns a clean "feature not enabled" failure and the completion backstop is not enforced. |
| Pay on account | `enableInvoicing` | `true` | Governs whether the pay-on-account (invoice) gateway is offered at checkout. When off, the gateway is never available regardless of company settings. |
| Settled order statuses | `excludedOrderStatusHandles` | `cancelled, refunded` | Comma-separated Commerce order-status handles whose orders are treated as settled and never count towards a company's outstanding balance or spending-budget spend. |
| Quick order | `enableQuickOrder` | `true` | Enables quick order, order lists and reorder for approved buyers. When off, those front-end endpoints return a clean "feature not enabled" failure and `craft.b2b` exposes no order-list data. |
| Hide prices for guests | `hidePricesForGuests` | `false` | Hide prices and disable add-to-cart for visitors without an approved company account. |
| Admin notification email | `adminNotificationEmail` | `''` | Receives a notification when a new company registers. Falls back to the system "from" address when empty. |
| Honeypot field name | `honeypotFieldName` | `'b2b_website'` | Name of the hidden anti-spam field on the registration form. Cannot match a real registration field name (`companyName`, `registrationNumber`, `taxId`, `firstName`, `lastName`, `email`). |
| Validate VAT IDs | `validateTaxIds` | `false` | Validate company VAT IDs against VIES when a company is registered or saved. |
| VIES outage policy | `taxIdValidationPolicy` | `'lenient'` | What to do when VIES is unreachable during validation: `lenient` accepts and logs a warning, `strict` refuses the save. A definitively invalid VAT ID is refused under both. |
| Quote PDF template | `quotePdfTemplate` | `''` | Site template path used to render the quote PDF. Leave blank to use the bundled default (`src/templates/pdf/quote.twig`). |
| Invoice PDF template | `invoicePdfTemplate` | `''` | Site template path used to render the order/invoice PDF. Leave blank to use the bundled default (`src/templates/pdf/invoice.twig`). |
| Statement PDF template | `statementPdfTemplate` | `''` | Site template path used to render the account-statement PDF. Leave blank to use the bundled default (`src/templates/pdf/statement.twig`). |
| Send payment reminders (dunning) | `enableDunning` | `false` | Lets the `b2b-commerce/dunning/run` console command email overdue-invoice payment reminders. **Off by default** — it is the only feature that emails customers autonomously; turn it on only once the command is scheduled. |
| Dunning offsets (days) | `dunningOffsets` | `7, 14, 30` | Comma-separated days past an invoice's due date at which a reminder is sent. Each offset is dunned at most once per invoice. |

## Validation notes

- `adminNotificationEmail` must be a valid email address when set (empty is allowed).
- `honeypotFieldName` is required and cannot collide with a real registration field.
- `excludedOrderStatusHandles` and `dunningOffsets` are edited as comma-separated text fields and
  normalized on save: blanks are trimmed away, `dunningOffsets` keeps only positive whole numbers
  and de-duplicates/sorts them ascending.
- `taxIdValidationPolicy` must be one of `lenient` / `strict`.

## Per-company settings

Beyond the plugin-wide settings above, several settings live **per company** on the company edit
screen (native field-layout elements, always present in the main content area):

| Field | Purpose |
| --- | --- |
| **Registration number** | Company registration number (e.g. chamber of commerce). |
| **Tax ID** | EU VAT ID with country prefix (e.g. `NL123456789B01`). Validated against VIES when **Validate VAT IDs** is enabled. |
| **Credit limit** | See [Pay on account & credit](/guides/pay-on-account). |
| **Payment term (days)** | Feeds `order.b2bPaymentDueDate` and the account statement aging. |
| **Allow pay on account** | Whether this company may use the invoice gateway. |
| **Require purchase order number** | See [PO numbers](/guides/po-numbers). |
| **Approval threshold** | Orders above this amount require approval. Leave empty to disable. See [Order approvals](/guides/approvals). |
| **Pricing group** | The Craft user group the company's approved members are kept in for Commerce catalog pricing. See [Company-specific pricing & catalog](/guides/company-catalog). |
| **Product catalog** | The product condition restricting which products this company's members may buy. Empty = full catalog. |

Approval tiers, departments and sales-rep assignments are configured on their own dedicated
company sub-pages rather than the field layout — see [Order approvals](/guides/approvals),
[Departments & budgets](/guides/departments-budgets) and
[Sales reps](/guides/sales-reps).

## Known limitation

**Company field layout is stored in the database, not project config.** The custom-field layout
you configure alongside the settings above is saved directly to the database and does **not**
deploy through project config. Reconfigure it per environment after deploying.
