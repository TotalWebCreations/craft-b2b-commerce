<?php

use craft\web\Response as WebResponse;
use totalwebcreations\b2bcommerce\controllers\QuickOrderController;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;
use totalwebcreations\b2bcommerce\variables\B2bVariable;

/**
 * Exposes the shared feature gate the quick-order actions run first, without the
 * full web request/response plumbing a real action dispatch would need. asFailure()
 * is Craft's own well-covered helper, so it is stubbed to a bare response carrying
 * the message; requireFeature()'s real logic (reading the setting, short-circuiting
 * when off) is exercised unchanged — it is the exact early check in actionAdd,
 * actionUploadCsv and actionReorder.
 */
class QuickOrderFeatureGateProbe extends QuickOrderController
{
    public function gate(string $settingName): ?WebResponse
    {
        return $this->requireFeature($settingName);
    }

    public function asFailure(?string $message = null, array $data = [], array $routeParams = []): ?WebResponse
    {
        $response = new WebResponse();
        $response->data = ['message' => $message];

        return $response;
    }
}

/**
 * Runs the callback while $user is the signed-in identity, restoring the previous
 * identity afterwards so the toggle assertions can resolve the user's company.
 */
function asIdentity(craft\elements\User $user, callable $callback): void
{
    $userComponent = craftApp()->getUser();
    $previous = $userComponent->getIdentity();
    $userComponent->setIdentity($user);

    try {
        $callback();
    } finally {
        $userComponent->setIdentity($previous);
    }
}

it('short-circuits the quick-order feature gate when the toggle is off', function () {
    $probe = new QuickOrderFeatureGateProbe('quick-order', Plugin::getInstance());

    expect($probe->gate('enableQuickOrder'))->toBeNull();

    $plugin = Plugin::getInstance();
    Craft::$app->getPlugins()->savePluginSettings($plugin, ['enableQuickOrder' => false]);

    try {
        $response = $probe->gate('enableQuickOrder');

        expect($response)->not->toBeNull()
            ->and($response->data['message'])->toBe('This feature is not enabled.');
    } finally {
        Craft::$app->getPlugins()->savePluginSettings($plugin, ['enableQuickOrder' => true]);
    }
});

it('hides order-list data from craft.b2b when quick order is disabled', function () {
    $company = createTestCompany('approved');
    $user = createTestUser('toggle_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    $listId = Plugin::getInstance()->orderLists->createList($company, 'Weekly staples', $user->id);
    $variant = createTestVariant('TOGGLE-' . substr(uniqid(), -6));
    Plugin::getInstance()->orderLists->setItem($company, $listId, $variant->id, 2);

    $variable = new B2bVariable();
    $plugin = Plugin::getInstance();

    asIdentity($user, function () use ($variable, $listId, $plugin) {
        // Enabled: the list and its items are exposed to the storefront.
        expect($variable->getOrderLists())->toHaveCount(1)
            ->and($variable->getOrderListItems($listId))->toHaveCount(1);

        Craft::$app->getPlugins()->savePluginSettings($plugin, ['enableQuickOrder' => false]);

        try {
            // Disabled: the example flows disappear from craft.b2b entirely.
            expect($variable->getOrderLists())->toBe([])
                ->and($variable->getOrderListItems($listId))->toBe([]);
        } finally {
            Craft::$app->getPlugins()->savePluginSettings($plugin, ['enableQuickOrder' => true]);
        }
    });
});
