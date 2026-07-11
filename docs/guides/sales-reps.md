# Sales reps (order on behalf)

A **sales rep** can act as a member of a company they are assigned to and place orders in that
member's name — useful when a rep takes an order by phone or manages an account's purchasing.

## No elevated rights

The flow is built on Craft's native user impersonation: when a rep starts acting as a member,
the **active session identity becomes that member**, so every storefront guard already in place
(spending budget, credit limit, order approval, required PO number, account status) is enforced
**against the member, exactly as if the member had signed in themselves**. A rep therefore gains
no elevated rights: they can never place an order the member could not place on their own —
including an order that exceeds the member's own spending budget, which is refused just as it
would be for the member.

## Two required permissions

Two permissions are required, and they are orthogonal:

- **`b2b-commerce:orderOnBehalf`** (*Order on behalf of a company*) — the B2B permission that
  marks a user as eligible to be a sales rep.
- Craft's native **`impersonateUsers`** (*Impersonate users*) — the mechanical permission Craft
  requires to switch identity. Because the flow reuses Craft's own impersonation, a rep without
  it cannot act as anyone.

Assignment is a separate, third gate handled entirely by the plugin: the server-side `canActFor`
check confirms the rep holds `orderOnBehalf` **and** carries an assignment row for the target's
company before impersonation is allowed. Assignment scope is independent of the impersonate
permission — holding `impersonateUsers` (or being an admin) grants **no** B2B rep scope on its
own; a rep can act only for the companies they are explicitly assigned to, and only for members
of those companies.

## Assigning reps

Assign or remove reps and review a **per-company, read-only impersonation audit log** on the
company's **Sales reps** page in the control panel (**B2B → Companies →** a company **→ Sales
reps**), gated behind the `manageCompanies` permission. The page also flags any assigned rep who
is still missing one of the two required permissions. Every act-as, end-act-as, and completed
on-behalf order is recorded in the log, and completed orders are stamped with the placing rep.

## Storefront flow

A rep's own **Sales rep** landing page (`examples/templates/b2b/sales-rep/index.twig`, gated by
the `orderOnBehalf` permission via `SalesRepController::beforeAction`) lists the companies and
members they may act for, and posts to:

```
b2b-commerce/sales-rep/act    (POST: userId)   — start acting as a member
b2b-commerce/sales-rep/end    (POST)           — end impersonation, back to the rep
```

Ending impersonation is deliberately reachable while the active identity is the impersonated
member (who does not hold the rep permission) — otherwise a rep would be trapped in the
impersonation once they switch. Every other action re-checks the `orderOnBehalf` permission; the
per-company scope is re-checked in `actAs`.

The catalog restriction guard (see [Company-specific pricing & catalog](/guides/company-catalog))
resolves the buyer's company from the **effective** (impersonated) identity, so a rep acting on
behalf is judged against the impersonated member's catalog — no elevation there either.
