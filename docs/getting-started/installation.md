# Installation & requirements

## Requirements

| Requirement | Notes |
| --- | --- |
| **Craft CMS 5, Pro edition** | Required, because business accounts rely on multiple users. The Solo edition only supports a single user and cannot run the B2B flows. |
| **Craft Commerce 5** (`^5.0`) | The **EU VAT ID validation & reverse charge** feature is built on Commerce's native VAT support, which arrived in **Commerce 5.3**; on Commerce 5.0–5.2 every other feature works, but leave **Validate VAT IDs** off. All other pillars have no minimum beyond Commerce 5.0. |
| **PHP 8.2** or newer | |
| **MySQL or PostgreSQL** | Both are supported and verified against clean installs. The plugin uses only Craft's query builder, so it runs on either of Craft's supported databases. |

## Install with Composer

```bash
composer require totalwebcreations/craft-b2b-commerce
php craft plugin/install b2b-commerce
```

Alternatively, install it from the control panel under **Settings → Plugins**.

## Uninstalling

Uninstalling the plugin intentionally leaves its database tables and their data behind (company
accounts, quotes, approvals, order lists, member and department budgets, sales-rep assignments,
and more). The install migration is idempotent: if the tables already exist it skips creation and
keeps your data, so a later reinstall picks up exactly where you left off — no manual SQL
required.

## Next step

Continue with the [quick start](/getting-started/quick-start) to copy the example templates,
configure the plugin settings, and walk through the registration → approval → ordering flow.
