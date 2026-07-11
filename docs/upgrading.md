# Upgrading

## General process

```bash
composer update totalwebcreations/craft-b2b-commerce
php craft migrate/all
```

Craft runs the plugin's migrations automatically when you visit the control panel (or via
`migrate/all` in a deploy script). The install migration and every subsequent migration are
idempotent — re-running them against a database that already has the expected tables/columns is
a no-op, so a partially-applied deploy can be safely retried.

## Check the changelog

Read [`CHANGELOG.md`](https://github.com/TotalWebCreations/craft-b2b-commerce/blob/main/CHANGELOG.md)
for the specific version you're moving to or across — new settings, new permissions, and new
control-panel pages are called out there per release, along with the plugin's `schemaVersion`
bump for that change.

## Things to double-check after upgrading

- **Company field layout.** The custom-field layout you configure under **Settings → Plugins →
  B2B Commerce** is stored in the database, not project config (see the
  [known limitation](/guides/companies-teams#known-limitation)). It does **not** carry across
  environments automatically — reconfigure it per environment after deploying.
- **New settings default to their documented default**, not necessarily "off"/"on" to match your
  prior behaviour. Review the [settings reference](/reference/settings) after an upgrade that
  introduces a new pillar (for example, dunning ships **off by default** deliberately, since it
  is the only feature that emails customers autonomously).
- **New permissions** are not automatically granted to any user group — review
  [Permissions](/reference/permissions) and grant them to the groups that should have them (for
  example, `orderOnBehalf` for sales reps).
- **New cron-able console commands.** Some features (quote expiry, VAT revalidation, dunning)
  rely on a scheduled console command to do anything continuously — see the
  [console commands reference](/reference/console-commands) and make sure your scheduler picks
  up any new ones you want to use.

## Uninstalling

Uninstalling the plugin intentionally leaves its database tables and their data behind. See
[Installation & requirements](/getting-started/installation#uninstalling).
