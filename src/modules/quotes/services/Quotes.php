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
use DateTimeZone;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;
use yii\base\InvalidArgumentException;

class Quotes extends Component
{
    /**
     * Disarms the open-quote save guard for the plugin's own saves on an open
     * quote. Buyer-side cart saves never touch this; only the service methods
     * below (and future ones) flip it via allowQuoteSave().
     */
    private bool $quoteSaveAllowed = false;

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

        if ($validUntil !== null && $validUntil <= new DateTime()) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'The validity date must be in the future.')
            );
        }

        $order->setRecalculationMode(Order::RECALCULATION_MODE_NONE);

        $saved = $this->allowQuoteSave(
            fn (): bool => Craft::$app->getElements()->saveElement($order)
        );

        if (!$saved) {
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
     * Looks up a quote row by its accept token. The token column carries a unique
     * index, so this is a single indexed equality lookup — no timing-sensitive manual
     * string compare is needed, and there is no fallback to guard. An empty or unknown
     * token simply yields null; the callers turn that into a generic, oracle-free error.
     *
     * @return array<string, mixed>|null
     */
    public function findByToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        return (new Query())
            ->from('{{%b2b_quotes}}')
            ->where(['acceptToken' => $token])
            ->one() ?: null;
    }

    /**
     * Accepts a sent quote by its token and hands the quote order to the session as the
     * active cart, so the buyer checks out directly against the frozen prices (pay on
     * account included; the credit check still runs at checkout).
     *
     * The status flip lives on the b2b_quotes row alone — the order element is never
     * saved here, so it keeps its frozen recalculationMode untouched and needs no
     * allowQuoteSave() guard. The order customer is left as-is (the requester): Commerce
     * checkout authorizes on the session cart, not on customer identity, and the invoice
     * gateway checks the customer's company, which equals the acceptor's company for any
     * member of the same company. See the price-integrity notes in the README.
     */
    public function acceptByToken(string $token, User $actor): Order
    {
        $row = $this->authorizeTokenAccess($token, $actor);

        $this->expireIfLapsed($row);

        $status = QuoteStatus::from($row['status']);

        if ($status === QuoteStatus::Requested) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This quote has not been sent yet.')
            );
        }

        if ($status !== QuoteStatus::Sent) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This quote has already been processed.')
            );
        }

        // Resolve the order BEFORE flipping the status, so a missing order can never leave an
        // accepted quote row without an order behind it.
        $order = Order::find()->id((int) $row['orderId'])->status(null)->one();

        if ($order === null) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This quote is not available.')
            );
        }

        Db::update('{{%b2b_quotes}}', [
            'status' => QuoteStatus::Accepted->value,
        ], ['orderId' => $row['orderId']]);

        $carts = Commerce::getInstance()->getCarts();
        $carts->forgetCart();
        $carts->setSessionCartNumber($order->number);

        return $order;
    }

    /**
     * Declines a sent (or still-requested) quote by its token, recording the reason and
     * notifying the store admin. Shares the token, membership and lapsed-quote guards with
     * acceptByToken, then delegates the status transition and notification to decline().
     */
    public function declineByToken(string $token, User $actor, string $reason): void
    {
        $row = $this->authorizeTokenAccess($token, $actor);

        $this->expireIfLapsed($row);

        $order = Order::find()->id((int) $row['orderId'])->status(null)->one();

        if ($order === null) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This quote is not available.')
            );
        }

        $this->decline($order, $reason, byCustomer: true);
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
            ['<=', 'validUntil', Db::prepareDateForDb(new DateTime())],
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

    /**
     * Runs the callback with the open-quote save guard disarmed, so the plugin's
     * own saves on an open quote (the freeze in markSent, future service saves)
     * are not vetoed as buyer mutations. The flag is always restored afterwards,
     * even on exception. markSent already runs in CP context (double-covered);
     * this keeps console and test contexts safe too.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function allowQuoteSave(callable $callback): mixed
    {
        $previous = $this->quoteSaveAllowed;
        $this->quoteSaveAllowed = true;

        try {
            return $callback();
        } finally {
            $this->quoteSaveAllowed = $previous;
        }
    }

    /**
     * Whether an open-quote save is currently sanctioned by the plugin itself,
     * so the buyer-mutation save guard should stand down.
     */
    public function isQuoteSaveAllowed(): bool
    {
        return $this->quoteSaveAllowed;
    }

    /**
     * Whether the order's in-memory line items diverge from the rows stored for
     * it: a line item without an id (a pending addition), a changed set of ids
     * (an addition or a removal), any quantity change, or a changed options
     * signature on an existing item (an in-place options edit). Read straight
     * from commerce_lineitems so it is independent of this plugin's own tables.
     * The open-quote save guard uses this to veto exactly the buyer-side cart
     * mutations (qty, options, additions, removals) while leaving untouched
     * saves — which never change the line-item set — free to proceed.
     */
    public function lineItemsDifferFromStored(Order $order): bool
    {
        $rows = (new Query())
            ->select(['id', 'qty', 'optionsSignature'])
            ->from('{{%commerce_lineitems}}')
            ->where(['orderId' => $order->id])
            ->indexBy('id')
            ->all();

        $current = [];

        foreach ($order->getLineItems() as $lineItem) {
            if ($lineItem->id === null) {
                return true;
            }

            $current[$lineItem->id] = $lineItem;
        }

        if (count($current) !== count($rows)) {
            return true;
        }

        foreach ($current as $id => $lineItem) {
            if (!array_key_exists($id, $rows)) {
                return true;
            }

            if ((int) $rows[$id]['qty'] !== (int) $lineItem->qty) {
                return true;
            }

            if ((string) $rows[$id]['optionsSignature'] !== $lineItem->getOptionsSignature()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves and authorizes a token for a token-driven action: the row must exist and
     * belong to the actor's company. Both failures raise the SAME generic message so a
     * guessed token cannot be distinguished from a real quote of another company (no
     * oracle), while leaking nothing a legitimate mail recipient could not already infer.
     *
     * @return array<string, mixed>
     */
    private function authorizeTokenAccess(string $token, User $actor): array
    {
        $row = $this->findByToken($token);
        $company = Plugin::getInstance()->companyMembers->getCompanyForUser($actor->id);

        if ($row === null || $company === null || (int) $row['companyId'] !== $company->id) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This quote is not available.')
            );
        }

        return $row;
    }

    /**
     * Lazily expires a still-open quote whose validity window has closed: the row flips to
     * expired on this first touch and the caller is stopped with a clear message. A terminal
     * quote, or one with no or a future deadline, passes through untouched.
     *
     * @param array<string, mixed> $row
     */
    private function expireIfLapsed(array $row): void
    {
        $status = QuoteStatus::from($row['status']);

        if (!in_array($status, [QuoteStatus::Requested, QuoteStatus::Sent], true)) {
            return;
        }

        if (!$this->hasLapsed($row)) {
            return;
        }

        Db::update('{{%b2b_quotes}}', [
            'status' => QuoteStatus::Expired->value,
        ], ['orderId' => $row['orderId']]);

        throw new InvalidArgumentException(
            Craft::t('b2b-commerce', 'This quote has expired.')
        );
    }

    /** @param array<string, mixed> $row */
    private function hasLapsed(array $row): bool
    {
        if (empty($row['validUntil'])) {
            return false;
        }

        $validUntil = new DateTime((string) $row['validUntil'], new DateTimeZone('UTC'));

        return $validUntil <= new DateTime('now', new DateTimeZone('UTC'));
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
