# Order approvals

Approve, decline and resume-checkout are the company's **own internal process**, driven from the
storefront by its approvers — a merchant does **not** approve on the company's behalf. Gated by
the **Order approvals** setting (`enableApprovals`); when off, the submit-for-approval endpoint
returns a clean "feature not enabled" failure and the completion backstop is not enforced.

## Single-threshold gate

Each company sets an `approvalThreshold`. A **purchaser** whose order total clears that
threshold cannot place the order directly: it is held for a company **approver** (or admin) to
approve or decline. Approvers and admins always order directly; a company with a `null`
threshold runs no approval gate, and an order exactly at the threshold is placed without
approval (the comparison is strictly greater-than). The threshold in force at submit time is
snapshotted onto the approval row, so a later threshold change never rewrites why an order was
held.

The four-eyes principle holds throughout: an approver may never approve their own submission.

## Amount-tiered, multi-level approval

Beyond the single threshold, a company can configure **approval tiers** on its **Approval
tiers** control-panel page (**B2B → Companies →** a company **→ Approval tiers**). Each tier is a
`(level, minAmount, approverRole, departmentScoped)` row: an order whose total is at or above a
tier's `minAmount` must clear that tier's rung before it can complete.

- **Levels are sequential.** When a submitted order's total clears one or more tiers, a pending
  **step** row is created per required level. Steps must be approved in level order — approving
  advances the ladder by one rung — and the aggregate approval only flips to `approved` once the
  last required step is signed.
- **Four-eyes across steps.** One approver can never clear two distinct rungs of the same
  approval: having resolved any step, they are excluded from every other step of it.
- **Department-scoped tiers.** A tier can be marked `departmentScoped`, routing that rung's
  eligible approvers to the requester's own department (its designated approver plus any
  department member who can approve orders) instead of any company approver — see
  [Departments & budgets](/guides/departments-budgets). A department-scoped tier falls back to
  any company approver when department routing does not resolve.
- **Tiers alone can arm the gate.** A company can configure tiers without a single
  `approvalThreshold` — the lowest tier's `minAmount` arms the gate exactly like a threshold
  would.
- **A tier-less company is unaffected.** With no tiers configured, `approve()`/`decline()` fall
  back to the legacy single-approval behaviour exactly as before tiers existed.

The buyer-facing approver queue (`craft.b2b` and the example templates) shows a legacy approval
to any eligible approver, and a laddered approval only to the approver(s) eligible for its
currently-open step.

## Submitting for approval

See `examples/templates/b2b/approvals/_submit-button.twig` for the cart submit button and
`examples/templates/b2b/approvals/index.twig` for the storefront approver queue and the buyer's
own requests. Submitting is refused when: the cart is empty; the order does not actually need
approval; the order already carries an approval row (message tailored to its status — pending,
declined, or approved-ready-to-resume); or the order is part of an *open* quote (an *accepted*
quote is the deliberate exception — see [Quotes](/guides/quotes)).

## Two-layer enforcement: payment-time and completion-time

The approval and credit gates are enforced in **two coexisting layers**, so a gated purchaser is
never charged for an order that cannot be placed:

1. **Payment-time (the charge is refused up front).** On
   `Payments::EVENT_BEFORE_PROCESS_PAYMENT` — before Commerce creates a transaction or asks the
   gateway to authorize or capture — a gated purchaser with no approved approval (or a
   pay-on-account order over the company credit limit) is refused. A purchaser paying by card is
   **never charged**; Commerce returns a clean storefront failure, with no transaction created.
2. **Completion-time (the defence-in-depth net).** The same gates are re-checked on
   `Order::EVENT_BEFORE_COMPLETE_ORDER`. This net catches the paths that never run a payment call
   at all: a zero-payment or free order that completes without a charge, an approver placing an
   approved invoice order directly, and any other completion that does not go through the
   payment service.

Both layers share the same decision logic, so the two can never disagree. **Console and
control-panel payments and completions bypass both layers by design** — that is the merchant
override for placing a held order by hand.

## Control-panel monitoring

**B2B → Approvals** (`Manage approvals` permission) is a native Craft element index: a
status-source sidebar (all, pending, approved, declined) with colored status dots, keyword
search, sortable columns and export. Each row shows the company, the order total (linking to the
order editor), the requester, resolver, the snapshotted threshold and the request date.

**This overview is deliberately read-only.** Approval decisions belong to the customer's own
approvers, not the store operator, so the control panel monitors the queue but never approves or
declines from here. A merchant who genuinely needs to place a held order overrides the gate by
completing the order from the control-panel order editor.

## Resuming checkout

Once an approver approves an order, one of two things happens:

- **Pay on account within the company's credit room** — the order is placed immediately on the
  requester's behalf, and the requester is mailed that it has been placed.
- **Any other case** (non-invoice gateway, or no credit room) — the requester is mailed a
  resume-checkout instruction; they finish the order themselves via the storefront **Resume
  checkout** action.

## Template variables

`craft.b2b.pendingApprovals` — the queue for the signed-in user, only when they are an approver
of their company. `craft.b2b.myApprovalRequests` — the signed-in user's own requests, any
status, with the decision reason. See the [template variables reference](/reference/template-variables)
for the exact shapes.
