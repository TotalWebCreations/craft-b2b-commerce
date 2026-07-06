<?php

namespace totalwebcreations\b2bcommerce\modules\dashboard\services;

use craft\db\Query;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\ApprovalStatus;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;

/**
 * Assembles the headline figures shown on the B2B control-panel overview and the
 * dashboard widget: company counts by status, the registration and approval queues,
 * open quotes, member reach and the total on-account receivable across all companies.
 *
 * Every figure is a single aggregate query, so the whole overview is a handful of
 * counts plus one balance sweep — never a per-company or per-order fan-out.
 */
class Overview extends Component
{
    /**
     * The complete set of overview figures, ready to render.
     *
     * @return array{
     *     companies: array{total: int, pending: int, approved: int, blocked: int},
     *     pendingRegistrations: int,
     *     openQuotes: int,
     *     pendingApprovals: int,
     *     members: int,
     *     outstanding: float
     * }
     */
    public function getStats(): array
    {
        $companies = $this->companyCounts();

        return [
            'companies' => $companies,
            // The registration queue is simply the pending companies awaiting approval; it is
            // surfaced under its own label because merchants think of it as "new sign-ups to review".
            'pendingRegistrations' => $companies['pending'],
            'openQuotes' => $this->openQuotesCount(),
            'pendingApprovals' => $this->pendingApprovalsCount(),
            'members' => $this->memberCount(),
            'outstanding' => $this->totalOutstanding(),
        ];
    }

    /**
     * Company totals broken down by status. The total is summed from the per-status counts so it
     * always matches the tiles below it, and each count runs through the element query so trashed
     * companies are excluded exactly as they are everywhere else in the control panel.
     *
     * @return array{total: int, pending: int, approved: int, blocked: int}
     */
    private function companyCounts(): array
    {
        $pending = (int) Company::find()->status(Company::STATUS_PENDING)->count();
        $approved = (int) Company::find()->status(Company::STATUS_APPROVED)->count();
        $blocked = (int) Company::find()->status(Company::STATUS_BLOCKED)->count();

        return [
            'total' => $pending + $approved + $blocked,
            'pending' => $pending,
            'approved' => $approved,
            'blocked' => $blocked,
        ];
    }

    /**
     * Quotes still in play: requested (awaiting a merchant price) plus sent (awaiting the buyer's
     * decision). Accepted, declined and expired quotes are settled and drop out of the queue.
     */
    private function openQuotesCount(): int
    {
        return (int) (new Query())
            ->from('{{%b2b_quotes}}')
            ->where(['status' => [QuoteStatus::Requested->value, QuoteStatus::Sent->value]])
            ->count();
    }

    private function pendingApprovalsCount(): int
    {
        return (int) (new Query())
            ->from('{{%b2b_approvals}}')
            ->where(['status' => ApprovalStatus::Pending->value])
            ->count();
    }

    /**
     * Distinct users across every company. A user linked to two companies counts once — this is
     * reach, not a sum of memberships.
     */
    private function memberCount(): int
    {
        return (int) (new Query())
            ->from('{{%b2b_company_users}}')
            ->count('DISTINCT [[userId]]');
    }

    /**
     * The total still owed on pay-on-account orders across all companies. Only companies that
     * actually carry an invoice order are swept (their ids come from the b2b_order_company invoice
     * snapshot), so the cost scales with on-account customers, not the whole company table. Each
     * company's figure reuses CreditBalance so the settled-status and out-of-band-payment rules
     * stay identical to the per-company credit screen.
     */
    private function totalOutstanding(): float
    {
        $companyIds = (new Query())
            ->select('companyId')
            ->distinct()
            ->from('{{%b2b_order_company}}')
            ->where(['isInvoice' => true])
            ->column();

        $creditBalance = Plugin::getInstance()->creditBalance;
        $outstanding = 0.0;

        foreach ($companyIds as $companyId) {
            $outstanding += $creditBalance->getOutstandingBalance((int) $companyId);
        }

        return $outstanding;
    }
}
