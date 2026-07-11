# Console commands

The plugin ships these Craft console commands:

## `php craft b2b-commerce/seed`

Bootstraps a demo, pre-approved company (*Acme Wholesale Ltd*) with an admin user
(`buyer@acme.test`) for local development. It is idempotent: if the demo user or company already
exists it does nothing.

## `php craft b2b-commerce/team/assign-role <companyId> <email> <role>`

Assigns (or re-assigns) a company role to a user, looked up by email. Roles are `admin`,
`purchaser` and `approver`. This is the **recovery path** for a company that lost its last
admin: unlike the frontend team flows it deliberately bypasses the last-admin guard, so an
operator can reinstate an admin from the command line.

If the user already belongs to a **different** company, the command refuses by default (so
nobody is moved between companies by accident) and asks for the `--force` flag:

```bash
php craft b2b-commerce/team/assign-role 42 buyer@acme.test admin --force
```

With `--force`, the user's existing membership is removed before the new one is added, so a
reassigned user never ends up belonging to two companies at once.

## `php craft b2b-commerce/quotes/expire`

Flips every still-open quote (`requested` or `sent`) whose `validUntil` has passed to `expired`,
in a single update, and prints the count. Quotes without a `validUntil` never expire. **Run it
on a cron** so quotes lapse on their own, for example hourly:

```sh
0 * * * * cd /path/to/project && php craft b2b-commerce/quotes/expire >> /dev/null 2>&1
```

## `php craft b2b-commerce/tax-id/revalidate`

Revalidates every company VAT ID against VIES, bypassing the known-valid cache, and reports per
company plus a summary count. Skips (with a warning) VAT IDs it cannot check because VIES is
unreachable and then exits `75` (`TEMPFAIL`) so schedulers can retry; exits `0` otherwise when
every VAT ID got a verdict. Cron-able, for example weekly. See
[VAT ID validation & reverse charge](/guides/companies-teams) for context, or the source
`src/console/controllers/TaxIdController.php`.

## `php craft b2b-commerce/dunning/run`

Emails overdue-invoice payment reminders, one per configured day-offset per invoice. A no-op
(with a message) unless **Send payment reminders (dunning)** (`enableDunning`) is turned on.
Guarded by a run-level mutex so an overlapping invocation skips cleanly instead of
double-sending; a per-company or per-send failure is reported and counted but never aborts the
rest of the run. Cron-able, for example daily:

```sh
0 6 * * * cd /path/to/project && php craft b2b-commerce/dunning/run >> /dev/null 2>&1
```

See [Statements & dunning](/guides/statements-dunning) for the full feature.
