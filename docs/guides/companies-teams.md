# Companies & teams

Companies are first-class Craft elements with their own control-panel section
(**B2B → Companies**), statuses, roles and permissions.

## Statuses

A company is `pending`, `approved` or `blocked`:

- **Pending** — created either by frontend registration or by an admin in the control panel.
  Members are not activated and are not placed in the company's pricing group.
- **Approved** — the store manager ran the **Approve** action. Members are activated, emailed
  the `B2B: company approved` message, and placed in the company's pricing group (if one is
  configured).
- **Blocked** — access revoked. Members are removed from the pricing group again.

## Roles

Each member of a company has one of three roles:

| Role | Can manage the team | Can approve orders |
| --- | --- | --- |
| `admin` | Yes | Yes |
| `purchaser` | No | No |
| `approver` | No | Yes |

A company always keeps at least one admin — the team-management flows guard against removing
or demoting the last one. (The console `team/assign-role` command deliberately bypasses this
guard as a recovery path — see the [console commands reference](/reference/console-commands).)

## Registration flow

A visitor submits the registration form (`b2b/register.twig`), which posts to
`b2b-commerce/registration/register`. This creates a **pending** Company element and a
**pending** user added to it with the `admin` role, then emails the store manager (the
**Admin notification email** setting, or the system "from" address when empty).

### Security notes

- **Honeypot.** The registration form includes a hidden field (default name `b2b_website`,
  configurable via the **Honeypot field name** setting) that real visitors never fill. A
  submission with that field filled returns the normal success response but creates nothing.
- **Before-register event.** The registration service fires a cancelable `RegisterEvent` before
  doing anything else, so you can plug in extra checks (rate limiting, disposable-email
  blocking, CAPTCHA). Set `$event->isValid = false` to cancel; see the
  [events reference](/reference/events) for the full example.
- **Email enumeration.** Registration reports "An account with this email address already
  exists." when the email is taken — an accepted tradeoff for a B2B flow where clear feedback to
  genuine business users outweighs the low enumeration risk of a manually reviewed, invite-style
  signup.

## Team management

Company admins manage their own team from the frontend through the `b2b-commerce/team/invite`,
`b2b-commerce/team/change-role` and `b2b-commerce/team/remove` actions (see
`examples/templates/b2b/team/index.twig`). The same operations are available to a
`manageCompanies`-permissioned operator from the company's **Members** page in the control
panel (add/invite, change role, remove) — reusing the identical guards (approved company,
duplicate-membership check, last-admin protection).

```twig
{% set company = craft.b2b.company %}

{% if company %}
    <p>{{ 'Ordering on behalf of'|t }} {{ company.title }}</p>
{% endif %}

<ul>
    {% for member in craft.b2b.teamMembers %}
        <li>{{ member.user.fullName }} — {{ member.role }}</li>
    {% endfor %}
</ul>
```

Use `company.title` as the canonical accessor for the company name — it is the element's title
attribute and is always populated.

## Address book

Companies own a shared address book. Every stored address is a native Craft `Address` element
owned by the `Company`, so the whole team sees the same list. Read it with
`craft.b2b.companyAddresses` (an array of `Address` elements, empty when the visitor has no
company). Company admins manage the list through the `b2b-commerce/addresses/save` and
`b2b-commerce/addresses/delete` actions.

Orders keep their own address copies, so to use a stored address at checkout you copy its
individual fields onto the cart rather than reference the shared address by id — see
`examples/templates/b2b/addresses/index.twig` for a complete add/edit/delete form.

## Orders linked to a company

A completed order is linked to the buyer's company. Read the company back from any order with
`order.b2bCompany`, which returns the `Company` element (or `null` for guest orders). Each
completed order also exposes its **payment due date** — the order date plus the company's
payment term — through `order.b2bPaymentDueDate` (a `DateTime`, or `null` when the order is not
completed, is a guest order, or the company has no payment term configured).

## Control panel

The **B2B** section opens on an **Overview** landing page showing companies by status, the
pending-registration queue, open quotes, pending approvals, the distinct member count and the
total outstanding on account — each linking through to its list. The same figures are available
as a **B2B overview** widget on Craft's dashboard. Both are gated behind the `Manage companies`
permission.

Each company's control-panel page also links to per-company **Members**, **Orders**,
**Departments** ([departments & budgets](/guides/departments-budgets)), **Sales reps**
([order on behalf](/guides/sales-reps)), **Approval tiers**
([order approvals](/guides/approvals)) and **Statement**
([statements & dunning](/guides/statements-dunning)) pages.

## Known limitation

**Company field layout is stored in the database, not project config.** The custom-field layout
you configure under **Settings → Plugins → B2B Commerce** is saved directly to the database and
does **not** deploy through project config. Reconfigure it per environment after deploying.
