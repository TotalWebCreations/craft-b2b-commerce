<?php

use craft\commerce\elements\Order;
use craft\elements\User;
use totalwebcreations\b2bcommerce\console\controllers\TaxIdController;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\models\Settings;
use totalwebcreations\b2bcommerce\modules\companies\services\TaxIdValidation;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;
use yii\caching\ArrayCache;
use yii\console\ExitCode;

/*
 * Network-free approach: TaxIdValidation exposes an $existenceLookup callable seam that replaces
 * the live VIES REST call. Every test stubs that seam, so the suite never talks to VIES while the
 * real format check, Commerce-shared cache and policy logic still run for real. The app cache is
 * swapped for a per-test ArrayCache so cache assertions are isolated and no cache files leak.
 */

/**
 * Generates a unique, format-valid NL VAT id.
 */
function testVatId(): string
{
    return 'NL' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT) . 'B01';
}

/**
 * Stubs the VIES existence lookup with a fixed outcome, recording whether it was called.
 */
function stubViesLookup(?bool $outcome, ?bool &$called = null): void
{
    $called = false;

    Plugin::getInstance()->taxIdValidation->existenceLookup = function (string $taxId) use ($outcome, &$called): ?bool {
        $called = true;

        return $outcome;
    };
}

/**
 * Runs the callback with VAT-id validation enabled under the given outage policy, restoring the
 * settings afterwards.
 */
function withTaxIdValidation(string $policy, callable $callback): void
{
    $settings = Plugin::getInstance()->getSettings();
    $previousEnabled = $settings->validateTaxIds;
    $previousPolicy = $settings->taxIdValidationPolicy;
    $settings->validateTaxIds = true;
    $settings->taxIdValidationPolicy = $policy;

    try {
        $callback();
    } finally {
        $settings->validateTaxIds = $previousEnabled;
        $settings->taxIdValidationPolicy = $previousPolicy;
    }
}

/**
 * Builds an unsaved company carrying the given VAT id.
 */
function companyWithTaxId(string $taxId): Company
{
    $company = new Company();
    $company->title = 'VAT Co ' . uniqid();
    $company->companyStatus = Company::STATUS_APPROVED;
    $company->taxId = $taxId;

    return $company;
}

/**
 * Creates a tracked cart owned by the given customer, saved once in console context so the
 * passthrough (site requests only) has not run yet.
 */
function taxIdCart(?User $customer): Order
{
    $order = new Order();
    $order->number = md5(uniqid((string) mt_rand(), true));

    if ($customer !== null) {
        $order->setCustomer($customer);
    }

    if (!craftApp()->getElements()->saveElement($order)) {
        throw new RuntimeException('Could not save cart: ' . implode(', ', $order->getFirstErrors()));
    }

    trackElement($order);

    return $order;
}

beforeEach(function () {
    $GLOBALS['b2bOriginalCache'] = craftApp()->getCache();
    craftApp()->set('cache', new ArrayCache());
});

afterEach(function () {
    Plugin::getInstance()->taxIdValidation->existenceLookup = null;
    craftApp()->set('cache', $GLOBALS['b2bOriginalCache']);
});

/*
|--------------------------------------------------------------------------
| Wrapper behaviour
|--------------------------------------------------------------------------
*/

it('rejects a format-invalid VAT id without consulting VIES', function () {
    stubViesLookup(true, $called);

    $result = Plugin::getInstance()->taxIdValidation->validate('NOT-A-VAT-ID');

    expect($result)->toBeFalse()
        ->and($called)->toBeFalse();
});

it('confirms a valid VAT id and seeds Commerce\'s shared cache key', function () {
    $taxId = testVatId();
    stubViesLookup(true);

    $result = Plugin::getInstance()->taxIdValidation->validate($taxId);

    expect($result)->toBeTrue()
        ->and(craftApp()->getCache()->exists(TaxIdValidation::CACHE_KEY_PREFIX . $taxId))->toBeTrue();
});

it('rejects a VIES-invalid VAT id without caching it', function () {
    $taxId = testVatId();
    stubViesLookup(false);

    $result = Plugin::getInstance()->taxIdValidation->validate($taxId);

    expect($result)->toBeFalse()
        ->and(craftApp()->getCache()->exists(TaxIdValidation::CACHE_KEY_PREFIX . $taxId))->toBeFalse();
});

it('returns null on a VIES outage without caching anything', function () {
    $taxId = testVatId();
    stubViesLookup(null);

    $result = Plugin::getInstance()->taxIdValidation->validate($taxId);

    expect($result)->toBeNull()
        ->and(craftApp()->getCache()->exists(TaxIdValidation::CACHE_KEY_PREFIX . $taxId))->toBeFalse();
});

it('answers from the shared cache without consulting VIES again', function () {
    $taxId = testVatId();
    craftApp()->getCache()->set(TaxIdValidation::CACHE_KEY_PREFIX . $taxId, '1');
    stubViesLookup(false, $called);

    $result = Plugin::getInstance()->taxIdValidation->validate($taxId);

    expect($result)->toBeTrue()
        ->and($called)->toBeFalse();
});

it('bypasses the cache on refresh and clears a stale known-valid entry', function () {
    $taxId = testVatId();
    craftApp()->getCache()->set(TaxIdValidation::CACHE_KEY_PREFIX . $taxId, '1');
    stubViesLookup(false);

    $result = Plugin::getInstance()->taxIdValidation->validate($taxId, refresh: true);

    expect($result)->toBeFalse()
        ->and(craftApp()->getCache()->exists(TaxIdValidation::CACHE_KEY_PREFIX . $taxId))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Save-path policies (company save + registration)
|--------------------------------------------------------------------------
*/

it('saves a company with an unvalidated VAT id when validation is disabled', function () {
    stubViesLookup(false, $called);

    $company = companyWithTaxId(testVatId());
    $saved = craftApp()->getElements()->saveElement($company);

    if ($saved) {
        trackElement($company);
    }

    expect($saved)->toBeTrue()
        ->and($called)->toBeFalse();
});

it('refuses a definitively invalid VAT id under both policies', function () {
    stubViesLookup(false);

    withTaxIdValidation(Settings::TAX_ID_POLICY_LENIENT, function () {
        $company = companyWithTaxId(testVatId());
        $saved = craftApp()->getElements()->saveElement($company);

        expect($saved)->toBeFalse()
            ->and($company->getFirstError('taxId'))->toBe('This VAT ID is invalid.');
    });

    withTaxIdValidation(Settings::TAX_ID_POLICY_STRICT, function () {
        $company = companyWithTaxId(testVatId());
        $saved = craftApp()->getElements()->saveElement($company);

        expect($saved)->toBeFalse()
            ->and($company->getFirstError('taxId'))->toBe('This VAT ID is invalid.');
    });
});

it('refuses the save on a VIES outage under the strict policy with a distinct message', function () {
    stubViesLookup(null);

    withTaxIdValidation(Settings::TAX_ID_POLICY_STRICT, function () {
        $company = companyWithTaxId(testVatId());
        $saved = craftApp()->getElements()->saveElement($company);

        expect($saved)->toBeFalse()
            ->and($company->getFirstError('taxId'))->toBe('This VAT ID could not be validated.');
    });
});

it('accepts the save on a VIES outage under the lenient policy', function () {
    stubViesLookup(null);

    withTaxIdValidation(Settings::TAX_ID_POLICY_LENIENT, function () {
        $company = companyWithTaxId(testVatId());
        $saved = craftApp()->getElements()->saveElement($company);

        if ($saved) {
            trackElement($company);
        }

        expect($saved)->toBeTrue();
    });
});

it('saves an unrelated edit during a strict-policy outage when the VAT id did not change', function () {
    $company = companyWithTaxId(testVatId());
    craftApp()->getElements()->saveElement($company);
    trackElement($company);

    stubViesLookup(null, $called);

    withTaxIdValidation(Settings::TAX_ID_POLICY_STRICT, function () use ($company, &$called) {
        $company->registrationNumber = 'CHANGED-' . uniqid();
        $saved = craftApp()->getElements()->saveElement($company);

        expect($saved)->toBeTrue()
            ->and($called)->toBeFalse();
    });
});

it('refuses a changed VAT id during a strict-policy outage', function () {
    $company = companyWithTaxId(testVatId());
    craftApp()->getElements()->saveElement($company);
    trackElement($company);

    stubViesLookup(null);

    withTaxIdValidation(Settings::TAX_ID_POLICY_STRICT, function () use ($company) {
        $company->taxId = testVatId();
        $saved = craftApp()->getElements()->saveElement($company);

        expect($saved)->toBeFalse()
            ->and($company->getFirstError('taxId'))->toBe('This VAT ID could not be validated.');
    });
});

it('rejects a registration carrying an invalid VAT id through the company save path', function () {
    stubViesLookup(false);

    withTaxIdValidation(Settings::TAX_ID_POLICY_STRICT, function () {
        expect(fn () => Plugin::getInstance()->registration->register(
            'VAT Reg Co ' . uniqid(),
            '12345678',
            testVatId(),
            'Jane',
            'Doe',
            'vat_reg_' . uniqid() . '@example.test',
        ))->toThrow(InvalidArgumentException::class, 'This VAT ID is invalid.');
    });
});

/*
|--------------------------------------------------------------------------
| Checkout passthrough
|--------------------------------------------------------------------------
*/

it('fills empty order addresses with the company VAT id on a storefront save', function () {
    [$user, $company] = quoteMember();
    $taxId = testVatId();
    $company->taxId = $taxId;
    craftApp()->getElements()->saveElement($company);

    $order = taxIdCart($user);
    $order->setShippingAddress(['countryCode' => 'NL']);
    $order->setBillingAddress(['countryCode' => 'NL']);

    asSiteRequest(fn () => craftApp()->getElements()->saveElement($order));

    expect($order->getShippingAddress()->organizationTaxId)->toBe($taxId)
        ->and($order->getBillingAddress()->organizationTaxId)->toBe($taxId);
});

it('never overwrites a VAT id the customer entered on the address', function () {
    [$user, $company] = quoteMember();
    $company->taxId = testVatId();
    craftApp()->getElements()->saveElement($company);

    $order = taxIdCart($user);
    $order->setShippingAddress(['countryCode' => 'DE', 'organizationTaxId' => 'DE129273398']);

    asSiteRequest(fn () => craftApp()->getElements()->saveElement($order));

    expect($order->getShippingAddress()->organizationTaxId)->toBe('DE129273398');
});

it('leaves addresses untouched for a customer without a company or company VAT id', function () {
    $userWithoutCompany = createTestUser('no_company_' . uniqid() . '@example.test');

    $order = taxIdCart($userWithoutCompany);
    $order->setShippingAddress(['countryCode' => 'NL']);

    asSiteRequest(fn () => craftApp()->getElements()->saveElement($order));

    expect($order->getShippingAddress()->organizationTaxId)->toBeNull();

    [$member] = quoteMember();

    $companylessTaxOrder = taxIdCart($member);
    $companylessTaxOrder->setShippingAddress(['countryCode' => 'NL']);

    asSiteRequest(fn () => craftApp()->getElements()->saveElement($companylessTaxOrder));

    expect($companylessTaxOrder->getShippingAddress()->organizationTaxId)->toBeNull();
});

it('does not run the passthrough for console or CP saves', function () {
    [$user, $company] = quoteMember();
    $company->taxId = testVatId();
    craftApp()->getElements()->saveElement($company);

    $order = taxIdCart($user);
    $order->setShippingAddress(['countryCode' => 'NL']);

    craftApp()->getElements()->saveElement($order);

    expect($order->getShippingAddress()->organizationTaxId)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Revalidate console command
|--------------------------------------------------------------------------
*/

/**
 * Builds a TaxIdController that captures its output instead of writing to STDOUT.
 */
function revalidateController(): TaxIdController
{
    return new class('tax-id', Plugin::getInstance()) extends TaxIdController {
        public string $output = '';

        public function stdout($string): int
        {
            $this->output .= $string;

            return strlen($string);
        }

        public function stderr($string): int
        {
            $this->output .= $string;

            return strlen($string);
        }
    };
}

it('reports per company and exits OK when VIES answered for every VAT id', function () {
    $validCompany = companyWithTaxId($validTaxId = testVatId());
    $invalidCompany = companyWithTaxId($invalidTaxId = testVatId());
    craftApp()->getElements()->saveElement($validCompany);
    craftApp()->getElements()->saveElement($invalidCompany);
    trackElement($validCompany);
    trackElement($invalidCompany);

    // Unknown VAT ids (e.g. the seeded demo company) validate as true so only our fixture drives
    // the invalid count and the exit code.
    Plugin::getInstance()->taxIdValidation->existenceLookup =
        fn (string $taxId): ?bool => $taxId !== $invalidTaxId;

    $controller = revalidateController();
    $exitCode = $controller->actionRevalidate();

    expect($exitCode)->toBe(ExitCode::OK)
        ->and($controller->output)->toContain("\"{$validCompany->title}\" ({$validTaxId})... valid")
        ->and($controller->output)->toContain("\"{$invalidCompany->title}\" ({$invalidTaxId})... invalid")
        ->and($controller->output)->toMatch('/Done: \d+ valid, 1 invalid, 0 skipped/');
});

it('skips unreachable VAT ids with a warning and exits TEMPFAIL', function () {
    $company = companyWithTaxId($taxId = testVatId());
    craftApp()->getElements()->saveElement($company);
    trackElement($company);

    Plugin::getInstance()->taxIdValidation->existenceLookup =
        fn (string $lookedUp): ?bool => $lookedUp === $taxId ? null : true;

    $controller = revalidateController();
    $exitCode = $controller->actionRevalidate();

    expect($exitCode)->toBe(ExitCode::TEMPFAIL)
        ->and($controller->output)->toContain("\"{$company->title}\" ({$taxId})... skipped: VIES unreachable")
        ->and($controller->output)->toContain('VIES was unreachable for 1 VAT ID(s)');
});
