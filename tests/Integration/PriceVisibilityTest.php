<?php

use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

/**
 * Toggles hidePricesForGuests, verifies the in-process flag flipped, runs the
 * assertions and always restores the flag to false afterwards.
 */
function withHidePricesForGuests(bool $enabled, callable $callback): void
{
    $plugin = Plugin::getInstance();

    Craft::$app->getPlugins()->savePluginSettings($plugin, ['hidePricesForGuests' => $enabled]);

    try {
        expect($plugin->getSettings()->hidePricesForGuests)->toBe($enabled);

        $callback();
    } finally {
        Craft::$app->getPlugins()->savePluginSettings($plugin, ['hidePricesForGuests' => false]);
    }
}

it('lets guests view prices when the setting is off', function () {
    withHidePricesForGuests(false, function () {
        expect(Plugin::getInstance()->priceVisibility->canViewPrices(null))->toBeTrue();
    });
});

it('hides prices from guests when the setting is on', function () {
    withHidePricesForGuests(true, function () {
        expect(Plugin::getInstance()->priceVisibility->canViewPrices(null))->toBeFalse();
    });
});

it('hides prices from a user without a company', function () {
    $user = createTestUser('nocompany_' . uniqid() . '@example.test');

    withHidePricesForGuests(true, function () use ($user) {
        expect(Plugin::getInstance()->priceVisibility->canViewPrices($user))->toBeFalse();
    });
});

it('hides prices from a user whose company is pending', function () {
    $user = createTestUser('pending_' . uniqid() . '@example.test');
    $company = createTestCompany('pending');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    withHidePricesForGuests(true, function () use ($user) {
        expect(Plugin::getInstance()->priceVisibility->canViewPrices($user))->toBeFalse();
    });
});

it('hides prices from a user whose company is blocked', function () {
    $user = createTestUser('blocked_' . uniqid() . '@example.test');
    $company = createTestCompany('blocked');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    withHidePricesForGuests(true, function () use ($user) {
        expect(Plugin::getInstance()->priceVisibility->canViewPrices($user))->toBeFalse();
    });
});

it('lets a user whose company is approved view prices', function () {
    $user = createTestUser('approved_' . uniqid() . '@example.test');
    $company = createTestCompany('approved');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    withHidePricesForGuests(true, function () use ($user) {
        expect(Plugin::getInstance()->priceVisibility->canViewPrices($user))->toBeTrue();
    });
});
