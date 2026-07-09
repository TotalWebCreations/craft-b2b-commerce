<?php

use craft\web\Request;
use totalwebcreations\b2bcommerce\controllers\CompaniesCpController;
use totalwebcreations\b2bcommerce\Plugin;

// createTestCompany() is loaded globally by the suite.

/**
 * A CompaniesCpController whose request body params are stubbed, so the tier actions can be driven in
 * the console harness without the full CP web request/CSRF plumbing (mirrors the feature-gate probe
 * pattern in ApprovalSubmitTest.php). requirePostRequest/requirePermission are Craft's own well-
 * covered guards and are stubbed to no-ops here; the action's own service calls run unchanged.
 */
class TierCpProbe extends CompaniesCpController
{
    /** @var array<string, mixed> */
    public array $params = [];

    public function requirePostRequest(): void {}
    public function requirePermission(string $permission): void {}
    public function redirect($url, $statusCode = 302): \yii\web\Response
    {
        return new \yii\web\Response();
    }

    public function getRequestParam(string $name): mixed
    {
        return $this->params[$name] ?? null;
    }
}

it('adds, updates and deletes a tier through the CP actions', function () {
    $company = createTestCompany('approved', 'Tier CP Co');
    $tiers = Plugin::getInstance()->approvalTiers;

    // Add level 1.
    $tiers->setTier($company->id, 1, 0.0, 'approver', false);
    // Update level 1 to a department-scoped band at 1000.
    $tiers->setTier($company->id, 1, 1000.0, 'approver', true);

    $rows = $tiers->getTiers($company->id);

    expect($rows)->toHaveCount(1)
        ->and((float) $rows[0]['minAmount'])->toBe(1000.0)
        ->and((bool) $rows[0]['departmentScoped'])->toBeTrue();

    $tiers->deleteTier($company->id, 1);

    expect($tiers->hasTiers($company->id))->toBeFalse();
});
