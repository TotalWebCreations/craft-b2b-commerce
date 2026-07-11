# Statements & dunning

## Account statements

A company's **statement** is its outstanding invoice orders bucketed by how far past due they
are — current, 1–30, 31–60, 61–90 and 90+ days — computed on demand from the same
outstanding-balance logic behind [credit balances](/guides/pay-on-account#reading-the-credit-position).
There is no statement database table.

- **Storefront:** `craft.b2b.statement` returns the signed-in user's company statement (`null`
  for a visitor without a company): `{ companyId, currency, asOf, totalOutstanding, buckets, lines }`.
- **Control panel:** the company's **Statement** page (linked from **Orders**, `Manage
  companies` permission) shows the aging summary and the outstanding invoice lines, read-only.
- **PDF download:** a **Download PDF** link on the Statement page renders the statement through
  the same PDF service as quotes and invoices (see [PDF documents](/guides/pdf-documents)), via
  the order-agnostic `PdfDocuments::streamPdf()` — a statement isn't a Commerce order.

## Dunning (overdue-invoice payment reminders)

Dunning is opt-in via the **Send payment reminders (dunning)** setting (`enableDunning`,
**off by default** — it is the only feature that emails customers autonomously). When on, run
the console command on a cron:

```sh
0 6 * * * cd /path/to/project && php craft b2b-commerce/dunning/run >> /dev/null 2>&1
```

For every company and every outstanding invoice, the command checks each configured
`dunningOffsets` day-offset (default `7, 14, 30` days past due) against a `b2b_dunning_log` table
and emails the `b2b_payment_reminder` system message to the company's admin-role members the
first time an invoice crosses an offset — never twice for the same invoice/offset pair.

The whole run is guarded by a named mutex, so an overlapping invocation (for example an
overrunning cron job) skips cleanly instead of racing the first one and double-sending. A send
failure or a per-company error is logged and counted but never aborts the rest of the run.

### Turning dunning on safely

1. Configure **Dunning offsets (days)** to match your terms (default `7, 14, 30`).
2. Enable **Send payment reminders (dunning)** only once the `b2b-commerce/dunning/run` command
   is scheduled — enabling the setting without a scheduled cron does nothing on its own, since
   the command is what actually sends reminders.
3. Optionally customize the `b2b_payment_reminder` system message under **Settings → Email →
   System messages**.

See the [console commands reference](/reference/console-commands) for the command's exit
behaviour, and the [settings reference](/reference/settings) for `enableDunning` and
`dunningOffsets`.
