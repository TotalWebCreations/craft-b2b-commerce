# System messages

The plugin registers these system messages under **Settings → Email → System messages**, so you
can customize their subject and body per site like any other Craft system message.

| Key | Heading | Sent when |
| --- | --- | --- |
| `b2b_company_approved` | B2B: company approved | A company is approved in the control panel — sent to each of its members so they can set a password and sign in. |
| `b2b_member_added` | B2B: added to a company | A user is added to a company (invite, control-panel add, or console). |
| `b2b_quote_sent` | B2B: quote sent | A merchant marks a quote sent — carries the accept and decline links. |
| `b2b_quote_declined` | B2B: quote declined | A buyer's quote request is declined by the merchant — carries the reason. |
| `b2b_approval_requested` | B2B: order approval requested | A purchaser submits an order for approval — sent to every approver/admin of the company. |
| `b2b_approval_approved` | B2B: order approved | An approver approves an order — carries either a "placed automatically" note or a resume-checkout instruction, depending on whether the order could complete directly. |
| `b2b_approval_declined` | B2B: order declined | An approver declines an order — carries the reason. |
| `b2b_payment_reminder` | B2B: payment reminder | The [`b2b-commerce/dunning/run`](/reference/console-commands) command sends an overdue-invoice reminder — carries the invoice reference, due date, days overdue and amount due. |

All messages are registered via Craft's `craft\services\SystemMessages::EVENT_REGISTER_MESSAGES`
event with a default English body; the Dutch translation of every message ships with the plugin.
