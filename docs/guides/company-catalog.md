# Company-specific pricing & catalog

Two related, independently configured features let you give each company its own wholesale
prices and its own restricted product catalog — both without a custom pricing engine.

## Company-specific pricing

The plugin wires companies onto Craft user groups and lets **native Commerce Catalog Pricing**
do the rest. A company points at a Craft **user group** (its "pricing group"). The plugin keeps
the company's approved members in that group, and you create Commerce **catalog pricing rules**
whose *customer condition* targets the group. Members then see the group's catalog prices
everywhere Commerce shows a price — product pages, cart, checkout — because it is Commerce's own
pricing, not an overlay.

### Setup

1. **Create a user group** for the pricing tier, e.g. *Settings → Users → User Groups →
   "Wholesale — Acme"*. See the security caveat below on which group to use.
2. **Assign it to the company.** Open the company (*B2B → Companies*) and pick the group in the
   **Pricing group** field. Leave it on *No pricing group* to give the company no special
   prices.
3. **Build a Commerce catalog pricing rule** (*Commerce → Store settings → Catalog pricing*).
   Set the discount (for example, a flat price or a percentage off), and under **Customer
   condition** add a **User Group** rule matching the group from step 1. Save — Commerce
   recalculates the catalog prices for that group's members.

From then on the plugin keeps membership in sync automatically: inviting, registering, or adding
a member (control panel, storefront, or console) places them in the company's pricing group;
removing a member removes them from it; changing a company's pricing group moves all its members
from the old group to the new one.

### Approved companies only

Only members of an **approved** company are placed in the pricing group. A pending company's
members stay out of it, and blocking an approved company removes its members again — so an
unapproved or suspended account can never resolve wholesale prices. Approving a company syncs
its members in; blocking syncs them out.

### What the plugin does and does not touch

The plugin only ever adds or removes the configured **pricing groups** (the set of groups any
company points at). Every other group a user belongs to — permission groups, roles, unrelated
memberships — is left completely untouched. A member is only ever in their own company's pricing
group among the managed set.

### Security caveat

> [!IMPORTANT]
> The assigned group is a **pricing group only**. Because every approved member of the company
> is placed in it automatically, it must **not** be a group that grants control-panel access or
> admin permissions. Use a permission-free group dedicated to pricing; grant any real permissions
> through separate groups the plugin never manages.

## Company-specific catalog

Restrict which products a company's members may see and buy — a per-company **product
condition** you set in the control panel. Open the company (*B2B → Companies*) and build the
condition in the **Product catalog** field, using Commerce's own product condition builder
(product type, SKU, and the rest). Leave it empty to give the company the **full catalog** —
that is the default, so the feature is dormant until you configure a condition, and it rides
`enableCompanies` like the rest of the company pillar.

### The add-to-cart veto is the authoritative boundary

Enforcement is server-side. A veto on Commerce's `Order::EVENT_BEFORE_ADD_LINE_ITEM` refuses any
purchasable outside the company's catalog **across every add path** — add-to-cart, quick order
(SKU paste and CSV), re-order, order lists, quote-accept adoption, and order-on-behalf — because
they all funnel through that single choke point. A member who tries to add a restricted product
gets *"This product is not available for your account."* and nothing enters the cart. An empty
condition vetoes nothing (full catalog). The check fails **closed**: a corrupt or unusable
stored condition denies the purchasable rather than opening the full catalog.

### `craft.b2b.catalogCriteria` is convenience filtering only

For storefronts, `craft.b2b.catalogCriteria` returns product-query criteria you can spread into
a product query to hide non-catalog products from a listing:

```twig
{% set products = craft.products(craft.b2b.catalogCriteria).all() %}
```

It returns an empty array (no narrowing) for a visitor with no company or a company on the full
catalog. This helper is **convenience only — not a security boundary.** It merely keeps
restricted products out of sight; the add-to-cart veto above is what actually enforces the
catalog. Never rely on the helper for enforcement.
