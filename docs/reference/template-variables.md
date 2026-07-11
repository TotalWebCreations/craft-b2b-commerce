# Template variables

The plugin registers a `craft.b2b` variable (`totalwebcreations\b2bcommerce\variables\B2bVariable`)
for use in your frontend templates. Every property below is a real PHP getter method; Twig's
property-access sugar means `getCompany()` is read as `craft.b2b.company`, and a method that
takes an argument (like `getOrderListItems($listId)`) is called as
`craft.b2b.getOrderListItems(listId)` — Twig also lets you drop the `get` prefix and call it as
`craft.b2b.orderListItems(listId)`.

## `craft.b2b.canViewPrices`

`bool`. Whether the current visitor may see prices, per
[Hide prices for guests](/reference/settings).

## `craft.b2b.canPurchase`

`bool`. Whether the current visitor may add products to the cart.

## `craft.b2b.company`

The signed-in user's `Company` element, or `null` when the visitor is not linked to a company.
Use `company.title` as the canonical accessor for the company name.

```twig
{% set company = craft.b2b.company %}

{% if company %}
    <p>{{ 'Ordering on behalf of'|t }} {{ company.title }}</p>
{% endif %}
```

## `craft.b2b.creditSummary`

`{ outstanding: float, creditLimit: ?float, available: ?float }|null`. The current user's
company credit position, or `null` with no company. See
[Pay on account & credit](/guides/pay-on-account#reading-the-credit-position).

## `craft.b2b.statement`

`{ companyId, currency, asOf, totalOutstanding, buckets, lines }|null`. The signed-in user's
company account statement, or `null` with no company. See
[Statements & dunning](/guides/statements-dunning).

## `craft.b2b.memberBudget`

`{ amount: float, period: string, spent: float, remaining: float }|null`. The current user's own
spending budget, or `null` when they have no budget (unlimited), no company, or are a guest. See
[Departments & budgets](/guides/departments-budgets).

## `craft.b2b.departmentBudget`

`{ name: string, amount: float, period: string, spent: float, remaining: float }|null`. The
current user's department spending budget, or `null` when they have no department, the
department has no budget, or they have no company. See
[Departments & budgets](/guides/departments-budgets).

## `craft.b2b.teamMembers`

`array<{ user: User, role: string }>`. The current user's colleagues, empty when the visitor has
no company.

```twig
<ul>
    {% for member in craft.b2b.teamMembers %}
        <li>{{ member.user.fullName }} — {{ member.role }}</li>
    {% endfor %}
</ul>
```

## `craft.b2b.companyAddresses`

`array<Address>`. The company's shared address book, empty when the visitor has no company. See
[Companies & teams](/guides/companies-teams#address-book).

## `craft.b2b.orderLists`

`array<{ id: int, name: string, createdByUserId: ?int, itemCount: int }>`. Empty when
[Quick order](/reference/settings) is disabled or the visitor has no company.

## `craft.b2b.getOrderListItems(listId)` (aka `craft.b2b.orderListItems(listId)`)

`array<{ purchasableId: int, qty: int, sku: string, description: ?string }>`. The items of one of
the current user's company's order lists, guarded by company ownership. Empty for an unknown or
foreign list id.

## `craft.b2b.getQuoteByToken(token)` (aka `craft.b2b.quoteByToken(token)`)

`{ status, validUntil, notes, orderNumber, reference, itemSubtotal, total, currency }|null`.
Read-only quote data for the token accept page. Returns `null` for an unknown token or a quote
that does not belong to the signed-in user's company. See [Quotes](/guides/quotes).

## `craft.b2b.quotes`

`array<{ status, validUntil, dateCreated, orderNumber, reference, total, currency, acceptToken }>`.
The current user's company quotes, newest first. `acceptToken` is present only on a still-`sent`
quote. Empty when the visitor has no company.

## `craft.b2b.pendingApprovals`

`array<{ orderId, reference, total, currency, requesterName, dateCreated }>`. The pending
approval queue for the signed-in user, but only when they are an approver (admin or approver
role) of their company; any other visitor sees an empty queue. See
[Order approvals](/guides/approvals).

## `craft.b2b.myApprovalRequests`

`array<{ orderId, status, reference, total, currency, reason, dateCreated }>`. The signed-in
user's own approval requests, any status, newest first, with the decision reason. Empty for a
guest.

## `craft.b2b.catalogCriteria`

`array<string, mixed>`. Convenience product-query criteria for hiding non-catalog products from
a listing — **not** the security boundary. See
[Company-specific pricing & catalog](/guides/company-catalog#craft-b2b-catalogcriteria-is-convenience-filtering-only).

```twig
{% set products = craft.products(craft.b2b.catalogCriteria).all() %}
```

## Order-level accessors

Two Twig/PHP properties are added to every Commerce `Order` via an `Order` behavior — available
in Twig, in PHP, and inside Commerce order-email templates:

- **`order.b2bCompany`** — the `Company` element the order is linked to, or `null` for a guest
  order.
- **`order.b2bPaymentDueDate`** — a `DateTime` (order date plus the company's payment term), or
  `null` when the order is not completed, is a guest order, or the company has no payment term
  configured.
- **`order.b2bPoNumber`** — the buyer purchase-order number set at checkout, or `null`. See
  [PO numbers](/guides/po-numbers).
