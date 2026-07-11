# GraphQL API

The plugin exposes its B2B data through GraphQL for headless / decoupled storefronts. Reads are
the primary surface; a small, **opt-in** set of write mutations additionally lets an
authenticated storefront act on the caller's own cart or company without a full page load.
Company settings (credit limit, approval threshold, catalog restriction) remain control-panel
only — there is no mutation that changes them.

Nothing is queryable until you opt in per schema. Under **GraphQL → Schemas** (or the public
schema) in the control panel, enable the scopes you need.

## Schema scopes

| Scope | Key | Enables |
| --- | --- | --- |
| View companies | `b2bCompanies.all:read` | The `companies`, `company` and `companyCount` queries for the `Company` element type. Exposes only non-sensitive company **identity**: `id`, `name`, `registrationNumber`, `status` and any custom fields on the company field layout. |
| View company financial fields | `b2bCompanies.financials:read` | A separate, opt-in add-on that unlocks the financial fields (`taxId`, `creditLimit`, `paymentTermDays`, `allowInvoicePayment`, `approvalThreshold`) across **all** companies in the `companies`/`company` queries. **Off by default. Do not enable it on a public schema.** |
| View the current user's B2B context | `b2bContext.self:read` | The top-level `b2bContext` query. |
| Perform B2B write mutations | `b2bContext.write:edit` | The write mutations described below. **Off by default.** |

Without the financials scope, the sensitive financial fields resolve to `null` — **unless** the
field belongs to the signed-in user's *own* company (a caller reading their own financials is
always permitted). So enabling **View companies** on a public token can never dump every
company's tax IDs, credit limits or approval thresholds to competitors.

## `b2bContext`

```graphql
query {
    b2bContext {
        role
        company { id name creditLimit }
        memberBudget { amount period spent remaining }
        creditSummary { outstanding creditLimit available }
        members { role user { id email fullName } }
        quotes { status total currency validUntil poNumber }
        pendingApprovals { orderId reference total requesterName }
        myApprovalRequests { orderId status reason poNumber steps { level status resolvedByName } }
        orderLists { id name itemCount }
        departments { id name budgetAmount budgetPeriod approverUserId }
        departmentBudget { amount period spent remaining }
        approvalTiers { level minAmount approverRole departmentScoped }
        catalogCriteria
        statement {
            current due1To30 due31To60 due61To90 due90Plus
            lines { orderNumber outstanding daysPastDue reference }
        }
    }
}
```

- `departments` / `departmentBudget` — the company's departments and the current user's own
  department spend budget (`null` with no department, or an unlimited one). See
  [Departments & budgets](/guides/departments-budgets).
- `approvalTiers` — the company's amount-tiered approval ladder; empty for a tier-less company.
  `myApprovalRequests[].steps` exposes the per-request step ladder the same way. See
  [Order approvals](/guides/approvals).
- `catalogCriteria` — a convenience summary string of the company's catalog restriction, or
  `null` for the full catalog; the add-to-cart veto remains the actual security boundary (see
  [Company-specific pricing & catalog](/guides/company-catalog)).
- `statement` — the company's account statement with aging buckets, identical to
  `craft.b2b.statement` on the storefront.
- `poNumber` on `quotes`/`myApprovalRequests`/`pendingApprovals` — the buyer purchase-order
  number stamped on that order, if any. Commerce 5 ships no GraphQL `Order` type or top-level
  `order` query, so the PO number is read where B2B already surfaces orders (quotes and approval
  requests) rather than as a bespoke field on an order type.

### Security

`b2bContext` takes no arguments: it always resolves from the authenticated user and is scoped to
*their own* company, so one company can never read another's members, quotes, approvals,
budgets, departments, approval tiers, catalog criteria, statement, PO numbers or order lists —
there is no id by which to cross company boundaries. `pendingApprovals` is returned only to
approvers (admins and approvers); everyone else gets an empty list. For a request with no
signed-in user (for example a public token), `b2bContext` resolves to `null` rather than an
error.

## Write mutations

Gated by the separate **`b2bContext.write`** schema component, **off by default** — enabling any
of the read scopes above never enables writes. Every mutation requires an authenticated member
(a signed-in user belonging to a company); a guest is refused with a clean error. None of them
accept a company id: the acting company is always derived from the caller, so a token can never
write another company's data. Each mutation is a thin wrapper over the same service the
equivalent storefront action controller calls, so every existing guard (four-eyes, department
scoping, cross-company checks, credit/budget enforcement) applies unchanged.

```graphql
mutation {
    setPoNumber(poNumber: "PO-1234")
}

mutation {
    requestQuote(notes: "Please quote for 500 units")
    acceptQuote(token: "…")
    declineQuote(token: "…", reason: "No longer needed")
}

mutation {
    submitForApproval
    approveOrder(orderId: 123)
    declineOrder(orderId: 123, reason: "Over budget")
}

mutation {
    createOrderList(name: "Weekly restock")
    renameOrderList(listId: 1, name: "Fortnightly restock")
    addOrderListItem(listId: 1, purchasableId: 456, qty: 3)
}
```

| Mutation | Backing service call |
| --- | --- |
| `setPoNumber(poNumber: String!): String` | `OrderReferences::setPoNumber` — sets the PO number on the caller's active cart. |
| `requestQuote(notes: String): Boolean` | `Quotes::requestQuote` |
| `acceptQuote(token: String!): String` | `Quotes::acceptByToken` — authorized by token, not the caller's company. Returns the cart number. |
| `declineQuote(token: String!, reason: String): Boolean` | `Quotes::declineByToken` — authorized by token. |
| `submitForApproval: Boolean` | `Approvals::submitForApproval` |
| `approveOrder(orderId: Int!): Boolean` | `Approvals::approve` — four-eyes and sequential step order enforced, unchanged from the storefront controller. |
| `declineOrder(orderId: Int!, reason: String): Boolean` | `Approvals::decline` |
| `createOrderList(name: String!): Int` | `OrderLists::createList` — returns the new list id, always scoped to the caller's own company. |
| `renameOrderList(listId: Int!, name: String!): Boolean` | `OrderLists::renameList` — a `listId` naming another company's list is refused, not silently ignored. |
| `addOrderListItem(listId: Int!, purchasableId: Int!, qty: Int!): Boolean` | `OrderLists::setItem` — `qty: 0` removes the item. |

## Element type behaviour

The `Company` element type behaves like any other Craft element type: once **View companies** is
enabled on a schema, that schema can read **all** companies — but only their identity (`name`,
`registrationNumber`, `status`, `id`), not their financials. Each sensitive financial field
carries a per-field resolver that returns `null` unless either the active schema also has the
dedicated **View company financial fields** scope, or the field belongs to the signed-in user's
*own* company.

A signed-in user's own per-company sensitive data (their company's financials, plus budgets,
credit, members, quotes, approvals and order lists) is always available — with no extra scope —
through the user-scoped `b2bContext`, which resolves solely from the authenticated user and
cannot be pointed at another company. Aggregate data (members, quotes, approvals, order lists,
budgets) is never reachable through the element type at all; it lives only under `b2bContext`.
