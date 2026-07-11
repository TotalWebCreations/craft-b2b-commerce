# PDF documents

Quote, invoice and account-statement PDFs are rendered through Commerce's **native** `Pdfs`
service (dompdf) — the same renderer Commerce itself uses, so there is no extra PDF library
dependency and no new database table.

## Templates

Three overridable Twig templates ship as working defaults, plus copies you can restyle:

| Document | Bundled default | Restyle starting point | Setting |
| --- | --- | --- | --- |
| Quote | `src/templates/pdf/quote.twig` | `examples/templates/b2b/pdf/quote.twig` | `quotePdfTemplate` |
| Invoice | `src/templates/pdf/invoice.twig` | `examples/templates/b2b/pdf/invoice.twig` | `invoicePdfTemplate` |
| Statement | `src/templates/pdf/statement.twig` | `examples/templates/b2b/pdf/statement.twig` | `statementPdfTemplate` |

The quote and invoice PDFs render out of the box using the templates bundled inside the plugin —
no site template is required. Copy the matching example from `examples/templates/b2b/pdf/` into
your own site templates folder only if you want to restyle it (they are plain Twig/HTML, styled
with inline CSS as dompdf requires), then point the matching setting at your copy. Leave a
setting blank to use the bundled default.

Both the quote and invoice templates read the due date and PO number straight off the order —
`order.b2bPaymentDueDate` and `order.b2bPoNumber` — so a custom template gets them for free.

## Control panel downloads

- **Quote edit screen** (**B2B → Quotes**) has a **Download PDF** link, gated by the
  `Manage quotes` permission.
- The **company order overview** has a **Download invoice PDF** link per order, gated by the
  `Manage companies` permission — a deliberate split, since the two links live on pages with
  different permissions.
- The company's **Statement** page has a **Download PDF** link, gated by `Manage companies`,
  rendered through the order-agnostic `PdfDocuments::streamPdf()` since a statement isn't a
  Commerce order.

## Storefront downloads

- The quote **accept page** (`examples/templates/b2b/quotes/accept.twig`) shows a
  **Download PDF** link once the quote is `sent` or `accepted`, authorized by the same token the
  accept/decline links carry — no separate login-gated permission check beyond the token's own
  company scoping.
- A member-guarded **Download invoice PDF** snippet
  (`examples/templates/b2b/orders/_invoice-pdf-button.twig`) is available for a completed,
  pay-on-account order: include it with
  `{% include 'b2b/orders/_invoice-pdf-button' with { order: order } %}` from your
  order-history/detail template. The download action re-checks server-side that the order is a
  completed invoice order belonging to the caller's own company, so the link itself cannot be
  used to leak another company's invoice.
