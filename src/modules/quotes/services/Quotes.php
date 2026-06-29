<?php

namespace totalwebcreations\b2bcommerce\modules\quotes\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use DateTime;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;
use yii\base\InvalidArgumentException;

class Quotes extends Component
{
    /**
     * Turns the given cart into a quote request: records a requested quote row for the
     * actor's approved company and notifies an admin, then forgets the session cart so
     * the order survives untouched as the quote while the customer keeps a fresh cart.
     */
    public function requestQuote(Order $cart, User $actor, ?string $notes): void
    {
        if ($cart->getLineItems() === []) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'Your cart is empty.')
            );
        }

        $company = Plugin::getInstance()->companyMembers->getCompanyForUser($actor->id);

        if ($company === null || $company->companyStatus !== Company::STATUS_APPROVED) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'Only approved company members can request quotes.')
            );
        }

        if ($this->orderIsQuote((int) $cart->id)) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This cart is already a quote request.')
            );
        }

        Db::insert('{{%b2b_quotes}}', [
            'orderId' => $cart->id,
            'companyId' => $company->id,
            'status' => QuoteStatus::Requested->value,
            'notes' => $notes,
            'requestedById' => $actor->id,
            'acceptToken' => StringHelper::randomString(40),
        ]);

        $this->notifyAdmin($company, $cart);

        Commerce::getInstance()->getCarts()->forgetCart();
    }

    /**
     * Sends a requested quote to its requester with a merchant-set price freeze.
     *
     * The freeze is Commerce's own recalculationMode: setting it to none and saving
     * pins every line-item price exactly as the merchant left it. The mode persists
     * as a column on the order and is restored on load before the element's init()
     * can default it back, so it survives reloads and every later save short-circuits
     * recalculate(). See the price-integrity notes in the README.
     */
    public function markSent(Order $order, ?DateTime $validUntil): void
    {
        $row = $this->requireQuoteRow($order);
        $this->assertTransition(QuoteStatus::from($row['status']), QuoteStatus::Sent);

        $order->setRecalculationMode(Order::RECALCULATION_MODE_NONE);

        if (!Craft::$app->getElements()->saveElement($order)) {
            throw new InvalidArgumentException(implode(' ', $order->getFirstErrors()));
        }

        Db::update('{{%b2b_quotes}}', [
            'status' => QuoteStatus::Sent->value,
            'validUntil' => $validUntil !== null ? Db::prepareDateForDb($validUntil) : null,
        ], ['orderId' => $order->id]);

        $this->notifyQuoteSent($order, $row['acceptToken'], $this->requester($row));
    }

    /**
     * Declines a requested or sent quote, recording the reason. A merchant decline
     * (byCustomer = false) notifies the requester; a customer decline notifies the
     * store admin.
     */
    public function decline(Order $order, string $reason, bool $byCustomer): void
    {
        $row = $this->requireQuoteRow($order);
        $this->assertTransition(QuoteStatus::from($row['status']), QuoteStatus::Declined);

        Db::update('{{%b2b_quotes}}', [
            'status' => QuoteStatus::Declined->value,
            'declineReason' => $reason,
        ], ['orderId' => $order->id]);

        if ($byCustomer) {
            $this->notifyAdminDeclined($order, $reason);

            return;
        }

        $this->notifyQuoteDeclined($order, $reason, $this->requester($row));
    }

    /**
     * Expires every still-open quote (requested or sent) whose validity window has
     * closed, in a single UPDATE. Returns the number of rows expired.
     */
    public function expireOverdue(): int
    {
        return Db::update('{{%b2b_quotes}}', [
            'status' => QuoteStatus::Expired->value,
        ], [
            'and',
            ['status' => [QuoteStatus::Requested->value, QuoteStatus::Sent->value]],
            ['not', ['validUntil' => null]],
            ['<', 'validUntil', Db::prepareDateForDb(new DateTime())],
        ]);
    }

    /**
     * Whether the order carries a quote that is still open (requested or sent) and so
     * must not be mutated through the cart endpoints.
     */
    public function orderHasOpenQuote(?int $orderId): bool
    {
        if ($orderId === null) {
            return false;
        }

        return (new Query())
            ->from('{{%b2b_quotes}}')
            ->where([
                'orderId' => $orderId,
                'status' => [QuoteStatus::Requested->value, QuoteStatus::Sent->value],
            ])
            ->exists();
    }

    private function orderIsQuote(int $orderId): bool
    {
        return (new Query())
            ->from('{{%b2b_quotes}}')
            ->where(['orderId' => $orderId])
            ->exists();
    }

    /** @return array<string, mixed> */
    private function requireQuoteRow(Order $order): array
    {
        $row = (new Query())
            ->from('{{%b2b_quotes}}')
            ->where(['orderId' => $order->id])
            ->one();

        if (!$row) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This order is not a quote.')
            );
        }

        return $row;
    }

    private function assertTransition(QuoteStatus $from, QuoteStatus $to): void
    {
        if ($from->canTransitionTo($to)) {
            return;
        }

        throw new InvalidArgumentException(
            Craft::t('b2b-commerce', 'A quote cannot move from {from} to {to}.', [
                'from' => $from->value,
                'to' => $to->value,
            ])
        );
    }

    /** @param array<string, mixed> $row */
    private function requester(array $row): ?User
    {
        if ($row['requestedById'] === null) {
            return null;
        }

        return Craft::$app->getUsers()->getUserById((int) $row['requestedById']);
    }

    private function notifyQuoteSent(Order $order, string $acceptToken, ?User $recipient): void
    {
        if ($recipient === null) {
            Craft::warning("Quote {$order->id} has no requester to notify of sending", 'b2b-commerce');

            return;
        }

        $sent = Craft::$app->getMailer()
            ->composeFromKey('b2b_quote_sent', [
                'order' => $order,
                'user' => $recipient,
                'acceptUrl' => UrlHelper::siteUrl('quotes/accept', ['token' => $acceptToken]),
                'declineUrl' => UrlHelper::siteUrl('quotes/decline', ['token' => $acceptToken]),
            ])
            ->setTo($recipient)
            ->send();

        if (!$sent) {
            Craft::warning("Failed to send quote sent email to {$recipient->email}", 'b2b-commerce');
        }
    }

    private function notifyQuoteDeclined(Order $order, string $reason, ?User $recipient): void
    {
        if ($recipient === null) {
            Craft::warning("Quote {$order->id} has no requester to notify of decline", 'b2b-commerce');

            return;
        }

        $sent = Craft::$app->getMailer()
            ->composeFromKey('b2b_quote_declined', [
                'order' => $order,
                'user' => $recipient,
                'reason' => $reason,
            ])
            ->setTo($recipient)
            ->send();

        if (!$sent) {
            Craft::warning("Failed to send quote declined email to {$recipient->email}", 'b2b-commerce');
        }
    }

    private function notifyAdminDeclined(Order $order, string $reason): void
    {
        $to = Plugin::getInstance()->getSettings()->adminNotificationEmail
            ?: App::parseEnv(App::mailSettings()->fromEmail);

        if (!$to) {
            return;
        }

        $reference = $order->reference ?: $order->getShortNumber();

        $sent = Craft::$app->getMailer()
            ->compose()
            ->setTo($to)
            ->setSubject(Craft::t('b2b-commerce', 'Quote declined by customer: {reference}', ['reference' => $reference]))
            ->setTextBody(Craft::t('b2b-commerce', 'The customer declined quote {reference}. Reason: {reason}', [
                'reference' => $reference,
                'reason' => $reason,
            ]))
            ->send();

        if (!$sent) {
            Craft::warning("Failed to send customer-decline notification to {$to}", 'b2b-commerce');
        }
    }

    private function notifyAdmin(Company $company, Order $cart): void
    {
        $to = Plugin::getInstance()->getSettings()->adminNotificationEmail
            ?: App::parseEnv(App::mailSettings()->fromEmail);

        if (!$to) {
            return;
        }

        $sent = Craft::$app->getMailer()
            ->compose()
            ->setTo($to)
            ->setSubject(Craft::t('b2b-commerce', 'New quote request: {company}', ['company' => $company->title]))
            ->setTextBody(Craft::t('b2b-commerce', '{company} requested a quote. Review it in the control panel: {url}', [
                'company' => $company->title,
                'url' => $cart->getCpEditUrl(),
            ]))
            ->send();

        if (!$sent) {
            Craft::warning("Failed to send quote request notification to {$to}", 'b2b-commerce');
        }
    }
}
