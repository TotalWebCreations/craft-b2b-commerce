<?php

namespace totalwebcreations\b2bcommerce\modules\quotes\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use DateTime;
use DateTimeZone;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidArgumentException;

class Quotes extends Component
{
    /**
     * Disarms the buyer-mutation save guard for the plugin's own saves on a guarded
     * (frozen quote or pending approval) order. Buyer-side cart saves never touch
     * this; only the service methods that legitimately save such an order flip it via
     * allowGuardedSave(). Shared across the quote and approval flows since the single
     * BEFORE_SAVE guard enforces both.
     */
    private bool $guardedSaveAllowed = false;

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
            'acceptToken' => Craft::$app->getSecurity()->generateRandomString(40),
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

        if ($order->isCompleted) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This quote order has already been completed.')
            );
        }

        $this->assertTransition(QuoteStatus::from($row['status']), QuoteStatus::Sent);

        if ($validUntil !== null && $validUntil <= new DateTime()) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'The validity date must be in the future.')
            );
        }

        $order->setRecalculationMode(Order::RECALCULATION_MODE_NONE);

        $saved = $this->allowGuardedSave(
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
     * allowGuardedSave() guard. The order customer is left as-is (the requester): Commerce
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

        // A quote already lapsed by the expire cron reads as expired, not merely "processed",
        // so the buyer sees the same reason the lazy expireIfLapsed() path gives.
        if ($status === QuoteStatus::Expired) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This quote has expired.')
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

        if ($order->isCompleted) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This quote order has already been completed.')
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
     * Every quote for the control-panel workbench, newest first, optionally filtered to a
     * single status. Built without an N+1: one query loads the rows, then the referenced
     * orders, companies and requesters are each batch-loaded once and stitched onto the rows.
     *
     * @return array<int, array{
     *     orderId: int,
     *     status: string,
     *     companyId: int,
     *     companyName: ?string,
     *     requesterName: ?string,
     *     notes: ?string,
     *     declineReason: ?string,
     *     validUntil: ?DateTime,
     *     dateCreated: ?DateTime,
     *     order: ?Order
     * }>
     */
    public function getQuotesForCp(?string $status = null): array
    {
        $query = (new Query())
            ->from('{{%b2b_quotes}}')
            ->orderBy(['dateCreated' => SORT_DESC]);

        if ($status !== null) {
            $query->where(['status' => $status]);
        }

        $rows = $query->all();

        if ($rows === []) {
            return [];
        }

        $orders = Order::find()
            ->id(array_column($rows, 'orderId'))
            ->status(null)
            ->indexBy('id')
            ->all();

        // Companies are non-localized elements hosted on the primary site only, so query with site('*').
        $companies = Company::find()
            ->id(array_unique(array_column($rows, 'companyId')))
            ->site('*')
            ->unique()
            ->status(null)
            ->indexBy('id')
            ->all();

        $requesterIds = array_values(array_filter(array_column($rows, 'requestedById')));
        $requesters = $requesterIds !== []
            ? User::find()->id($requesterIds)->status(null)->indexBy('id')->all()
            : [];

        return array_map(function (array $row) use ($orders, $companies, $requesters): array {
            $requesterId = $row['requestedById'] !== null ? (int) $row['requestedById'] : null;
            $requester = $requesterId !== null ? ($requesters[$requesterId] ?? null) : null;

            return [
                'orderId' => (int) $row['orderId'],
                'status' => (string) $row['status'],
                'companyId' => (int) $row['companyId'],
                'companyName' => ($companies[(int) $row['companyId']] ?? null)?->title,
                'requesterName' => $requester !== null ? ($requester->fullName ?: $requester->email) : null,
                'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
                'declineReason' => $row['declineReason'] !== null ? (string) $row['declineReason'] : null,
                'validUntil' => $this->toUtcDateTime($row['validUntil']),
                'dateCreated' => $this->toUtcDateTime($row['dateCreated']),
                'order' => $orders[(int) $row['orderId']] ?? null,
            ];
        }, $rows);
    }

    /**
     * Every quote belonging to the given company, newest first, for the storefront overview.
     * Any member may view; the accept token is exposed only for a still-sent quote (the sole
     * status a buyer can act on), so the template can rebuild the accept link the mail sends.
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
    public function getQuotesForCompany(int $companyId): array
    {
        $rows = (new Query())
            ->from('{{%b2b_quotes}}')
            ->where(['companyId' => $companyId])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->all();

        if ($rows === []) {
            return [];
        }

        $orders = Order::find()
            ->id(array_column($rows, 'orderId'))
            ->status(null)
            ->indexBy('id')
            ->all();

        return array_map(function (array $row) use ($orders): array {
            $order = $orders[(int) $row['orderId']] ?? null;
            $status = (string) $row['status'];

            return [
                'status' => $status,
                'validUntil' => $this->toUtcDateTime($row['validUntil']),
                'dateCreated' => $this->toUtcDateTime($row['dateCreated']),
                'orderNumber' => $order?->number,
                'reference' => $order !== null ? ($order->reference ?: $order->getShortNumber()) : null,
                'total' => $order?->getTotalPrice(),
                'currency' => $order?->currency,
                'acceptToken' => $status === QuoteStatus::Sent->value ? (string) $row['acceptToken'] : null,
            ];
        }, $rows);
    }

    /**
     * Whether the order carries a quote whose line items must stay frozen against buyer
     * mutation: any non-terminal-for-editing status (requested, sent or accepted) on an
     * order that is not yet completed. An accepted quote stays line-item-frozen right
     * through checkout — the negotiated deal — so post-accept additions cannot ride in at
     * resolve-time prices while the freeze (recalculationMode = none) leaves tax and
     * shipping unrecomputed. Once the order completes, the freeze is spent and editing is
     * no longer this guard's concern.
     */
    public function orderHasLineItemFrozenQuote(?int $orderId): bool
    {
        if ($orderId === null) {
            return false;
        }

        return (new Query())
            ->from(['q' => '{{%b2b_quotes}}'])
            ->innerJoin(['o' => '{{%commerce_orders}}'], '[[o.id]] = [[q.orderId]]')
            ->where([
                'q.orderId' => $orderId,
                'q.status' => [
                    QuoteStatus::Requested->value,
                    QuoteStatus::Sent->value,
                    QuoteStatus::Accepted->value,
                ],
            ])
            ->andWhere(['not', ['o.isCompleted' => true]])
            ->exists();
    }

    /**
     * Vetoes completion of an order whose quote has not been accepted. A quote order can be
     * reactivated as a cart by number (commerce/cart/load-cart) and taken to checkout while
     * still requested (catalog prices), sent, declined or expired — bypassing accept, the
     * validity window and decline. Only an accepted quote may complete. Storefront-scoped
     * like the other completion guards; console and control-panel completions are the
     * deliberate merchant override.
     */
    public function enforceAcceptedBeforeCompletion(Order $order): void
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || $request->getIsCpRequest()) {
            return;
        }

        $status = (new Query())
            ->select(['status'])
            ->from('{{%b2b_quotes}}')
            ->where(['orderId' => $order->id])
            ->scalar();

        if ($status === false || $status === null || $status === QuoteStatus::Accepted->value) {
            return;
        }

        $message = Craft::t('b2b-commerce', 'This order is part of a quote that has not been accepted.');

        // The error MUST be set on an order attribute before throwing so Commerce's CartController
        // short-circuits the half-completed save (see OrderCompanyLink::enforcePurchasePolicy for the
        // full rationale). EVENT_BEFORE_COMPLETE_ORDER is not cancelable, so throwing aborts completion.
        $order->addError('customerId', $message);

        throw new Exception($message);
    }

    /**
     * Excludes every order that carries a quote row from Commerce's inactive-cart purge
     * query. The purge (Carts::purgeIncompleteCarts, on by default, 90 days) deletes
     * non-completed orders and the CASCADE FK would wipe their b2b_quotes rows — silently
     * losing sent quotes with long validity and ALL terminal quote history. Quotes are
     * business records, so keep the whole set. The purge query selects orders.id.
     */
    public function excludeQuoteOrdersFromPurge(Query $query): void
    {
        $query->andWhere([
            'not', [
                'orders.id' => (new Query())
                    ->select(['orderId'])
                    ->from('{{%b2b_quotes}}'),
            ],
        ]);
    }

    /**
     * Runs the callback with the buyer-mutation save guard disarmed, so the plugin's
     * own saves on a guarded order (the freeze in markSent, future quote or approval
     * service saves) are not vetoed as buyer mutations. The flag is always restored
     * afterwards, even on exception. markSent already runs in CP context
     * (double-covered); this keeps console and test contexts safe too.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function allowGuardedSave(callable $callback): mixed
    {
        $previous = $this->guardedSaveAllowed;
        $this->guardedSaveAllowed = true;

        try {
            return $callback();
        } finally {
            $this->guardedSaveAllowed = $previous;
        }
    }

    /**
     * Whether a guarded-order save is currently sanctioned by the plugin itself,
     * so the buyer-mutation save guard should stand down.
     */
    public function isGuardedSaveAllowed(): bool
    {
        return $this->guardedSaveAllowed;
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

    /**
     * Reads a stored UTC datetime column into a DateTime, or null when the column is empty.
     */
    private function toUtcDateTime(mixed $value): ?DateTime
    {
        if (empty($value)) {
            return null;
        }

        return new DateTime((string) $value, new DateTimeZone('UTC'));
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
