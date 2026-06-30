<?php

namespace totalwebcreations\b2bcommerce\variables;

use Craft;
use craft\commerce\elements\Order;
use craft\elements\Address;
use craft\elements\User;
use DateTime;
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
}
