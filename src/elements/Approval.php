<?php

namespace totalwebcreations\b2bcommerce\elements;

use Craft;
use craft\base\Element;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\elements\User;
use craft\enums\Color;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use totalwebcreations\b2bcommerce\elements\db\ApprovalQuery;
use totalwebcreations\b2bcommerce\enums\ApprovalStatus;

/**
 * An approval is a Craft element that wraps the b2b_approvals business record so its control-panel
 * index gets the native element experience (status sources, colored dots, search, sortable columns,
 * export). The element identity sits AROUND the row: the element id is the table's primary key, but
 * orderId stays the business key every enforcement guard reads directly. The four-eyes decision
 * transitions all key on orderId and are unchanged — the element only adds identity.
 *
 * The control-panel index is deliberately read-only monitoring: an order's approval is decided by
 * the company's own approvers on the storefront, never by the store operator, so the element carries
 * no create, save or delete capability.
 */
class Approval extends Element
{
    public const GQL_TYPE_NAME = 'B2bApprovalElement';

    public ?int $orderId = null;
    public ?int $companyId = null;
    public string $approvalStatus = ApprovalStatus::Pending->value;
    public ?int $requestedById = null;
    public ?int $resolvedById = null;
    public ?string $reason = null;
    public ?float $thresholdAmount = null;

    public static function displayName(): string
    {
        return Craft::t('b2b-commerce', 'Approval');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('b2b-commerce', 'Approvals');
    }

    public static function refHandle(): ?string
    {
        return 'approval';
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
     * The approval statuses, driven by ApprovalStatus, each with a color so the CP index renders a
     * colored status dot and a status source per state.
     *
     * @return array<string, array{label: string, color: Color}>
     */
    public static function statuses(): array
    {
        return [
            ApprovalStatus::Pending->value => ['label' => Craft::t('b2b-commerce', 'Pending'), 'color' => Color::Orange],
            ApprovalStatus::Approved->value => ['label' => Craft::t('b2b-commerce', 'Approved'), 'color' => Color::Green],
            ApprovalStatus::Declined->value => ['label' => Craft::t('b2b-commerce', 'Declined'), 'color' => Color::Red],
        ];
    }

    public static function find(): ApprovalQuery
    {
        return new ApprovalQuery(static::class);
    }

    public function getStatus(): ?string
    {
        return $this->approvalStatus;
    }

    /**
     * Approvals have no user-facing title, so the element label is a computed reference. The order
     * number is preferred; the id keeps the label stable before an order is resolvable.
     */
    public function getUiLabel(): string
    {
        return Craft::t('b2b-commerce', 'Approval #{number}', ['number' => $this->orderId ?? $this->id]);
    }

    public function getGqlTypeName(): string
    {
        return self::GQL_TYPE_NAME;
    }

    public function canView(User $user): bool
    {
        return $user->can('b2b-commerce:manageApprovals');
    }

    /**
     * The control-panel approvals page is read-only monitoring: an order's approval is decided by
     * the company's own approvers on the storefront (submit / approve / decline), never by the store
     * operator. So the element carries no create, save or delete capability. A merchant who genuinely
     * needs to place a held order overrides the gate the same way every other completion guard is
     * overridden — by completing the order from the control-panel order editor.
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
        return false;
    }

    /**
     * Monitoring-only: an approval carries no control-panel detail page (its decisions are made on
     * the storefront by the company's own approvers, and the index needs no per-row action surface).
     * Returning null keeps the element-index rows as plain, unlinked labels rather than pointing at a
     * route that does not exist.
     */
    protected function cpEditUrl(): ?string
    {
        return null;
    }

    protected static function defineSources(string $context): array
    {
        $request = Craft::$app->getRequest();

        if (!$request->getIsConsoleRequest() && !Craft::$app->getUser()->checkPermission('b2b-commerce:manageApprovals')) {
            return [];
        }

        $sources = [
            ['key' => '*', 'label' => Craft::t('b2b-commerce', 'All approvals'), 'criteria' => []],
        ];

        foreach (self::statuses() as $status => $config) {
            $sources[] = [
                'key' => "status:$status",
                'label' => $config['label'],
                'criteria' => ['approvalStatus' => $status],
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
            'resolver' => ['label' => Craft::t('b2b-commerce', 'Resolver')],
            'threshold' => ['label' => Craft::t('b2b-commerce', 'Threshold')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['company', 'order', 'requester', 'resolver', 'threshold', 'dateCreated'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'dateCreated' => Craft::t('app', 'Date Created'),
            'b2b_approvals.thresholdAmount' => Craft::t('b2b-commerce', 'Threshold'),
        ];
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'company' => $this->companyAttributeHtml(),
            'order' => $this->orderAttributeHtml(),
            'requester' => $this->userAttributeHtml($this->requestedById),
            'resolver' => $this->userAttributeHtml($this->resolvedById),
            'threshold' => $this->thresholdAttributeHtml(),
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

    private function userAttributeHtml(?int $userId): string
    {
        if ($userId === null) {
            return '';
        }

        $user = Craft::$app->getUsers()->getUserById($userId);

        if ($user === null) {
            return '';
        }

        return Html::encode($user->fullName ?: $user->email);
    }

    private function thresholdAttributeHtml(): string
    {
        if ($this->thresholdAmount === null) {
            return '';
        }

        $order = $this->getOrder();

        if ($order !== null) {
            return Html::encode(Craft::$app->getFormatter()->asCurrency($this->thresholdAmount, $order->currency));
        }

        return Html::encode((string) $this->thresholdAmount);
    }

    public function getOrder(): ?Order
    {
        if ($this->orderId === null) {
            return null;
        }

        return Order::find()->id($this->orderId)->status(null)->one();
    }

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['orderId', 'companyId'], 'required'],
            [['orderId', 'companyId', 'requestedById', 'resolvedById'], 'integer'],
            ['approvalStatus', 'in', 'range' => array_map(fn(ApprovalStatus $status) => $status->value, ApprovalStatus::cases())],
            [['reason', 'thresholdAmount'], 'safe'],
        ]);
    }

    /**
     * Upserts the b2b_approvals business columns keyed on the element id, mirroring Quote::afterSave.
     * orderId remains the business key every enforcement guard reads; this write only keeps the row
     * that backs the element consistent with it. Db::upsert manages dateCreated/dateUpdated/uid.
     */
    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            Db::upsert('{{%b2b_approvals}}', [
                'id' => $this->id,
                'orderId' => $this->orderId,
                'companyId' => $this->companyId,
                'status' => $this->approvalStatus,
                'requestedById' => $this->requestedById,
                'resolvedById' => $this->resolvedById,
                'reason' => $this->reason,
                'thresholdAmount' => $this->thresholdAmount,
            ]);
        }

        parent::afterSave($isNew);
    }
}
