<?php

namespace totalwebcreations\b2bcommerce\modules\approvals\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use totalwebcreations\b2bcommerce\helpers\Money;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Owns the per-company approval tier configuration: the bands that define which approver levels are
 * required at/above which order amount. The tiers are plain configuration rows (not element-backed);
 * the Approvals service reads them on submit to build the per-approval step ladder, and on
 * needsApproval to arm the gate on the lowest band.
 */
class ApprovalTiers extends Component
{
    /**
     * Every tier of the company, ordered by level ascending.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTiers(int $companyId): array
    {
        return (new Query())
            ->from('{{%b2b_approval_tiers}}')
            ->where(['companyId' => $companyId])
            ->orderBy(['level' => SORT_ASC])
            ->all();
    }

    /**
     * The tier rows whose minAmount is at or below the given amount — the levels an order of that
     * amount must clear — ordered by level ascending. Compared via {@see Money::withinLimit()} so a
     * tier boundary is never missed by a float rounding hair.
     *
     * @return array<int, array<string, mixed>>
     */
    public function requiredLevels(int $companyId, float $amount): array
    {
        return array_values(array_filter(
            $this->getTiers($companyId),
            static fn (array $tier): bool => Money::withinLimit((float) $tier['minAmount'], $amount),
        ));
    }

    /**
     * The smallest minAmount across the company's tiers, or null when it has none. Used to arm the
     * approval gate on the lowest band even when the company sets no single approvalThreshold.
     */
    public function lowestMinAmount(int $companyId): ?float
    {
        $min = (new Query())
            ->from('{{%b2b_approval_tiers}}')
            ->where(['companyId' => $companyId])
            ->min('minAmount');

        return $min !== null ? (float) $min : null;
    }

    /** @return array<string, mixed>|null */
    public function getTier(int $companyId, int $level): ?array
    {
        return (new Query())
            ->from('{{%b2b_approval_tiers}}')
            ->where(['companyId' => $companyId, 'level' => $level])
            ->one() ?: null;
    }

    public function hasTiers(int $companyId): bool
    {
        return (new Query())
            ->from('{{%b2b_approval_tiers}}')
            ->where(['companyId' => $companyId])
            ->exists();
    }

    /**
     * Upserts a tier keyed on (companyId, level). approverRole falls back to 'approver' when empty.
     */
    public function setTier(int $companyId, int $level, float $minAmount, string $approverRole, bool $departmentScoped): void
    {
        if ($level < 1) {
            throw new InvalidArgumentException(Craft::t('b2b-commerce', 'Invalid tier level.'));
        }

        if ($minAmount < 0) {
            throw new InvalidArgumentException(Craft::t('b2b-commerce', 'A tier amount cannot be negative.'));
        }

        Db::upsert('{{%b2b_approval_tiers}}', [
            'companyId' => $companyId,
            'level' => $level,
            'minAmount' => $minAmount,
            'approverRole' => $approverRole !== '' ? $approverRole : 'approver',
            'departmentScoped' => $departmentScoped,
        ]);
    }

    public function deleteTier(int $companyId, int $level): void
    {
        Db::delete('{{%b2b_approval_tiers}}', ['companyId' => $companyId, 'level' => $level]);
    }
}
