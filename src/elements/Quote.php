<?php

namespace totalwebcreations\b2bcommerce\elements;

use Craft;
use craft\base\Element;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\elements\User;
use craft\enums\Color;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use DateTime;
use totalwebcreations\b2bcommerce\elements\db\QuoteQuery;
use totalwebcreations\b2bcommerce\enums\QuoteOrigin;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;

/**
 * A quote is a Craft element that wraps the b2b_quotes business record so its control-panel index
 * gets the native element experience (status sources, colored dots, search, sortable columns,
 * export). The element identity sits AROUND the row: the element id is the table's primary key, but
 * orderId stays the business key every enforcement guard reads directly. Status transitions and the
 * price-freeze enforcement all key on orderId and are unchanged — the element only adds identity.
 */
class Quote extends Element
{
    public const GQL_TYPE_NAME = 'B2bQuoteElement';

    public ?int $orderId = null;
    public ?int $companyId = null;
    public string $quoteStatus = QuoteStatus::Requested->value;
    public ?DateTime $validUntil = null;
    public ?string $notes = null;
    public ?string $declineReason = null;
    public ?int $requestedById = null;
    public ?string $acceptToken = null;
    public string $origin = QuoteOrigin::Customer->value;

    public static function displayName(): string
    {
        return Craft::t('b2b-commerce', 'Quote');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('b2b-commerce', 'Quotes');
    }

    public static function refHandle(): ?string
    {
        return 'quote';
    }

    public static function hasTitles(): bool
    {
        return false;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    /**
     * The quote statuses, driven by QuoteStatus, each with a color so the CP index renders a colored
     * status dot and a status source per state.
     *
     * @return array<string, array{label: string, color: Color}>
     */
    public static function statuses(): array
    {
        return [
            QuoteStatus::Requested->value => ['label' => Craft::t('b2b-commerce', 'Requested'), 'color' => Color::Orange],
            QuoteStatus::Sent->value => ['label' => Craft::t('b2b-commerce', 'Sent'), 'color' => Color::Blue],
            QuoteStatus::Accepted->value => ['label' => Craft::t('b2b-commerce', 'Accepted'), 'color' => Color::Green],
            QuoteStatus::Declined->value => ['label' => Craft::t('b2b-commerce', 'Declined'), 'color' => Color::Red],
            QuoteStatus::Expired->value => ['label' => Craft::t('b2b-commerce', 'Expired'), 'color' => Color::Gray],
        ];
    }

    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), ['validUntil']);
    }

    public static function find(): QuoteQuery
    {
        return new QuoteQuery(static::class);
    }

    public function getStatus(): ?string
    {
        return $this->quoteStatus;
    }

    /**
     * Quotes have no user-facing title, so the element label is a computed reference. The order
     * number is preferred; the id keeps the label stable before an order is resolvable.
     */
    public function getUiLabel(): string
    {
        return Craft::t('b2b-commerce', 'Quote #{number}', ['number' => $this->orderId ?? $this->id]);
    }

    public function getGqlTypeName(): string
    {
        return self::GQL_TYPE_NAME;
    }

    public function canView(User $user): bool
    {
        return $user->can('b2b-commerce:manageQuotes');
    }

    /**
     * Quotes are read-mostly: merchants never hand-create or hand-edit a quote through the element
     * editor. Requests come from the storefront and status changes go through the mark-sent/decline
     * actions, so the element carries no create or save capability. Delete stays available for
     * cleanup, gated by the same permission.
     */
    public function canSave(User $user): bool
    {
        return false;
    }

    public function canCreate(User $user): bool
    {
        return false;
    }

    public function canDelete(User $user): bool
    {
        return $user->can('b2b-commerce:manageQuotes');
    }

    /**
     * Quotes opt out of Craft's soft-delete. A quote's enforcement on its order — the price freeze
     * (orderHasLineItemFrozenQuote), the new-quote/approval block (orderHasOpenQuoteRow) and the
     * completion veto (enforceAcceptedBeforeCompletion) — all key on the b2b_quotes row, and that
     * row is dropped only by the id → elements CASCADE, which fires on a HARD delete. Trashing an
     * element leaves its row untouched, so a soft-deleted quote would vanish from the index while it
     * kept freezing its order until garbage collection opaquely dropped the row. Forcing a hard
     * delete makes "gone from the index" mean "no longer enforcing" the instant the merchant deletes
     * it. Because a quote is therefore never trashed there is likewise no restore path that could
     * bring back a row-less zombie element — delete stays authoritative and consistent.
     *
     * hardDelete is read by Elements::deleteElement() only after beforeDelete() returns, so setting
     * it here reliably promotes any delete (element-index action, programmatic) to a hard delete.
     */
    public function beforeDelete(): bool
    {
        $this->hardDelete = true;

        return parent::beforeDelete();
    }

    protected function cpEditUrl(): ?string
    {
        return UrlHelper::cpUrl("b2b/quotes/$this->id");
    }

    public function getPostEditUrl(): ?string
    {
        return UrlHelper::cpUrl('b2b/quotes');
    }

    /**
     * Roots the edit screen's breadcrumbs in the B2B section (Overview → Quotes), matching the rest
     * of the plugin.
     *
     * @return array<int, array{label: string, url: string}>
     */
    protected function crumbs(): array
    {
        return [
            [
                'label' => Craft::t('b2b-commerce', 'B2B'),
                'url' => UrlHelper::cpUrl('b2b'),
            ],
            [
                'label' => Craft::t('b2b-commerce', 'Quotes'),
                'url' => UrlHelper::cpUrl('b2b/quotes'),
            ],
        ];
    }

    protected static function defineSources(string $context): array
    {
        $request = Craft::$app->getRequest();

        if (!$request->getIsConsoleRequest() && !Craft::$app->getUser()->checkPermission('b2b-commerce:manageQuotes')) {
            return [];
        }

        $sources = [
            ['key' => '*', 'label' => Craft::t('b2b-commerce', 'All quotes'), 'criteria' => []],
        ];

        foreach (self::statuses() as $status => $config) {
            $sources[] = [
                'key' => "status:$status",
                'label' => $config['label'],
                'criteria' => ['quoteStatus' => $status],
            ];
        }

        return $sources;
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'company' => ['label' => Craft::t('b2b-commerce', 'Company')],
            'order' => ['label' => Craft::t('b2b-commerce', 'Order')],
            'requester' => ['label' => Craft::t('b2b-commerce', 'Requester')],
            'validUntil' => ['label' => Craft::t('b2b-commerce', 'Valid until')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['company', 'order', 'requester', 'validUntil', 'dateCreated'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'dateCreated' => Craft::t('app', 'Date Created'),
            'b2b_quotes.validUntil' => Craft::t('b2b-commerce', 'Valid until'),
        ];
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'company' => $this->companyAttributeHtml(),
            'order' => $this->orderAttributeHtml(),
            'requester' => $this->requesterAttributeHtml(),
            'validUntil' => $this->validUntil !== null
                ? Craft::$app->getFormatter()->asDate($this->validUntil, 'short')
                : '',
            default => parent::attributeHtml($attribute),
        };
    }

    private function companyAttributeHtml(): string
    {
        if ($this->companyId === null) {
            return '';
        }

        $name = (new Query())
            ->select(['name'])
            ->from('{{%b2b_companies}}')
            ->where(['id' => $this->companyId])
            ->scalar();

        if ($name === false || $name === null) {
            return '';
        }

        return Html::a((string) $name, UrlHelper::cpUrl("b2b/companies/$this->companyId"));
    }

    private function orderAttributeHtml(): string
    {
        $order = $this->getOrder();

        if ($order === null) {
            return '';
        }

        return Html::a(
            $order->getTotalPrice() . ' ' . $order->currency,
            (string) $order->getCpEditUrl()
        );
    }

    private function requesterAttributeHtml(): string
    {
        if ($this->requestedById === null) {
            return '';
        }

        $user = Craft::$app->getUsers()->getUserById($this->requestedById);

        if ($user === null) {
            return '';
        }

        return Html::encode($user->fullName ?: $user->email);
    }

    public function getOrder(): ?Order
    {
        if ($this->orderId === null) {
            return null;
        }

        return Order::find()->id($this->orderId)->status(null)->one();
    }

    protected function metaFieldsHtml(bool $static): string
    {
        $statusLabel = self::statuses()[$this->quoteStatus]['label'] ?? $this->quoteStatus;

        $rows = [
            Cp::fieldHtml(Html::encode($statusLabel), ['label' => Craft::t('b2b-commerce', 'Status')]),
        ];

        if ($this->notes !== null && $this->notes !== '') {
            $rows[] = Cp::fieldHtml(Html::encode($this->notes), ['label' => Craft::t('b2b-commerce', 'Notes')]);
        }

        if ($this->declineReason !== null && $this->declineReason !== '') {
            $rows[] = Cp::fieldHtml(Html::encode($this->declineReason), ['label' => Craft::t('b2b-commerce', 'Decline reason')]);
        }

        return implode('', $rows);
    }

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['orderId', 'companyId', 'acceptToken'], 'required'],
            [['orderId', 'companyId', 'requestedById'], 'integer'],
            ['quoteStatus', 'in', 'range' => array_map(fn(QuoteStatus $status) => $status->value, QuoteStatus::cases())],
            ['origin', 'in', 'range' => array_map(fn (QuoteOrigin $origin) => $origin->value, QuoteOrigin::cases())],
            [['notes', 'declineReason', 'acceptToken', 'validUntil'], 'safe'],
        ]);
    }

    /**
     * Upserts the b2b_quotes business columns keyed on the element id, mirroring Company::afterSave.
     * orderId remains the business key every enforcement guard reads; this write only keeps the row
     * that backs the element consistent with it. Db::upsert manages dateCreated/dateUpdated/uid.
     */
    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            Db::upsert('{{%b2b_quotes}}', [
                'id' => $this->id,
                'orderId' => $this->orderId,
                'companyId' => $this->companyId,
                'status' => $this->quoteStatus,
                'origin' => $this->origin,
                'validUntil' => $this->validUntil !== null ? Db::prepareDateForDb($this->validUntil) : null,
                'notes' => $this->notes,
                'declineReason' => $this->declineReason,
                'requestedById' => $this->requestedById,
                'acceptToken' => $this->acceptToken,
            ]);
        }

        parent::afterSave($isNew);
    }
}
