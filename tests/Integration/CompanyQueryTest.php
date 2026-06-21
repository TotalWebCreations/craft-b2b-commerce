<?php

use craft\models\Site;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

it('filters companies by status with companyStatus()', function () {
    $pending = createTestCompany('pending');
    $approved = createTestCompany('approved');

    $pendingIds = Company::find()->companyStatus('pending')->status(null)->ids();

    expect($pendingIds)->toContain($pending->id)
        ->and($pendingIds)->not->toContain($approved->id);
});

it('returns companies of every status with status(null)', function () {
    $pending = createTestCompany('pending');
    $blocked = createTestCompany('blocked');

    $ids = Company::find()->status(null)->ids();

    expect($ids)->toContain($pending->id)
        ->and($ids)->toContain($blocked->id);
});

it('resolves the company for a user on a secondary site', function () {
    $user = createTestUser('multisite_' . uniqid() . '@example.test');
    $company = createTestCompany('approved');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    $sitesService = Craft::$app->getSites();
    $originalSite = $sitesService->getCurrentSite();
    $group = $sitesService->getAllGroups()[0];

    $tempSite = new Site([
        'groupId' => $group->id,
        'name' => 'Temp ' . uniqid(),
        'handle' => 'temp' . uniqid(),
        'language' => 'en-US',
        'hasUrls' => false,
        'primary' => false,
    ]);

    $sitesService->saveSite($tempSite);

    try {
        $sitesService->setCurrentSite($tempSite);

        $resolved = Plugin::getInstance()->companyMembers->getCompanyForUser($user->id);

        expect($resolved)->not->toBeNull()
            ->and($resolved->id)->toBe($company->id);
    } finally {
        $sitesService->setCurrentSite($originalSite);
        $sitesService->deleteSiteById($tempSite->id);
    }
});
