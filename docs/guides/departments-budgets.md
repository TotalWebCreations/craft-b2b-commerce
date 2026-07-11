# Departments & budgets

Three independent spending caps can apply to a single order, layered on top of each other: the
company's [credit limit](/guides/pay-on-account), a member's own **spending budget**, and their
**department's** aggregate budget. All are checked independently — a member can be under their
own budget while their department is over its aggregate, and vice versa.

## Per-member spending budgets

A **spending budget** caps how much a single team member may spend for their company within a
period. Set per member on the company's **Members** page in the control panel
(**B2B → Companies →** a company **→ Members**, requires `manageCompanies`): an **amount** and a
**period**, or removed entirely. The page also shows the member's spend this period and the room
remaining.

The **period** decides the window spend is counted over, and therefore when it resets:

- **Monthly / Quarterly / Yearly** — spend is counted from the start of the current calendar
  period (measured in the site timezone) and resets when the next one begins.
- **None** — an all-time cap that never resets (a lifetime ceiling).
- **No budget at all** — the member has unlimited spending. This is distinct from a *None*
  budget, which still caps.

Spend is the sum of the member's completed orders for that company in the current period, minus
settled statuses (the same cancelled/refunded exclusion the credit balance uses). It counts the
order's full total on **every** gateway, not just pay-on-account — a budget caps what a member
spends, not what the company owes on account.

`craft.b2b.memberBudget` exposes the signed-in user's own budget as a
`{ amount, period, spent, remaining }` array, or `null` when they have no budget (unlimited) or
no company:

```twig
{% set budget = craft.b2b.memberBudget %}

{% if budget %}
    <p>{{ 'Spent'|t('b2b-commerce') }}: {{ budget.spent|currency }} / {{ budget.amount|currency }}</p>
    <p>{{ 'Remaining'|t('b2b-commerce') }}: {{ budget.remaining|currency }}</p>
{% endif %}
```

## Departments

A company can be split into flat (one-level) **departments** on its **Departments** page
(**B2B → Companies →** a company **→ Departments**). Each department has a name, an optional
aggregate **budget** (amount + period, same period semantics as member budgets), and an optional
designated **approver**. A member belongs to at most one department.

### Department budgets

A department budget caps the **combined** spend of its current members within the department's
own period — independent of, and layered on top of, each member's individual budget. The member
set is read live, so reassigning a member mid-period shifts their future orders' spend onto the
new department immediately.

`craft.b2b.departmentBudget` exposes the signed-in user's department budget as
`{ name, amount, period, spent, remaining }`, or `null` when they have no department, the
department has no budget (unlimited), or they have no company.

### Department-scoped approval routing

A department's designated approver (plus any department member who can approve orders) becomes
the eligible approver set for a **department-scoped** [approval tier](/guides/approvals) on
that department's requests. A department-scoped tier falls back to any company approver when
department routing does not resolve (for example, the requester has no department).

Deleting a department does not orphan its members: their `departmentId` is set to null, so they
simply become department-less (and department-budget-unlimited).

## Enforcement

Like every other completion guard, both budget checks run as a storefront-scoped backstop on
`Order::EVENT_BEFORE_COMPLETE_ORDER` (registered after the approval and account-status guards
but before the credit-limit check), each under its own fail-safe lock so two orders completing
at once cannot both slip past the same member's or department's cap. Console and control-panel
completions are the deliberate merchant override, exactly as for approvals and credit.
