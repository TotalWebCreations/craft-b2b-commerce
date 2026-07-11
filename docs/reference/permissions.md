# Permissions

B2B Commerce registers its own permissions under a **B2B Commerce** heading in Craft's
user-group permission editor, plus it relies on one of Craft's native permissions for the sales
rep flow.

## Plugin permissions

| Permission | Key | Gates |
| --- | --- | --- |
| Manage companies | `b2b-commerce:manageCompanies` | The **B2B** control-panel section overview, dashboard widget, **B2B → Companies** index and every company sub-page (Members, Orders, Departments, Sales reps, Approval tiers, Statement) — including the invoice PDF download and the statement PDF download. |
| Manage quotes | `b2b-commerce:manageQuotes` | **B2B → Quotes** (the quote workbench: mark sent, decline, create a merchant-initiated quote), the quote PDF download, and the **Send as B2B quote** button injected onto Commerce's order-edit screen. |
| Manage approvals | `b2b-commerce:manageApprovals` | **B2B → Approvals**, the read-only control-panel monitoring index. |
| Order on behalf of a company | `b2b-commerce:orderOnBehalf` | The [sales rep](/guides/sales-reps) flow: the rep's own storefront landing page and the act-as/end actions. Required **together with** Craft's native `impersonateUsers` — see below. |

## Craft's native `impersonateUsers`

The [sales rep](/guides/sales-reps) flow reuses Craft's own user impersonation to switch the
active session identity to the target member. A user needs **both**
`b2b-commerce:orderOnBehalf` **and** Craft's native **Impersonate users** permission to actually
act as anyone — `orderOnBehalf` alone marks them *eligible* to be a rep, but the mechanical
identity switch is Craft's own gate. The company's **Sales reps** page flags any assigned rep
still missing either permission.

Assignment to specific companies is a third, independent gate handled entirely by the plugin
(the `b2b_rep_companies` table) — holding both permissions (or being an admin) grants **no** B2B
rep scope on its own; a rep can act only for the companies they are explicitly assigned to.

## GraphQL schema components

GraphQL exposure is gated separately, per schema, under **GraphQL → Schemas** rather than through
the control-panel user-permission editor above. See the
[GraphQL reference](/reference/graphql#schema-scopes) for the four opt-in scopes
(`b2bCompanies.all`, `b2bCompanies.financials`, `b2bContext.self`, `b2bContext.write`).
