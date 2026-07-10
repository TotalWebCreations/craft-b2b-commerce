<?php

namespace totalwebcreations\b2bcommerce\variables;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\elements\Address;
use craft\elements\User;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;

class B2bVariable
{
    public function getCanViewPrices(): bool
    {
        return Plugin::getInstance()->priceVisibility->canViewPrices(
            Craft::$app->getUser()->getIdentity()
        );
    }

    public function getCanPurchase(): bool
    {
        return Plugin::getInstance()->priceVisibility->canPurchase(
            Craft::$app->getUser()->getIdentity()
        );
    }

    public function getCompany(): ?Company
    {
        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            return null;
        }

        return Plugin::getInstance()->companyMembers->getCompanyForUser($user->id);
    }

    /**
     * The current user's company credit position, or null when the visitor has no company.
     * `available` is the room left under the credit limit (never below zero), or null when the
     * company has no credit limit set.
     *
     * @return array{outstanding: float, creditLimit: ?float, available: ?float}|null
     */
    public function getCreditSummary(): ?array
    {
        $company = $this->getCompany();

        if ($company === null) {
            return null;
        }

        return Plugin::getInstance()->creditBalance->getSummary($company->id);
    }

    /**
     * The signed-in user's company account statement: outstanding invoice orders bucketed by aging,
     * or null when the visitor has no company. Read-only and scoped to the user's own company, so a
     * template can never read another company's statement.
     *
     * @return array{
     *     companyId: int,
     *     currency: ?string,
     *     asOf: DateTimeImmutable,
     *     totalOutstanding: float,
     *     buckets: array<string, float>,
     *     lines: array<int, array<string, mixed>>
     * }|null
     */
    public function getStatement(): ?array
    {
        $company = $this->getCompany();

        if ($company === null) {
            return null;
        }

        return Plugin::getInstance()->statements->getStatement($company->id);
    }

    /**
     * The current user's own spending budget for their company as
     * `{amount, period, spent, remaining}`, or null when they have no budget (unlimited), no company,
     * or are a guest. `spent` is this member's spend in the current period; `remaining` is the room
     * left under the budget, never below zero. Mirrors {@see getCreditSummary} as a read-only view.
     *
     * @return array{amount: float, period: string, spent: float, remaining: float}|null
     */
    public function getMemberBudget(): ?array
    {
        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            return null;
        }

        $company = Plugin::getInstance()->companyMembers->getCompanyForUser($user->id);

        if ($company === null) {
            return null;
        }

        $budgets = Plugin::getInstance()->budgets;
        $budget = $budgets->getBudget($company->id, $user->id);

        if ($budget === null) {
            return null;
        }

        $amount = (float) $budget['amount'];
        $spent = $budgets->getSpent($company->id, $user->id, new DateTimeImmutable('now'));

        return [
            'amount' => $amount,
            'period' => (string) $budget['period'],
            'spent' => $spent,
            'remaining' => max(0.0, $amount - $spent),
        ];
    }

    /**
     * The current user's department spending budget as `{name, amount, period, spent, remaining}`, or
     * null when they have no department, the department has no budget (unlimited), or they have no
     * company/are a guest. `spent` is the department's combined member spend this period; `remaining`
     * is the room left, never below zero. Mirrors {@see getMemberBudget} as a read-only view.
     *
     * @return array{name: string, amount: float, period: string, spent: float, remaining: float}|null
     */
    public function getDepartmentBudget(): ?array
    {
        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            return null;
        }

        if (Plugin::getInstance()->companyMembers->getCompanyForUser($user->id) === null) {
            return null;
        }

        $department = Plugin::getInstance()->departments->getDepartmentForUser($user->id);

        if ($department === null || $department['budgetAmount'] === null) {
            return null;
        }

        $amount = (float) $department['budgetAmount'];
        $spent = Plugin::getInstance()->departmentBudget->getSpent($department, new DateTimeImmutable('now'));

        return [
            'name' => (string) $department['name'],
            'amount' => $amount,
            'period' => (string) $department['budgetPeriod'],
            'spent' => $spent,
            'remaining' => max(0.0, $amount - $spent),
        ];
    }

    /** @return array<int, array{user: User, role: string}> */
    public function getTeamMembers(): array
    {
        $company = $this->getCompany();

        if ($company === null) {
            return [];
        }

        $rows = [];

        foreach (Plugin::getInstance()->companyMembers->getMemberUsers($company->id) as $member) {
            $rows[] = [
                'user' => $member['user'],
                'role' => $member['role']->value,
            ];
        }

        return $rows;
    }

    /** @return array<int, Address> */
    public function getCompanyAddresses(): array
    {
        $company = $this->getCompany();

        if ($company === null) {
            return [];
        }

        return Plugin::getInstance()->companyAddresses->getAddresses($company->id);
    }

    /** @return array<int, array{id: int, name: string, createdByUserId: ?int, itemCount: int}> */
    public function getOrderLists(): array
    {
        if (!Plugin::getInstance()->getSettings()->enableQuickOrder) {
            return [];
        }

        $company = $this->getCompany();

        if ($company === null) {
            return [];
        }

        return Plugin::getInstance()->orderLists->getLists($company->id);
    }

    /**
     * Read-only quote data for the token accept page (craft.b2b.quoteByToken(token)).
     * Returns null for an unknown token or a quote that does not belong to the signed-in
     * user's company, so a template can never probe another company's quotes. Every field
     * is null-safe against a missing order element.
     *
     * @return array{
     *     status: string,
     *     validUntil: ?DateTime,
     *     notes: ?string,
     *     orderNumber: ?string,
     *     reference: ?string,
     *     itemSubtotal: ?float,
     *     total: ?float,
     *     currency: ?string
     * }|null
     */
    public function getQuoteByToken(string $token): ?array
    {
        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            return null;
        }

        $row = Plugin::getInstance()->quotes->findByToken($token);

        if ($row === null) {
            return null;
        }

        $company = Plugin::getInstance()->companyMembers->getCompanyForUser($user->id);

        if ($company === null || (int) $row['companyId'] !== $company->id) {
            return null;
        }

        $order = Order::find()->id((int) $row['orderId'])->status(null)->one();

        return [
            'status' => (string) $row['status'],
            'validUntil' => empty($row['validUntil'])
                ? null
                : new DateTime((string) $row['validUntil'], new DateTimeZone('UTC')),
            'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
            'orderNumber' => $order?->number,
            'reference' => $order !== null ? ($order->reference ?: $order->getShortNumber()) : null,
            'itemSubtotal' => $order !== null ? $order->getItemSubtotal() : null,
            'total' => $order !== null ? $order->getTotalPrice() : null,
            'currency' => $order?->currency,
        ];
    }

    /**
     * The current user's company quotes, newest first, for the storefront overview.
     * Any company member may view them. Returns an empty array when the visitor has no
     * company. The accept token is present only on a still-sent quote, so a template can
     * build the same accept link the quote mail sends.
     *
     * @return array<int, array{
     *     status: string,
     *     validUntil: ?DateTime,
     *     dateCreated: ?DateTime,
     *     orderNumber: ?string,
     *     reference: ?string,
     *     total: ?float,
     *     currency: ?string,
     *     acceptToken: ?string
     * }>
     */
    public function getQuotes(): array
    {
        $company = $this->getCompany();

        if ($company === null) {
            return [];
        }

        return Plugin::getInstance()->quotes->getQuotesForCompany($company->id);
    }

    /**
     * The pending approval queue for the signed-in user, but only when they are an approver
     * (admin or approver role) of their company; any other visitor sees an empty queue, so a
     * purchaser can never read the company's pending requests. Newest first, with order and
     * requester data batch-loaded.
     *
     * @return array<int, array{
     *     orderId: int,
     *     reference: ?string,
     *     total: ?float,
     *     currency: ?string,
     *     requesterName: ?string,
     *     dateCreated: ?DateTime
     * }>
     */
    public function getPendingApprovals(): array
    {
        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            return [];
        }

        $members = Plugin::getInstance()->companyMembers;
        $company = $members->getCompanyForUser($user->id);

        if ($company === null) {
            return [];
        }

        $role = $members->getRoleForUser($user->id, $company->id);

        if ($role === null || !$role->canApproveOrders()) {
            return [];
        }

        return Plugin::getInstance()->approvals->getOpenForApprover($company->id, $user->id);
    }

    /**
     * The signed-in user's own approval requests, any status, newest first, with the decision
     * reason. Returns an empty array for a guest. Feeds the requester's overview and the
     * resume-checkout button on an approved request.
     *
     * @return array<int, array{
     *     orderId: int,
     *     status: string,
     *     reference: ?string,
     *     total: ?float,
     *     currency: ?string,
     *     reason: ?string,
     *     dateCreated: ?DateTime
     * }>
     */
    public function getMyApprovalRequests(): array
    {
        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            return [];
        }

        return Plugin::getInstance()->approvals->getRequestsForRequester($user->id);
    }

    /** @return array<int, array{purchasableId: int, qty: int, sku: string, description: ?string}> */
    public function getOrderListItems(int $listId): array
    {
        if (!Plugin::getInstance()->getSettings()->enableQuickOrder) {
            return [];
        }

        $company = $this->getCompany();

        if ($company === null) {
            return [];
        }

        try {
            return Plugin::getInstance()->orderLists->getItems($company, $listId);
        } catch (InvalidArgumentException) {
            return [];
        }
    }

    /**
     * Convenience criteria for hiding non-catalog products in a listing template — spread into a
     * product query, e.g. `{% set products = craft.products(craft.b2b.catalogCriteria).all() %}`.
     * Returns an empty array (no restriction) for a visitor with no company or a company on the full
     * catalog; otherwise `{id: <allowed product ids>}`.
     *
     * CONVENIENCE ONLY — this is NOT a security boundary. The add-to-cart veto
     * (Order::EVENT_BEFORE_ADD_LINE_ITEM) is the authoritative catalog gate; this helper merely keeps
     * restricted products out of sight. Resolving the id set runs the condition once, so cache the
     * result in hot templates.
     *
     * @return array<string, mixed>
     */
    public function getCatalogCriteria(): array
    {
        $company = $this->getCompany();

        if ($company === null) {
            return [];
        }

        $catalog = Plugin::getInstance()->companyCatalog;

        if ($catalog->getConditionForCompany($company) === null) {
            return [];
        }

        $ids = $catalog->applyToProductQuery(Product::find(), $company)->ids();

        return ['id' => $ids ?: [0]];
    }
}
