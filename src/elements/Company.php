<?php

namespace totalwebcreations\b2bcommerce\elements;

use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\elements\User;
use craft\enums\Color;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use totalwebcreations\b2bcommerce\elements\actions\ApproveCompanies;
use totalwebcreations\b2bcommerce\elements\actions\BlockCompanies;
use totalwebcreations\b2bcommerce\elements\db\CompanyQuery;
use totalwebcreations\b2bcommerce\models\Settings;
use totalwebcreations\b2bcommerce\Plugin;

class Company extends Element
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_BLOCKED = 'blocked';

    public const GQL_TYPE_NAME = 'Company';

    public ?string $registrationNumber = null;
    public ?string $taxId = null;
    public string $companyStatus = self::STATUS_PENDING;
    public ?float $creditLimit = null;
    public ?int $paymentTermDays = null;
    public bool $allowInvoicePayment = false;
    public ?float $approvalThreshold = null;
    public ?int $customerGroupId = null;

    public static function displayName(): string
    {
        return Craft::t('b2b-commerce', 'Company');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('b2b-commerce', 'Companies');
    }

    public static function refHandle(): ?string
    {
        return 'company';
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING => ['label' => Craft::t('b2b-commerce', 'Pending'), 'color' => Color::Orange],
            self::STATUS_APPROVED => ['label' => Craft::t('b2b-commerce', 'Approved'), 'color' => Color::Green],
            self::STATUS_BLOCKED => ['label' => Craft::t('b2b-commerce', 'Blocked'), 'color' => Color::Red],
        ];
    }

    public static function find(): CompanyQuery
    {
        return new CompanyQuery(static::class);
    }

    public function getStatus(): ?string
    {
        return $this->companyStatus;
    }

    public function getGqlTypeName(): string
    {
        return self::GQL_TYPE_NAME;
    }

    public static function gqlScopesByContext(mixed $context): array
    {
        return ['b2bCompanies.all'];
    }

    public function canView(User $user): bool
    {
        return $user->can('b2b-commerce:manageCompanies');
    }

    public function canSave(User $user): bool
    {
        return $user->can('b2b-commerce:manageCompanies');
    }

    public function canDelete(User $user): bool
    {
        return $user->can('b2b-commerce:manageCompanies');
    }

    public function canCreate(User $user): bool
    {
        return $user->can('b2b-commerce:manageCompanies');
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return Craft::$app->getFields()->getLayoutByType(static::class);
    }

    public function getPostEditUrl(): ?string
    {
        return UrlHelper::cpUrl('b2b/companies');
    }

    protected function cpEditUrl(): ?string
    {
        return UrlHelper::cpUrl("b2b/companies/$this->id");
    }

    /**
     * Roots the edit screen's breadcrumbs in the B2B section (Overview → Companies), so the topbar
     * matches the rest of the plugin instead of falling back to an empty trail.
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
                'label' => Craft::t('b2b-commerce', 'Companies'),
                'url' => UrlHelper::cpUrl('b2b/companies'),
            ],
        ];
    }

    protected static function defineActions(string $source): array
    {
        return [
            ApproveCompanies::class,
            BlockCompanies::class,
        ];
    }

    protected static function defineSources(string $context): array
    {
        $request = Craft::$app->getRequest();

        if (!$request->getIsConsoleRequest() && !Craft::$app->getUser()->checkPermission('b2b-commerce:manageCompanies')) {
            return [];
        }

        $sources = [
            ['key' => '*', 'label' => Craft::t('b2b-commerce', 'All companies'), 'criteria' => []],
        ];

        foreach (self::statuses() as $status => $config) {
            $sources[] = [
                'key' => "status:$status",
                'label' => $config['label'],
                'criteria' => ['companyStatus' => $status],
            ];
        }

        return $sources;
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'registrationNumber' => ['label' => Craft::t('b2b-commerce', 'Registration number')],
            'taxId' => ['label' => Craft::t('b2b-commerce', 'Tax ID')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['registrationNumber', 'taxId', 'dateCreated'];
    }

    protected function metaFieldsHtml(bool $static): string
    {
        return implode('', [
            $this->relatedPagesFieldHtml(),
            Cp::selectFieldHtml([
                'label' => Craft::t('b2b-commerce', 'Status'),
                'id' => 'companyStatus',
                'name' => 'companyStatus',
                'options' => collect(self::statuses())
                    ->map(fn(array $config, string $status) => ['value' => $status, 'label' => $config['label']])
                    ->values()
                    ->all(),
                'value' => $this->companyStatus,
                'disabled' => $static,
            ]),
        ]);
    }

    private function relatedPagesFieldHtml(): string
    {
        if ($this->id === null) {
            return '';
        }

        $links = Html::tag('ul', implode('', [
            Html::tag('li', Html::a(
                Craft::t('b2b-commerce', 'Members'),
                UrlHelper::cpUrl("b2b/companies/$this->id/members"),
            )),
            Html::tag('li', Html::a(
                Craft::t('b2b-commerce', 'Orders'),
                UrlHelper::cpUrl("b2b/companies/$this->id/orders"),
            )),
        ]));

        return Cp::fieldHtml($links, [
            'label' => Craft::t('b2b-commerce', 'Related'),
        ]);
    }

    public function setAttributesFromRequest(array $values): void
    {
        if (array_key_exists('allowInvoicePayment', $values)) {
            $values['allowInvoicePayment'] = (bool) $values['allowInvoicePayment'];
        }

        foreach (['creditLimit', 'approvalThreshold'] as $attribute) {
            if (array_key_exists($attribute, $values)) {
                $values[$attribute] = $this->normalizeFloat($values[$attribute]);
            }
        }

        foreach (['paymentTermDays', 'customerGroupId'] as $attribute) {
            if (array_key_exists($attribute, $values)) {
                $values[$attribute] = $this->normalizeInt($values[$attribute]);
            }
        }

        parent::setAttributesFromRequest($values);
    }

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['registrationNumber', 'taxId'], 'safe'],
            [
                'taxId',
                'validateTaxIdAgainstVies',
                'skipOnEmpty' => true,
                'when' => fn(): bool => Plugin::getInstance()->getSettings()->validateTaxIds && $this->taxIdHasChanged(),
            ],
            ['companyStatus', 'in', 'range' => array_keys(self::statuses())],
            ['allowInvoicePayment', 'boolean'],
            [['creditLimit', 'approvalThreshold'], 'number', 'min' => 0],
            ['paymentTermDays', 'integer', 'min' => 0],
            ['customerGroupId', 'integer'],
        ]);
    }

    /**
     * Validates the company VAT id against VIES via the plugin's TaxIdValidation service. This
     * rule is the single validation seam for every save path — control panel edits and frontend
     * registration alike (Registration saves a Company element, so it inherits this rule).
     *
     * Outcome mapping: false = definitively invalid (refused under both policies); null = VIES
     * unreachable, where the strict policy refuses with a distinct message and the lenient policy
     * accepts with a logged warning.
     */
    public function validateTaxIdAgainstVies(string $attribute): void
    {
        $plugin = Plugin::getInstance();
        $result = $plugin->taxIdValidation->validate((string) $this->$attribute);

        if ($result === true) {
            return;
        }

        if ($result === false) {
            $this->addError($attribute, Craft::t('b2b-commerce', 'This VAT ID is invalid.'));

            return;
        }

        if ($plugin->getSettings()->taxIdValidationPolicy === Settings::TAX_ID_POLICY_STRICT) {
            $this->addError($attribute, Craft::t('b2b-commerce', 'This VAT ID could not be validated.'));

            return;
        }

        Craft::warning(
            "VIES was unreachable while validating VAT ID \"{$this->$attribute}\" for company \"{$this->title}\"; accepted under the lenient policy.",
            'b2b-commerce'
        );
    }

    /**
     * Reports whether the VAT id differs from the persisted value, so the VIES rule only fires
     * when the number actually changed. New companies always count as changed; an unrelated edit
     * to an existing company therefore never triggers a VIES call and cannot be refused by a
     * strict-policy outage.
     */
    private function taxIdHasChanged(): bool
    {
        if ($this->id === null) {
            return true;
        }

        $persisted = (new Query())
            ->select(['taxId'])
            ->from('{{%b2b_companies}}')
            ->where(['id' => $this->id])
            ->scalar();

        if ($persisted === false) {
            return true;
        }

        return trim((string) $this->taxId) !== trim((string) $persisted);
    }

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $previous = $this->persistedPricingState();

            Db::upsert('{{%b2b_companies}}', [
                'id' => $this->id,
                'name' => $this->title,
                'registrationNumber' => $this->registrationNumber,
                'taxId' => $this->taxId,
                'status' => $this->companyStatus,
                'creditLimit' => $this->creditLimit,
                'paymentTermDays' => $this->paymentTermDays,
                'allowInvoicePayment' => $this->allowInvoicePayment,
                'approvalThreshold' => $this->approvalThreshold,
                'customerGroupId' => $this->customerGroupId,
            ]);

            $this->syncPricingGroupIfChanged($previous);
        }

        parent::afterSave($isNew);
    }

    /**
     * Reads the pricing-relevant state persisted for this company before the current save, so
     * afterSave can tell whether the pricing group or approval status just changed.
     *
     * @return array{customerGroupId: ?int, status: ?string}
     */
    private function persistedPricingState(): array
    {
        if ($this->id === null) {
            return ['customerGroupId' => null, 'status' => null];
        }

        $row = (new Query())
            ->select(['customerGroupId', 'status'])
            ->from('{{%b2b_companies}}')
            ->where(['id' => $this->id])
            ->one();

        if ($row === false || $row === null) {
            return ['customerGroupId' => null, 'status' => null];
        }

        return [
            'customerGroupId' => $row['customerGroupId'] !== null ? (int) $row['customerGroupId'] : null,
            'status' => $row['status'],
        ];
    }

    /**
     * Resyncs the company's members into the correct pricing group whenever the group or the
     * approval status changed. Both matter: the group determines which prices apply, and only
     * approved companies place their members in the group at all (approve → members join, block →
     * members leave). The previous group is passed through so members are moved out of it even when
     * the change orphaned it.
     *
     * @param array{customerGroupId: ?int, status: ?string} $previous
     */
    private function syncPricingGroupIfChanged(array $previous): void
    {
        $groupChanged = $previous['customerGroupId'] !== $this->customerGroupId;
        $statusChanged = $previous['status'] !== $this->companyStatus;

        if (!$groupChanged && !$statusChanged) {
            return;
        }

        Plugin::getInstance()->customerGroupSync->syncCompany($this, $previous['customerGroupId']);
    }

    private function normalizeFloat(mixed $value): ?float
    {
        if (is_array($value)) {
            $value = $value['value'] ?? null;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function normalizeInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
